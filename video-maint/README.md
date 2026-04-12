# video-maint

Tools for auditing and cleaning up a local media library on RAID storage. The scanner records video files and non-video files in one CSV, and the static HTML UI splits them into separate review tabs.

**Repo:** https://github.com/Oshyan/small/tree/main/video-maint

## How it works

1. **Scan** - `scan_videos_remote.py` runs on Mac-Server via SSH, walks every file under `/Volumes/RAID Store/Downloads/`, records non-video files by extension, and outputs CSV. Existing video dimensions are reused by `Full Path`; only new videos or old `ERROR` rows are probed with `ffprobe`.
2. **Review** - `video_library.html` presents separate tabs for videos and other files. The tabs are mutually exclusive based on the `Category` column.
3. **Delete decisions** - both tabs use the same `Delete` column. Existing decisions are preserved by `Full Path` during rescans.
4. **Refresh** - `rescan_remote.sh` backs up the local CSV, uploads it to Mac-Server, rescans the remote tree, downloads the refreshed CSV, and regenerates HTML/XLSX.

## Files

| File | Purpose |
|---|---|
| `scan_videos_remote.py` | Remote scanner for all files; video resolution plus non-video extension/size |
| `rescan_remote.sh` | Local helper that backs up, rescans, downloads, and rebuilds outputs |
| `csv_to_xlsx.py` | Converts scan CSV to formatted Excel sheets for videos and other files |
| `embed_csv.py` | Embeds CSV data into the HTML template for offline use |
| `video_library.template.html` | Source template for the review UI |
| `video_library.html` | Generated interactive review UI with embedded CSV |
| `video_library.csv` | Raw scan results and delete decisions |
| `video_library.xlsx` | Excel version of scan results |
| `scan_videos.py` | Original local scan script, retained for reference |

## CSV Schema

The combined CSV uses these columns:

```text
Filename,Category,Extension,Size Bytes,Size,Width,Height,Resolution,Delete,Full Path
```

`Category` is `Video` or `Other`. `Width`, `Height`, and `Resolution` are populated for videos. `Extension`, `Size Bytes`, and `Size` are populated for all files where the filesystem exposes size.

## Setup

ffprobe must be available on Mac-Server at `/tmp/ffprobe`. To install:

```sh
ssh oshyan@Mac-Server.local
cd /tmp && curl -L -o ffprobe.zip "https://evermeet.cx/ffmpeg/getrelease/ffprobe/zip" && unzip -o ffprobe.zip && chmod +x ffprobe
```

## Usage

Run a full remote rescan and preserve existing delete decisions:

```sh
./rescan_remote.sh
```

The helper writes a local backup before modifying `video_library.csv`:

```text
backups/video_library.csv.YYYYMMDDTHHMMSS-0700.bak
```

You can override the remote host:

```sh
REMOTE=oshyan@Mac-Server.local ./rescan_remote.sh
```

Open `video_library.html` in a browser to review. Click "Save delete list" when done. Shift-click checkboxes to mark or unmark a visible range.

Re-embed CSV into HTML after manual CSV edits:

```sh
uv run embed_csv.py
```

Regenerate the Excel workbook:

```sh
uv run csv_to_xlsx.py
```

## TODO

- [ ] Review all files in `video_library.html` and mark/unmark delete checkboxes
- [ ] Save reviewed CSV via the "Save delete list" button
- [ ] Execute deletion of marked files on Mac-Server
- [ ] Save a log/list of all deleted files (filename, resolution, path, size)
- [ ] Clean up: remove `/tmp/ffprobe` and `/tmp/scan_videos.py` from Mac-Server
- [ ] Clean up: remove `video_library.csv`, `.xlsx`, and `.html` once deletion is confirmed
