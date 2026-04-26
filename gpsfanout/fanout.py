#!/usr/bin/env python3
import base64
import hashlib
import json
import logging
import os
import signal
import sqlite3
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Optional


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def parse_bool(value: Optional[str], default: bool = False) -> bool:
    if value is None or value == "":
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def env_int(name: str, default: int) -> int:
    value = os.environ.get(name)
    if not value:
        return default
    return int(value)


@dataclass(frozen=True)
class Downstream:
    name: str
    url: str
    timeout: float
    headers: dict[str, str]
    retry_client_errors: bool


class FanoutApp:
    def __init__(self) -> None:
        self.db_path = os.environ.get("FANOUT_DB_PATH", "/data/fanout.sqlite3")
        self.token = os.environ.get("FANOUT_TOKEN", "")
        self.max_attempts = env_int("FANOUT_MAX_ATTEMPTS", 0)
        self.max_body_bytes = env_int("FANOUT_MAX_BODY_BYTES", 1024 * 1024)
        self.worker_interval = env_int("FANOUT_WORKER_INTERVAL_SECONDS", 2)
        self.downstreams = self._load_downstreams()
        self.wake_worker = threading.Event()
        self.stopping = threading.Event()

        if not self.token:
            raise RuntimeError("FANOUT_TOKEN is required")
        if not self.downstreams:
            raise RuntimeError("At least one downstream must be enabled")

        os.makedirs(os.path.dirname(self.db_path), exist_ok=True)
        self._init_db()

    def _load_downstreams(self) -> list[Downstream]:
        downstreams: list[Downstream] = []

        if parse_bool(os.environ.get("REITTI_ENABLED"), default=True):
            url = os.environ.get("REITTI_URL", "").strip()
            token = os.environ.get("REITTI_API_TOKEN", "").strip()
            if not url:
                raise RuntimeError("REITTI_URL is required when REITTI_ENABLED=true")
            if not token:
                raise RuntimeError("REITTI_API_TOKEN is required when REITTI_ENABLED=true")
            downstreams.append(
                Downstream(
                    name="reitti",
                    url=url,
                    timeout=float(os.environ.get("REITTI_TIMEOUT_SECONDS", "10")),
                    headers={
                        "Content-Type": "application/json",
                        "X-API-TOKEN": token,
                        "User-Agent": "location-fanout/1.0",
                    },
                    retry_client_errors=parse_bool(
                        os.environ.get("REITTI_RETRY_CLIENT_ERRORS"), default=False
                    ),
                )
            )

        if parse_bool(os.environ.get("GEOPULSE_ENABLED"), default=False):
            url = os.environ.get("GEOPULSE_URL", "").strip()
            username = os.environ.get("GEOPULSE_USERNAME", "").strip()
            password = os.environ.get("GEOPULSE_PASSWORD", "").strip()
            if not url:
                raise RuntimeError("GEOPULSE_URL is required when GEOPULSE_ENABLED=true")
            if not username or not password:
                raise RuntimeError(
                    "GEOPULSE_USERNAME and GEOPULSE_PASSWORD are required when GEOPULSE_ENABLED=true"
                )
            auth = base64.b64encode(f"{username}:{password}".encode("utf-8")).decode(
                "ascii"
            )
            downstreams.append(
                Downstream(
                    name="geopulse",
                    url=url,
                    timeout=float(os.environ.get("GEOPULSE_TIMEOUT_SECONDS", "10")),
                    headers={
                        "Content-Type": "application/json",
                        "Authorization": f"Basic {auth}",
                        "User-Agent": "location-fanout/1.0",
                    },
                    retry_client_errors=parse_bool(
                        os.environ.get("GEOPULSE_RETRY_CLIENT_ERRORS"), default=False
                    ),
                )
            )

        return downstreams

    def connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.db_path, timeout=30)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA journal_mode=WAL")
        conn.execute("PRAGMA foreign_keys=ON")
        return conn

    def _init_db(self) -> None:
        with self.connect() as conn:
            conn.executescript(
                """
                CREATE TABLE IF NOT EXISTS inbound (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    received_at TEXT NOT NULL,
                    sha256 TEXT NOT NULL UNIQUE,
                    content_type TEXT NOT NULL,
                    body BLOB NOT NULL
                );

                CREATE TABLE IF NOT EXISTS deliveries (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    inbound_id INTEGER NOT NULL REFERENCES inbound(id) ON DELETE CASCADE,
                    downstream TEXT NOT NULL,
                    state TEXT NOT NULL,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    next_attempt_at REAL,
                    last_status INTEGER,
                    last_error TEXT,
                    delivered_at TEXT,
                    updated_at TEXT NOT NULL,
                    UNIQUE(inbound_id, downstream)
                );

                CREATE INDEX IF NOT EXISTS idx_deliveries_due
                    ON deliveries(state, next_attempt_at, id);
                """
            )
            conn.execute(
                """
                UPDATE deliveries
                SET state = 'retry',
                    next_attempt_at = NULL,
                    updated_at = ?
                WHERE state = 'processing'
                """,
                (utc_now(),),
            )

    def is_authorized(self, headers: object) -> bool:
        header_token = ""
        auth_header = ""
        if hasattr(headers, "get"):
            header_token = headers.get("X-Fanout-Token", "")  # type: ignore[attr-defined]
            auth_header = headers.get("Authorization", "")  # type: ignore[attr-defined]

        bearer_prefix = "Bearer "
        bearer_token = (
            auth_header[len(bearer_prefix) :]
            if auth_header.startswith(bearer_prefix)
            else ""
        )
        return header_token == self.token or bearer_token == self.token

    def enqueue(self, body: bytes, content_type: str) -> dict[str, object]:
        if len(body) > self.max_body_bytes:
            raise ValueError(f"payload is larger than {self.max_body_bytes} bytes")

        try:
            json.loads(body.decode("utf-8"))
        except (json.JSONDecodeError, UnicodeDecodeError) as exc:
            raise ValueError("payload must be valid JSON") from exc

        sha = hashlib.sha256(body).hexdigest()
        now = utc_now()
        downstream_names = [d.name for d in self.downstreams]

        with self.connect() as conn:
            existing = conn.execute(
                "SELECT id FROM inbound WHERE sha256 = ?", (sha,)
            ).fetchone()
            if existing:
                inbound_id = int(existing["id"])
                duplicate = True
            else:
                cursor = conn.execute(
                    """
                    INSERT INTO inbound (received_at, sha256, content_type, body)
                    VALUES (?, ?, ?, ?)
                    """,
                    (now, sha, content_type or "application/json", body),
                )
                inbound_id = int(cursor.lastrowid)
                duplicate = False

            for downstream_name in downstream_names:
                conn.execute(
                    """
                    INSERT OR IGNORE INTO deliveries (
                        inbound_id, downstream, state, attempts, updated_at
                    )
                    VALUES (?, ?, 'pending', 0, ?)
                    """,
                    (inbound_id, downstream_name, now),
                )

        self.wake_worker.set()
        return {
            "accepted": True,
            "duplicate": duplicate,
            "id": inbound_id,
            "sha256": sha,
            "downstreams": downstream_names,
        }

    def status(self) -> dict[str, object]:
        with self.connect() as conn:
            inbound_count = conn.execute("SELECT COUNT(*) AS c FROM inbound").fetchone()[
                "c"
            ]
            rows = conn.execute(
                "SELECT state, COUNT(*) AS c FROM deliveries GROUP BY state"
            ).fetchall()
            by_state = {row["state"]: row["c"] for row in rows}
            recent = conn.execute(
                """
                SELECT d.id, i.received_at, d.downstream, d.state, d.attempts,
                       d.last_status, d.last_error, d.delivered_at, d.updated_at
                FROM deliveries d
                JOIN inbound i ON i.id = d.inbound_id
                ORDER BY d.id DESC
                LIMIT 20
                """
            ).fetchall()

        return {
            "ok": True,
            "downstreams": [d.name for d in self.downstreams],
            "inbound": inbound_count,
            "deliveries": by_state,
            "recent": [dict(row) for row in recent],
        }

    def worker_loop(self) -> None:
        logging.info("worker started with downstreams: %s", [d.name for d in self.downstreams])
        while not self.stopping.is_set():
            delivered_any = False
            try:
                while self.deliver_next():
                    delivered_any = True
            except Exception:
                logging.exception("worker iteration failed")

            if delivered_any:
                continue

            self.wake_worker.wait(self.worker_interval)
            self.wake_worker.clear()

    def deliver_next(self) -> bool:
        now_ts = time.time()
        with self.connect() as conn:
            row = conn.execute(
                """
                SELECT d.id, d.downstream, d.attempts, i.body, i.content_type
                FROM deliveries d
                JOIN inbound i ON i.id = d.inbound_id
                WHERE d.state IN ('pending', 'retry')
                  AND (d.next_attempt_at IS NULL OR d.next_attempt_at <= ?)
                ORDER BY d.id
                LIMIT 1
                """,
                (now_ts,),
            ).fetchone()

        if not row:
            return False

        downstream = next((d for d in self.downstreams if d.name == row["downstream"]), None)
        if downstream is None:
            self._mark_delivery(
                int(row["id"]),
                "failed",
                int(row["attempts"]),
                None,
                None,
                "downstream is no longer configured",
            )
            return True

        delivery_id = int(row["id"])
        attempts = int(row["attempts"])
        body = bytes(row["body"])

        self._mark_processing(delivery_id)
        status_code, error = self._post_downstream(downstream, body)

        if status_code is not None and 200 <= status_code <= 299:
            self._mark_delivery(
                delivery_id,
                "delivered",
                attempts + 1,
                None,
                status_code,
                None,
                delivered=True,
            )
            logging.info("delivered id=%s downstream=%s status=%s", delivery_id, downstream.name, status_code)
            return True

        should_retry = True
        if status_code is not None and 400 <= status_code <= 499:
            should_retry = downstream.retry_client_errors or status_code in {408, 429}

        if self.max_attempts > 0 and attempts + 1 >= self.max_attempts:
            should_retry = False

        next_attempt = None
        next_state = "failed"
        if should_retry:
            delay = min(3600, 2 ** min(attempts, 11))
            next_attempt = time.time() + delay
            next_state = "retry"

        self._mark_delivery(
            delivery_id,
            next_state,
            attempts + 1,
            next_attempt,
            status_code,
            error or f"HTTP {status_code}",
        )
        logging.warning(
            "delivery id=%s downstream=%s state=%s status=%s error=%s",
            delivery_id,
            downstream.name,
            next_state,
            status_code,
            error,
        )
        return True

    def _post_downstream(self, downstream: Downstream, body: bytes) -> tuple[Optional[int], Optional[str]]:
        request = urllib.request.Request(
            downstream.url,
            data=body,
            method="POST",
            headers=downstream.headers,
        )
        try:
            with urllib.request.urlopen(request, timeout=downstream.timeout) as response:
                response.read()
                return int(response.status), None
        except urllib.error.HTTPError as exc:
            error_body = exc.read(500).decode("utf-8", errors="replace")
            return int(exc.code), error_body or str(exc)
        except Exception as exc:
            return None, str(exc)

    def _mark_processing(self, delivery_id: int) -> None:
        with self.connect() as conn:
            conn.execute(
                """
                UPDATE deliveries
                SET state = 'processing', updated_at = ?
                WHERE id = ?
                """,
                (utc_now(), delivery_id),
            )

    def _mark_delivery(
        self,
        delivery_id: int,
        state: str,
        attempts: int,
        next_attempt_at: Optional[float],
        last_status: Optional[int],
        last_error: Optional[str],
        delivered: bool = False,
    ) -> None:
        delivered_at = utc_now() if delivered else None
        with self.connect() as conn:
            conn.execute(
                """
                UPDATE deliveries
                SET state = ?,
                    attempts = ?,
                    next_attempt_at = ?,
                    last_status = ?,
                    last_error = ?,
                    delivered_at = COALESCE(?, delivered_at),
                    updated_at = ?
                WHERE id = ?
                """,
                (
                    state,
                    attempts,
                    next_attempt_at,
                    last_status,
                    last_error,
                    delivered_at,
                    utc_now(),
                    delivery_id,
                ),
            )


class Handler(BaseHTTPRequestHandler):
    app: FanoutApp

    def log_message(self, fmt: str, *args: object) -> None:
        logging.info("%s - %s", self.address_string(), fmt % args)

    def do_GET(self) -> None:
        parsed = urllib.parse.urlparse(self.path)
        if parsed.path == "/health":
            self.write_json({"ok": True})
            return

        if parsed.path == "/status":
            if not self.app.is_authorized(self.headers):
                self.write_json({"error": "unauthorized"}, HTTPStatus.UNAUTHORIZED)
                return
            self.write_json(self.app.status())
            return

        self.write_json({"error": "not found"}, HTTPStatus.NOT_FOUND)

    def do_POST(self) -> None:
        parsed = urllib.parse.urlparse(self.path)
        if parsed.path != "/ingest":
            self.write_json({"error": "not found"}, HTTPStatus.NOT_FOUND)
            return

        if not self.app.is_authorized(self.headers):
            self.write_json({"error": "unauthorized"}, HTTPStatus.UNAUTHORIZED)
            return

        length = int(self.headers.get("Content-Length", "0") or "0")
        if length <= 0:
            self.write_json({"error": "empty request body"}, HTTPStatus.BAD_REQUEST)
            return

        body = self.rfile.read(length)
        try:
            result = self.app.enqueue(
                body,
                self.headers.get("Content-Type", "application/json"),
            )
        except ValueError as exc:
            self.write_json({"error": str(exc)}, HTTPStatus.BAD_REQUEST)
            return

        self.write_json(result, HTTPStatus.ACCEPTED)

    def write_json(self, payload: dict[str, object], status: int = HTTPStatus.OK) -> None:
        data = json.dumps(payload, sort_keys=True).encode("utf-8")
        self.send_response(int(status))
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


def main() -> None:
    logging.basicConfig(
        level=os.environ.get("FANOUT_LOG_LEVEL", "INFO").upper(),
        format="%(asctime)s %(levelname)s %(message)s",
    )
    app = FanoutApp()
    Handler.app = app

    host = os.environ.get("FANOUT_LISTEN_HOST", "0.0.0.0")
    port = env_int("FANOUT_LISTEN_PORT", 8080)
    server = ThreadingHTTPServer((host, port), Handler)

    worker = threading.Thread(target=app.worker_loop, name="worker", daemon=True)
    worker.start()

    def stop(_signum: int, _frame: object) -> None:
        logging.info("shutting down")
        app.stopping.set()
        app.wake_worker.set()
        server.shutdown()

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)

    logging.info("listening on %s:%s", host, port)
    server.serve_forever()


if __name__ == "__main__":
    main()
