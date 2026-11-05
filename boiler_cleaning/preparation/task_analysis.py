"""Source-grounded task review, isolated from safety records and meeting answers."""
import json
import sqlite3
import uuid
from contextlib import closing
from datetime import datetime
from pathlib import Path

import db
from . import service

SOURCE_NAME = '2. 과업지시서.pdf'
STATUSES = ('미검토', '확인완료', '발주처 재확인', '현장확인 필요', '내부검토 필요', '계획회의 필요')


def migrate():
    with service.connection() as conn:
        if conn.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='prep_task_analysis'").fetchone():
            return None
        folder = db.DATA_DIR / 'backups'
        folder.mkdir(parents=True, exist_ok=True)
        backup = folder / f'task_analysis_v1_{datetime.now():%Y%m%d_%H%M%S}_{uuid.uuid4().hex[:8]}.db'
        with closing(sqlite3.connect(backup)) as target:
            conn.backup(target)
            if target.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
                raise RuntimeError('과업 분석 DB 백업 검증에 실패했습니다.')
        seeds = json.loads(Path(__file__).with_name('task_analysis_seed.json').read_text(encoding='utf-8'))
        conn.execute('BEGIN IMMEDIATE')
        conn.execute('''CREATE TABLE prep_task_analysis (
            id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE,
            work_type TEXT NOT NULL, category TEXT NOT NULL, title TEXT NOT NULL,
            pages TEXT NOT NULL, section TEXT NOT NULL, requirement TEXT NOT NULL,
            responsibility TEXT NOT NULL, question TEXT NOT NULL, priority TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT '미검토', note TEXT NOT NULL DEFAULT '',
            owner TEXT NOT NULL DEFAULT '', due_date TEXT NOT NULL DEFAULT '',
            revision INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL)''')
        for item in seeds:
            conn.execute('''INSERT INTO prep_task_analysis
                (code,work_type,category,title,pages,section,requirement,responsibility,question,priority,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)''',
                tuple(item[k] for k in ('code','work_type','category','title','pages','section','requirement','responsibility','question','priority')) + (db.now_text(),))
        return str(backup)


def overview():
    with service.connection() as conn:
        items = [dict(r) for r in conn.execute('SELECT * FROM prep_task_analysis ORDER BY id')]
    return {'items':items, 'statuses':STATUSES, 'source_name':SOURCE_NAME,
            'source_available':(db.BASE_DIR / SOURCE_NAME).is_file(), 'source_pages':19,
            'summary':{'total':len(items), 'reviewed':sum(i['status']=='확인완료' for i in items),
                       'pending':sum(i['status'] not in ('미검토','확인완료') for i in items)}}


def update(item_id, payload):
    status = payload.get('status')
    if status not in STATUSES: raise ValueError('검토 상태를 확인하세요.')
    values = {}
    for key,limit in [('note',10000),('owner',100),('due_date',10)]:
        value = payload.get(key,'')
        if not isinstance(value,str) or len(value)>limit: raise ValueError('입력 길이와 형식을 확인하세요.')
        values[key] = value.strip()
    if values['due_date']:
        from datetime import date
        date.fromisoformat(values['due_date'])
    if status == '확인완료' and not values['note']:
        raise ValueError('확인완료로 저장하려면 확인 근거 또는 검토 결과를 메모에 입력하세요.')
    with service.connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row=conn.execute('SELECT * FROM prep_task_analysis WHERE id=?',(item_id,)).fetchone()
        if not row: raise LookupError('과업 분석 항목을 찾을 수 없습니다.')
        if payload.get('revision') != row['revision']:
            raise service.Conflict('다른 화면에서 변경되었습니다. 목록을 새로고침한 뒤 다시 확인하세요.')
        conn.execute('''UPDATE prep_task_analysis SET status=?,note=?,owner=?,due_date=?,
                     revision=revision+1,updated_at=? WHERE id=?''',
                     (status,values['note'],values['owner'],values['due_date'],db.now_text(),item_id))
        return dict(conn.execute('SELECT * FROM prep_task_analysis WHERE id=?',(item_id,)).fetchone())


def source_file():
    path = (db.BASE_DIR / SOURCE_NAME).resolve()
    if path.parent != db.BASE_DIR.resolve() or not path.is_file():
        raise LookupError('원본 과업지시서 파일을 찾을 수 없습니다.')
    return path
