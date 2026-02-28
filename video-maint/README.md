# video-maint

Tools for auditing and cleaning up a local video library on RAID storage. Scans all video files for resolution, outputs an interactive review interface, and handles bulk deletion of low-quality files.

**Repo:** https://github.com/Oshyan/small/tree/main/video-maint

## How it works

1. **Scan** — `scan_videos_remote.py` runs on Mac-Server via SSH, uses `ffprobe` to extract resolution (width x height) from every video file under `/Volumes/RAID Store/Downloads/`, outputs CSV
2. **Review** — `video_library.html` presents all 3,711 files with checkboxes, sortable columns, and filters. Files with width < 1920 are pre-checked for deletion
3. **Delete** — After review, save the updated CSV and use it to execute bulk deletion, producing a log of removed files

## Files

| File | Purpose |
|---|---|
| `scan_videos_remote.py` | Resolution scanner (runs on Mac-Server, uses `/tmp/ffprobe`) |
| `csv_to_xlsx.py` | Converts scan CSV to formatted Excel (optional) |
| `embed_csv.py` | Embeds CSV data into the HTML for offline use |
| `video_library.html` | Interactive review UI with checkboxes, sorting, filtering |
| `video_library.csv` | Raw scan results |
| `video_library.xlsx` | Excel version of scan results |
| `scan_videos.py` | Original local scan script (unused, superseded by remote version) |

## Setup

ffprobe must be available on Mac-Server at `/tmp/ffprobe`. To install:

```sh
ssh oshyan@Mac-Server.local
cd /tmp && curl -L -o ffprobe.zip "https://evermeet.cx/ffmpeg/getrelease/ffprobe/zip" && unzip -o ffprobe.zip && chmod +x ffprobe
```

## Usage

Run the scan remotely:

```sh
scp scan_videos_remote.py oshyan@Mac-Server.local:/tmp/scan_videos.py
ssh oshyan@Mac-Server.local "python3 /tmp/scan_videos.py"
scp oshyan@Mac-Server.local:/tmp/video_library.csv .
```

Re-embed CSV into HTML (if CSV changes):

```sh
uv run embed_csv.py
```

Open `video_library.html` in a browser to review. Click "Save delete list" when done.

## TODO

- [ ] Review all files in `video_library.html` and mark/unmark delete checkboxes
- [ ] Save reviewed CSV via the "Save delete list" button
- [ ] Execute deletion of marked files on Mac-Server
- [ ] Save a log/list of all deleted files (filename, resolution, path, size)
- [ ] Clean up: remove `/tmp/ffprobe` and `/tmp/scan_videos.py` from Mac-Server
- [ ] Clean up: remove `video_library.csv`, `.xlsx`, and `.html` once deletion is confirmed
