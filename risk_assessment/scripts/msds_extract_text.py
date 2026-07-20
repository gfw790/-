#!/usr/bin/env python
import argparse
import json
import re
import sys
from pathlib import Path

from pypdf import PdfReader


def normalize_text(value: str) -> str:
    value = value.replace("\r\n", "\n").replace("\r", "\n")
    value = re.sub(r"[ \t]+", " ", value)
    value = re.sub(r"\n{3,}", "\n\n", value)
    return value.strip()


def is_heading(line: str) -> bool:
    heading_patterns = [
        r"^\d{1,2}[.)]?\s*",
        r"^[①-⑳]\s*",
        r"^(응급조치|유해성|위험성|취급|저장|노출방지|보호구|물리화학적|안정성|독성|환경|폐기|운송|법적|기타 참고|성분|구성)",
    ]
    return any(re.search(pattern, line) for pattern in heading_patterns)


def split_sections(text: str) -> list[dict]:
    lines = [line.strip() for line in text.split("\n") if line.strip()]
    sections: list[dict] = []
    current: dict | None = None

    for line in lines:
        if current is None:
            current = {"title": "추출 본문", "paragraphs": []}

        if is_heading(line) and current["paragraphs"]:
            sections.append(current)
            current = {"title": line, "paragraphs": []}
            continue

        if is_heading(line) and current["title"] == "추출 본문" and not current["paragraphs"]:
            current["title"] = line
            continue

        current["paragraphs"].append(line)

    if current and (current["paragraphs"] or current["title"] != "추출 본문"):
        sections.append(current)

    return sections[:24]


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


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
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

        text, sections = extract_with_pypdf(input_path)
        if text:
            payload["status"] = "ready"
            payload["engine"] = "pypdf"
            payload["text"] = text
            payload["sections"] = sections
        else:
            payload["status"] = "failed"
            payload["engine"] = "pypdf"
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
