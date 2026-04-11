#!/usr/bin/env python3
import json
import sys
from pathlib import Path

try:
    import easyocr
except Exception as e:
    print(json.dumps({"ok": False, "error": f"EasyOCR import failed: {e}"}, ensure_ascii=False))
    sys.exit(1)


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "image path required"}, ensure_ascii=False))
        return 1

    image_path = Path(sys.argv[1])
    if not image_path.is_file():
        print(json.dumps({"ok": False, "error": "image not found"}, ensure_ascii=False))
        return 1

    try:
        reader = easyocr.Reader(['ko', 'en'], gpu=False)
        results = reader.readtext(str(image_path), detail=0, paragraph=True)
        text = "\n".join([x.strip() for x in results if str(x).strip()])
        print(json.dumps({"ok": True, "text": text}, ensure_ascii=False))
        return 0
    except Exception as e:
        print(json.dumps({"ok": False, "error": str(e)}, ensure_ascii=False))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
