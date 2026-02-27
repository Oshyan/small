#!/usr/bin/env python3
"""Scan video files for resolution and output to CSV. Runs on remote Mac-Server."""

import csv
import json
import os
import subprocess
import sys
import time
from pathlib import Path

VIDEO_EXTENSIONS = {
    ".mp4", ".mkv", ".avi", ".mov", ".wmv", ".flv",
    ".webm", ".m4v", ".ts", ".mpg", ".mpeg",
}
SCAN_DIR = "/Volumes/RAID Store/Downloads"
FFPROBE = "/tmp/ffprobe"
OUTPUT_CSV = "/tmp/video_library.csv"
WIDTH_THRESHOLD = 1920


def find_video_files(root):
    videos = []
    for dirpath, _, filenames in os.walk(root):
        for f in filenames:
            ext = os.path.splitext(f)[1].lower()
            if ext in VIDEO_EXTENSIONS:
                videos.append(os.path.join(dirpath, f))
    videos.sort(key=lambda p: os.path.basename(p).lower())
    return videos


def get_resolution(filepath):
    """Use ffprobe to get width and height of the first video stream."""
    try:
        result = subprocess.run(
            [
                FFPROBE, "-v", "error",
                "-select_streams", "v:0",
                "-show_entries", "stream=width,height",
                "-of", "json",
                filepath,
            ],
            capture_output=True,
            text=True,
            timeout=30,
        )
        data = json.loads(result.stdout)
        streams = data.get("streams", [])
        if streams:
            w = streams[0].get("width")
            h = streams[0].get("height")
            return (w, h)
    except Exception as e:
        print("  Error probing {}: {}".format(os.path.basename(filepath), e), file=sys.stderr)
    return (None, None)


def main():
    print("Scanning: {}".format(SCAN_DIR))
    print("Threshold: width < {}px -> pre-checked for deletion".format(WIDTH_THRESHOLD))
    print()

    videos = find_video_files(SCAN_DIR)
    total = len(videos)
    print("Found {} video files. Starting resolution scan...".format(total))
    print()

    start = time.time()
    errors = 0
    delete_count = 0

    with open(OUTPUT_CSV, "w", newline="") as f:
        writer = csv.writer(f)
        writer.writerow(["Filename", "Width", "Height", "Resolution", "Delete", "Full Path"])

        for i, vpath in enumerate(videos, 1):
            w, h = get_resolution(vpath)

            if w is not None and h is not None:
                resolution = "{}x{}".format(w, h)
                should_delete = w < WIDTH_THRESHOLD
            else:
                resolution = "ERROR"
                should_delete = False
                errors += 1

            if should_delete:
                delete_count += 1

            writer.writerow([
                os.path.basename(vpath),
                w if w is not None else "",
                h if h is not None else "",
                resolution,
                "YES" if should_delete else "",
                vpath,
            ])

            # Progress every 50 files
            if i % 50 == 0 or i == total:
                elapsed = time.time() - start
                rate = i / elapsed if elapsed > 0 else 0
                eta = (total - i) / rate if rate > 0 else 0
                mins = int(eta) // 60
                secs = int(eta) % 60
                print("  [{}/{}] {:.1f} files/sec, ETA: {}m{:02d}s".format(
                    i, total, rate, mins, secs))

    elapsed = time.time() - start
    mins = int(elapsed) // 60
    secs = int(elapsed) % 60
    print()
    print("Scan complete in {}m{:02d}s".format(mins, secs))
    keep_count = total - delete_count - errors
    print("  Total files:     {}".format(total))
    print("  Pre-checked DEL: {} (width < {})".format(delete_count, WIDTH_THRESHOLD))
    print("  Keep:            {}".format(keep_count))
    print("  Errors:          {}".format(errors))
    print()
    print("CSV written to: {}".format(OUTPUT_CSV))


if __name__ == "__main__":
    main()
