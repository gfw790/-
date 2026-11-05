"""산업안전보건관리비 사용계획·집행 관리 (산업안전보건법 시행규칙 별지 제102호서식 기준)."""
from __future__ import annotations

import sqlite3
import uuid
from contextlib import closing
from datetime import date, datetime
from pathlib import Path
from typing import Any

import db
from . import service

CATEGORIES = (
    ('MANAGER_WAGE', '안전·보건관리자 임금 등'),
    ('SAFETY_FACILITY', '안전시설비 등'),
    ('PROTECTIVE_GEAR', '보호구 등'),
    ('SAFETY_DIAGNOSIS', '안전보건진단비 등'),
    ('SAFETY_EDUCATION', '안전보건교육비 등'),
    ('HEALTH_PREVENTION', '근로자 건강장해예방비 등'),
    ('TECH_GUIDANCE', '건설재해예방전문지도기관 기술지도비'),
    ('HQ_ORG_WAGE', '본사 전담조직 근로자 임금 등'),
    ('RISK_ASSESSMENT', '위험성평가 등에 따른 소요비용'),
)
CATEGORY_CODES = tuple(c for c, _ in CATEGORIES)
CATEGORY_LABELS = dict(CATEGORIES)
WORK_KINDS = ('건축공사', '토목공사', '중건설공사', '특수건설공사')
MAX_FILE = 15 * 1024 * 1024


def _ensure_line_columns(conn) -> None:
    columns = {r[1] for r in conn.execute('PRAGMA table_info(safety_budget_lines)')}
    if 'basis' not in columns:
        conn.execute("ALTER TABLE safety_budget_lines ADD COLUMN basis TEXT NOT NULL DEFAULT ''")


def migrate():
    with service.connection() as conn:
        found = {r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        if {'safety_budget_header', 'safety_budget_items', 'safety_budget_lines', 'safety_budget_attachments'}.issubset(found):
            conn.execute('BEGIN IMMEDIATE')
            _ensure_line_columns(conn)
            return None
        backup_dir = db.DATA_DIR / 'backups'
        backup_dir.mkdir(parents=True, exist_ok=True)
        backup = backup_dir / f'safety_budget_v1_{datetime.now():%Y%m%d_%H%M%S}_{uuid.uuid4().hex[:8]}.db'
        with closing(sqlite3.connect(backup)) as target:
            conn.backup(target)
            if target.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
                raise RuntimeError('안전관리비 DB 백업 검증에 실패했습니다.')
        conn.execute('BEGIN IMMEDIATE')
        conn.execute(f'''CREATE TABLE IF NOT EXISTS safety_budget_header (
            id INTEGER PRIMARY KEY CHECK(id = 1),
            client_name TEXT NOT NULL DEFAULT '', work_kind TEXT NOT NULL DEFAULT '특수건설공사' CHECK(work_kind IN {WORK_KINDS!r}),
            material_cost INTEGER NOT NULL DEFAULT 0, government_material_cost INTEGER NOT NULL DEFAULT 0,
            direct_labor_cost INTEGER NOT NULL DEFAULT 0, other_cost INTEGER NOT NULL DEFAULT 0,
            budget_amount INTEGER NOT NULL DEFAULT 0,
            prepared_date TEXT NOT NULL DEFAULT '', preparer_title TEXT NOT NULL DEFAULT '안전관리자',
            preparer_name TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '',
            revision INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL)''')
        conn.execute('''CREATE TABLE IF NOT EXISTS safety_budget_items (
            category TEXT PRIMARY KEY, planned_amount INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL)''')
        conn.execute('''CREATE TABLE IF NOT EXISTS safety_budget_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT NOT NULL CHECK(category IN ''' + str(CATEGORY_CODES) + '''),
            expense_date TEXT NOT NULL, description TEXT NOT NULL DEFAULT '',
            quantity REAL NOT NULL DEFAULT 1, unit TEXT NOT NULL DEFAULT '',
            unit_price INTEGER NOT NULL DEFAULT 0, amount INTEGER NOT NULL DEFAULT 0,
            basis TEXT NOT NULL DEFAULT '', usage_period TEXT NOT NULL DEFAULT '', payee TEXT NOT NULL DEFAULT '',
            receipt_no TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '',
            revision INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''')
        conn.execute('''CREATE TABLE IF NOT EXISTS safety_budget_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            line_id INTEGER NOT NULL REFERENCES safety_budget_lines(id) ON DELETE CASCADE,
            stored_path TEXT NOT NULL UNIQUE, original_name TEXT NOT NULL, mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL CHECK(size_bytes > 0), description TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL)''')
        conn.execute('CREATE INDEX IF NOT EXISTS safety_budget_lines_category ON safety_budget_lines(category)')
        conn.execute('CREATE INDEX IF NOT EXISTS safety_budget_attachments_line ON safety_budget_attachments(line_id)')
        now = db.now_text()
        conn.execute('''INSERT OR IGNORE INTO safety_budget_header
            (id, client_name, work_kind, prepared_date, updated_at) VALUES (1,'','특수건설공사',?,?)''',
            (date.today().isoformat(), now))
        for code in CATEGORY_CODES:
            conn.execute('INSERT OR IGNORE INTO safety_budget_items (category, planned_amount, updated_at) VALUES (?,0,?)', (code, now))
        return str(backup)


def header_get() -> dict[str, Any]:
    with service.connection() as conn:
        return dict(conn.execute('SELECT * FROM safety_budget_header WHERE id=1').fetchone())


def header_update(payload: dict[str, Any]) -> dict[str, Any]:
    with service.connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        existing = dict(conn.execute('SELECT * FROM safety_budget_header WHERE id=1').fetchone())
        if payload.get('revision') != existing['revision']:
            raise service.Conflict('다른 화면에서 변경되었습니다. 새로고침한 뒤 다시 확인하세요.')
        work_kind = str(payload.get('work_kind', existing['work_kind'])).strip()
        if work_kind not in WORK_KINDS:
            raise ValueError('공사종류를 확인하세요.')
        values: dict[str, Any] = {'work_kind': work_kind}
        for key in ('material_cost', 'government_material_cost', 'direct_labor_cost', 'other_cost', 'budget_amount'):
            try:
                value = int(payload.get(key, existing[key]) or 0)
            except (TypeError, ValueError):
                raise ValueError('금액을 확인하세요.')
            if value < 0:
                raise ValueError('금액은 0 이상이어야 합니다.')
            values[key] = value
        for key, limit in [('client_name', 200), ('prepared_date', 10), ('preparer_title', 50), ('preparer_name', 50), ('note', 2000)]:
            value = str(payload.get(key, existing[key]) or '').strip()
            if len(value) > limit:
                raise ValueError('입력 길이를 확인하세요.')
            if key == 'prepared_date' and value:
                date.fromisoformat(value)
            values[key] = value
        now = db.now_text()
        conn.execute('''UPDATE safety_budget_header SET client_name=?,work_kind=?,material_cost=?,government_material_cost=?,
            direct_labor_cost=?,other_cost=?,budget_amount=?,prepared_date=?,preparer_title=?,preparer_name=?,note=?,
            revision=revision+1,updated_at=? WHERE id=1''',
            (values['client_name'], values['work_kind'], values['material_cost'], values['government_material_cost'],
             values['direct_labor_cost'], values['other_cost'], values['budget_amount'], values['prepared_date'],
             values['preparer_title'], values['preparer_name'], values['note'], now))
        return dict(conn.execute('SELECT * FROM safety_budget_header WHERE id=1').fetchone())


def _line_dict(row, attachments) -> dict[str, Any]:
    line = dict(row)
    line['attachments'] = attachments
    return line


def list_lines() -> list[dict[str, Any]]:
    with service.connection() as conn:
        rows = conn.execute('SELECT * FROM safety_budget_lines ORDER BY expense_date, id').fetchall()
        result = []
        for row in rows:
            attachments = [dict(a) for a in conn.execute(
                'SELECT id,original_name,mime_type,size_bytes,description,created_at FROM safety_budget_attachments WHERE line_id=? ORDER BY id', (row['id'],))]
            result.append(_line_dict(row, attachments))
        return result


def items_list() -> list[dict[str, Any]]:
    # 계획금액과 집행액 모두 3번 "세부 사용계획" 목록에서 직접 계산합니다.
    # 계획금액 = 해당 항목의 모든 등록 내역 합계, 집행액 = 그중 사용(집행)일자가 입력된 내역만의 합계.
    lines = list_lines()
    planned_total = sum(l['amount'] for l in lines) or 1
    result = []
    for code, label in CATEGORIES:
        cat_lines = [l for l in lines if l['category'] == code]
        planned = sum(l['amount'] for l in cat_lines)
        executed = sum(l['amount'] for l in cat_lines if l['expense_date'])
        result.append({'category': code, 'label': label, 'planned_amount': planned,
                        'ratio': round(planned / planned_total * 100, 1),
                        'executed_amount': executed, 'remaining_amount': planned - executed})
    return result


def summary() -> dict[str, Any]:
    header = header_get()
    items = items_list()
    lines = list_lines()
    total_planned = sum(i['planned_amount'] for i in items)
    total_executed = sum(i['executed_amount'] for i in items)
    target_amount = header['material_cost'] + header['government_material_cost'] + header['direct_labor_cost']
    return {'header': header, 'items': items, 'lines': lines, 'categories': CATEGORIES, 'work_kinds': WORK_KINDS,
            'total_planned': total_planned, 'total_executed': total_executed,
            'target_amount': target_amount, 'remaining_amount': header['budget_amount'] - total_executed}


def _validate_payload(payload: dict[str, Any], existing: dict[str, Any] | None) -> dict[str, Any]:
    category = str(payload.get('category', existing['category'] if existing else CATEGORY_CODES[0])).strip()
    if category not in CATEGORY_CODES:
        raise ValueError('항목을 확인하세요.')
    expense_date = str(payload.get('expense_date', existing['expense_date'] if existing else '')).strip()
    if expense_date:
        date.fromisoformat(expense_date)
    try:
        quantity = float(payload.get('quantity', existing['quantity'] if existing else 1))
    except (TypeError, ValueError):
        raise ValueError('수량을 확인하세요.')
    if quantity <= 0:
        raise ValueError('수량은 0보다 커야 합니다.')
    try:
        unit_price = int(payload.get('unit_price', existing['unit_price'] if existing else 0) or 0)
    except (TypeError, ValueError):
        raise ValueError('단가를 확인하세요.')
    amount = payload.get('amount')
    amount = int(amount) if amount not in (None, '') else round(unit_price * quantity)
    values: dict[str, Any] = {'category': category, 'expense_date': expense_date, 'quantity': quantity,
                               'unit_price': unit_price, 'amount': amount}
    for key, limit in [('description', 500), ('unit', 20), ('basis', 200), ('usage_period', 50), ('payee', 200), ('receipt_no', 100), ('note', 2000)]:
        value = payload.get(key, existing[key] if existing else '')
        value = str(value or '').strip()
        if len(value) > limit:
            raise ValueError('입력 길이를 확인하세요.')
        values[key] = value
    return values


def create_line(payload: dict[str, Any]) -> dict[str, Any]:
    values = _validate_payload(payload, None)
    now = db.now_text()
    with service.connection() as conn:
        cursor = conn.execute('''INSERT INTO safety_budget_lines
            (category,expense_date,description,quantity,unit,unit_price,amount,basis,usage_period,payee,receipt_no,note,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)''',
            (values['category'], values['expense_date'], values['description'], values['quantity'], values['unit'],
             values['unit_price'], values['amount'], values['basis'], values['usage_period'], values['payee'],
             values['receipt_no'], values['note'], now, now))
        row = conn.execute('SELECT * FROM safety_budget_lines WHERE id=?', (cursor.lastrowid,)).fetchone()
        return _line_dict(row, [])


def update_line(line_id: int, payload: dict[str, Any]) -> dict[str, Any]:
    with service.connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row = conn.execute('SELECT * FROM safety_budget_lines WHERE id=?', (line_id,)).fetchone()
        if not row:
            raise LookupError('사용 기록을 찾을 수 없습니다.')
        existing = dict(row)
        if payload.get('revision') != existing['revision']:
            raise service.Conflict('다른 화면에서 변경되었습니다. 목록을 새로고침한 뒤 다시 확인하세요.')
        values = _validate_payload(payload, existing)
        now = db.now_text()
        conn.execute('''UPDATE safety_budget_lines SET category=?,expense_date=?,description=?,quantity=?,unit=?,
            unit_price=?,amount=?,basis=?,usage_period=?,payee=?,receipt_no=?,note=?,revision=revision+1,updated_at=?
            WHERE id=?''',
            (values['category'], values['expense_date'], values['description'], values['quantity'], values['unit'],
             values['unit_price'], values['amount'], values['basis'], values['usage_period'], values['payee'],
             values['receipt_no'], values['note'], now, line_id))
        row = conn.execute('SELECT * FROM safety_budget_lines WHERE id=?', (line_id,)).fetchone()
        attachments = [dict(a) for a in conn.execute(
            'SELECT id,original_name,mime_type,size_bytes,description,created_at FROM safety_budget_attachments WHERE line_id=? ORDER BY id', (line_id,))]
        return _line_dict(row, attachments)


def delete_line(line_id: int) -> bool:
    with service.connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row = conn.execute('SELECT id FROM safety_budget_lines WHERE id=?', (line_id,)).fetchone()
        if not row:
            return False
        attachments = conn.execute('SELECT stored_path FROM safety_budget_attachments WHERE line_id=?', (line_id,)).fetchall()
        conn.execute('DELETE FROM safety_budget_lines WHERE id=?', (line_id,))
        for a in attachments:
            (attachment_root() / a['stored_path']).unlink(missing_ok=True)
        return True


def attachment_root() -> Path:
    root = (db.STORAGE_DIR / 'safety_budget').resolve()
    root.mkdir(parents=True, exist_ok=True)
    return root


def save_attachment(line_id: int, name: str, content: bytes, description: str = '') -> dict[str, Any]:
    if not name or len(name) > 200 or any(c in name for c in '/\\:\x00\r\n') or name in ('.', '..'):
        raise ValueError('파일명에 경로 문자를 사용할 수 없습니다.')
    if not 0 < len(content) <= MAX_FILE:
        raise ValueError('파일은 15MB 이하로 첨부하세요.')
    suffix = Path(name).suffix.lower()
    signatures = {'.jpg': ('image/jpeg', content.startswith(b'\xff\xd8\xff')),
                  '.jpeg': ('image/jpeg', content.startswith(b'\xff\xd8\xff')),
                  '.png': ('image/png', content.startswith(b'\x89PNG\r\n\x1a\n')),
                  '.webp': ('image/webp', content[:4] == b'RIFF' and content[8:12] == b'WEBP'),
                  '.pdf': ('application/pdf', content.startswith(b'%PDF-'))}
    mime, valid = signatures.get(suffix, ('', False))
    if not valid:
        raise ValueError('실제 JPG·PNG·WEBP 이미지 또는 PDF 파일만 첨부할 수 있습니다.')
    if len(description) > 1000:
        raise ValueError('설명은 1,000자 이내로 입력하세요.')
    root = attachment_root()
    destination = root / (uuid.uuid4().hex + suffix)
    try:
        with service.connection() as conn:
            conn.execute('BEGIN IMMEDIATE')
            line = conn.execute('SELECT id FROM safety_budget_lines WHERE id=?', (line_id,)).fetchone()
            if not line:
                raise LookupError('사용 기록을 찾을 수 없습니다.')
            with destination.open('xb') as stream:
                stream.write(content)
            cursor = conn.execute('''INSERT INTO safety_budget_attachments
                (line_id,stored_path,original_name,mime_type,size_bytes,description,created_at)
                VALUES (?,?,?,?,?,?,?)''',
                (line_id, destination.name, name, mime, len(content), description, db.now_text()))
            return {'id': cursor.lastrowid, 'original_name': name, 'mime_type': mime, 'size_bytes': len(content)}
    except Exception:
        destination.unlink(missing_ok=True)
        raise


def get_attachment(attachment_id: int) -> tuple[Path, dict[str, Any]]:
    with service.connection() as conn:
        row = conn.execute('SELECT * FROM safety_budget_attachments WHERE id=?', (attachment_id,)).fetchone()
    if not row:
        raise LookupError('첨부파일을 찾을 수 없습니다.')
    root = attachment_root()
    path = (root / row['stored_path']).resolve()
    if root not in path.parents or not path.is_file():
        raise LookupError('첨부파일을 찾을 수 없습니다.')
    return path, dict(row)


TEMPLATE_PATH = db.BASE_DIR / 'static' / 'assets' / '안전보건관리비 사용계획서.xlsx'

# Each category's fixed detail-row block in the official template: (first_row, row_count).
# A block with row_count == 1 only fits one lump-sum line in the printed form; extra app
# lines are combined into that single row. PROTECTIVE_GEAR has 7 rows for itemized entries;
# beyond 7 lines, the remainder is folded into the last ('기타') row.
TEMPLATE_CATEGORY_ROWS = {
    'MANAGER_WAGE': (33, 1),
    'SAFETY_FACILITY': (37, 1),
    'PROTECTIVE_GEAR': (38, 7),
    'SAFETY_DIAGNOSIS': (45, 1),
    'SAFETY_EDUCATION': (46, 1),
    'HEALTH_PREVENTION': (47, 1),
    'TECH_GUIDANCE': (48, 1),
    'HQ_ORG_WAGE': (50, 1),
    'RISK_ASSESSMENT': (52, 1),
}
_WORK_KIND_ROW = {'건축공사': 7, '토목공사': 8, '중건설공사': 9, '특수건설공사': 10}


def _fill_detail_row(ws, row: int, line: dict[str, Any]) -> None:
    ws[f'J{row}'] = line['description']
    ws[f'N{row}'] = line['unit']
    ws[f'P{row}'] = line['quantity']
    ws[f'R{row}'] = line['amount']
    ws[f'V{row}'] = line['basis']
    ws[f'Z{row}'] = line['usage_period']


def _clear_detail_row(ws, row: int) -> None:
    for col in ('J', 'N', 'P', 'R', 'V', 'Z'):
        ws[f'{col}{row}'] = None


def export_excel() -> tuple[bytes, str]:
    import io
    import openpyxl

    if not TEMPLATE_PATH.is_file():
        raise LookupError('안전관리비 사용계획서 서식 파일을 찾을 수 없습니다. static/assets에 서식을 넣어주세요.')
    data = summary()
    header, lines = data['header'], data['lines']

    wb = openpyxl.load_workbook(TEMPLATE_PATH)
    ws = wb.active

    ws['F6'] = header['client_name']
    for kind, row in _WORK_KIND_ROW.items():
        ws[f'F{row}'] = f'[ √ ]{kind}' if kind == header['work_kind'] else f'[    ]{kind}'
    ws['W6'] = header['material_cost'] + header['government_material_cost'] + header['direct_labor_cost'] + header['other_cost']
    ws['W7'] = header['material_cost']
    ws['W8'] = header['government_material_cost'] or None
    ws['W9'] = header['direct_labor_cost']
    ws['W10'] = header['other_cost'] or None
    ws['F11'] = header['budget_amount']

    prepared = header['prepared_date']
    if prepared:
        y, m, d = prepared.split('-')
        date_line = f'{y}년   {m} 월   {d} 일'
    else:
        date_line = ''
    ws['A26'] = f"{date_line}\n\n\n작성자 직책 : {header['preparer_title']}              성명: {header['preparer_name']} (서명 또는 인)"

    lines_by_category: dict[str, list[dict[str, Any]]] = {code: [] for code in CATEGORY_CODES}
    for line in lines:
        lines_by_category[line['category']].append(line)

    for category, (start_row, capacity) in TEMPLATE_CATEGORY_ROWS.items():
        # The template ships with sample rows filled in; wipe this category's block first
        # so stale example data never leaks into an export of the app's actual current state.
        for offset in range(capacity):
            _clear_detail_row(ws, start_row + offset)
        entries = lines_by_category[category]
        if not entries:
            continue
        if len(entries) <= capacity:
            for offset, line in enumerate(entries):
                _fill_detail_row(ws, start_row + offset, line)
        else:
            # More entries than the printed form has rows for: fill rows 1..capacity-1
            # individually and fold everything else into the last row as a combined total.
            for offset in range(capacity - 1):
                _fill_detail_row(ws, start_row + offset, entries[offset])
            remainder = entries[capacity - 1:]
            combined = {
                'description': f"{remainder[0]['description']} 외 {len(remainder) - 1}건" if remainder[0]['description'] else '기타',
                'unit': remainder[0]['unit'] if len({r['unit'] for r in remainder}) == 1 else '',
                'quantity': sum(r['quantity'] for r in remainder) if len({r['unit'] for r in remainder}) == 1 else '',
                'amount': sum(r['amount'] for r in remainder),
                'basis': '붙임(사용내역 상세) 참조',
                'usage_period': remainder[0]['usage_period'],
            }
            _fill_detail_row(ws, start_row + capacity - 1, combined)

    buffer = io.BytesIO()
    wb.save(buffer)
    file_name = f"산업안전보건관리비_사용계획서_{date.today():%Y%m%d}.xlsx"
    return buffer.getvalue(), file_name
