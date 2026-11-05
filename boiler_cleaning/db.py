from __future__ import annotations

import io
import json
import shutil
import sqlite3
from contextlib import contextmanager
from datetime import datetime
from pathlib import Path
from typing import Any

import openpyxl


BASE_DIR = Path(__file__).resolve().parent
DATA_DIR = BASE_DIR / "data"
STORAGE_DIR = BASE_DIR / "storage"
DB_PATH = DATA_DIR / "risk_management.db"
SOURCE_DIR = Path(r"D:\안전보건관리양식")


FORM_ROWS = [
    (1, "위험성관리", "위험성평가 기준표", "04 위험성평가", "착수 전·변경 시", "검토·승인", "01_위험성평가기준표.xlsx", "A4 세로", "기준 변경 시 갱신"),
    (2, "위험성관리", "작업별 위험성평가표", "04 위험성평가", "착수 전·작업 변경 시", "작성·검토·승인·작업자", "02_작업별위험성평가표_인쇄폭통일.xlsx", "A4 가로 / 너비 1쪽", "인쇄폭 통일본"),
    (3, "점검관리", "안전점검 기록표", "05 안전점검", "작업 전·중·후", "점검자·책임자", "03_안전점검기록표.xlsx", "A4 세로", "착수 후 매 작업 기록"),
    (4, "개선관리", "개선조치 및 이행확인 관리대장", "06 이행확인", "위험요인 발견 시", "조치자·확인자", "04_개선조치및이행확인.xlsx", "A4 가로", "개선 전후 사진 연계"),
    (5, "교육관리", "안전보건교육·TBM 실시기록", "07 교육 및 기록", "투입 전·매 작업", "강사·참석자", "05_안전보건교육_TBM.xlsx", "A4 세로", "참석자 서명 필수"),
    (6, "작업허가", "안전작업허가서", "08 안전작업허가", "위험작업 시작 전", "신청·검토·승인", "06_안전작업허가서_보완.xlsx", "A4 세로", "보완본 우선"),
    (7, "연락체계", "현장 신호·연락체계 및 비상연락망", "09 신호 및 연락체계", "착수 전·담당 변경 시", "책임자·담당자", "07_신호연락체계.xlsx", "A4 세로", "현장 연락처 확정 후 작성"),
    (8, "설비관리", "설비·장비·공구·보호구 점검관리표", "10 위험물질 및 설비", "반입·작업 전·정기", "점검자·책임자", "08_설비장비공구점검.xlsx", "A4 세로", "불량품 격리 기록"),
    (9, "비상관리", "비상훈련 및 비상연락 확인기록", "11 비상대책", "착수 후·정기", "훈련책임자·평가자", "09_비상훈련기록.xlsx", "A4 세로", "훈련사진·서명부 첨부"),
    (10, "인원관리", "투입인원 자격·교육 이수현황표", "03 역할 및 책임", "투입 전·인원 변경 시", "작성·확인", "10_자격교육이수현황.xlsx", "A4 가로", "자격 유효기간 확인"),
    (11, "투입관리", "투입인원·장비 현황표", "02 계획수립", "착수 전·변경 시", "작성·승인", "11_투입인원장비현황.xlsx", "A4 가로", "확정 인원·장비 반영"),
    (12, "격리관리", "LOTO 에너지원 격리 확인표", "08·09·10", "설비작업 시작 전", "작업·격리·발주처", "12_LOTO확인표.xlsx", "A4 세로", "허가번호와 연계"),
    (13, "보호구관리", "개인보호구 지급·점검대장", "05·10", "지급·교체 시", "수령자·확인자", "13_보호구지급점검.xlsx", "A4 세로", "수령서명 필수"),
    (14, "의견수렴", "근로자 의견수렴 및 조치기록", "04·06·07", "의견 접수 시", "제안자·조치확인", "14_근로자의견조치.xlsx", "A4 세로", "조치결과 공유"),
    (15, "비상관리", "비상연락망 게시용 양식", "09·11", "착수 전·연락처 변경 시", "관리책임자", "15_비상연락망.xlsx", "A4 세로", "게시일·위치 기록"),
    (16, "화학물질관리", "화학물질·MSDS 관리대장", "10 위험물질 및 설비", "반입·변경·사용 전", "점검자·책임자", "16_화학물질_MSDS관리.xlsx", "A4 가로", "최신 MSDS 확인"),
    (17, "밀폐공간관리", "밀폐공간 작업관리·출입기록", "08·09·10", "내부작업 시작 전·중", "감시인·책임자", "17_밀폐공간작업관리.xlsx", "A4 세로", "가스측정·출입인원 기록"),
    (18, "작업중지", "작업중지권 행사 및 조치기록", "04·06", "위험상황 발생 시", "행사자·확인자", "18_작업중지권행사조치.xlsx", "A4 세로", "불이익 금지 및 재개 확인"),
    (19, "사고관리", "사고·아차사고 발생보고 및 조사서", "06·11", "사고·아차사고 발생 시", "보고자·승인자", "19_사고_아차사고보고.xlsx", "A4 세로", "재발방지대책 연계"),
    (20, "순찰관리", "일일 안전순찰 및 위험요인 점검표", "05 안전점검", "작업일 매일", "순찰자·확인자", "20_일일안전순찰점검.xlsx", "A4 세로", "미조치 사항 관리대장 이관"),
]


EVIDENCE_ROWS = [
    (1, "일반원칙", 5, 5, "완료", "01_일반원칙_안전보건경영방침.pdf", "현재 유지"),
    (2, "계획수립", 10, 10, "표기 확인", "02_계획수립_산업재해예방활동이행계획.pdf", "2025·2026 자료 구분 표시"),
    (3, "역할 및 책임", 5, 5, "착수 후 갱신", "03_역할책임_조직도및자격.pdf", "투입인원 확정 후 현장조직 갱신"),
    (4, "위험성평가", 10, 10, "완료", "04_위험성평가.pdf", "작업 전 최종평가 실시"),
    (5, "안전점검", 10, 5, "실적 보완", "05_안전점검.pdf", "작성된 점검표 1건 이상 첨부"),
    (6, "이행확인", 5, 3, "실적 보완", "06_이행확인.pdf", "개선 전후·완료확인 사례 첨부"),
    (7, "교육 및 기록", 5, 3, "실적 보완", "07_교육및기록.pdf", "서명된 최근 교육일지 첨부"),
    (8, "안전작업허가", 10, 10, "완료", "08_안전작업허가.pdf", "착수 후 허가대장 기록"),
    (9, "신호 및 연락체계", 5, 5, "착수 후 갱신", "09_신호및연락체계.pdf", "담당자·발주처 연락처 확정"),
    (10, "위험물질 및 설비", 10, 10, "완료", "10_위험물질및설비.pdf", "반입장비·MSDS 확정 후 기록"),
    (11, "비상대책", 5, 3, "현장정보 보완", "11_비상대책.pdf", "현장 연락처 확정·현장훈련 실시"),
    (12, "산업재해 현황", 20, 20, "표기 수정", "12_산업재해현황.pdf", "28p 참조를 3쪽 참조로 수정"),
]


def now_text() -> str:
    return datetime.now().astimezone().isoformat(timespec="seconds")


def connect() -> sqlite3.Connection:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    connection = sqlite3.connect(DB_PATH)
    connection.row_factory = sqlite3.Row
    connection.execute("PRAGMA foreign_keys = ON")
    connection.execute("PRAGMA journal_mode = WAL")
    return connection


@contextmanager
def connection_scope():
    connection = connect()
    try:
        with connection:
            yield connection
    finally:
        connection.close()


def initialize() -> None:
    STORAGE_DIR.mkdir(parents=True, exist_ok=True)
    with connection_scope() as connection:
        connection.executescript(
            """
            CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY,
                company_name TEXT NOT NULL,
                project_name TEXT NOT NULL,
                project_stage TEXT NOT NULL,
                representative TEXT NOT NULL,
                safety_manager TEXT NOT NULL,
                site_manager TEXT NOT NULL DEFAULT '',
                work_manager TEXT NOT NULL DEFAULT '',
                client_contact TEXT NOT NULL DEFAULT '',
                site_address TEXT NOT NULL DEFAULT '',
                work_period TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                document_kind TEXT NOT NULL CHECK(document_kind IN ('form', 'evidence')),
                sequence INTEGER NOT NULL,
                category TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL,
                evaluation_item TEXT NOT NULL DEFAULT '',
                timing TEXT NOT NULL DEFAULT '',
                signature_rule TEXT NOT NULL DEFAULT '',
                print_rule TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT '미작성',
                source_file_name TEXT NOT NULL,
                stored_path TEXT NOT NULL DEFAULT '',
                max_score INTEGER,
                current_score INTEGER,
                note TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL,
                UNIQUE(project_id, document_kind, sequence)
            );
            CREATE TABLE IF NOT EXISTS document_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                version_number INTEGER NOT NULL,
                file_name TEXT NOT NULL,
                stored_path TEXT NOT NULL,
                uploaded_at TEXT NOT NULL,
                UNIQUE(document_id, version_number)
            );
            CREATE TABLE IF NOT EXISTS form_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                record_date TEXT NOT NULL,
                record_title TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT '작성 중',
                payload_json TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS activity_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                document_id INTEGER REFERENCES documents(id) ON DELETE SET NULL,
                action TEXT NOT NULL,
                details TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL
            );
            """
        )
        connection.execute(
            """INSERT OR IGNORE INTO projects
            (id, company_name, project_name, project_stage, representative, safety_manager, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, ?)""",
            (
                "주식회사 현대기전",
                "강릉시 폐기물 소각시설 폐열보일러 외 부대설비 세정 용역",
                "착수 전",
                "정연탁",
                "김남균",
                now_text(),
            ),
        )
        for row in FORM_ROWS:
            sequence, category, title, evaluation_item, timing, signature_rule, file_name, print_rule, note = row
            stored_path = _seed_file(file_name, "forms", SOURCE_DIR / file_name)
            connection.execute(
                """INSERT OR IGNORE INTO documents
                (project_id, document_kind, sequence, category, title, evaluation_item, timing,
                 signature_rule, print_rule, status, source_file_name, stored_path, note, updated_at)
                VALUES (1, 'form', ?, ?, ?, ?, ?, ?, ?, '미작성', ?, ?, ?, ?)""",
                (sequence, category, title, evaluation_item, timing, signature_rule, print_rule, file_name, stored_path, note, now_text()),
            )
        for row in EVIDENCE_ROWS:
            sequence, title, max_score, current_score, status, file_name, note = row
            stored_path = _seed_file(file_name, "evidence", SOURCE_DIR / "실행증빙" / file_name)
            connection.execute(
                """INSERT OR IGNORE INTO documents
                (project_id, document_kind, sequence, title, evaluation_item, status,
                 source_file_name, stored_path, max_score, current_score, note, updated_at)
                VALUES (1, 'evidence', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
                (sequence, title, f"{sequence:02d} {title}", status, file_name, stored_path, max_score, current_score, note, now_text()),
            )
        _seed_versions(connection)


def _seed_file(file_name: str, folder_name: str, source: Path) -> str:
    destination_dir = STORAGE_DIR / folder_name
    destination_dir.mkdir(parents=True, exist_ok=True)
    destination = destination_dir / file_name
    if source.exists() and not destination.exists():
        shutil.copy2(source, destination)
    return destination.relative_to(BASE_DIR).as_posix() if destination.exists() else ""


def _seed_versions(connection: sqlite3.Connection) -> None:
    rows = connection.execute("SELECT id, source_file_name, stored_path FROM documents WHERE stored_path <> ''").fetchall()
    for row in rows:
        connection.execute(
            """INSERT OR IGNORE INTO document_versions
            (document_id, version_number, file_name, stored_path, uploaded_at)
            VALUES (?, 1, ?, ?, ?)""",
            (row["id"], row["source_file_name"], row["stored_path"], now_text()),
        )


def get_project() -> dict[str, Any]:
    with connection_scope() as connection:
        return dict(connection.execute("SELECT * FROM projects WHERE id = 1").fetchone())


def update_project(values: dict[str, Any]) -> dict[str, Any]:
    allowed = {
        "company_name", "project_name", "project_stage", "representative", "safety_manager",
        "site_manager", "work_manager", "client_contact", "site_address", "work_period",
    }
    updates = {key: str(value).strip() for key, value in values.items() if key in allowed}
    if not updates:
        return get_project()
    assignments = ", ".join(f"{key} = ?" for key in updates)
    with connection_scope() as connection:
        connection.execute(
            f"UPDATE projects SET {assignments}, updated_at = ? WHERE id = 1",
            [*updates.values(), now_text()],
        )
        _log(connection, None, "프로젝트 정보 수정", updates)
    return get_project()


def list_documents(kind: str | None = None) -> list[dict[str, Any]]:
    query = "SELECT * FROM documents WHERE project_id = 1"
    parameters: list[Any] = []
    if kind in {"form", "evidence"}:
        query += " AND document_kind = ?"
        parameters.append(kind)
    query += " ORDER BY document_kind, sequence"
    with connection_scope() as connection:
        return [dict(row) for row in connection.execute(query, parameters).fetchall()]


def get_document(document_id: int) -> dict[str, Any] | None:
    with connection_scope() as connection:
        row = connection.execute("SELECT * FROM documents WHERE id = ?", (document_id,)).fetchone()
        return dict(row) if row else None


def update_document(document_id: int, values: dict[str, Any]) -> dict[str, Any] | None:
    allowed = {"status", "note", "current_score", "timing", "signature_rule", "print_rule"}
    updates: dict[str, Any] = {}
    for key, value in values.items():
        if key not in allowed:
            continue
        updates[key] = None if key == "current_score" and value in (None, "") else int(value) if key == "current_score" else str(value).strip()
    if not updates:
        return get_document(document_id)
    assignments = ", ".join(f"{key} = ?" for key in updates)
    with connection_scope() as connection:
        existing = connection.execute("SELECT id, max_score FROM documents WHERE id = ?", (document_id,)).fetchone()
        if not existing:
            return None
        if updates.get("current_score") is not None and existing["max_score"] is not None:
            updates["current_score"] = max(0, min(int(updates["current_score"]), int(existing["max_score"])))
        connection.execute(
            f"UPDATE documents SET {assignments}, updated_at = ? WHERE id = ?",
            [*updates.values(), now_text(), document_id],
        )
        _log(connection, document_id, "문서 정보 수정", updates)
    return get_document(document_id)


def dashboard() -> dict[str, Any]:
    with connection_scope() as connection:
        form_total = connection.execute("SELECT COUNT(*) FROM documents WHERE document_kind = 'form'").fetchone()[0]
        form_done = connection.execute("SELECT COUNT(*) FROM documents WHERE document_kind = 'form' AND status = '완료'").fetchone()[0]
        evidence_total = connection.execute("SELECT COUNT(*) FROM documents WHERE document_kind = 'evidence'").fetchone()[0]
        scores = connection.execute("SELECT COALESCE(SUM(current_score), 0), COALESCE(SUM(max_score), 0) FROM documents WHERE document_kind = 'evidence'").fetchone()
        attention = connection.execute("SELECT COUNT(*) FROM documents WHERE status NOT IN ('완료', '해당없음')").fetchone()[0]
    return {"form_total": form_total, "form_done": form_done, "evidence_total": evidence_total, "current_score": scores[0], "max_score": scores[1], "attention_count": attention}


def list_versions(document_id: int) -> list[dict[str, Any]]:
    with connection_scope() as connection:
        rows = connection.execute("SELECT * FROM document_versions WHERE document_id = ? ORDER BY version_number DESC", (document_id,)).fetchall()
        return [dict(row) for row in rows]


def list_form_entries(document_id: int) -> list[dict[str, Any]]:
    with connection_scope() as connection:
        rows = connection.execute(
            "SELECT * FROM form_entries WHERE document_id = ? ORDER BY record_date DESC, id DESC",
            (document_id,),
        ).fetchall()
    entries = []
    for row in rows:
        entry = dict(row)
        entry["data"] = json.loads(entry.pop("payload_json") or "{}")
        entries.append(entry)
    return entries


def create_form_entry(document_id: int, values: dict[str, Any]) -> dict[str, Any]:
    document = get_document(document_id)
    if not document or document["document_kind"] != "form":
        raise ValueError("입력 가능한 실행양식이 아닙니다.")
    record_date = str(values.get("record_date") or datetime.now().date().isoformat()).strip()
    record_title = str(values.get("record_title") or document["title"]).strip()
    status = str(values.get("status") or "작성 중").strip()
    data = values.get("data") or {}
    if not isinstance(data, dict):
        raise ValueError("입력 내용을 확인해주세요.")
    timestamp = now_text()
    with connection_scope() as connection:
        cursor = connection.execute(
            """INSERT INTO form_entries
            (document_id, record_date, record_title, status, payload_json, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)""",
            (document_id, record_date, record_title, status, json.dumps(data, ensure_ascii=False), timestamp, timestamp),
        )
        if document["status"] == "미작성":
            connection.execute("UPDATE documents SET status = '작성 중', updated_at = ? WHERE id = ?", (timestamp, document_id))
        _log(connection, document_id, "입력기록 생성", {"entry_id": cursor.lastrowid, "title": record_title})
        entry_id = cursor.lastrowid
    return get_form_entry(entry_id)


def get_form_entry(entry_id: int) -> dict[str, Any] | None:
    with connection_scope() as connection:
        row = connection.execute("SELECT * FROM form_entries WHERE id = ?", (entry_id,)).fetchone()
    if not row:
        return None
    entry = dict(row)
    entry["data"] = json.loads(entry.pop("payload_json") or "{}")
    return entry


def update_form_entry(entry_id: int, values: dict[str, Any]) -> dict[str, Any] | None:
    existing = get_form_entry(entry_id)
    if not existing:
        return None
    record_date = str(values.get("record_date", existing["record_date"])).strip()
    record_title = str(values.get("record_title", existing["record_title"])).strip()
    status = str(values.get("status", existing["status"])).strip()
    data = values.get("data", existing["data"])
    if not isinstance(data, dict):
        raise ValueError("입력 내용을 확인해주세요.")
    with connection_scope() as connection:
        connection.execute(
            """UPDATE form_entries SET record_date = ?, record_title = ?, status = ?,
            payload_json = ?, updated_at = ? WHERE id = ?""",
            (record_date, record_title, status, json.dumps(data, ensure_ascii=False), now_text(), entry_id),
        )
        _log(connection, existing["document_id"], "입력기록 수정", {"entry_id": entry_id, "title": record_title})
    return get_form_entry(entry_id)


def delete_form_entry(entry_id: int) -> bool:
    existing = get_form_entry(entry_id)
    if not existing:
        return False
    with connection_scope() as connection:
        connection.execute("DELETE FROM form_entries WHERE id = ?", (entry_id,))
        _log(connection, existing["document_id"], "입력기록 삭제", {"entry_id": entry_id, "title": existing["record_title"]})
    return True


def save_upload(document_id: int, original_name: str, content: bytes) -> dict[str, Any] | None:
    document = get_document(document_id)
    if not document:
        return None
    safe_name = Path(original_name).name
    allowed_suffixes = {".xlsx", ".xlsm", ".xls", ".pdf", ".pptx", ".jpg", ".jpeg", ".png"}
    if Path(safe_name).suffix.lower() not in allowed_suffixes:
        raise ValueError("허용되지 않는 파일 형식입니다.")
    if not content:
        raise ValueError("빈 파일은 업로드할 수 없습니다.")
    version_dir = STORAGE_DIR / "versions" / str(document_id)
    version_dir.mkdir(parents=True, exist_ok=True)
    with connection_scope() as connection:
        version_number = int(connection.execute("SELECT COALESCE(MAX(version_number), 0) FROM document_versions WHERE document_id = ?", (document_id,)).fetchone()[0]) + 1
        destination = version_dir / f"v{version_number:03d}_{safe_name}"
        destination.write_bytes(content)
        relative_path = destination.relative_to(BASE_DIR).as_posix()
        connection.execute(
            "INSERT INTO document_versions (document_id, version_number, file_name, stored_path, uploaded_at) VALUES (?, ?, ?, ?, ?)",
            (document_id, version_number, safe_name, relative_path, now_text()),
        )
        connection.execute("UPDATE documents SET source_file_name = ?, stored_path = ?, updated_at = ? WHERE id = ?", (safe_name, relative_path, now_text(), document_id))
        _log(connection, document_id, "파일 업로드", {"file_name": safe_name, "version": version_number})
    return get_document(document_id)


FORM_EXCEL_FILLERS: dict[int, Any] = {}


def _set_cell(ws, coord: str, value: Any) -> None:
    if value not in (None, ""):
        ws[coord] = value


def _first_empty_row(ws, col: str, start: int, end: int) -> int | None:
    for row in range(start, end + 1):
        if ws[f"{col}{row}"].value in (None, ""):
            return row
    return None


def _fill_ledger_row_4(ws, row: int, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, f"B{row}", entry.get("record_date"))
    _set_cell(ws, f"D{row}", data.get("location"))
    _set_cell(ws, f"E{row}", data.get("hazard"))
    _set_cell(ws, f"G{row}", data.get("required_action"))
    _set_cell(ws, f"H{row}", data.get("owner"))
    _set_cell(ws, f"I{row}", data.get("due_date"))
    _set_cell(ws, f"K{row}", data.get("completion"))
    _set_cell(ws, f"L{row}", data.get("checker"))


def _fill_ledger_row_10(ws, row: int, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, f"B{row}", data.get("name"))
    _set_cell(ws, f"C{row}", data.get("position"))
    _set_cell(ws, f"E{row}", data.get("qualification"))
    _set_cell(ws, f"G{row}", data.get("certificate_no"))
    _set_cell(ws, f"I{row}", data.get("valid_until"))


def _fill_ledger_row_13(ws, row: int, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, f"B{row}", data.get("employee"))
    _set_cell(ws, f"E{row}", data.get("ppe"))
    _set_cell(ws, f"H{row}", data.get("quantity"))
    _set_cell(ws, f"I{row}", data.get("issue_date"))
    inspection_result = data.get("inspection_result")
    if inspection_result == "양호":
        ws[f"J{row}"] = "☑ 양호 □교체"
    elif inspection_result in ("교체 필요", "폐기"):
        ws[f"J{row}"] = "□ 양호 ☑교체"
    if data.get("receiver_confirm"):
        ws[f"L{row}"] = f"서명: {data['receiver_confirm']}"


def _fill_ledger_row_14(ws, row: int, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, f"C{row}", data.get("proposer"))
    _set_cell(ws, f"E{row}", data.get("opinion"))
    _set_cell(ws, f"H{row}", data.get("action"))


def _fill_ledger_row_16(ws, row: int, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, f"B{row}", data.get("chemical"))
    _set_cell(ws, f"D{row}", data.get("purpose"))
    _set_cell(ws, f"G{row}", data.get("msds_version"))
    _set_cell(ws, f"J{row}", data.get("measure"))


LEDGER_FORMS: dict[int, dict[str, Any]] = {
    4: {"start": 9, "end": 18, "scan_col": "B", "fill": _fill_ledger_row_4},
    10: {"start": 11, "end": 20, "scan_col": "B", "fill": _fill_ledger_row_10},
    13: {"start": 11, "end": 25, "scan_col": "B", "fill": _fill_ledger_row_13},
    14: {"start": 13, "end": 22, "scan_col": "C", "fill": _fill_ledger_row_14},
    16: {"start": 11, "end": 20, "scan_col": "B", "fill": _fill_ledger_row_16},
}


def export_document_ledger_excel(document_id: int) -> tuple[bytes, str]:
    document = get_document(document_id)
    if not document:
        raise LookupError("문서를 찾을 수 없습니다.")
    config = LEDGER_FORMS.get(document["sequence"])
    if not config:
        raise ValueError("이 양식은 전체 내보내기를 지원하지 않습니다.")
    source_path = (BASE_DIR / document["stored_path"]).resolve()
    if BASE_DIR.resolve() not in source_path.parents or not source_path.exists():
        raise LookupError("원본 엑셀 서식 파일을 찾을 수 없습니다.")
    entries = list(reversed(list_form_entries(document_id)))
    workbook = openpyxl.load_workbook(source_path)
    ws = workbook.active
    row = _first_empty_row(ws, config["scan_col"], config["start"], config["end"]) or config["start"]
    for entry in entries:
        if row > config["end"]:
            break
        config["fill"](ws, row, entry)
        row += 1
    buffer = io.BytesIO()
    workbook.save(buffer)
    source_name = Path(document["source_file_name"])
    file_name = f"{source_name.stem}_전체.xlsx"
    return buffer.getvalue(), file_name


def _fill_form_3(ws, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, "C5", entry.get("record_date"))
    _set_cell(ws, "H5", data.get("location"))
    _set_cell(ws, "N6", data.get("inspector"))
    inspection_type = data.get("inspection_type")
    if inspection_type:
        marks = {"작업 전": 0, "작업 중": 1, "작업 후": 2, "정기점검": 3}
        labels = ["작업 전", "작업 중", "작업 후", "수시"]
        idx = marks.get(inspection_type)
        if idx is not None:
            ws["H7"] = "     ".join(f"{'☑' if i == idx else '□'} {label}" for i, label in enumerate(labels))


FORM_EXCEL_FILLERS[3] = _fill_form_3


def _fill_form_6(ws, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, "I4", entry.get("record_date"))
    _set_cell(ws, "C5", data.get("location"))
    _set_cell(ws, "C7", data.get("work_period"))
    _set_cell(ws, "C8", data.get("work_name"))


FORM_EXCEL_FILLERS[6] = _fill_form_6


def _fill_form_7(ws, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, "C6", entry.get("record_date"))
    _set_cell(ws, "G7", data.get("responsible_person"))


FORM_EXCEL_FILLERS[7] = _fill_form_7


_FORM8_EQUIPMENT_ROWS = {
    "압축공기 압축기": 11,
    "압축공기 호스·커플링": 12,
    "이동식 조명": 14,
    "환기·송풍기": 16,
    "복합가스측정기": 17,
    "비계·작업발판": 18,
    "사다리·작업발판": 18,
    "Wire Brush·Scraper 수공구": 19,
    "산업용 진공청소기": 20,
}


def _fill_form_8(ws, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, "C6", entry.get("record_date"))
    _set_cell(ws, "C7", data.get("inspector"))
    row = _FORM8_EQUIPMENT_ROWS.get(data.get("equipment"))
    if row is None:
        return
    _set_cell(ws, f"E{row}", data.get("asset_no"))
    result = data.get("result")
    result_map = {"양호": "☑양호 □보완 □사용금지", "수리 필요": "□양호 ☑보완 □사용금지", "사용금지": "□양호 □보완 ☑사용금지"}
    if result in result_map:
        ws[f"I{row}"] = result_map[result]
    _set_cell(ws, f"K{row}", data.get("action"))
    if data.get("checker"):
        ws[f"M{row}"] = "☑"


FORM_EXCEL_FILLERS[8] = _fill_form_8


def _fill_form_9(ws, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, "C6", entry.get("record_date"))
    _set_cell(ws, "I6", data.get("location"))
    _set_cell(ws, "F7", data.get("participants"))
    _set_cell(ws, "C9", data.get("scenario"))
    _set_cell(ws, "C38", data.get("evaluation"))
    _set_cell(ws, "C39", data.get("improvement"))


FORM_EXCEL_FILLERS[9] = _fill_form_9


def _fill_form_11(ws, entry: dict[str, Any]) -> None:
    data = entry["data"]
    set_cell = lambda coord, value: _set_cell(ws, coord, value)

    set_cell("C6", data.get("work_date"))
    shift = data.get("shift")
    if shift == "주간":
        ws["F6"] = "☑ 주간  □ 야간"
    elif shift == "야간":
        ws["F6"] = "□ 주간  ☑ 야간"
    set_cell("J6", data.get("work_location"))
    set_cell("C7", data.get("target_facility"))
    set_cell("G7", data.get("work_responsible"))
    set_cell("K7", data.get("planned_time"))

    for index, row in enumerate((data.get("personnel") or [])[:12]):
        r = 11 + index
        set_cell(f"B{r}", row.get("name"))
        set_cell(f"C{r}", row.get("affiliation"))
        set_cell(f"D{r}", row.get("role"))
        set_cell(f"E{r}", row.get("task"))
        set_cell(f"G{r}", row.get("enter_time"))
        set_cell(f"H{r}", row.get("exit_time"))
        ws[f"I{r}"] = "☑ 확인" if row.get("education") else "□ 확인"
        ws[f"J{r}"] = "☑ 확인" if row.get("ppe") else "□ 확인"
        health = row.get("health")
        ws[f"K{r}"] = f"☑ {health}" if health else "□ 이상없음"
        set_cell(f"L{r}", row.get("remarks"))

    for index, row in enumerate((data.get("deployed_equipment") or [])[:8]):
        r = 26 + index
        set_cell(f"B{r}", row.get("category"))
        set_cell(f"C{r}", row.get("name"))
        set_cell(f"E{r}", row.get("spec"))
        set_cell(f"G{r}", row.get("qty"))
        set_cell(f"H{r}", row.get("operator"))
        set_cell(f"J{r}", row.get("inout"))
        check_state = row.get("check_state")
        ws[f"K{r}"] = f"☑ {check_state}" if check_state else "□ 양호"
        set_cell(f"L{r}", row.get("remarks"))

    if data.get("input_count"):
        ws["C36"] = f"{data['input_count']}명"
    if data.get("exit_confirm") == "전원 퇴장":
        ws["F36"] = "☑ 전원 퇴장"
    elif data.get("exit_confirm"):
        ws["F36"] = data["exit_confirm"]
    if data.get("remain_count"):
        ws["J36"] = f"{data['remain_count']}명"
    if data.get("personnel_checker"):
        ws["K36"] = f"인원확인자: {data['personnel_checker']}"
    if data.get("equipment_in"):
        ws["C37"] = f"{data['equipment_in']}대"
    if data.get("equipment_out"):
        ws["F37"] = f"{data['equipment_out']}대"
    if data.get("equipment_remain"):
        ws["I37"] = f"{data['equipment_remain']}대"
    set_cell("L37", data.get("equipment_checker"))
    if data.get("area_cleanup") == "완료":
        ws["C38"] = "☑ 완료  □ 미완료"
    elif data.get("area_cleanup") == "미완료":
        ws["C38"] = "□ 완료  ☑ 미완료"
    if data.get("residual_material") == "없음":
        ws["H38"] = "☑ 없음  □ 있음"
    elif data.get("residual_material") == "있음":
        ws["H38"] = "□ 없음  ☑ 있음"
    set_cell("C39", data.get("remarks"))


FORM_EXCEL_FILLERS[11] = _fill_form_11


def _fill_form_12(ws, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, "C6", entry.get("record_date"))
    _set_cell(ws, "C7", data.get("equipment"))
    _set_cell(ws, "F8", data.get("isolator"))


FORM_EXCEL_FILLERS[12] = _fill_form_12


def _fill_form_17(ws, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, "C6", entry.get("record_date"))
    _set_cell(ws, "G6", data.get("permit_no"))
    _set_cell(ws, "K6", data.get("location"))
    _set_cell(ws, "C8", data.get("work_time"))
    _set_cell(ws, "L7", data.get("watcher"))
    _set_cell(ws, "D12", data.get("oxygen"))
    _set_cell(ws, "F12", data.get("gas_result"))
    _set_cell(ws, "B22", data.get("workers"))
    _set_cell(ws, "F22", data.get("entry_log"))


FORM_EXCEL_FILLERS[17] = _fill_form_17


def _fill_form_18(ws, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, "C6", data.get("stop_time"))
    _set_cell(ws, "G6", data.get("location"))
    _set_cell(ws, "C7", data.get("exerciser"))
    _set_cell(ws, "D11", data.get("reason"))
    _set_cell(ws, "E22", data.get("action"))
    _set_cell(ws, "K22", data.get("restart_approver"))
    _set_cell(ws, "K32", data.get("restart_time"))


FORM_EXCEL_FILLERS[18] = _fill_form_18


def _fill_form_19(ws, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, "C6", data.get("occurred_at"))
    _set_cell(ws, "G6", data.get("location"))
    _set_cell(ws, "I7", data.get("reporter"))
    _set_cell(ws, "D12", data.get("summary"))
    _set_cell(ws, "C18", data.get("damage"))
    _set_cell(ws, "B32", data.get("prevention"))


FORM_EXCEL_FILLERS[19] = _fill_form_19


def _fill_form_20(ws, entry: dict[str, Any]) -> None:
    data = entry["data"]
    _set_cell(ws, "C6", entry.get("record_date"))
    _set_cell(ws, "G6", data.get("inspection_time"))
    _set_cell(ws, "B29", data.get("area"))
    _set_cell(ws, "D29", data.get("hazard"))
    _set_cell(ws, "G29", data.get("required_action"))
    _set_cell(ws, "I29", data.get("owner"))
    completion = data.get("completion")
    if completion == "완료":
        ws["L29"] = "☑완료 □진행"
    elif completion == "조치 중":
        ws["L29"] = "□완료 ☑진행"


FORM_EXCEL_FILLERS[20] = _fill_form_20


def export_form_entry_excel(entry_id: int) -> tuple[bytes, str]:
    entry = get_form_entry(entry_id)
    if not entry:
        raise LookupError("입력기록을 찾을 수 없습니다.")
    document = get_document(entry["document_id"])
    if not document:
        raise LookupError("문서를 찾을 수 없습니다.")
    filler = FORM_EXCEL_FILLERS.get(document["sequence"])
    if not filler:
        raise ValueError("이 양식은 엑셀 내보내기를 지원하지 않습니다.")
    source_path = (BASE_DIR / document["stored_path"]).resolve()
    if BASE_DIR.resolve() not in source_path.parents or not source_path.exists():
        raise LookupError("원본 엑셀 서식 파일을 찾을 수 없습니다.")
    workbook = openpyxl.load_workbook(source_path)
    filler(workbook.active, entry)
    buffer = io.BytesIO()
    workbook.save(buffer)
    source_name = Path(document["source_file_name"])
    file_name = f"{source_name.stem}_{entry['record_date']}{source_name.suffix or '.xlsx'}"
    return buffer.getvalue(), file_name


def resolve_document_file(document_id: int, version_id: int | None = None) -> tuple[Path, str] | None:
    with connection_scope() as connection:
        if version_id is None:
            row = connection.execute("SELECT stored_path, source_file_name AS file_name FROM documents WHERE id = ?", (document_id,)).fetchone()
        else:
            row = connection.execute("SELECT stored_path, file_name FROM document_versions WHERE id = ? AND document_id = ?", (version_id, document_id)).fetchone()
    if not row or not row["stored_path"]:
        return None
    path = (BASE_DIR / row["stored_path"]).resolve()
    if BASE_DIR.resolve() not in path.parents or not path.exists():
        return None
    return path, row["file_name"]


def recent_logs(limit: int = 20) -> list[dict[str, Any]]:
    with connection_scope() as connection:
        rows = connection.execute(
            """SELECT activity_logs.*, documents.title AS document_title
            FROM activity_logs LEFT JOIN documents ON documents.id = activity_logs.document_id
            ORDER BY activity_logs.id DESC LIMIT ?""",
            (max(1, min(limit, 100)),),
        ).fetchall()
        return [dict(row) for row in rows]


def _log(connection: sqlite3.Connection, document_id: int | None, action: str, details: Any) -> None:
    connection.execute(
        "INSERT INTO activity_logs (project_id, document_id, action, details, created_at) VALUES (1, ?, ?, ?, ?)",
        (document_id, action, json.dumps(details, ensure_ascii=False), now_text()),
    )
