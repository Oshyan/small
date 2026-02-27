#!/usr/bin/env python3
"""Embed CSV data into the HTML file as a JS variable."""
# /// script
# requires-python = ">=3.12"
# ///

import json
from pathlib import Path

csv_path = Path("/Users/oshyan/Projects/Coding/small/video-maint/video_library.csv")
html_path = Path("/Users/oshyan/Projects/Coding/small/video-maint/video_library.html")

csv_text = csv_path.read_text()
html_text = html_path.read_text()

# Replace the fetch-based loadData with embedded data
old_load = """async function loadData() {
  const resp = await fetch("video_library.csv");
  const text = await resp.text();
  const lines = text.split("\\n");"""

new_load = f"""const CSV_RAW = {json.dumps(csv_text)};

async function loadData() {{
  const text = CSV_RAW;
  const lines = text.split("\\n");"""

html_text = html_text.replace(old_load, new_load)
html_path.write_text(html_text)
print(f"Done. HTML size: {len(html_text):,} bytes")
