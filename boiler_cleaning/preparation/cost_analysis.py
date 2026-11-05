"""Source-grounded cost breakdown review, cross-referenced against task_analysis findings."""
import json
import sqlite3
import uuid
from contextlib import closing
from datetime import datetime
from pathlib import Path

import db
from . import service

SOURCE_NAME = '계약내역서-폐열보일러 외 부대설비 세정 용역 단가계약.pdf'
STATUSES = ('미검토', '확인완료', '발주처 재확인', '현장확인 필요', '내부검토 필요', '계획회의 필요')

SUMMARY = {
    'material_cost': 14454992, 'labor_cost': 59080278, 'expense_cost': 9205066,
    'total_cost': 82740336, 'general_admin': 6619227, 'profit': 11235390,
    'supply_amount': 100594952, 'vat': 10059495, 'contract_amount': 110654447,
    'basis_note': '2026년 건축·산업환경설비공사 원가계산 간접공사비(제비율) 적용기준 2026.04.13.',
}

FACILITIES = [
    {'name': '01. 폐열보일러 세정', 'work_type': 'BOILER', 'quantity': '4식', 'total': 23697508, 'material': 2056098, 'labor': 8224392, 'expense': 96372},
    {'name': '02. 절탄기 세정', 'work_type': 'ECONOMIZER', 'quantity': '4식', 'total': 7438168, 'material': 0, 'labor': 7438168, 'expense': 0},
    {'name': '03. 반건식 반응탑 세정', 'work_type': 'SDR', 'quantity': '4식', 'total': 17891924, 'material': 1557650, 'labor': 11588336, 'expense': 72988},
    {'name': '04. 여과집진기 세정', 'work_type': 'BF', 'quantity': '4식', 'total': 7938920, 'material': 0, 'labor': 7938920, 'expense': 0},
    {'name': '05. 촉매환원탑 세정', 'work_type': 'SCR', 'quantity': '4식', 'total': 7938920, 'material': 0, 'labor': 7938920, 'expense': 0},
]


def migrate():
    with service.connection() as conn:
        if conn.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='prep_cost_analysis'").fetchone():
            return None
        folder = db.DATA_DIR / 'backups'
        folder.mkdir(parents=True, exist_ok=True)
        backup = folder / f'cost_analysis_v1_{datetime.now():%Y%m%d_%H%M%S}_{uuid.uuid4().hex[:8]}.db'
        with closing(sqlite3.connect(backup)) as target:
            conn.backup(target)
            if target.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
                raise RuntimeError('원가 분석 DB 백업 검증에 실패했습니다.')
        seeds = json.loads(Path(__file__).with_name('cost_analysis_seed.json').read_text(encoding='utf-8'))
        conn.execute('BEGIN IMMEDIATE')
        conn.execute('''CREATE TABLE prep_cost_analysis (
            id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE,
            work_type TEXT NOT NULL, category TEXT NOT NULL, title TEXT NOT NULL,
            cost_ref TEXT NOT NULL, requirement TEXT NOT NULL,
            related_task_code TEXT NOT NULL DEFAULT '', question TEXT NOT NULL, priority TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT '미검토', note TEXT NOT NULL DEFAULT '',
            owner TEXT NOT NULL DEFAULT '', due_date TEXT NOT NULL DEFAULT '',
            revision INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL)''')
        for item in seeds:
            conn.execute('''INSERT INTO prep_cost_analysis
                (code,work_type,category,title,cost_ref,requirement,related_task_code,question,priority,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?)''',
                tuple(item[k] for k in ('code','work_type','category','title','cost_ref','requirement','related_task_code','question','priority')) + (db.now_text(),))
        return str(backup)


def overview():
    with service.connection() as conn:
        items = [dict(r) for r in conn.execute('SELECT * FROM prep_cost_analysis ORDER BY id')]
    return {'items': items, 'statuses': STATUSES, 'source_name': SOURCE_NAME,
            'source_available': (db.BASE_DIR / SOURCE_NAME).is_file(),
            'summary': SUMMARY, 'facilities': FACILITIES,
            'review': {'total': len(items), 'reviewed': sum(i['status'] == '확인완료' for i in items),
                       'pending': sum(i['status'] not in ('미검토', '확인완료') for i in items)}}


def update(item_id, payload):
    status = payload.get('status')
    if status not in STATUSES: raise ValueError('검토 상태를 확인하세요.')
    values = {}
    for key, limit in [('note', 10000), ('owner', 100), ('due_date', 10)]:
        value = payload.get(key, '')
        if not isinstance(value, str) or len(value) > limit: raise ValueError('입력 길이와 형식을 확인하세요.')
        values[key] = value.strip()
    if values['due_date']:
        from datetime import date
        date.fromisoformat(values['due_date'])
    if status == '확인완료' and not values['note']:
        raise ValueError('확인완료로 저장하려면 확인 근거 또는 검토 결과를 메모에 입력하세요.')
    with service.connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row = conn.execute('SELECT * FROM prep_cost_analysis WHERE id=?', (item_id,)).fetchone()
        if not row: raise LookupError('원가 분석 항목을 찾을 수 없습니다.')
        if payload.get('revision') != row['revision']:
            raise service.Conflict('다른 화면에서 변경되었습니다. 목록을 새로고침한 뒤 다시 확인하세요.')
        conn.execute('''UPDATE prep_cost_analysis SET status=?,note=?,owner=?,due_date=?,
                     revision=revision+1,updated_at=? WHERE id=?''',
                     (status, values['note'], values['owner'], values['due_date'], db.now_text(), item_id))
        return dict(conn.execute('SELECT * FROM prep_cost_analysis WHERE id=?', (item_id,)).fetchone())


def source_file():
    path = (db.BASE_DIR / SOURCE_NAME).resolve()
    if path.parent != db.BASE_DIR.resolve() or not path.is_file():
        raise LookupError('원본 원가내역서 파일을 찾을 수 없습니다.')
    return path
