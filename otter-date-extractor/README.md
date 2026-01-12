# Otter.ai Recording Date Extractor

**Project Date:** January 9, 2026

## Problem

Otter.ai exports (mp3, txt, docx, pdf, srt) do not include the original recording date in any metadata or file content. The only place this information exists is in the Otter.ai web interface. With hundreds of recordings, many without dates in the filename, there was no easy way to know when recordings were originally made.

## Solution

Used browser automation (Playwright) to scrape recording metadata directly from the Otter.ai web interface, then created a matching script to enrich exported files with date prefixes.

## Process

1. **Analyzed export formats** - Confirmed that none of the 5 export formats (mp3, txt, docx, pdf, srt) contain original recording dates:
   - TXT: Just transcript text with timestamps
   - SRT: Subtitle format, no date
   - DOCX: Empty metadata
   - PDF: Only contains export date, not recording date
   - MP3: Only encoder tag, no date

2. **Researched programmatic options** - Found that Otter.ai's API is Enterprise-only and Zapier integration only works for new recordings going forward.

3. **Browser automation approach** - Used Playwright to:
   - Navigate to Otter.ai and log in
   - Scroll through the "All Recordings" list to load all content
   - Extract recording titles, dates, times, and durations via DOM parsing

4. **Created matching script** - Python script that:
   - Matches exported files to scraped recording data
   - Generates ISO date prefixes (YYYY-MM-DD)
   - Handles fuzzy matching for slight title variations
   - Produces a detailed CSV report

## Results

| Metric | Value |
|--------|-------|
| Recordings scraped | 569 |
| Date range | Jan 2019 - Nov 2022 |
| Export files matched | 457 (96%) |
| Already had dates | 17 |
| No match found | 1 |
| Archive files processed | 81 |

## Files Created

| File | Description |
|------|-------------|
| `otter_recordings.json` | All scraped recording metadata |
| `otter_recordings.csv` | Same data in CSV format |
| `match_report.csv` | Full matching report with proposed renames |
| `match_exports.py` | Main script to match and rename exports |
| `create_csv.py` | Helper to regenerate CSV from JSON |

## Usage

```bash
# Dry run (preview changes):
python3 match_exports.py --exports-dir "/path/to/exports"

# Actually rename files:
python3 match_exports.py --exports-dir "/path/to/exports" --rename

# Adjust match threshold (default 0.7):
python3 match_exports.py --threshold 0.8
```

## Notes

- The "All Recordings" view in Otter.ai includes archived recordings
- Files are renamed with ISO date prefix: `YYYY-MM-DD original name.ext`
- All file types sharing a base name are renamed together
- The one unmatched file was "My 1st Voiceprint" (a default Otter recording)
