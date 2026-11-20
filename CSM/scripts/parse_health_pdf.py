#!/usr/bin/env python3
"""
Local PDF parser for CSM 2.8.1 (일반/특수 건강진단 사후관리 소견서 & 사후관리 조치 확인서).
- Text PDFs: extracted directly with PyMuPDF.
- Scanned image PDFs: rendered with PyMuPDF and OCR'd locally via RapidOCR (PaddleOCR PP-OCRv5 Korean ONNX)
  with word/box-level (x, y) bounding boxes for accurate table column reconstruction (zero external API).
  Automatically falls back to Windows native Korean OcrEngine if RapidOCR is unavailable.
"""
import argparse
from concurrent.futures import ProcessPoolExecutor, as_completed
import json
import logging
import os
import re
import subprocess
import sys
import tempfile

for _sp in [
    r"C:\Users\gfw79\AppData\Roaming\Python\Python314\site-packages",
    os.path.expandvars(r"%APPDATA%\Python\Python314\site-packages"),
]:
    if os.path.isdir(_sp) and _sp not in sys.path:
        sys.path.append(_sp)

import pymupdf


PS_OCR_SCRIPT = r"""
param([string]$ListFile, [string]$OutFile)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
Add-Type -AssemblyName System.Runtime.WindowsRuntime
$null = [Windows.Media.Ocr.OcrEngine, Windows.Foundation, ContentType=WindowsRuntime]
$null = [Windows.Globalization.Language, Windows.Globalization, ContentType=WindowsRuntime]
$null = [Windows.Graphics.Imaging.BitmapDecoder, Windows.Foundation, ContentType=WindowsRuntime]
$null = [Windows.Storage.StorageFile, Windows.Foundation, ContentType=WindowsRuntime]
$null = [Windows.Storage.FileAccessMode, Windows.Foundation, ContentType=WindowsRuntime]
$null = [Windows.Graphics.Imaging.SoftwareBitmap, Windows.Foundation, ContentType=WindowsRuntime]
$null = [Windows.Media.Ocr.OcrResult, Windows.Foundation, ContentType=WindowsRuntime]
$null = [Windows.Storage.Streams.IRandomAccessStream, Windows.Foundation, ContentType=WindowsRuntime]

$asTaskGeneric = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1'
})[0]

function AwaitOp($op, $resultType) {
    $netTask = $asTaskGeneric.MakeGenericMethod($resultType).Invoke($null, @($op))
    $netTask.Wait(-1) | Out-Null
    return $netTask.Result
}

$lang = [Windows.Globalization.Language]::new('ko')
$engine = [Windows.Media.Ocr.OcrEngine]::TryCreateFromLanguage($lang)
if (-not $engine) {
    $engine = [Windows.Media.Ocr.OcrEngine]::TryCreateFromUserProfileLanguages()
}

$paths = Get-Content -LiteralPath $ListFile -Encoding UTF8
$pagesOut = @()
foreach ($imgPath in $paths) {
    if (-not $imgPath -or -not (Test-Path -LiteralPath $imgPath)) {
        $pagesOut += @{ text = ""; w = 1; h = 1; words = @() }
        continue
    }
    $file = AwaitOp ([Windows.Storage.StorageFile]::GetFileFromPathAsync($imgPath)) ([Windows.Storage.StorageFile])
    $stream = AwaitOp ($file.OpenAsync([Windows.Storage.FileAccessMode]::Read)) ([Windows.Storage.Streams.IRandomAccessStream])
    $decoder = AwaitOp ([Windows.Graphics.Imaging.BitmapDecoder]::CreateAsync($stream)) ([Windows.Graphics.Imaging.BitmapDecoder])
    $bitmap = AwaitOp ($decoder.GetSoftwareBitmapAsync()) ([Windows.Graphics.Imaging.SoftwareBitmap])
    $pw = [double]$bitmap.PixelWidth
    $ph = [double]$bitmap.PixelHeight
    $ocrRes = AwaitOp ($engine.RecognizeAsync($bitmap)) ([Windows.Media.Ocr.OcrResult])
    $lines = @()
    $words = @()
    foreach ($line in $ocrRes.Lines) {
        $lines += $line.Text
        foreach ($wd in $line.Words) {
            $r = $wd.BoundingRect
            $words += @{
                t = $wd.Text
                x = [Math]::Round($r.X / $pw, 4)
                y = [Math]::Round($r.Y / $ph, 4)
                w = [Math]::Round($r.Width / $pw, 4)
                h = [Math]::Round($r.Height / $ph, 4)
            }
        }
    }
    $pagesOut += @{ text = ($lines -join "`n"); w = $pw; h = $ph; words = $words }
    $stream.Dispose()
}
$jsonOut = $pagesOut | ConvertTo-Json -Depth 5 -Compress
[System.IO.File]::WriteAllText($OutFile, $jsonOut, [System.Text.UTF8Encoding]::new($false))
"""


def write_status(status_path: str, payload: dict) -> None:
    if not status_path:
        return
    try:
        tmp = status_path + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(payload, f, ensure_ascii=False)
        os.replace(tmp, status_path)
    except Exception:
        pass


_WORKER_STATE = {}


def _rapidocr_worker_init(pdf_path: str, dpi: int, threads: int) -> None:
    """Initialize RapidOCR PP-OCRv5 Korean engine once per worker process."""
    for _sp in [
        r"C:\Users\gfw79\AppData\Roaming\Python\Python314\site-packages",
        os.path.expandvars(r"%APPDATA%\Python\Python314\site-packages"),
    ]:
        if os.path.isdir(_sp) and _sp not in sys.path:
            sys.path.append(_sp)

    import pymupdf as _fitz
    import rapidocr as _rapidocr
    from rapidocr import RapidOCR, LangRec, OCRVersion, ModelType

    logging.getLogger("RapidOCR").setLevel(logging.ERROR)

    models_dir = os.path.join(os.path.dirname(_rapidocr.__file__), "models")
    det_path = os.path.join(models_dir, "ch_PP-OCRv5_det_mobile.onnx")
    rec_path = os.path.join(models_dir, "korean_PP-OCRv5_rec_mobile.onnx")

    params = {
        "Global.use_cls": False,
        "Global.log_level": "error",
        "Det.ocr_version": OCRVersion.PPOCRV5,
        "Det.model_type": ModelType.MOBILE,
        "Rec.ocr_version": OCRVersion.PPOCRV5,
        "Rec.model_type": ModelType.MOBILE,
        "Rec.lang_type": LangRec.KOREAN,
        "EngineConfig.onnxruntime.intra_op_num_threads": threads,
    }
    if os.path.isfile(det_path):
        params["Det.model_path"] = det_path
    if os.path.isfile(rec_path):
        params["Rec.model_path"] = rec_path

    _WORKER_STATE["engine"] = RapidOCR(params=params)
    _WORKER_STATE["doc"] = _fitz.open(pdf_path)
    _WORKER_STATE["dpi"] = dpi


def _rapidocr_worker_page(idx: int) -> tuple[int, dict]:
    """OCR a single PDF page using the initialized worker engine."""
    import numpy as np

    engine = _WORKER_STATE["engine"]
    doc = _WORKER_STATE["doc"]
    dpi = _WORKER_STATE["dpi"]

    pix = doc[idx].get_pixmap(dpi=dpi)
    pw = float(pix.width) or 1.0
    ph = float(pix.height) or 1.0
    img = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, pix.n)
    if pix.n == 4:
        img = img[:, :, :3]
    res = engine(img)
    words = []
    lines = []
    if res is not None and getattr(res, "txts", None):
        for box, txt in zip(res.boxes, res.txts):
            t_clean = (txt or "").strip()
            if not t_clean:
                continue
            xs = [float(pt[0]) for pt in box]
            ys = [float(pt[1]) for pt in box]
            x0, x1 = min(xs), max(xs)
            y0, y1 = min(ys), max(ys)
            lines.append(t_clean)
            words.append({
                "t": t_clean,
                "x": round(x0 / pw, 4),
                "y": round(y0 / ph, 4),
                "w": round((x1 - x0) / pw, 4),
                "h": round((y1 - y0) / ph, 4),
            })
    return idx, {"text": "\n".join(lines), "words": words}


def ocr_pages_rapidocr(pdf_path: str, scan_indices: list[int], dpi: int = 130, status_path: str = "", total_pages: int = 0) -> dict[int, dict]:
    """OCR scanned pages locally with RapidOCR (PaddleOCR PP-OCRv5 Korean ONNX)."""
    # Verify importable before spawning worker processes
    import rapidocr  # noqa: F401

    cpu = os.cpu_count() or 4
    n_pages = len(scan_indices)
    total = total_pages or n_pages
    max_workers = min(2, max(1, cpu // 4), n_pages)
    threads_per_worker = max(2, min(4, cpu // max_workers))

    out: dict[int, dict] = {}
    done_count = total - n_pages
    write_status(status_path, {"status": "running", "done": done_count, "total": total, "message": f"로컬 OCR 엔진 준비 완료 ({done_count}/{total}페이지)"})

    if max_workers <= 1 or n_pages <= 2:
        _rapidocr_worker_init(pdf_path, dpi, min(4, cpu))
        try:
            for idx in scan_indices:
                p_idx, page_data = _rapidocr_worker_page(idx)
                out[p_idx] = page_data
                done_count += 1
                write_status(status_path, {"status": "running", "done": done_count, "total": total, "message": f"로컬 OCR 판독 중 ({done_count}/{total}페이지)"})
        finally:
            if "doc" in _WORKER_STATE:
                _WORKER_STATE["doc"].close()
                _WORKER_STATE.clear()
        return out

    with ProcessPoolExecutor(
        max_workers=max_workers,
        initializer=_rapidocr_worker_init,
        initargs=(pdf_path, dpi, threads_per_worker),
    ) as ex:
        futs = [ex.submit(_rapidocr_worker_page, idx) for idx in scan_indices]
        for fut in as_completed(futs):
            p_idx, page_data = fut.result()
            out[p_idx] = page_data
            done_count += 1
            write_status(status_path, {"status": "running", "done": done_count, "total": total, "message": f"로컬 OCR 판독 중 ({done_count}/{total}페이지)"})
    return out


def ocr_pages_windows(pdf_path: str, scan_indices: list[int]) -> dict[int, dict]:
    """Fallback: OCR scanned pages locally with Windows native Korean OcrEngine."""
    doc = pymupdf.open(pdf_path)
    out: dict[int, dict] = {}
    with tempfile.TemporaryDirectory(prefix="hmg_ocr_") as tmp:
        img_paths = []
        for idx in scan_indices:
            pix = doc[idx].get_pixmap(dpi=160)
            p = os.path.join(tmp, f"page_{idx:03d}.png")
            pix.save(p)
            img_paths.append(p)
        doc.close()

        list_file = os.path.join(tmp, "files.txt")
        ps_file = os.path.join(tmp, "ocr.ps1")
        out_file = os.path.join(tmp, "ocr_out.json")
        with open(list_file, "w", encoding="utf-8") as f:
            f.write("\n".join(img_paths))
        with open(ps_file, "w", encoding="utf-8") as f:
            f.write(PS_OCR_SCRIPT)

        ps_exe = r"C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe"
        if not os.path.isfile(ps_exe):
            ps_exe = "powershell"
        proc = subprocess.run(
            [ps_exe, "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", ps_file, "-ListFile", list_file, "-OutFile", out_file],
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=240,
        )
        if proc.returncode != 0 or not os.path.isfile(out_file):
            raise RuntimeError(f"Windows OCR failed: {proc.stderr.strip()}")
        with open(out_file, "r", encoding="utf-8") as f:
            raw_out = f.read().strip()
        if raw_out:
            ocr_list = json.loads(raw_out)
            if isinstance(ocr_list, dict):
                ocr_list = [ocr_list]
            for idx, item in zip(scan_indices, ocr_list):
                out[idx] = {
                    "text": (item.get("text") or "").strip(),
                    "words": item.get("words") or [],
                }
    return out


def extract_pages(pdf_path: str, status_path: str = "") -> list[dict]:
    doc = pymupdf.open(pdf_path)
    total_pages = len(doc)
    write_status(status_path, {"status": "running", "done": 0, "total": total_pages, "message": f"총 {total_pages}페이지 분석 시작..."})
    pages = []
    scan_indices = []
    for i, page in enumerate(doc):
        t = (page.get_text("text") or "").strip()
        w_list = []
        if len(t) >= 30:
            rect = page.rect
            pw = float(rect.width) or 1.0
            ph = float(rect.height) or 1.0
            for x0, y0, x1, y1, wt, *_ in page.get_text("words"):
                w_list.append({
                    "t": wt,
                    "x": round(x0 / pw, 4),
                    "y": round(y0 / ph, 4),
                    "w": round((x1 - x0) / pw, 4),
                    "h": round((y1 - y0) / ph, 4),
                })
        pages.append({"text": t, "words": w_list})
        if len(t) < 30:
            scan_indices.append(i)
    doc.close()

    if not scan_indices:
        write_status(status_path, {"status": "running", "done": total_pages, "total": total_pages, "message": "텍스트 추출 완료, 결과 정리 중..."})
        return pages

    try:
        ocr_map = ocr_pages_rapidocr(pdf_path, scan_indices, status_path=status_path, total_pages=total_pages)
    except Exception:
        write_status(status_path, {"status": "running", "done": 0, "total": total_pages, "message": "Windows OCR로 전환하여 분석 중..."})
        ocr_map = ocr_pages_windows(pdf_path, scan_indices)

    for idx, item in ocr_map.items():
        pages[idx] = item

    return pages


def detect_doc_type(full_text: str) -> str:
    compact = re.sub(r"\s+", "", full_text)
    if "사후관리조치확인서" in compact or "사후관리지원명단" in compact:
        return "followup_cert"
    if "사후관리소견서" in compact and "특수" in compact:
        return "special_report"
    if "특수건강진단" in compact:
        return "special_report"
    return "general_report"


def parse_followup_cert(full_text: str) -> dict:
    date_m = re.search(r"(20\d{2})\s*[년.-]\s*(\d{1,2})\s*[월.-]\s*(\d{1,2})", full_text)
    consult_date = f"{date_m.group(1)}-{int(date_m.group(2)):02d}-{int(date_m.group(3)):02d}" if date_m else ""
    agency = "강원근로자건강센터" if "강원근로자건강센터" in re.sub(r"\s+", "", full_text) else "근로자건강센터"

    workers = []
    seen = set()
    for m in re.finditer(r"현대기전\s+([가-힣]{2,4})\s+(20\d{2}-\d{2}-\d{2})", full_text):
        name, dt = m.group(1), m.group(2)
        if name not in seen:
            seen.add(name)
            workers.append({
                "worker_name": name,
                "dept_name": "",
                "general_grade": "",
                "special_grade": "",
                "work_fitness": "",
                "result_grade": "",
                "exam_date": dt,
                "hospital": agency,
                "finding_summary": "",
                "followup_note": f"{dt} {agency} 사후관리 상담 완료",
                "followup_done": 1,
            })
    return {
        "doc_type": "followup_cert",
        "doc_label": "사후관리 조치 확인서",
        "exam_date": consult_date,
        "hospital": agency,
        "records": workers,
    }


def join_words(words: list[dict]) -> str:
    if not words:
        return ""
    ws = sorted(words, key=lambda w: (round(w["y"] / 0.015), w["x"]))
    return " ".join(w["t"] for w in ws)


def parse_exam_report(pages: list[dict], doc_type: str) -> dict:
    full_text = "\n".join(p["text"] for p in pages)
    is_special = doc_type == "special_report"

    hospital = "근로복지공단 동해병원" if "동해병원" in re.sub(r"\s+", "", full_text) else ""
    date_m = re.search(r"(20\d{2})[-./년\s]+(\d{1,2})[-./월\s]+(\d{1,2})", full_text)
    exam_date = f"{date_m.group(1)}-{int(date_m.group(2)):02d}-{int(date_m.group(3)):02d}" if date_m else ""

    # On landscape 사후관리 소견서 tables:
    # Col 1 (dept): x in [0.04, 0.13)
    # Col 2 (name): x in [0.125, 0.185)
    # Col 3 (sex/age): x in [0.18, 0.23)
    # Col (grade): general x in [0.24, 0.285), special x in [0.50, 0.56)
    # Col (fitness): x > 0.90
    skip_names = {"성명", "명", "총인원", "가", "나", "다", "라", "사업장명", "현대기전", "공정", "부서", "사", "성"}

    merged = {}
    order = []
    active_name = None

    for p in pages:
        compact_p = re.sub(r"\s+", "", p["text"])
        if "사후관리소견서" not in compact_p:
            continue
        words = p["words"]
        if not words:
            continue

        # Find footer y cutoff ("총 인원" / "총 건수" / "건강관리상")
        y_max = 0.89
        for w in words:
            if w["y"] > 0.40 and any(k in w["t"] for k in ("총인원", "총건수", "건강관리상", "인원:", "건수:")):
                y_max = min(y_max, w["y"] - 0.015)

        # Find table body y-range (below header ~0.225, above footer)
        body_words = [w for w in words if 0.226 <= w["y"] <= y_max]

        # Detect worker anchors in the name column (x in [0.130, 0.185))
        name_words = sorted([
            w for w in body_words
            if 0.130 <= w["x"] <= 0.185
            and not re.search(r"\d", w["t"])
            and re.sub(r"[^가-힣]", "", w["t"]) not in ("", "년", "개월", "월", "일")
        ], key=lambda w: (w["y"], w["x"]))
        # Group name tokens on the same y-line (e.g., "김" + "남균")
        anchors = []
        for nw in name_words:
            if anchors and abs(nw["y"] - anchors[-1]["y"]) < 0.015:
                anchors[-1]["name"] += nw["t"]
            else:
                anchors.append({"y": nw["y"], "name": nw["t"]})

        # Keep only valid 2-4 char Korean names
        valid_anchors = []
        for a in anchors:
            nm = re.sub(r"[^가-힣]", "", a["name"])
            if len(nm) == 4 and nm.startswith("년"):
                nm = nm[1:]
            if 2 <= len(nm) <= 4 and nm not in skip_names:
                valid_anchors.append({"y": a["y"], "name": nm})

        # Assign page y-bands to workers (including continuation from previous page)
        bands = []
        if valid_anchors:
            if active_name is not None and valid_anchors[0]["y"] > 0.26:
                bands.append((active_name, 0.226, valid_anchors[0]["y"] - 0.01, False))
            for idx, anc in enumerate(valid_anchors):
                y_start = anc["y"] - 0.015
                y_end = (valid_anchors[idx + 1]["y"] - 0.015) if idx + 1 < len(valid_anchors) else 0.90
                bands.append((anc["name"], y_start, y_end, True))
                active_name = anc["name"]
        elif active_name is not None:
            bands.append((active_name, 0.226, 0.90, False))

        for nm, y0, y1, is_start in bands:
            band_words = [w for w in body_words if y0 <= w["y"] < y1]
            if nm not in merged:
                merged[nm] = {"worker_name": nm, "dept_words": [], "grade_words": [], "fit_words": [], "body_words": [], "action_words": []}
                order.append(nm)
            if is_start:
                merged[nm]["dept_words"].extend([w for w in band_words if 0.04 <= w["x"] < 0.132 and abs(w["y"] - (y0 + 0.015)) < 0.06])
            gx0, gx1 = (0.500, 0.545) if is_special else (0.242, 0.280)
            bx0 = 0.545 if is_special else 0.280
            ax0 = 0.745 if is_special else 0.45
            merged[nm]["grade_words"].extend([w for w in band_words if gx0 <= w["x"] < gx1])
            merged[nm]["fit_words"].extend([w for w in band_words if w["x"] >= 0.90])
            merged[nm]["body_words"].extend([w for w in band_words if bx0 <= w["x"] < ax0])
            merged[nm]["action_words"].extend([w for w in band_words if ax0 <= w["x"] < 0.90])

    grade_re = re.compile(r"\b(D1|D2|DN|C1|C2|CN|D|C|A|B)\b")
    priority = ["D1", "DN", "D2", "D", "C1", "CN", "C2", "C", "A", "B"]
    kw_list = [
        "소음성난청주의", "소음성난청", "2-부톡시에탄올", "망상적혈구수 증가",
        "당뇨병의심", "당뇨 관리필요", "당뇨 관리적절", "당뇨유질환", "당뇨전단계",
        "고혈압의심", "고혈압 관리필요", "고혈압 관리적절", "고혈압유질환", "고혈압관리",
        "간장질환주의", "간장질환", "국소 폐섬유화", "무기폐", "심장 비대증", "만성기관지염",
        "폐쇄성 환기장애", "제한성 환기장애", "폐기능이상",
        "이상지질혈증주의", "이상지질혈증", "비만질환의심", "복부비만의심", "복부비만", "비만관리", "비만",
        "청력저하", "콜레스테롤",
    ]

    records = []
    for nm in order:
        info = merged[nm]
        dept_raw = re.sub(r"\s+", "", join_words(info["dept_words"]))
        dept = dept_raw[:16] if dept_raw else "공사부"

        g_text = join_words(info["grade_words"]).upper()
        # Normalize common OCR confusions: C1->CI/CL, D1->DI/DL
        g_text = re.sub(r"\bC[IL|]\b", "C1", g_text)
        g_text = re.sub(r"\bD[IL|]\b", "D1", g_text)
        found_g = []
        for g in grade_re.findall(g_text):
            if g not in found_g:
                found_g.append(g)
        sorted_g = [g for g in priority if g in found_g]
        top = sorted_g[0] if sorted_g else ""

        if is_special:
            sig = [g for g in sorted_g if g not in ("A", "B")][:3]
            spec_g = " / ".join(sig) if sig else ("A" if top in ("A", "B") else "확인필요")
            gen_g = ""
        else:
            sig = [g for g in sorted_g if g not in ("A", "B")][:2]
            gen_g = " / ".join(sig) if sig else ("A" if top in ("A", "B") else "확인필요")
            spec_g = ""

        if top == "D1":
            res_grade = "직업병유소견(D1)"
        elif top in ("DN", "D2", "D"):
            res_grade = f"유소견({top})"
        elif top in ("C1", "CN", "C2", "C"):
            res_grade = f"요관찰({top})"
        elif top in ("A", "B"):
            res_grade = "정상(A/B)"
        else:
            res_grade = "판정확인필요"

        fit_text = join_words(info["fit_words"])
        fitness = "나" if "나" in fit_text else ("다" if "다" in fit_text else ("라" if "라" in fit_text else ("가" if top in ("A", "B") else "나")))

        body_compact = join_words(info["body_words"]).replace(" ", "")
        found_kw = []
        for kw in kw_list:
            kw_ns = kw.replace(" ", "")
            if kw_ns in body_compact and not any(kw_ns in prev.replace(" ", "") for prev in found_kw):
                found_kw.append(kw)
                if len(found_kw) >= 4:
                    break
        prefix = "특수: " if is_special else "일반: "
        finding = prefix + (", ".join(found_kw) if found_kw else ("전 항목 정상" if top in ("A", "B") else "OCR 판독 확인 필요"))

        # Build tailored followup recommendation from actual right-column OCR text
        act_text = join_words(info["action_words"])
        act_ns = act_text.replace(" ", "")
        if top in ("A", "B"):
            followup = "정상 (사후관리 불필요)"
        else:
            acts = []
            if "근무중치료" in act_ns:
                acts.append("근무중치료")
            if "내과진" in act_ns or "병/의원" in act_ns or "병원" in act_ns:
                acts.append("내과 진료 권고")
            if "청력보호구" in act_ns:
                acts.append("청력보호구 착용 철저")
            elif "호흡보호구" in act_ns:
                acts.append("호흡기 보호구 착용")
            elif "보호구착용" in act_ns:
                acts.append("보호구 착용")
            if "추적검사" in act_ns:
                dm_f = re.search(r"(202\d[-./]\d{2}[-./]\d{2})", act_text)
                acts.append(f"추적검사({dm_f.group(1)})" if dm_f else "추적검사")
            elif "추적관찰" in act_ns:
                acts.append("정기 추적관찰")
            if "건강상담" in act_ns and not acts:
                acts.append("건강상담")
            followup = " · ".join(acts) if acts else "사후관리 소견 확인 및 추적관리"

        records.append({
            "worker_name": nm,
            "dept_name": dept,
            "general_grade": gen_g,
            "special_grade": spec_g,
            "work_fitness": fitness,
            "result_grade": res_grade,
            "exam_date": exam_date,
            "hospital": hospital,
            "finding_summary": finding,
            "followup_note": followup,
            "followup_done": 0,
        })

    return {
        "doc_type": doc_type,
        "doc_label": "특수건강진단 사후관리 소견서" if is_special else "일반건강진단 사후관리 소견서",
        "exam_date": exam_date,
        "hospital": hospital,
        "records": records,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", default="")
    parser.add_argument("--status", default="")
    args = parser.parse_args()

    try:
        pages = extract_pages(args.input, status_path=args.status)
        full_text = "\n".join(p["text"] for p in pages)
        doc_type = detect_doc_type(full_text)

        if doc_type == "followup_cert":
            result = parse_followup_cert(full_text)
        else:
            result = parse_exam_report(pages, doc_type)

        result["page_count"] = len(pages)
        payload = json.dumps(result, ensure_ascii=False, indent=2)
        if args.output:
            tmp_out = args.output + ".tmp"
            with open(tmp_out, "w", encoding="utf-8") as f:
                f.write(payload)
            os.replace(tmp_out, args.output)
        else:
            sys.stdout.buffer.write(payload.encode("utf-8"))

        write_status(args.status, {
            "status": "done",
            "done": len(pages),
            "total": len(pages),
            "count": len(result.get("records", [])),
            "message": "분석 완료",
        })
    except Exception as exc:
        write_status(args.status, {"status": "error", "error": str(exc)})
        raise


if __name__ == "__main__":
    main()
