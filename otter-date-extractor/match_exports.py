#!/usr/bin/env python3
"""
Match Otter.ai exports with recording dates from scraped data.

This script:
1. Loads recording metadata from otter_recordings.json
2. Scans an exports folder for Otter export files
3. Matches exports to recordings by title
4. Generates a report showing matches and proposed date enrichments
5. Can optionally rename files to include recording dates
"""

import json
import re
import csv
from pathlib import Path
from datetime import datetime
from collections import defaultdict
from difflib import SequenceMatcher
import argparse

# File extensions exported by Otter
OTTER_EXTENSIONS = {'.mp3', '.txt', '.docx', '.pdf', '.srt'}


def normalize_title(title: str) -> str:
    """Normalize a title for comparison."""
    # Remove common date prefixes that might already be in filename
    # Pattern: M-D-YYYY or MM-DD-YYYY or similar
    title = re.sub(r'^\d{1,2}-\d{1,2}-\d{4}\s*', '', title)
    # Remove file extension if present
    for ext in OTTER_EXTENSIONS:
        if title.lower().endswith(ext):
            title = title[:-len(ext)]
    # Normalize whitespace and case
    title = ' '.join(title.lower().split())
    # Remove special characters for fuzzy matching
    title = re.sub(r'[^\w\s]', '', title)
    return title


def parse_otter_date(date_str: str, time_str: str) -> datetime:
    """Parse Otter date format like 'Thu, Nov 3, 2022' and '5:36 PM'."""
    if not date_str:
        return None
    try:
        # Remove day of week prefix
        date_str = re.sub(r'^(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s*', '', date_str)
        # Combine date and time
        full_str = f"{date_str} {time_str}" if time_str else date_str
        # Try parsing
        for fmt in ['%b %d, %Y %I:%M %p', '%b %d, %Y']:
            try:
                return datetime.strptime(full_str.strip(), fmt)
            except ValueError:
                continue
    except Exception:
        pass
    return None


def format_date_for_filename(dt: datetime) -> str:
    """Format datetime for filename prefix like '2022-11-03'."""
    return dt.strftime('%Y-%m-%d')


def similarity_score(s1: str, s2: str) -> float:
    """Calculate similarity ratio between two strings."""
    return SequenceMatcher(None, s1, s2).ratio()


def find_best_match(filename: str, recordings: list, threshold: float = 0.7) -> dict:
    """Find the best matching recording for a filename."""
    norm_filename = normalize_title(filename)

    best_match = None
    best_score = 0

    for rec in recordings:
        norm_title = normalize_title(rec['title'])

        # Exact match
        if norm_filename == norm_title:
            return {'recording': rec, 'score': 1.0, 'match_type': 'exact'}

        # Check if one contains the other
        if norm_filename in norm_title or norm_title in norm_filename:
            score = 0.95
            if score > best_score:
                best_score = score
                best_match = {'recording': rec, 'score': score, 'match_type': 'contains'}

        # Fuzzy match
        score = similarity_score(norm_filename, norm_title)
        if score > best_score:
            best_score = score
            best_match = {'recording': rec, 'score': score, 'match_type': 'fuzzy'}

    if best_match and best_score >= threshold:
        return best_match
    return None


def has_date_prefix(filename: str) -> bool:
    """Check if filename already has a date prefix."""
    # Patterns like: 2022-11-03, 11-3-2022, 2022_11_03, etc.
    patterns = [
        r'^\d{4}[-_]\d{1,2}[-_]\d{1,2}',  # YYYY-MM-DD
        r'^\d{1,2}[-_]\d{1,2}[-_]\d{4}',  # MM-DD-YYYY or M-D-YYYY
    ]
    for pattern in patterns:
        if re.match(pattern, filename):
            return True
    return False


def load_recordings(json_path: Path) -> list:
    """Load recordings from JSON file."""
    with open(json_path) as f:
        return json.load(f)


def scan_exports(exports_dir: Path) -> dict:
    """Scan exports directory and group files by base name."""
    files_by_base = defaultdict(list)

    for filepath in exports_dir.iterdir():
        if filepath.is_file() and filepath.suffix.lower() in OTTER_EXTENSIONS:
            base_name = filepath.stem
            files_by_base[base_name].append(filepath)

    return files_by_base


def main():
    parser = argparse.ArgumentParser(
        description='Match Otter exports with recording dates'
    )
    parser.add_argument(
        '--exports-dir',
        type=Path,
        default=Path('/Volumes/RAID Store/Documents/Audio Notes/Otter Exports'),
        help='Path to Otter exports directory'
    )
    parser.add_argument(
        '--recordings-json',
        type=Path,
        default=Path(__file__).parent / 'otter_recordings.json',
        help='Path to otter_recordings.json'
    )
    parser.add_argument(
        '--output',
        type=Path,
        default=Path(__file__).parent / 'match_report.csv',
        help='Output CSV report path'
    )
    parser.add_argument(
        '--threshold',
        type=float,
        default=0.7,
        help='Minimum similarity threshold for matching (0-1)'
    )
    parser.add_argument(
        '--rename',
        action='store_true',
        help='Actually rename files (default: dry run only)'
    )

    args = parser.parse_args()

    # Load data
    print(f"Loading recordings from {args.recordings_json}...")
    recordings = load_recordings(args.recordings_json)
    print(f"Loaded {len(recordings)} recordings")

    print(f"\nScanning exports in {args.exports_dir}...")
    files_by_base = scan_exports(args.exports_dir)
    print(f"Found {len(files_by_base)} unique export names")

    # Match and categorize
    results = {
        'already_dated': [],      # Files that already have date in name
        'matched': [],            # Successfully matched to a recording
        'no_match': [],           # Could not find matching recording
        'recording_no_date': [],  # Matched but recording has no date
    }

    for base_name, files in files_by_base.items():
        if has_date_prefix(base_name):
            results['already_dated'].append({
                'base_name': base_name,
                'files': files,
                'reason': 'Already has date prefix'
            })
            continue

        match = find_best_match(base_name, recordings, args.threshold)

        if not match:
            results['no_match'].append({
                'base_name': base_name,
                'files': files,
                'reason': 'No matching recording found'
            })
            continue

        rec = match['recording']
        dt = parse_otter_date(rec['date'], rec['time'])

        if not dt:
            results['recording_no_date'].append({
                'base_name': base_name,
                'files': files,
                'recording': rec,
                'match_score': match['score'],
                'reason': 'Recording has no parseable date'
            })
            continue

        date_prefix = format_date_for_filename(dt)
        new_base_name = f"{date_prefix} {base_name}"

        results['matched'].append({
            'base_name': base_name,
            'new_base_name': new_base_name,
            'files': files,
            'recording': rec,
            'parsed_date': dt,
            'match_score': match['score'],
            'match_type': match['match_type'],
        })

    # Print summary
    print("\n" + "="*60)
    print("SUMMARY")
    print("="*60)
    print(f"Already have date prefix: {len(results['already_dated'])}")
    print(f"Successfully matched:     {len(results['matched'])}")
    print(f"No matching recording:    {len(results['no_match'])}")
    print(f"Recording has no date:    {len(results['recording_no_date'])}")

    # Show some examples of each category
    print("\n" + "-"*60)
    print("SAMPLE MATCHES (first 10)")
    print("-"*60)
    for item in results['matched'][:10]:
        print(f"  {item['base_name']}")
        print(f"    -> {item['new_base_name']}")
        print(f"    (matched: {item['recording']['title']}, score: {item['match_score']:.2f})")

    if results['no_match']:
        print("\n" + "-"*60)
        print("SAMPLE NON-MATCHES (first 10)")
        print("-"*60)
        for item in results['no_match'][:10]:
            print(f"  {item['base_name']}")

    # Write full report
    print(f"\nWriting full report to {args.output}...")
    with open(args.output, 'w', newline='', encoding='utf-8') as f:
        writer = csv.writer(f)
        writer.writerow([
            'Status', 'Original Name', 'Proposed Name', 'Recording Date',
            'Recording Time', 'Match Score', 'Match Type', 'File Count'
        ])

        for item in results['already_dated']:
            writer.writerow([
                'already_dated', item['base_name'], '', '', '', '', '',
                len(item['files'])
            ])

        for item in results['matched']:
            rec = item['recording']
            writer.writerow([
                'matched', item['base_name'], item['new_base_name'],
                rec['date'], rec['time'], f"{item['match_score']:.2f}",
                item['match_type'], len(item['files'])
            ])

        for item in results['no_match']:
            writer.writerow([
                'no_match', item['base_name'], '', '', '', '', '',
                len(item['files'])
            ])

        for item in results['recording_no_date']:
            rec = item['recording']
            writer.writerow([
                'no_date', item['base_name'], '', rec['date'], rec['time'],
                f"{item['match_score']:.2f}", '', len(item['files'])
            ])

    print(f"Report saved to {args.output}")

    # Rename files if requested
    if args.rename and results['matched']:
        print("\n" + "="*60)
        print("RENAMING FILES")
        print("="*60)
        renamed_count = 0
        for item in results['matched']:
            for old_path in item['files']:
                new_name = f"{item['new_base_name']}{old_path.suffix}"
                new_path = old_path.parent / new_name
                print(f"  {old_path.name} -> {new_name}")
                old_path.rename(new_path)
                renamed_count += 1
        print(f"\nRenamed {renamed_count} files")
    elif results['matched'] and not args.rename:
        print("\n(Dry run - use --rename to actually rename files)")


if __name__ == "__main__":
    main()
