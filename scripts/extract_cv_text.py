#!/usr/bin/env python3
"""Extraction texte CV (PDF/DOCX) via PyMuPDF — appele par le backend PHP."""

from __future__ import annotations

import base64
import json
import sys
from pathlib import Path


def extract_pdf(path: Path) -> str:
    import fitz

    doc = fitz.open(str(path))
    pages: list[str] = []
    try:
        for page in doc:
            text = (page.get_text("text") or "").strip()
            if not text:
                blocks = page.get_text("blocks") or []
                blocks = sorted(blocks, key=lambda b: (b[1], b[0]))
                text = "\n".join(
                    (b[4] or "").strip()
                    for b in blocks
                    if len(b) > 4 and (b[4] or "").strip()
                )
            if not text:
                text = (page.get_text("html") or "").strip()
                if text:
                    import re

                    text = re.sub(r"<[^>]+>", " ", text)
                    text = re.sub(r"\s+", " ", text).strip()
            if text:
                pages.append(text)
    finally:
        doc.close()
    return "\n".join(pages).strip()


def export_pdf_pages_base64(path: Path, max_pages: int = 4) -> list[str]:
    import fitz

    doc = fitz.open(str(path))
    images: list[str] = []
    try:
        for index, page in enumerate(doc):
            if index >= max_pages:
                break
            pix = page.get_pixmap(matrix=fitz.Matrix(2, 2), alpha=False)
            images.append(base64.b64encode(pix.tobytes("png")).decode("ascii"))
    finally:
        doc.close()
    return images


def extract_docx(path: Path) -> str:
    import zipfile
    from xml.etree import ElementTree

    with zipfile.ZipFile(path) as archive:
        xml = archive.read("word/document.xml")
    root = ElementTree.fromstring(xml)
    ns = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
    paragraphs: list[str] = []
    for para in root.iter(f"{ns}p"):
        text = "".join(node.text or "" for node in para.iter(f"{ns}t"))
        if text.strip():
            paragraphs.append(text.strip())
    return "\n".join(paragraphs).strip()


def main() -> int:
    if len(sys.argv) < 2:
        print("Usage: extract_cv_text.py <fichier> [pdf|docx] [--pages-base64]", file=sys.stderr)
        return 1

    path = Path(sys.argv[1])
    if not path.is_file():
        print(f"Fichier introuvable: {path}", file=sys.stderr)
        return 1

    args = [a for a in sys.argv[2:] if a.startswith("--")]
    fmt_arg = next((a for a in sys.argv[2:] if not a.startswith("--")), None)
    fmt = (fmt_arg or path.suffix.lstrip(".")).lower()

    try:
        if "--pages-base64" in args:
            if fmt != "pdf":
                print("Mode --pages-base64 reserve aux PDF.", file=sys.stderr)
                return 1
            sys.stdout.write(json.dumps(export_pdf_pages_base64(path), ensure_ascii=False))
            return 0

        if fmt == "pdf":
            text = extract_pdf(path)
        elif fmt == "docx":
            text = extract_docx(path)
        else:
            print(f"Format non supporte: {fmt}", file=sys.stderr)
            return 1
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        return 1

    sys.stdout.write(text)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
