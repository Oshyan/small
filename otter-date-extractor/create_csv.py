#!/usr/bin/env python3
"""Convert otter_recordings.json to CSV format."""

import json
import csv
from pathlib import Path

def main():
    script_dir = Path(__file__).parent
    json_path = script_dir / "otter_recordings.json"
    csv_path = script_dir / "otter_recordings.csv"

    with open(json_path) as f:
        recordings = json.load(f)

    with open(csv_path, 'w', newline='', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=['id', 'title', 'date', 'time', 'duration'])
        writer.writeheader()
        writer.writerows(recordings)

    print(f"Created {csv_path} with {len(recordings)} recordings")

if __name__ == "__main__":
    main()
