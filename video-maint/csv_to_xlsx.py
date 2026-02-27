#!/usr/bin/env python3
"""Convert video scan CSV to formatted Excel spreadsheet."""
# /// script
# requires-python = ">=3.12"
# dependencies = ["openpyxl"]
# ///

import csv
from pathlib import Path

from openpyxl import Workbook
from openpyxl.styles import Alignment, Font, PatternFill

INPUT_CSV = Path("/Users/oshyan/Projects/Coding/small/video-maint/video_library.csv")
OUTPUT_XLSX = Path("/Users/oshyan/Projects/Coding/small/video-maint/video_library.xlsx")

wb = Workbook()
ws = wb.active
ws.title = "Video Library"

header_fill = PatternFill(start_color="4472C4", end_color="4472C4", fill_type="solid")
header_font = Font(color="FFFFFF", bold=True, size=11)
delete_fill = PatternFill(start_color="FFE0E0", end_color="FFE0E0", fill_type="solid")
error_fill = PatternFill(start_color="FFF3CD", end_color="FFF3CD", fill_type="solid")

with open(INPUT_CSV) as f:
    reader = csv.reader(f)
    headers = next(reader)

    for col_idx, header in enumerate(headers, 1):
        cell = ws.cell(row=1, column=col_idx, value=header)
        cell.fill = header_fill
        cell.font = header_font
        cell.alignment = Alignment(horizontal="center")

    for row_idx, row in enumerate(reader, 2):
        filename, width, height, resolution, delete, path = row

        ws.cell(row=row_idx, column=1, value=filename)
        ws.cell(row=row_idx, column=2, value=int(width) if width else None)
        ws.cell(row=row_idx, column=3, value=int(height) if height else None)
        ws.cell(row=row_idx, column=4, value=resolution)
        ws.cell(row=row_idx, column=5, value=delete)
        ws.cell(row=row_idx, column=5).alignment = Alignment(horizontal="center")
        ws.cell(row=row_idx, column=6, value=path)

        if delete == "YES":
            for col in range(1, 7):
                ws.cell(row=row_idx, column=col).fill = delete_fill
        elif resolution == "ERROR":
            for col in range(1, 7):
                ws.cell(row=row_idx, column=col).fill = error_fill

ws.column_dimensions["A"].width = 65
ws.column_dimensions["B"].width = 8
ws.column_dimensions["C"].width = 8
ws.column_dimensions["D"].width = 14
ws.column_dimensions["E"].width = 8
ws.column_dimensions["F"].width = 110

ws.freeze_panes = "A2"
ws.auto_filter.ref = f"A1:F{ws.max_row}"

wb.save(OUTPUT_XLSX)
print(f"Written: {OUTPUT_XLSX}")
print(f"Rows: {ws.max_row - 1}")
