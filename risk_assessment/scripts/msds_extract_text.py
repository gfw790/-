#!/usr/bin/env python
import argparse
import json
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

import pymupdf
from pypdf import PdfReader


def normalize_text(value: str) -> str:
    value = value.replace("\r\n", "\n").replace("\r", "\n")
    value = re.sub(r"[ \t]+", " ", value)
    value = re.sub(r"\n{3,}", "\n\n", value)
    return value.strip()


def normalize_heading_prefix(line: str) -> str:
    line = line.strip()
    line = re.sub(r"^(\d{1,2})\s*,\s*", r"\1. ", line)
    line = re.sub(r"^(\d{1,2})\)\s*", r"\1. ", line)
    return line


def is_page_marker(line: str) -> bool:
    normalized = line.strip()
    return bool(
        re.fullmatch(r"\d+\s*/\s*\d+", normalized)
        or re.fullmatch(r"-\s*\d+\s*-", normalized)
    )


def is_major_heading(line: str) -> bool:
    normalized = normalize_heading_prefix(line)
    match = re.match(r"^(\d{1,2})\.\s*(.+)$", normalized)
    if not match:
        return False

    number = int(match.group(1))
    if number < 1 or number > 16:
        return False

    title = match.group(2).strip()
    if not title:
        return False

    major_keywords = (
        "화학제품", "회사", "유해성", "위험성", "구성성분", "명칭", "함유량",
        "응급조치", "폭발", "화재", "누출", "취급", "저장", "노출방지", "보호구",
        "물리화학적", "안정성", "반응성", "독성", "환경", "폐기", "운송", "법적",
        "참고사항", "기타",
    )
    return any(keyword in title for keyword in major_keywords)


def split_sections(text: str) -> list[dict]:
    lines = []
    for raw_line in text.split("\n"):
        line = raw_line.strip()
        if not line or is_page_marker(line):
            continue
        lines.append(normalize_heading_prefix(line))

    sections: list[dict] = []
    current: dict | None = None

    for line in lines:
        if current is None:
            current = {"title": "추출 본문", "paragraphs": []}

        if is_major_heading(line) and current["paragraphs"]:
            sections.append(current)
            current = {"title": line, "paragraphs": []}
            continue

        if is_major_heading(line) and current["title"] == "추출 본문" and not current["paragraphs"]:
            current["title"] = line
            continue

        current["paragraphs"].append(line)

    if current and (current["paragraphs"] or current["title"] != "추출 본문"):
        sections.append(current)

    return sections[:24]


def split_block_lines(value: str) -> list[str]:
    normalized = normalize_text(value)
    if not normalized:
        return []

    lines = []
    for raw_line in normalized.split("\n"):
        line = raw_line.strip()
        if line:
            lines.append(line)
    return lines


def extract_ordered_page_lines(page: pymupdf.Page) -> list[str]:
    raw_blocks = page.get_text("blocks") or []
    text_blocks: list[dict] = []

    for block in raw_blocks:
        if len(block) < 5:
            continue

        x0, y0, x1, y1, text = block[:5]
        lines = split_block_lines(text or "")
        if not lines:
            continue

        text_blocks.append(
            {
                "x0": float(x0),
                "y0": float(y0),
                "x1": float(x1),
                "y1": float(y1),
                "lines": lines,
            }
        )

    if not text_blocks:
        page_text = page.get_text() or ""
        return split_block_lines(page_text)

    row_tolerance = 14.0
    text_blocks.sort(key=lambda item: (round(item["y0"] / row_tolerance), item["y0"], item["x0"]))

    ordered_lines: list[str] = []
    current_row_key: int | None = None
    current_row_blocks: list[dict] = []

    def flush_row() -> None:
        nonlocal current_row_blocks
        if not current_row_blocks:
            return

        current_row_blocks.sort(key=lambda item: item["x0"])
        for row_block in current_row_blocks:
            ordered_lines.extend(row_block["lines"])
        current_row_blocks = []

    for block in text_blocks:
        row_key = round(block["y0"] / row_tolerance)
        if current_row_key is None:
            current_row_key = row_key
        if row_key != current_row_key:
            flush_row()
            current_row_key = row_key
        current_row_blocks.append(block)

    flush_row()
    return ordered_lines


def extract_with_pypdf(pdf_path: Path) -> tuple[str, list[dict]]:
    reader = PdfReader(str(pdf_path))
    chunks: list[str] = []

    for page in reader.pages:
        page_text = page.extract_text() or ""
        page_text = normalize_text(page_text)
        if page_text:
            chunks.append(page_text)

    joined = "\n\n".join(chunks).strip()
    return joined, split_sections(joined)


def extract_with_pymupdf(pdf_path: Path) -> tuple[str, list[dict]]:
    document = pymupdf.open(str(pdf_path))
    try:
        chunks = []
        for page in document:
            page_lines = extract_ordered_page_lines(page)
            page_text = normalize_text("\n".join(page_lines))
            if page_text:
                chunks.append(page_text)
    finally:
        document.close()

    joined = "\n\n".join(chunks).strip()
    return joined, split_sections(joined)


def resolve_tesseract_path() -> str | None:
    candidates = [
        Path("A:/Tesseract-OCR/tesseract.exe"),
        Path("C:/Program Files/Tesseract-OCR/tesseract.exe"),
    ]

    for candidate in candidates:
        if candidate.is_file():
            return str(candidate)

    discovered = shutil.which("tesseract")
    return discovered or None


def extract_with_tesseract(pdf_path: Path) -> tuple[str, list[dict]]:
    tesseract_path = resolve_tesseract_path()
    if not tesseract_path:
        return "", []

    document = pymupdf.open(str(pdf_path))
    try:
        chunks: list[str] = []
        with tempfile.TemporaryDirectory(prefix="msds_tesseract_") as tmp_dir:
            temp_root = Path(tmp_dir)

            for page_index, page in enumerate(document):
                pixmap = page.get_pixmap(matrix=pymupdf.Matrix(2.4, 2.4), alpha=False)
                image_path = temp_root / f"page_{page_index + 1:03d}.png"
                text_base_path = temp_root / f"page_{page_index + 1:03d}"
                pixmap.save(str(image_path))

                command = [
                    tesseract_path,
                    str(image_path),
                    str(text_base_path),
                    "-l",
                    "kor+eng",
                    "--psm",
                    "6",
                ]
                subprocess.run(
                    command,
                    check=True,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                    text=True,
                )

                text_path = text_base_path.with_suffix(".txt")
                if text_path.is_file():
                    page_text = normalize_text(text_path.read_text(encoding="utf-8", errors="ignore"))
                    if page_text:
                        chunks.append(page_text)
    finally:
        document.close()

    joined = "\n\n".join(chunks).strip()
    return joined, split_sections(joined)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument(
        "--engine",
        choices=["auto", "pymupdf", "pypdf", "tesseract"],
        default="auto",
    )
    args = parser.parse_args()

    input_path = Path(args.input)
    output_path = Path(args.output)

    payload = {
        "status": "failed",
        "engine": "",
        "text": "",
        "sections": [],
        "error": "",
    }

    try:
        if not input_path.is_file():
            raise FileNotFoundError("입력 PDF 파일을 찾을 수 없습니다.")

        if args.engine == "pymupdf":
            text, sections = extract_with_pymupdf(input_path)
            engine = "pymupdf"
        elif args.engine == "pypdf":
            text, sections = extract_with_pypdf(input_path)
            engine = "pypdf"
        elif args.engine == "tesseract":
            text, sections = extract_with_tesseract(input_path)
            engine = "tesseract"
        else:
            text, sections = extract_with_pymupdf(input_path)
            engine = "pymupdf"
            if not text:
                text, sections = extract_with_pypdf(input_path)
                engine = "pypdf"
            if not text:
                text, sections = extract_with_tesseract(input_path)
                engine = "tesseract"

        if text:
            payload["status"] = "ready"
            payload["engine"] = engine
            payload["text"] = text
            payload["sections"] = sections
        else:
            payload["status"] = "failed"
            payload["engine"] = engine
            payload["error"] = (
                "이 PDF에서는 서버 추출 텍스트를 찾지 못했습니다. "
                "현재 Python 3.14 환경에서는 PaddleOCR 엔진을 설치할 수 없어, "
                "브라우저 자동 추출 또는 수동 정리 본문을 사용합니다."
            )
    except Exception as exc:  # pragma: no cover
        payload["status"] = "failed"
        payload["engine"] = "pypdf"
        payload["error"] = str(exc)

    output_path.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    return 0 if payload["status"] == "ready" else 1


if __name__ == "__main__":
    sys.exit(main())
