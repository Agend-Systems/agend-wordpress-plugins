#!/usr/bin/env python3
"""Convert a small subset of Markdown to HTML for manifest.json's
`sections.changelog` / `sections.description` fields.

This is intentionally not a full Markdown parser: it only understands
what release notes and plugin Description headers actually contain --
paragraphs and "- " bullet lists. Every other line is treated as its own
paragraph. Output is a single line of HTML with no surrounding whitespace,
suitable for embedding as a JSON string value.

Usage:
    md_to_html.py < input.md > output.html
    md_to_html.py input.md
"""
from __future__ import annotations

import html
import sys


def convert(text: str) -> str:
    lines = text.replace("\r\n", "\n").split("\n")
    blocks: list[str] = []
    paragraph: list[str] = []
    list_items: list[str] = []

    def flush_paragraph() -> None:
        if paragraph:
            joined = " ".join(paragraph).strip()
            if joined:
                blocks.append(f"<p>{html.escape(joined)}</p>")
            paragraph.clear()

    def flush_list() -> None:
        if list_items:
            items = "".join(f"<li>{html.escape(item)}</li>" for item in list_items)
            blocks.append(f"<ul>{items}</ul>")
            list_items.clear()

    for raw_line in lines:
        line = raw_line.strip()

        if not line:
            flush_paragraph()
            flush_list()
            continue

        if line.startswith("- ") or line.startswith("* "):
            flush_paragraph()
            list_items.append(line[2:].strip())
            continue

        # Any non-bullet line ends a list block.
        flush_list()
        paragraph.append(line)

    flush_paragraph()
    flush_list()

    return "".join(blocks)


def main() -> int:
    if len(sys.argv) > 1:
        with open(sys.argv[1], "r", encoding="utf-8") as handle:
            text = handle.read()
    else:
        text = sys.stdin.read()

    sys.stdout.write(convert(text))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
