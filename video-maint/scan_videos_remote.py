#!/usr/bin/env python3
"""Scan all files, probe videos, and output a merged cleanup CSV.

Runs on Mac-Server. If an existing CSV is present, delete decisions are
preserved by matching the Full Path column. Files no longer present on disk are
removed from the new CSV, and new files get default delete decisions.
"""

import argparse
import csv
import json
import os
import shutil
import subprocess
import sys
import time
from datetime import datetime

VIDEO_EXTENSIONS = {
    ".mp4", ".mkv", ".avi", ".mov", ".wmv", ".flv",
    ".webm", ".m4v", ".ts", ".mpg", ".mpeg",
}
SCAN_DIR = "/Volumes/RAID Store/Downloads"
FFPROBE = "/tmp/ffprobe"
OUTPUT_CSV = "/tmp/video_library.csv"
WIDTH_THRESHOLD = 1920

CSV_HEADERS = [
    "Filename",
    "Category",
    "Extension",
    "Size Bytes",
    "Size",
    "Width",
    "Height",
    "Resolution",
    "Delete",
    "Full Path",
]

YES_VALUES = {"1", "TRUE", "YES", "Y", "DELETE"}


def parse_args():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--scan-dir", default=SCAN_DIR, help="Root folder to scan")
    parser.add_argument("--ffprobe", default=FFPROBE, help="Path to ffprobe")
    parser.add_argument("--output", default=OUTPUT_CSV, help="CSV output path")
    parser.add_argument(
        "--existing-csv",
        default=None,
        help="CSV to preserve delete decisions from; defaults to --output if it exists",
    )
    parser.add_argument(
        "--no-probe",
        action="store_true",
        help="Skip ffprobe and keep video resolution blank",
    )
    return parser.parse_args()


def is_video(path):
    return os.path.splitext(path)[1].lower() in VIDEO_EXTENSIONS


def get_extension(path):
    return os.path.splitext(path)[1].lower()


def human_size(size_bytes):
    if size_bytes is None:
        return ""
    size = float(size_bytes)
    units = ["B", "KB", "MB", "GB", "TB", "PB"]
    unit_index = 0
    while size >= 1024 and unit_index < len(units) - 1:
        size /= 1024
        unit_index += 1
    if unit_index == 0:
        return "{} B".format(int(size))
    if size < 10:
        return "{:.1f} {}".format(size, units[unit_index])
    return "{:.0f} {}".format(size, units[unit_index])


def is_yes(value):
    return str(value or "").strip().upper() in YES_VALUES


def load_prior_records(csv_path):
    records = {}
    paths_seen = set()
    if not csv_path or not os.path.exists(csv_path):
        return records, paths_seen

    with open(csv_path, newline="", encoding="utf-8-sig") as f:
        reader = csv.DictReader(f)
        for row in reader:
            full_path = row.get("Full Path") or row.get("Path")
            if not full_path:
                continue
            paths_seen.add(full_path)
            records[full_path] = {
                "delete": is_yes(row.get("Delete")),
                "width": row.get("Width", ""),
                "height": row.get("Height", ""),
                "resolution": row.get("Resolution", ""),
            }
    return records, paths_seen


def backup_existing_csv(csv_path):
    if not csv_path or not os.path.exists(csv_path):
        return None
    timestamp = datetime.now().astimezone().strftime("%Y%m%dT%H%M%S%z")
    backup_path = "{}.backup-{}".format(csv_path, timestamp)
    shutil.copy2(csv_path, backup_path)
    return backup_path


def find_files(root):
    files = []
    for dirpath, _, filenames in os.walk(root):
        for filename in filenames:
            files.append(os.path.join(dirpath, filename))
    files.sort(key=lambda p: (os.path.basename(p).lower(), p.lower()))
    return files


def get_resolution(filepath, ffprobe):
    """Use ffprobe to get width and height of the first video stream."""
    try:
        result = subprocess.run(
            [
                ffprobe, "-v", "error",
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
            width = streams[0].get("width")
            height = streams[0].get("height")
            return (width, height)
    except Exception as exc:
        print("  Error probing {}: {}".format(os.path.basename(filepath), exc), file=sys.stderr)
    return (None, None)


def resolve_ffprobe(configured_path):
    if configured_path and os.path.exists(configured_path):
        return configured_path
    return shutil.which("ffprobe")


def has_reusable_resolution(prior_record):
    if not prior_record:
        return False
    resolution = prior_record.get("resolution", "")
    if not resolution or resolution == "ERROR":
        return False
    return bool(prior_record.get("width") or prior_record.get("height"))


def build_record(filepath, prior_records, ffprobe, probe_videos):
    filename = os.path.basename(filepath)
    extension = get_extension(filepath)
    category = "Video" if is_video(filepath) else "Other"
    prior_record = prior_records.get(filepath)

    try:
        size_bytes = os.path.getsize(filepath)
    except OSError:
        size_bytes = None

    width = ""
    height = ""
    resolution = ""
    probe_error = False
    reused_resolution = False
    probed_video = False

    if category == "Video":
        if has_reusable_resolution(prior_record):
            width = prior_record["width"]
            height = prior_record["height"]
            resolution = prior_record["resolution"]
            reused_resolution = True
        elif probe_videos:
            probed_video = True
            width_value, height_value = get_resolution(filepath, ffprobe)
            if width_value is not None and height_value is not None:
                width = width_value
                height = height_value
                resolution = "{}x{}".format(width_value, height_value)
            else:
                resolution = "ERROR"
                probe_error = True
        else:
            resolution = ""

    if prior_record is not None:
        should_delete = prior_record["delete"]
    elif category == "Video" and width != "" and not probe_error:
        should_delete = int(width) < WIDTH_THRESHOLD
    else:
        should_delete = False

    return {
        "Filename": filename,
        "Category": category,
        "Extension": extension,
        "Size Bytes": size_bytes if size_bytes is not None else "",
        "Size": human_size(size_bytes),
        "Width": width,
        "Height": height,
        "Resolution": resolution,
        "Delete": "YES" if should_delete else "",
        "Full Path": filepath,
    }, reused_resolution, probed_video


def main():
    args = parse_args()
    existing_csv = args.existing_csv or (args.output if os.path.exists(args.output) else None)

    print("Scanning: {}".format(args.scan_dir))
    print("Output CSV: {}".format(args.output))
    print("Threshold: video width < {}px -> pre-checked for deletion".format(WIDTH_THRESHOLD))
    print()

    prior_records, previous_paths = load_prior_records(existing_csv)
    if previous_paths:
        print("Loaded {} prior delete decisions from {}".format(len(previous_paths), existing_csv))

    files = find_files(args.scan_dir)
    current_paths = set(files)
    removed_count = len(previous_paths - current_paths)
    videos_needing_probe = sum(
        1 for filepath in files
        if is_video(filepath) and not has_reusable_resolution(prior_records.get(filepath))
    )

    probe_videos = not args.no_probe
    if probe_videos and videos_needing_probe:
        resolved_ffprobe = resolve_ffprobe(args.ffprobe)
        if not resolved_ffprobe:
            raise SystemExit(
                "Error: ffprobe not found at {} or on PATH; refusing to probe {} videos".format(
                    args.ffprobe,
                    videos_needing_probe,
                )
            )
        args.ffprobe = resolved_ffprobe
        print("Using ffprobe: {}".format(args.ffprobe))

    backup_path = backup_existing_csv(args.output)
    if backup_path:
        print("Backed up existing CSV to: {}".format(backup_path))

    print("Found {} files. Starting scan...".format(len(files)))
    print("Reusing existing video dimensions for {} videos".format(
        sum(1 for filepath in files if is_video(filepath) and has_reusable_resolution(prior_records.get(filepath)))
    ))
    print("Videos needing ffprobe: {}".format(videos_needing_probe if probe_videos else 0))
    if removed_count:
        print("Pruning {} rows that no longer exist on disk".format(removed_count))
    print()

    start = time.time()
    video_count = 0
    other_count = 0
    errors = 0
    delete_count = 0
    reused_resolution_count = 0
    probed_video_count = 0

    with open(args.output, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=CSV_HEADERS, lineterminator="\n")
        writer.writeheader()

        for i, filepath in enumerate(files, 1):
            record, reused_resolution, probed_video = build_record(filepath, prior_records, args.ffprobe, probe_videos)
            writer.writerow(record)

            if reused_resolution:
                reused_resolution_count += 1
            if probed_video:
                probed_video_count += 1
            if record["Category"] == "Video":
                video_count += 1
            else:
                other_count += 1
            if record["Resolution"] == "ERROR":
                errors += 1
            if record["Delete"] == "YES":
                delete_count += 1

            if i % 100 == 0 or i == len(files):
                elapsed = time.time() - start
                rate = i / elapsed if elapsed > 0 else 0
                eta = (len(files) - i) / rate if rate > 0 else 0
                mins = int(eta) // 60
                secs = int(eta) % 60
                print("  [{}/{}] {:.1f} files/sec, ETA: {}m{:02d}s".format(
                    i, len(files), rate, mins, secs))

    elapsed = time.time() - start
    mins = int(elapsed) // 60
    secs = int(elapsed) % 60
    keep_count = len(files) - delete_count

    print()
    print("Scan complete in {}m{:02d}s".format(mins, secs))
    print("  Total files: {}".format(len(files)))
    print("  Videos:      {}".format(video_count))
    print("  Other files: {}".format(other_count))
    print("  Delete:      {}".format(delete_count))
    print("  Keep:        {}".format(keep_count))
    print("  Errors:      {}".format(errors))
    print("  Pruned:      {}".format(removed_count))
    print("  Reused dims: {}".format(reused_resolution_count))
    print("  Probed vids: {}".format(probed_video_count))
    print()
    print("CSV written to: {}".format(args.output))


if __name__ == "__main__":
    main()
