# gpsfanout

`gpsfanout` is a small durable fan-out helper for GPS webhook payloads.

It was built for a Colota -> Reitti + GeoPulse setup where the phone app can only post to one endpoint. `gpsfanout` accepts one JSON payload, stores it in a local SQLite queue, and forwards it to each enabled downstream independently. It returns success to the phone after the payload is queued, then retries downstream delivery until each enabled service accepts it.

## Why This Exists

Colota supports one HTTP endpoint at a time. Reitti and GeoPulse both need the same current location stream. A plain reverse proxy or Nginx mirror can duplicate requests, but it is awkward when each downstream needs different authentication, retry behavior, and observability.

`gpsfanout` keeps the mobile configuration simple:

```text
Colota -> gpsfanout -> Reitti
                    -> GeoPulse
```

## Features

- Single `/ingest` endpoint for JSON GPS payloads
- Token protection via `X-Fanout-Token` or `Authorization: Bearer ...`
- SQLite-backed durable queue
- Independent delivery state per downstream
- Retry with exponential backoff
- Duplicate request de-duplication by payload SHA-256
- Reitti downstream support via `X-API-TOKEN`
- GeoPulse downstream template via Basic Auth
- Health and authenticated status endpoints

## Endpoints

- `GET /health`: unauthenticated health check
- `GET /status`: authenticated queue and delivery status
- `POST /ingest`: authenticated JSON ingestion endpoint

Example:

```bash
curl -X POST http://127.0.0.1:8765/ingest \
  -H "Content-Type: application/json" \
  -H "X-Fanout-Token: $FANOUT_TOKEN" \
  -d '{"_type":"location","lat":37.7749,"lon":-122.4194,"acc":10,"tst":1777165200}'
```

## Configuration

Copy `.env.example` to `.env` and fill in secrets.

```bash
cp .env.example .env
```

Required for the helper:

```env
FANOUT_TOKEN=change-this-fanout-token
FANOUT_HOST_BIND=127.0.0.1
FANOUT_HOST_PORT=8765
FANOUT_DB_PATH=/data/fanout.sqlite3
```

Reitti downstream:

```env
REITTI_ENABLED=true
REITTI_URL=http://host.docker.internal:8080/reitti/api/v1/ingest/owntracks
REITTI_API_TOKEN=change-this-reitti-token
```

GeoPulse downstream, disabled until configured:

```env
GEOPULSE_ENABLED=false
GEOPULSE_URL=http://host.docker.internal:5555/api/colota
GEOPULSE_USERNAME=
GEOPULSE_PASSWORD=
```

## Docker Compose

```bash
docker compose up -d --build
docker compose ps
docker compose logs -f
```

The compose file binds the service to `127.0.0.1` by default:

```text
127.0.0.1:8765 -> container:8080
```

This keeps the helper private to the host unless something like Tailscale Serve exposes it.

## Colota Setup

Use the HTTPS URL exposed by your reverse proxy/Tailscale Serve, for example:

```text
https://mac-server.tail927595.ts.net:8765/ingest
```

In Colota:

- Authentication type: `None`
- Custom header name: `X-Fanout-Token`
- Custom header value: the `FANOUT_TOKEN` from `.env`

Do not put the Reitti or GeoPulse downstream credentials into Colota. `gpsfanout` injects those credentials when forwarding.

## Tailscale Serve Example

Colota requires HTTPS for non-local endpoints. Tailscale Serve can terminate HTTPS and proxy to the local helper:

```bash
tailscale serve --bg --yes --https=8765 http://127.0.0.1:8765
```

That exposes:

```text
https://<machine>.<tailnet>.ts.net:8765/ingest
```

## Operations

Health:

```bash
curl http://127.0.0.1:8765/health
```

Status:

```bash
curl -H "X-Fanout-Token: $FANOUT_TOKEN" http://127.0.0.1:8765/status
```

Inspect the SQLite queue:

```bash
sqlite3 data/fanout.sqlite3 '.tables'
sqlite3 data/fanout.sqlite3 'select state, count(*) from deliveries group by state;'
```

## Current Mac Server Deployment

The working deployment on `Mac-Server.local` currently lives outside this repo at:

```text
/Users/oshyan/Services/location-fanout
```

It is exposed to the tailnet as:

```text
https://mac-server.tail927595.ts.net:8765/ingest
```

The deployed helper currently forwards to Reitti only. GeoPulse can be enabled later by setting `GEOPULSE_ENABLED=true` and filling in the GeoPulse source credentials.
