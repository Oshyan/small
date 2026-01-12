#!/usr/bin/env python3
"""
Process newly exported Otter files in the New folder.
- Strips existing M-D-YYYY date prefixes
- Adds correct YYYY-MM-DD prefix from scraped data
- Reports files that couldn't be matched
"""

import json
import re
from pathlib import Path
from datetime import datetime
import argparse


def parse_otter_date(date_str: str, time_str: str) -> datetime:
    """Parse Otter date format like 'Thu, Nov 3, 2022' and '5:36 PM'."""
    if not date_str:
        return None
    try:
        date_str = re.sub(r'^(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s*', '', date_str)
        full_str = f"{date_str} {time_str}" if time_str else date_str
        for fmt in ['%b %d, %Y %I:%M %p', '%b %d, %Y']:
            try:
                return datetime.strptime(full_str.strip(), fmt)
            except ValueError:
                continue
    except Exception:
        pass
    return None


def strip_date_prefix(name: str) -> str:
    """Remove common date prefix patterns from a filename."""
    # Pattern: M-D-YYYY or MM-DD-YYYY at start
    name = re.sub(r'^\d{1,2}-\d{1,2}-\d{4}\s+', '', name)
    # Pattern: YYYY-MM-DD at start
    name = re.sub(r'^\d{4}-\d{1,2}-\d{1,2}\s+', '', name)
    return name


def normalize_name(name: str) -> str:
    """Normalize a name for comparison."""
    # Replace special chars that Otter converts
    name = name.replace('/', '_').replace(':', '_').replace('?', '_')
    # Lowercase
    name = name.lower().strip()
    # Remove ellipsis variations (macOS truncation)
    name = name.replace('...', '')
    name = name.replace('…', '')
    return name


def find_matching_recording(filename: str, recordings: list) -> dict:
    """Find the matching recording for a filename."""
    norm_file = normalize_name(filename)
    norm_file_no_date = normalize_name(strip_date_prefix(filename))

    best_match = None
    best_score = 0

    for rec in recordings:
        title = rec['title']
        norm_title = normalize_name(title)
        norm_title_no_date = normalize_name(strip_date_prefix(title))

        # Exact match (with normalization)
        if norm_file == norm_title:
            return rec

        # Exact match after stripping dates from both
        if norm_file_no_date == norm_title_no_date and norm_file_no_date:
            # But only if they're reasonably specific (not just "chantal therapy session")
            if len(norm_file_no_date) > 25 or 'chantal' not in norm_file_no_date.lower():
                return rec

        # For files with dates, the date in the filename should match the recording date
        # Extract date from filename if present
        file_date_match = re.match(r'^(\d{1,2})-(\d{1,2})-(\d{4})', filename)
        if file_date_match:
            file_month = int(file_date_match.group(1))
            file_day = int(file_date_match.group(2))
            file_year = int(file_date_match.group(3))

            # Parse recording date
            rec_dt = parse_otter_date(rec['date'], rec['time'])
            if rec_dt:
                # Check if dates match AND the non-date part matches
                if (rec_dt.month == file_month and rec_dt.day == file_day and rec_dt.year == file_year):
                    # Also check that the rest of the name matches
                    if norm_file_no_date == norm_title_no_date:
                        return rec

        # Handle truncated filenames (with ... in them)
        # If the file has truncation, check if one is a prefix of the other
        if '...' in filename or '…' in filename:
            # Get the parts before and after the ellipsis
            parts = re.split(r'\.\.\.+|…', filename)
            if len(parts) >= 2:
                prefix = normalize_name(parts[0].strip())
                suffix = normalize_name(parts[-1].strip())
                # Also strip date from prefix for matching
                prefix_no_date = normalize_name(strip_date_prefix(parts[0].strip()))

                # Check if prefix and suffix match the title
                if prefix and suffix:
                    if norm_title.startswith(prefix) and norm_title.endswith(suffix):
                        return rec
                    if norm_title_no_date.startswith(prefix_no_date) and norm_title_no_date.endswith(suffix):
                        return rec
                    # Also check if title starts with prefix_no_date when it has a date
                    if prefix_no_date and norm_title_no_date.startswith(prefix_no_date):
                        # Verify suffix matches too
                        if suffix in norm_title_no_date:
                            return rec

    return None


def main():
    parser = argparse.ArgumentParser(description='Process new Otter exports')
    parser.add_argument(
        '--new-dir',
        type=Path,
        default=Path('/Volumes/RAID Store/Documents/Audio Notes/Otter Exports/New'),
        help='Path to New exports folder'
    )
    parser.add_argument(
        '--recordings-json',
        type=Path,
        default=Path(__file__).parent / 'otter_recordings.json',
        help='Path to otter_recordings.json'
    )
    parser.add_argument(
        '--rename',
        action='store_true',
        help='Actually rename files (default: dry run)'
    )

    args = parser.parse_args()

    # Load recordings
    print(f"Loading recordings from {args.recordings_json}...")
    with open(args.recordings_json) as f:
        recordings = json.load(f)
    print(f"Loaded {len(recordings)} recordings")

    # Get unique base names from New folder
    print(f"\nScanning {args.new_dir}...")
    files_by_base = {}
    for f in args.new_dir.iterdir():
        if f.is_file() and f.suffix.lower() in {'.mp3', '.txt', '.docx', '.pdf', '.srt'}:
            if f.stem not in files_by_base:
                files_by_base[f.stem] = []
            files_by_base[f.stem].append(f)

    print(f"Found {len(files_by_base)} unique file names")

    # Process each file
    matched = []
    unmatched = []

    for base_name, files in files_by_base.items():
        rec = find_matching_recording(base_name, recordings)

        if rec:
            dt = parse_otter_date(rec['date'], rec['time'])
            if dt:
                date_prefix = dt.strftime('%Y-%m-%d')
                # Strip any existing date from the base name
                clean_name = strip_date_prefix(base_name)
                new_base = f"{date_prefix} {clean_name}"
                matched.append({
                    'old_base': base_name,
                    'new_base': new_base,
                    'files': files,
                    'recording': rec,
                    'date': dt
                })
            else:
                unmatched.append({
                    'base_name': base_name,
                    'files': files,
                    'reason': f"Recording found but no parseable date: {rec['date']}"
                })
        else:
            unmatched.append({
                'base_name': base_name,
                'files': files,
                'reason': 'No matching recording found'
            })

    # Report
    print("\n" + "="*60)
    print("RESULTS")
    print("="*60)
    print(f"Matched: {len(matched)}")
    print(f"Unmatched: {len(unmatched)}")

    print("\n" + "-"*60)
    print("FILES TO RENAME:")
    print("-"*60)
    for item in sorted(matched, key=lambda x: x['date']):
        print(f"\n  {item['old_base']}")
        print(f"  -> {item['new_base']}")
        print(f"     (from: {item['recording']['title']}, {item['recording']['date']})")

    if unmatched:
        print("\n" + "-"*60)
        print("UNMATCHED FILES (will not be renamed):")
        print("-"*60)
        for item in unmatched:
            print(f"  {item['base_name']}: {item['reason']}")

    # Rename if requested
    if args.rename and matched:
        print("\n" + "="*60)
        print("RENAMING FILES")
        print("="*60)
        renamed = 0
        for item in matched:
            for old_path in item['files']:
                new_name = f"{item['new_base']}{old_path.suffix}"
                new_path = old_path.parent / new_name
                print(f"  {old_path.name}")
                print(f"  -> {new_name}")
                old_path.rename(new_path)
                renamed += 1
        print(f"\nRenamed {renamed} files")
    elif matched and not args.rename:
        print("\n(Dry run - use --rename to actually rename files)")


if __name__ == "__main__":
    main()
