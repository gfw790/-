from __future__ import annotations

import sqlite3
import uuid
from contextlib import closing, contextmanager
from datetime import date, datetime
from pathlib import Path
from typing import Any

import db
from preparation.cost_analysis import FACILITIES, SUMMARY

STATUSES = ('청구예정', '청구완료', '입금대기', '입금완료')
MAX_FILE = 15 * 1024 * 1024
QUANTITY_LIMIT = 4  # sessions budgeted per facility for the contract period (2기 x 연 2회)


class Conflict(ValueError):
    pass


@contextmanager
def connection():
    conn = sqlite3.connect(db.DB_PATH, timeout=15)
    conn.row_factory = sqlite3.Row
    conn.execute('PRAGMA foreign_keys=ON')
    try:
        with conn:
            yield conn
    finally:
        conn.close()


def migrate():
    with connection() as conn:
        found = {r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        if {'billing_lines', 'billing_attachments'}.issubset(found):
            return None
        backup_dir = db.DATA_DIR / 'backups'
        backup_dir.mkdir(parents=True, exist_ok=True)
        backup = backup_dir / f'billing_v1_{datetime.now():%Y%m%d_%H%M%S}_{uuid.uuid4().hex[:8]}.db'
        with closing(sqlite3.connect(backup)) as target:
            conn.backup(target)
            if target.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
                raise RuntimeError('공사 금액 관리 DB 백업 검증에 실패했습니다.')
        conn.execute('BEGIN IMMEDIATE')
        conn.execute('''CREATE TABLE IF NOT EXISTS billing_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            work_type TEXT NOT NULL CHECK(work_type IN ('BOILER','ECONOMIZER','SDR','BF','SCR')),
            execution_date TEXT NOT NULL, quantity REAL NOT NULL DEFAULT 1,
            unit_price INTEGER NOT NULL DEFAULT 0, amount INTEGER NOT NULL DEFAULT 0,
            invoice_no TEXT NOT NULL DEFAULT '', invoice_date TEXT NOT NULL DEFAULT '',
            request_date TEXT NOT NULL DEFAULT '', payment_date TEXT NOT NULL DEFAULT '',
            payment_amount INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT '청구예정' CHECK(status IN ('청구예정','청구완료','입금대기','입금완료')),
            note TEXT NOT NULL DEFAULT '', revision INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''')
        conn.execute('''CREATE TABLE IF NOT EXISTS billing_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            line_id INTEGER NOT NULL REFERENCES billing_lines(id) ON DELETE CASCADE,
            stored_path TEXT NOT NULL UNIQUE, original_name TEXT NOT NULL, mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL CHECK(size_bytes > 0), description TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL)''')
        conn.execute('CREATE INDEX IF NOT EXISTS billing_lines_work_type ON billing_lines(work_type)')
        conn.execute('CREATE INDEX IF NOT EXISTS billing_attachments_line ON billing_attachments(line_id)')
        return str(backup)


def facility_contract(work_type: str) -> dict[str, Any]:
    facility = next((f for f in FACILITIES if f['work_type'] == work_type), None)
    if not facility:
        raise ValueError('설비 구분을 확인하세요.')
    return facility


def _line_dict(row, attachments) -> dict[str, Any]:
    line = dict(row)
    line['attachments'] = attachments
    return line


def list_lines() -> list[dict[str, Any]]:
    with connection() as conn:
        rows = conn.execute('SELECT * FROM billing_lines ORDER BY execution_date, id').fetchall()
        result = []
        for row in rows:
            attachments = [dict(a) for a in conn.execute(
                'SELECT id,original_name,mime_type,size_bytes,description,created_at FROM billing_attachments WHERE line_id=? ORDER BY id', (row['id'],))]
            result.append(_line_dict(row, attachments))
        return result


def summary() -> dict[str, Any]:
    lines = list_lines()
    per_facility = {}
    for facility in FACILITIES:
        wt = facility['work_type']
        unit_price = facility['total'] // QUANTITY_LIMIT
        matched = [l for l in lines if l['work_type'] == wt]
        executed_qty = sum(l['quantity'] for l in matched)
        billed_amount = sum(l['amount'] for l in matched if l['status'] != '청구예정')
        received_amount = sum(l['payment_amount'] for l in matched)
        per_facility[wt] = {
            'name': facility['name'], 'work_type': wt, 'contract_quantity': QUANTITY_LIMIT,
            'unit_price': unit_price, 'contract_amount': facility['total'],
            'executed_quantity': executed_qty, 'remaining_quantity': QUANTITY_LIMIT - executed_qty,
            'billed_amount': billed_amount, 'received_amount': received_amount,
            'remaining_amount': facility['total'] - billed_amount,
        }
    total_billed = sum(f['billed_amount'] for f in per_facility.values())
    total_received = sum(f['received_amount'] for f in per_facility.values())
    return {
        'contract_amount': SUMMARY['contract_amount'], 'facilities': list(per_facility.values()),
        'total_billed': total_billed, 'total_received': total_received,
        'remaining_amount': SUMMARY['contract_amount'] - total_billed,
        'lines': lines, 'statuses': STATUSES, 'quantity_limit': QUANTITY_LIMIT,
    }


def _validate_payload(payload: dict[str, Any], existing: dict[str, Any] | None) -> dict[str, Any]:
    work_type = payload.get('work_type', existing['work_type'] if existing else None)
    facility = facility_contract(work_type)
    execution_date = str(payload.get('execution_date', existing['execution_date'] if existing else '')).strip()
    if not execution_date:
        raise ValueError('수행일자를 입력하세요.')
    date.fromisoformat(execution_date)
    try:
        quantity = float(payload.get('quantity', existing['quantity'] if existing else 1))
    except (TypeError, ValueError):
        raise ValueError('수량을 확인하세요.')
    if quantity <= 0 or quantity > QUANTITY_LIMIT:
        raise ValueError(f'수량은 0보다 크고 {QUANTITY_LIMIT} 이하여야 합니다.')
    default_unit_price = facility['total'] // QUANTITY_LIMIT
    try:
        unit_price = int(payload.get('unit_price', existing['unit_price'] if existing else default_unit_price) or default_unit_price)
    except (TypeError, ValueError):
        raise ValueError('단가를 확인하세요.')
    amount = payload.get('amount')
    amount = int(amount) if amount not in (None, '') else round(unit_price * quantity)
    status = payload.get('status', existing['status'] if existing else STATUSES[0])
    if status not in STATUSES:
        raise ValueError('청구 상태를 확인하세요.')
    payment_amount = payload.get('payment_amount', existing['payment_amount'] if existing else 0)
    payment_amount = int(payment_amount) if payment_amount not in (None, '') else 0
    values: dict[str, Any] = {
        'work_type': work_type, 'execution_date': execution_date, 'quantity': quantity,
        'unit_price': unit_price, 'amount': amount, 'status': status, 'payment_amount': payment_amount,
    }
    for key, limit in [('invoice_no', 100), ('invoice_date', 10), ('request_date', 10), ('payment_date', 10), ('note', 2000)]:
        value = payload.get(key, existing[key] if existing else '')
        value = str(value or '').strip()
        if len(value) > limit:
            raise ValueError('입력 길이를 확인하세요.')
        if key.endswith('_date') and value:
            date.fromisoformat(value)
        values[key] = value
    return values


def create_line(payload: dict[str, Any]) -> dict[str, Any]:
    values = _validate_payload(payload, None)
    now = db.now_text()
    with connection() as conn:
        cursor = conn.execute('''INSERT INTO billing_lines
            (work_type,execution_date,quantity,unit_price,amount,invoice_no,invoice_date,request_date,
             payment_date,payment_amount,status,note,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)''',
            (values['work_type'], values['execution_date'], values['quantity'], values['unit_price'], values['amount'],
             values['invoice_no'], values['invoice_date'], values['request_date'], values['payment_date'],
             values['payment_amount'], values['status'], values['note'], now, now))
        line_id = cursor.lastrowid
        row = conn.execute('SELECT * FROM billing_lines WHERE id=?', (line_id,)).fetchone()
        return _line_dict(row, [])


def update_line(line_id: int, payload: dict[str, Any]) -> dict[str, Any]:
    with connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row = conn.execute('SELECT * FROM billing_lines WHERE id=?', (line_id,)).fetchone()
        if not row:
            raise LookupError('청구 기록을 찾을 수 없습니다.')
        existing = dict(row)
        if payload.get('revision') != existing['revision']:
            raise Conflict('다른 화면에서 변경되었습니다. 목록을 새로고침한 뒤 다시 확인하세요.')
        values = _validate_payload(payload, existing)
        now = db.now_text()
        conn.execute('''UPDATE billing_lines SET work_type=?,execution_date=?,quantity=?,unit_price=?,amount=?,
            invoice_no=?,invoice_date=?,request_date=?,payment_date=?,payment_amount=?,status=?,note=?,
            revision=revision+1,updated_at=? WHERE id=?''',
            (values['work_type'], values['execution_date'], values['quantity'], values['unit_price'], values['amount'],
             values['invoice_no'], values['invoice_date'], values['request_date'], values['payment_date'],
             values['payment_amount'], values['status'], values['note'], now, line_id))
        row = conn.execute('SELECT * FROM billing_lines WHERE id=?', (line_id,)).fetchone()
        attachments = [dict(a) for a in conn.execute(
            'SELECT id,original_name,mime_type,size_bytes,description,created_at FROM billing_attachments WHERE line_id=? ORDER BY id', (line_id,))]
        return _line_dict(row, attachments)


def delete_line(line_id: int) -> bool:
    with connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row = conn.execute('SELECT id FROM billing_lines WHERE id=?', (line_id,)).fetchone()
        if not row:
            return False
        attachments = conn.execute('SELECT stored_path FROM billing_attachments WHERE line_id=?', (line_id,)).fetchall()
        conn.execute('DELETE FROM billing_lines WHERE id=?', (line_id,))
        for a in attachments:
            (attachment_root() / a['stored_path']).unlink(missing_ok=True)
        return True


def attachment_root() -> Path:
    root = (db.STORAGE_DIR / 'billing').resolve()
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
        with connection() as conn:
            conn.execute('BEGIN IMMEDIATE')
            line = conn.execute('SELECT id FROM billing_lines WHERE id=?', (line_id,)).fetchone()
            if not line:
                raise LookupError('청구 기록을 찾을 수 없습니다.')
            with destination.open('xb') as stream:
                stream.write(content)
            cursor = conn.execute('''INSERT INTO billing_attachments
                (line_id,stored_path,original_name,mime_type,size_bytes,description,created_at)
                VALUES (?,?,?,?,?,?,?)''',
                (line_id, destination.name, name, mime, len(content), description, db.now_text()))
            return {'id': cursor.lastrowid, 'original_name': name, 'mime_type': mime, 'size_bytes': len(content)}
    except Exception:
        destination.unlink(missing_ok=True)
        raise


def get_attachment(attachment_id: int) -> tuple[Path, dict[str, Any]]:
    with connection() as conn:
        row = conn.execute('SELECT * FROM billing_attachments WHERE id=?', (attachment_id,)).fetchone()
    if not row:
        raise LookupError('첨부파일을 찾을 수 없습니다.')
    root = attachment_root()
    path = (root / row['stored_path']).resolve()
    if root not in path.parents or not path.is_file():
        raise LookupError('첨부파일을 찾을 수 없습니다.')
    return path, dict(row)
