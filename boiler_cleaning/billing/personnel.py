"""Personnel roster and settlement/invoice tracking (agency-dispatched vs direct-hire)."""
from __future__ import annotations

import sqlite3
import uuid
from contextlib import closing
from datetime import date, datetime
from pathlib import Path
from typing import Any

import db
from . import service

EMPLOYMENT_TYPES = ('AGENCY', 'DIRECT')
STATUSES = ('청구예정', '청구완료', '입금대기', '입금완료')
PERSONNEL_STATUSES = ('재직중', '계약종료')
MAX_FILE = 15 * 1024 * 1024


def migrate():
    with service.connection() as conn:
        found = {r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        needed = {'personnel', 'personnel_settlements', 'personnel_attachments', 'settlement_attachments'}
        if needed.issubset(found):
            return None
        backup_dir = db.DATA_DIR / 'backups'
        backup_dir.mkdir(parents=True, exist_ok=True)
        backup = backup_dir / f'personnel_v1_{datetime.now():%Y%m%d_%H%M%S}_{uuid.uuid4().hex[:8]}.db'
        with closing(sqlite3.connect(backup)) as target:
            conn.backup(target)
            if target.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
                raise RuntimeError('인원 관리 DB 백업 검증에 실패했습니다.')
        conn.execute('BEGIN IMMEDIATE')
        conn.execute('''CREATE TABLE IF NOT EXISTS personnel (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
            employment_type TEXT NOT NULL CHECK(employment_type IN ('AGENCY','DIRECT')),
            agency_name TEXT NOT NULL DEFAULT '', role TEXT NOT NULL DEFAULT '',
            daily_rate INTEGER NOT NULL DEFAULT 0, phone TEXT NOT NULL DEFAULT '',
            contract_start TEXT NOT NULL DEFAULT '', contract_end TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT '재직중' CHECK(status IN ('재직중','계약종료')),
            note TEXT NOT NULL DEFAULT '', revision INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''')
        conn.execute('''CREATE TABLE IF NOT EXISTS personnel_settlements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            personnel_id INTEGER NOT NULL REFERENCES personnel(id) ON DELETE CASCADE,
            period_start TEXT NOT NULL, period_end TEXT NOT NULL DEFAULT '',
            work_days REAL NOT NULL DEFAULT 0, amount INTEGER NOT NULL DEFAULT 0,
            document_no TEXT NOT NULL DEFAULT '', document_date TEXT NOT NULL DEFAULT '',
            payment_date TEXT NOT NULL DEFAULT '', payment_amount INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT '청구예정' CHECK(status IN ('청구예정','청구완료','입금대기','입금완료')),
            note TEXT NOT NULL DEFAULT '', revision INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''')
        conn.execute('''CREATE TABLE IF NOT EXISTS personnel_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            personnel_id INTEGER NOT NULL REFERENCES personnel(id) ON DELETE CASCADE,
            stored_path TEXT NOT NULL UNIQUE, original_name TEXT NOT NULL, mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL CHECK(size_bytes > 0), description TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL)''')
        conn.execute('''CREATE TABLE IF NOT EXISTS settlement_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            settlement_id INTEGER NOT NULL REFERENCES personnel_settlements(id) ON DELETE CASCADE,
            stored_path TEXT NOT NULL UNIQUE, original_name TEXT NOT NULL, mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL CHECK(size_bytes > 0), description TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL)''')
        conn.execute('CREATE INDEX IF NOT EXISTS personnel_settlements_person ON personnel_settlements(personnel_id)')
        conn.execute('CREATE INDEX IF NOT EXISTS personnel_attachments_person ON personnel_attachments(personnel_id)')
        conn.execute('CREATE INDEX IF NOT EXISTS settlement_attachments_settlement ON settlement_attachments(settlement_id)')
        return str(backup)


# ---------- Personnel ----------

def _personnel_dict(row, attachments, settlements) -> dict[str, Any]:
    person = dict(row)
    person['attachments'] = attachments
    person['settlements'] = settlements
    return person


def list_personnel() -> list[dict[str, Any]]:
    with service.connection() as conn:
        rows = conn.execute('SELECT * FROM personnel ORDER BY status, name').fetchall()
        result = []
        for row in rows:
            attachments = [dict(a) for a in conn.execute(
                'SELECT id,original_name,mime_type,size_bytes,description,created_at FROM personnel_attachments WHERE personnel_id=? ORDER BY id', (row['id'],))]
            settlements = [dict(s) for s in conn.execute(
                'SELECT * FROM personnel_settlements WHERE personnel_id=? ORDER BY period_start, id', (row['id'],))]
            for s in settlements:
                s['attachments'] = [dict(a) for a in conn.execute(
                    'SELECT id,original_name,mime_type,size_bytes,description,created_at FROM settlement_attachments WHERE settlement_id=? ORDER BY id', (s['id'],))]
            result.append(_personnel_dict(row, attachments, settlements))
        return result


def summary() -> dict[str, Any]:
    people = list_personnel()
    total_paid = sum(s['payment_amount'] for p in people for s in p['settlements'])
    total_billed = sum(s['amount'] for p in people for s in p['settlements'] if s['status'] != '청구예정')
    by_type = {t: sum(s['amount'] for p in people if p['employment_type'] == t for s in p['settlements']) for t in EMPLOYMENT_TYPES}
    return {'people': people, 'employment_types': EMPLOYMENT_TYPES, 'statuses': STATUSES,
            'personnel_statuses': PERSONNEL_STATUSES, 'total_billed': total_billed, 'total_paid': total_paid,
            'by_employment_type': by_type, 'active_count': sum(p['status'] == '재직중' for p in people)}


def _validate_personnel(payload: dict[str, Any], existing: dict[str, Any] | None) -> dict[str, Any]:
    name = str(payload.get('name', existing['name'] if existing else '')).strip()
    if not name or len(name) > 100:
        raise ValueError('성명을 1~100자로 입력하세요.')
    employment_type = str(payload.get('employment_type', existing['employment_type'] if existing else 'DIRECT')).strip()
    if employment_type not in EMPLOYMENT_TYPES:
        raise ValueError('소속 구분을 확인하세요.')
    status = str(payload.get('status', existing['status'] if existing else PERSONNEL_STATUSES[0])).strip()
    if status not in PERSONNEL_STATUSES:
        raise ValueError('재직 상태를 확인하세요.')
    try:
        daily_rate = int(payload.get('daily_rate', existing['daily_rate'] if existing else 0) or 0)
    except (TypeError, ValueError):
        raise ValueError('일당을 확인하세요.')
    values: dict[str, Any] = {'name': name, 'employment_type': employment_type, 'status': status, 'daily_rate': daily_rate}
    for key, limit in [('agency_name', 200), ('role', 100), ('phone', 30), ('note', 2000)]:
        value = str(payload.get(key, existing[key] if existing else '') or '').strip()
        if len(value) > limit:
            raise ValueError('입력 길이를 확인하세요.')
        values[key] = value
    for key in ('contract_start', 'contract_end'):
        value = str(payload.get(key, existing[key] if existing else '') or '').strip()
        if value:
            date.fromisoformat(value)
        values[key] = value
    return values


def create_personnel(payload: dict[str, Any]) -> dict[str, Any]:
    values = _validate_personnel(payload, None)
    now = db.now_text()
    with service.connection() as conn:
        cursor = conn.execute('''INSERT INTO personnel
            (name,employment_type,agency_name,role,daily_rate,phone,contract_start,contract_end,status,note,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)''',
            (values['name'], values['employment_type'], values['agency_name'], values['role'], values['daily_rate'],
             values['phone'], values['contract_start'], values['contract_end'], values['status'], values['note'], now, now))
        row = conn.execute('SELECT * FROM personnel WHERE id=?', (cursor.lastrowid,)).fetchone()
        return _personnel_dict(row, [], [])


def update_personnel(personnel_id: int, payload: dict[str, Any]) -> dict[str, Any]:
    with service.connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row = conn.execute('SELECT * FROM personnel WHERE id=?', (personnel_id,)).fetchone()
        if not row:
            raise LookupError('인원 정보를 찾을 수 없습니다.')
        existing = dict(row)
        if payload.get('revision') != existing['revision']:
            raise service.Conflict('다른 화면에서 변경되었습니다. 목록을 새로고침한 뒤 다시 확인하세요.')
        values = _validate_personnel(payload, existing)
        now = db.now_text()
        conn.execute('''UPDATE personnel SET name=?,employment_type=?,agency_name=?,role=?,daily_rate=?,phone=?,
            contract_start=?,contract_end=?,status=?,note=?,revision=revision+1,updated_at=? WHERE id=?''',
            (values['name'], values['employment_type'], values['agency_name'], values['role'], values['daily_rate'],
             values['phone'], values['contract_start'], values['contract_end'], values['status'], values['note'], now, personnel_id))
        row = conn.execute('SELECT * FROM personnel WHERE id=?', (personnel_id,)).fetchone()
        attachments = [dict(a) for a in conn.execute(
            'SELECT id,original_name,mime_type,size_bytes,description,created_at FROM personnel_attachments WHERE personnel_id=? ORDER BY id', (personnel_id,))]
        settlements = [dict(s) for s in conn.execute('SELECT * FROM personnel_settlements WHERE personnel_id=? ORDER BY period_start, id', (personnel_id,))]
        return _personnel_dict(row, attachments, settlements)


def delete_personnel(personnel_id: int) -> bool:
    with service.connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row = conn.execute('SELECT id FROM personnel WHERE id=?', (personnel_id,)).fetchone()
        if not row:
            return False
        settlement_ids = [r['id'] for r in conn.execute('SELECT id FROM personnel_settlements WHERE personnel_id=?', (personnel_id,))]
        for sid in settlement_ids:
            for a in conn.execute('SELECT stored_path FROM settlement_attachments WHERE settlement_id=?', (sid,)):
                (settlement_attachment_root() / a['stored_path']).unlink(missing_ok=True)
        for a in conn.execute('SELECT stored_path FROM personnel_attachments WHERE personnel_id=?', (personnel_id,)):
            (personnel_attachment_root() / a['stored_path']).unlink(missing_ok=True)
        conn.execute('DELETE FROM personnel WHERE id=?', (personnel_id,))
        return True


# ---------- Settlements (계산서/정산 기록) ----------

def _validate_settlement(payload: dict[str, Any], existing: dict[str, Any] | None) -> dict[str, Any]:
    personnel_id = payload.get('personnel_id', existing['personnel_id'] if existing else None)
    try:
        personnel_id = int(personnel_id)
    except (TypeError, ValueError):
        raise ValueError('인원을 확인하세요.')
    with service.connection() as conn:
        if not conn.execute('SELECT 1 FROM personnel WHERE id=?', (personnel_id,)).fetchone():
            raise ValueError('인원을 확인하세요.')
    period_start = str(payload.get('period_start', existing['period_start'] if existing else '')).strip()
    if not period_start:
        raise ValueError('정산 시작일을 입력하세요.')
    date.fromisoformat(period_start)
    period_end = str(payload.get('period_end', existing['period_end'] if existing else '') or '').strip()
    if period_end:
        date.fromisoformat(period_end)
    try:
        work_days = float(payload.get('work_days', existing['work_days'] if existing else 0) or 0)
    except (TypeError, ValueError):
        raise ValueError('근무일수를 확인하세요.')
    amount = payload.get('amount')
    amount = int(amount) if amount not in (None, '') else 0
    status = payload.get('status', existing['status'] if existing else STATUSES[0])
    if status not in STATUSES:
        raise ValueError('상태를 확인하세요.')
    payment_amount = payload.get('payment_amount', existing['payment_amount'] if existing else 0)
    payment_amount = int(payment_amount) if payment_amount not in (None, '') else 0
    values: dict[str, Any] = {'personnel_id': personnel_id, 'period_start': period_start, 'period_end': period_end,
                               'work_days': work_days, 'amount': amount, 'status': status, 'payment_amount': payment_amount}
    for key, limit in [('document_no', 100), ('document_date', 10), ('payment_date', 10), ('note', 2000)]:
        value = str(payload.get(key, existing[key] if existing else '') or '').strip()
        if len(value) > limit:
            raise ValueError('입력 길이를 확인하세요.')
        if key.endswith('_date') and value:
            date.fromisoformat(value)
        values[key] = value
    return values


def create_settlement(payload: dict[str, Any]) -> dict[str, Any]:
    values = _validate_settlement(payload, None)
    now = db.now_text()
    with service.connection() as conn:
        cursor = conn.execute('''INSERT INTO personnel_settlements
            (personnel_id,period_start,period_end,work_days,amount,document_no,document_date,payment_date,
             payment_amount,status,note,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)''',
            (values['personnel_id'], values['period_start'], values['period_end'], values['work_days'], values['amount'],
             values['document_no'], values['document_date'], values['payment_date'], values['payment_amount'],
             values['status'], values['note'], now, now))
        row = conn.execute('SELECT * FROM personnel_settlements WHERE id=?', (cursor.lastrowid,)).fetchone()
        result = dict(row)
        result['attachments'] = []
        return result


def update_settlement(settlement_id: int, payload: dict[str, Any]) -> dict[str, Any]:
    with service.connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row = conn.execute('SELECT * FROM personnel_settlements WHERE id=?', (settlement_id,)).fetchone()
        if not row:
            raise LookupError('정산 기록을 찾을 수 없습니다.')
        existing = dict(row)
        if payload.get('revision') != existing['revision']:
            raise service.Conflict('다른 화면에서 변경되었습니다. 목록을 새로고침한 뒤 다시 확인하세요.')
        values = _validate_settlement(payload, existing)
        now = db.now_text()
        conn.execute('''UPDATE personnel_settlements SET personnel_id=?,period_start=?,period_end=?,work_days=?,
            amount=?,document_no=?,document_date=?,payment_date=?,payment_amount=?,status=?,note=?,
            revision=revision+1,updated_at=? WHERE id=?''',
            (values['personnel_id'], values['period_start'], values['period_end'], values['work_days'], values['amount'],
             values['document_no'], values['document_date'], values['payment_date'], values['payment_amount'],
             values['status'], values['note'], now, settlement_id))
        row = conn.execute('SELECT * FROM personnel_settlements WHERE id=?', (settlement_id,)).fetchone()
        result = dict(row)
        result['attachments'] = [dict(a) for a in conn.execute(
            'SELECT id,original_name,mime_type,size_bytes,description,created_at FROM settlement_attachments WHERE settlement_id=? ORDER BY id', (settlement_id,))]
        return result


def delete_settlement(settlement_id: int) -> bool:
    with service.connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row = conn.execute('SELECT id FROM personnel_settlements WHERE id=?', (settlement_id,)).fetchone()
        if not row:
            return False
        attachments = conn.execute('SELECT stored_path FROM settlement_attachments WHERE settlement_id=?', (settlement_id,)).fetchall()
        conn.execute('DELETE FROM personnel_settlements WHERE id=?', (settlement_id,))
        for a in attachments:
            (settlement_attachment_root() / a['stored_path']).unlink(missing_ok=True)
        return True


# ---------- Attachments ----------

def personnel_attachment_root() -> Path:
    root = (db.STORAGE_DIR / 'personnel').resolve()
    root.mkdir(parents=True, exist_ok=True)
    return root


def settlement_attachment_root() -> Path:
    root = (db.STORAGE_DIR / 'personnel-settlements').resolve()
    root.mkdir(parents=True, exist_ok=True)
    return root


def _save_attachment(root: Path, owner_table: str, owner_id_col: str, owner_id: int, target_table: str,
                      name: str, content: bytes, description: str) -> dict[str, Any]:
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
    destination = root / (uuid.uuid4().hex + suffix)
    try:
        with service.connection() as conn:
            conn.execute('BEGIN IMMEDIATE')
            if not conn.execute(f'SELECT id FROM {owner_table} WHERE id=?', (owner_id,)).fetchone():
                raise LookupError('대상을 찾을 수 없습니다.')
            with destination.open('xb') as stream:
                stream.write(content)
            cursor = conn.execute(f'''INSERT INTO {target_table}
                ({owner_id_col},stored_path,original_name,mime_type,size_bytes,description,created_at)
                VALUES (?,?,?,?,?,?,?)''',
                (owner_id, destination.name, name, mime, len(content), description, db.now_text()))
            return {'id': cursor.lastrowid, 'original_name': name, 'mime_type': mime, 'size_bytes': len(content)}
    except Exception:
        destination.unlink(missing_ok=True)
        raise


def save_personnel_attachment(personnel_id: int, name: str, content: bytes, description: str = '') -> dict[str, Any]:
    return _save_attachment(personnel_attachment_root(), 'personnel', 'personnel_id', personnel_id, 'personnel_attachments', name, content, description)


def save_settlement_attachment(settlement_id: int, name: str, content: bytes, description: str = '') -> dict[str, Any]:
    return _save_attachment(settlement_attachment_root(), 'personnel_settlements', 'settlement_id', settlement_id, 'settlement_attachments', name, content, description)


def _get_attachment(root: Path, table: str, attachment_id: int) -> tuple[Path, dict[str, Any]]:
    with service.connection() as conn:
        row = conn.execute(f'SELECT * FROM {table} WHERE id=?', (attachment_id,)).fetchone()
    if not row:
        raise LookupError('첨부파일을 찾을 수 없습니다.')
    path = (root / row['stored_path']).resolve()
    if root not in path.parents or not path.is_file():
        raise LookupError('첨부파일을 찾을 수 없습니다.')
    return path, dict(row)


def get_personnel_attachment(attachment_id: int) -> tuple[Path, dict[str, Any]]:
    return _get_attachment(personnel_attachment_root(), 'personnel_attachments', attachment_id)


def get_settlement_attachment(attachment_id: int) -> tuple[Path, dict[str, Any]]:
    return _get_attachment(settlement_attachment_root(), 'settlement_attachments', attachment_id)
