#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
REMOTE="${REMOTE:-oshyan@Mac-Server.local}"
REMOTE_SCRIPT="${REMOTE_SCRIPT:-/tmp/scan_videos.py}"
REMOTE_CSV="${REMOTE_CSV:-/tmp/video_library.csv}"
LOCAL_CSV="$BASE_DIR/video_library.csv"
BACKUP_DIR="$BASE_DIR/backups"

if [[ ! -f "$LOCAL_CSV" ]]; then
  echo "Missing local CSV: $LOCAL_CSV" >&2
  exit 1
fi

timestamp="$(date +%Y%m%dT%H%M%S%z)"
mkdir -p "$BACKUP_DIR"
backup_path="$BACKUP_DIR/video_library.csv.$timestamp.bak"
cp -p "$LOCAL_CSV" "$backup_path"
echo "Backed up local CSV to: $backup_path"

echo "Uploading scanner and current CSV to $REMOTE..."
scp "$BASE_DIR/scan_videos_remote.py" "$REMOTE:$REMOTE_SCRIPT"
scp "$LOCAL_CSV" "$REMOTE:$REMOTE_CSV"

echo "Running remote scan..."
ssh "$REMOTE" "python3 -u '$REMOTE_SCRIPT' --existing-csv '$REMOTE_CSV' --output '$REMOTE_CSV'"

echo "Downloading refreshed CSV..."
scp "$REMOTE:$REMOTE_CSV" "$LOCAL_CSV"

echo "Regenerating static HTML..."
if command -v uv >/dev/null 2>&1; then
  (cd "$BASE_DIR" && UV_CACHE_DIR="$BASE_DIR/.uv-cache" uv run embed_csv.py)
  (cd "$BASE_DIR" && UV_CACHE_DIR="$BASE_DIR/.uv-cache" uv run csv_to_xlsx.py)
else
  python3 "$BASE_DIR/embed_csv.py"
  python3 "$BASE_DIR/csv_to_xlsx.py"
fi

echo "Rescan complete."
