#!/usr/bin/env python3
"""Embed CSV data into the static HTML review UI."""
# /// script
# requires-python = ">=3.12"
# ///

import json
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
CSV_PATH = BASE_DIR / "video_library.csv"
TEMPLATE_PATH = BASE_DIR / "video_library.template.html"
HTML_PATH = BASE_DIR / "video_library.html"
PLACEHOLDER = "const CSV_RAW = __CSV_RAW__;"


def main():
    csv_text = CSV_PATH.read_text(encoding="utf-8")
    template = TEMPLATE_PATH.read_text(encoding="utf-8")

    if PLACEHOLDER not in template:
        raise SystemExit("Template is missing CSV placeholder: {}".format(PLACEHOLDER))

    html_text = template.replace(
        PLACEHOLDER,
        "const CSV_RAW = {};".format(json.dumps(csv_text)),
    )
    HTML_PATH.write_text(html_text, encoding="utf-8")
    print("Embedded {} into {}".format(CSV_PATH.name, HTML_PATH.name))
    print("HTML size: {:,} bytes".format(len(html_text)))


if __name__ == "__main__":
    main()
