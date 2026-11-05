"""Actual internal cost execution tracking (separate from client billing)."""
from __future__ import annotations

import sqlite3
import uuid
from contextlib import closing
from datetime import date, datetime
from pathlib import Path
from typing import Any

import db
from preparation.cost_analysis import SUMMARY as COST_SUMMARY
from . import service

CATEGORIES = ('재료비', '노무비', '경비', '기타')
MAX_FILE = 15 * 1024 * 1024


def migrate():
    with service.connection() as conn:
        found = {r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        if {'expenditure_lines', 'expenditure_attachments'}.issubset(found):
            return None
        backup_dir = db.DATA_DIR / 'backups'
        backup_dir.mkdir(parents=True, exist_ok=True)
        backup = backup_dir / f'expenditure_v1_{datetime.now():%Y%m%d_%H%M%S}_{uuid.uuid4().hex[:8]}.db'
        with closing(sqlite3.connect(backup)) as target:
            conn.backup(target)
            if target.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
                raise RuntimeError('집행 관리 DB 백업 검증에 실패했습니다.')
        conn.execute('BEGIN IMMEDIATE')
        conn.execute('''CREATE TABLE IF NOT EXISTS expenditure_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            work_type TEXT NOT NULL CHECK(work_type IN ('COMMON','BOILER','ECONOMIZER','SDR','BF','SCR')),
            category TEXT NOT NULL CHECK(category IN ('재료비','노무비','경비','기타')),
            expense_date TEXT NOT NULL, description TEXT NOT NULL DEFAULT '',
            quantity REAL NOT NULL DEFAULT 1, unit TEXT NOT NULL DEFAULT '',
            unit_price INTEGER NOT NULL DEFAULT 0, amount INTEGER NOT NULL DEFAULT 0,
            payee TEXT NOT NULL DEFAULT '', receipt_no TEXT NOT NULL DEFAULT '',
            payment_method TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '',
            revision INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''')
        conn.execute('''CREATE TABLE IF NOT EXISTS expenditure_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            line_id INTEGER NOT NULL REFERENCES expenditure_lines(id) ON DELETE CASCADE,
            stored_path TEXT NOT NULL UNIQUE, original_name TEXT NOT NULL, mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL CHECK(size_bytes > 0), description TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL)''')
        conn.execute('CREATE INDEX IF NOT EXISTS expenditure_lines_work_type ON expenditure_lines(work_type)')
        conn.execute('CREATE INDEX IF NOT EXISTS expenditure_attachments_line ON expenditure_attachments(line_id)')
        return str(backup)


def _line_dict(row, attachments) -> dict[str, Any]:
    line = dict(row)
    line['attachments'] = attachments
    return line


def list_lines() -> list[dict[str, Any]]:
    with service.connection() as conn:
        rows = conn.execute('SELECT * FROM expenditure_lines ORDER BY expense_date, id').fetchall()
        result = []
        for row in rows:
            attachments = [dict(a) for a in conn.execute(
                'SELECT id,original_name,mime_type,size_bytes,description,created_at FROM expenditure_attachments WHERE line_id=? ORDER BY id', (row['id'],))]
            result.append(_line_dict(row, attachments))
        return result


def summary() -> dict[str, Any]:
    lines = list_lines()
    category_totals = {c: sum(l['amount'] for l in lines if l['category'] == c) for c in CATEGORIES}
    facility_totals: dict[str, int] = {}
    for line in lines:
        facility_totals[line['work_type']] = facility_totals.get(line['work_type'], 0) + line['amount']
    total = sum(l['amount'] for l in lines)
    budget = {'material_cost': COST_SUMMARY['material_cost'], 'labor_cost': COST_SUMMARY['labor_cost'],
              'expense_cost': COST_SUMMARY['expense_cost'], 'total_cost': COST_SUMMARY['total_cost']}
    budget_map = {'재료비': 'material_cost', '노무비': 'labor_cost', '경비': 'expense_cost'}
    variance = {cat: budget[budget_map[cat]] - category_totals[cat] for cat in budget_map}
    return {'lines': lines, 'categories': CATEGORIES, 'category_totals': category_totals,
            'facility_totals': facility_totals, 'total': total, 'budget': budget, 'variance': variance}


def _validate_payload(payload: dict[str, Any], existing: dict[str, Any] | None) -> dict[str, Any]:
    work_type = str(payload.get('work_type', existing['work_type'] if existing else 'COMMON')).strip()
    if work_type not in ('COMMON', 'BOILER', 'ECONOMIZER', 'SDR', 'BF', 'SCR'):
        raise ValueError('설비 구분을 확인하세요.')
    category = str(payload.get('category', existing['category'] if existing else CATEGORIES[0])).strip()
    if category not in CATEGORIES:
        raise ValueError('비목(재료비·노무비·경비·기타)을 확인하세요.')
    expense_date = str(payload.get('expense_date', existing['expense_date'] if existing else '')).strip()
    if not expense_date:
        raise ValueError('집행일자를 입력하세요.')
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
    values: dict[str, Any] = {'work_type': work_type, 'category': category, 'expense_date': expense_date,
                               'quantity': quantity, 'unit_price': unit_price, 'amount': amount}
    for key, limit in [('description', 500), ('unit', 20), ('payee', 200), ('receipt_no', 100), ('payment_method', 50), ('note', 2000)]:
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
        cursor = conn.execute('''INSERT INTO expenditure_lines
            (work_type,category,expense_date,description,quantity,unit,unit_price,amount,payee,receipt_no,payment_method,note,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)''',
            (values['work_type'], values['category'], values['expense_date'], values['description'], values['quantity'],
             values['unit'], values['unit_price'], values['amount'], values['payee'], values['receipt_no'],
             values['payment_method'], values['note'], now, now))
        row = conn.execute('SELECT * FROM expenditure_lines WHERE id=?', (cursor.lastrowid,)).fetchone()
        return _line_dict(row, [])


def update_line(line_id: int, payload: dict[str, Any]) -> dict[str, Any]:
    with service.connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row = conn.execute('SELECT * FROM expenditure_lines WHERE id=?', (line_id,)).fetchone()
        if not row:
            raise LookupError('집행 기록을 찾을 수 없습니다.')
        existing = dict(row)
        if payload.get('revision') != existing['revision']:
            raise service.Conflict('다른 화면에서 변경되었습니다. 목록을 새로고침한 뒤 다시 확인하세요.')
        values = _validate_payload(payload, existing)
        now = db.now_text()
        conn.execute('''UPDATE expenditure_lines SET work_type=?,category=?,expense_date=?,description=?,quantity=?,
            unit=?,unit_price=?,amount=?,payee=?,receipt_no=?,payment_method=?,note=?,revision=revision+1,updated_at=?
            WHERE id=?''',
            (values['work_type'], values['category'], values['expense_date'], values['description'], values['quantity'],
             values['unit'], values['unit_price'], values['amount'], values['payee'], values['receipt_no'],
             values['payment_method'], values['note'], now, line_id))
        row = conn.execute('SELECT * FROM expenditure_lines WHERE id=?', (line_id,)).fetchone()
        attachments = [dict(a) for a in conn.execute(
            'SELECT id,original_name,mime_type,size_bytes,description,created_at FROM expenditure_attachments WHERE line_id=? ORDER BY id', (line_id,))]
        return _line_dict(row, attachments)


def delete_line(line_id: int) -> bool:
    with service.connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row = conn.execute('SELECT id FROM expenditure_lines WHERE id=?', (line_id,)).fetchone()
        if not row:
            return False
        attachments = conn.execute('SELECT stored_path FROM expenditure_attachments WHERE line_id=?', (line_id,)).fetchall()
        conn.execute('DELETE FROM expenditure_lines WHERE id=?', (line_id,))
        for a in attachments:
            (attachment_root() / a['stored_path']).unlink(missing_ok=True)
        return True


def attachment_root() -> Path:
    root = (db.STORAGE_DIR / 'expenditure').resolve()
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
            line = conn.execute('SELECT id FROM expenditure_lines WHERE id=?', (line_id,)).fetchone()
            if not line:
                raise LookupError('집행 기록을 찾을 수 없습니다.')
            with destination.open('xb') as stream:
                stream.write(content)
            cursor = conn.execute('''INSERT INTO expenditure_attachments
                (line_id,stored_path,original_name,mime_type,size_bytes,description,created_at)
                VALUES (?,?,?,?,?,?,?)''',
                (line_id, destination.name, name, mime, len(content), description, db.now_text()))
            return {'id': cursor.lastrowid, 'original_name': name, 'mime_type': mime, 'size_bytes': len(content)}
    except Exception:
        destination.unlink(missing_ok=True)
        raise


def get_attachment(attachment_id: int) -> tuple[Path, dict[str, Any]]:
    with service.connection() as conn:
        row = conn.execute('SELECT * FROM expenditure_attachments WHERE id=?', (attachment_id,)).fetchone()
    if not row:
        raise LookupError('첨부파일을 찾을 수 없습니다.')
    root = attachment_root()
    path = (root / row['stored_path']).resolve()
    if root not in path.parents or not path.is_file():
        raise LookupError('첨부파일을 찾을 수 없습니다.')
    return path, dict(row)
