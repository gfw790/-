from __future__ import annotations

import json
import math
import sqlite3
import uuid
from contextlib import contextmanager, closing
from datetime import date, datetime
from pathlib import Path

import db

MODULE_DIR = Path(__file__).resolve().parent
TABLES = ('prep_questions', 'prep_question_options', 'prep_question_rules', 'prep_sessions',
          'prep_answers', 'prep_issues', 'prep_attachments')
STATUSES = ('확인완료', '미확인', '발주처 재확인', '현장확인 필요', '내부검토 필요', '계획회의 필요', '계획반영 완료')
OPEN_STATUSES = set(STATUSES[1:6])
MAX_FILE = 15 * 1024 * 1024


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
    """Install v1 atomically, backing up an existing database with SQLite's backup API."""
    db.DATA_DIR.mkdir(parents=True, exist_ok=True)
    existed = db.DB_PATH.exists()
    with connection() as conn:
        found = {r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        if set(TABLES).issubset(found):
            return None
        if set(TABLES) & found:
            raise RuntimeError('착공 준비 테이블이 일부만 존재합니다. 자동 변경을 중단합니다.')
        backup = None
        if existed:
            backup_dir = db.DATA_DIR / 'backups'
            backup_dir.mkdir(exist_ok=True)
            backup = backup_dir / f'preparation_v1_{datetime.now():%Y%m%d_%H%M%S}_{uuid.uuid4().hex[:8]}.db'
            with closing(sqlite3.connect(backup)) as target:
                conn.backup(target)
                if target.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
                    raise RuntimeError('백업 검증 실패: 마이그레이션을 중단합니다.')
        scripts = [ (MODULE_DIR / 'migrations' / name).read_text(encoding='utf-8')
                   for name in ('001_initial.sql', '001_demo_questions.sql') ]
        sql = '\n'.join(s.replace('BEGIN IMMEDIATE;', '').replace('COMMIT;', '') for s in scripts)
        try:
            conn.executescript('BEGIN IMMEDIATE;\n' + sql + '\nCOMMIT;')
        except Exception:
            conn.rollback()
            raise
        return str(backup) if backup else None


def questions(conn):
    rows = [dict(r) for r in conn.execute("SELECT * FROM prep_questions WHERE stage='CLIENT_MEETING' ORDER BY sort_order,id")]
    for q in rows:
        q['options'] = [dict(r) for r in conn.execute('SELECT value,label FROM prep_question_options WHERE question_id=? ORDER BY sort_order,id', (q['id'],))]
        q['rules'] = [dict(r) for r in conn.execute('SELECT source_question_id,operator,expected_json FROM prep_question_rules WHERE question_id=? ORDER BY id', (q['id'],))]
    return rows


def rule_matches(rule, value):
    expected = json.loads(rule['expected_json'])
    op = rule['operator']
    if value is None or value == '' or value == []:
        return False
    if op == 'eq': return value == expected
    if op == 'ne': return value != expected
    if op == 'contains': return isinstance(value, list) and expected in value
    if op == 'in': return isinstance(expected, list) and value in expected
    if op in ('gt', 'gte', 'lt', 'lte'):
        if type(value) not in (int, float) or type(expected) not in (int, float): return False
        return {'gt': value > expected, 'gte': value >= expected, 'lt': value < expected, 'lte': value <= expected}[op]
    raise ValueError('지원하지 않는 조건 연산자입니다.')


def visible_ids(qs, answers):
    by_id = {q['id']: q for q in qs}
    result = {}
    def visible(qid, chain):
        if qid in result: return result[qid]
        if qid in chain: raise ValueError('질문 조건에 순환 참조가 있습니다.')
        q = by_id.get(qid)
        if not q or not q['active']: return False
        result[qid] = all(visible(r['source_question_id'], chain | {qid}) and
                          rule_matches(r, answers.get(r['source_question_id'], {}).get('value')) for r in q['rules'])
        return result[qid]
    return [q['id'] for q in qs if visible(q['id'], set())]


def require_session(conn, sid):
    row = conn.execute('SELECT * FROM prep_sessions WHERE id=?', (sid,)).fetchone()
    if not row: raise LookupError('미팅 회차를 찾을 수 없습니다.')
    return dict(row)


def session_data(conn, sid):
    session = require_session(conn, sid)
    qs = questions(conn)
    answers = {}
    for row in conn.execute('SELECT * FROM prep_answers WHERE session_id=?', (sid,)):
        a = dict(row)
        a['value'] = json.loads(a.pop('answer_json'))
        a['question_snapshot'] = json.loads(a.pop('question_snapshot_json'))
        a['attachments'] = [dict(r) for r in conn.execute('SELECT id,original_name,mime_type,size_bytes,description,created_at FROM prep_attachments WHERE answer_id=? ORDER BY id', (a['id'],))]
        answers[a['question_id']] = a
    visible = visible_ids(qs, answers)
    return {'session': session, 'questions': qs, 'answers': answers, 'visible_ids': visible,
            'saved_count': sum(qid in answers for qid in visible), 'statuses': STATUSES}


def get_session(sid):
    with connection() as conn: return session_data(conn, sid)


def list_sessions():
    with connection() as conn:
        return [dict(r) for r in conn.execute('SELECT * FROM prep_sessions ORDER BY id DESC')]


def create_session(payload):
    title = str(payload.get('title') or f'발주처 미팅 {date.today().isoformat()}').strip()
    if not title or len(title) > 200: raise ValueError('미팅 제목은 1~200자로 입력하세요.')
    with connection() as conn:
        now = db.now_text()
        cursor = conn.execute('INSERT INTO prep_sessions(title,created_at,updated_at) VALUES (?,?,?)', (title, now, now))
        return session_data(conn, cursor.lastrowid)


def validate_value(q, value, status):
    empty = value is None or value == '' or value == []
    if empty:
        if q['required'] and status in ('확인완료', '계획반영 완료'):
            raise ValueError('필수 답변을 입력하거나 미확인 상태로 저장하세요.')
        return None
    kind = q['answer_type']
    if kind == 'number':
        if type(value) not in (int, float) or not math.isfinite(value): raise ValueError('유효한 숫자를 입력하세요.')
    elif kind == 'multi_choice':
        allowed = {o['value'] for o in q['options']}
        if not isinstance(value, list) or any(not isinstance(v, str) or v not in allowed for v in value):
            raise ValueError('선택지를 확인하세요.')
        value = list(dict.fromkeys(value))
    else:
        if not isinstance(value, str) or len(value) > 20000: raise ValueError('답변 형식 또는 길이를 확인하세요.')
        if kind in ('yes_no', 'single_choice'):
            allowed = {o['value'] for o in q['options']} or ({'yes', 'no'} if kind == 'yes_no' else set())
            if value not in allowed: raise ValueError('선택지를 확인하세요.')
        if kind == 'date': date.fromisoformat(value)
        if kind == 'datetime':
            if 'T' not in value: raise ValueError('날짜와 시간을 함께 입력하세요.')
            datetime.fromisoformat(value)
    return value


def save_answer(sid, qid, payload):
    with connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        data = session_data(conn, sid)
        q = next((q for q in data['questions'] if q['id'] == qid), None)
        if not q: raise LookupError('질문을 찾을 수 없습니다.')
        if qid not in data['visible_ids']: raise ValueError('현재 적용되지 않는 질문입니다. 새로 조회하세요.')
        existing = data['answers'].get(qid)
        if payload.get('revision') != (existing['revision'] if existing else 0):
            raise Conflict('다른 화면에서 답변이 변경되었습니다. 회차를 다시 열어 최신 답변을 확인하세요.')
        status = payload.get('status', '미확인')
        if status not in STATUSES: raise ValueError('확인상태를 확인하세요.')
        value = validate_value(q, payload.get('value'), status)
        note = payload.get('note', '')
        if not isinstance(note, str) or len(note) > 10000: raise ValueError('메모는 10,000자 이내로 입력하세요.')
        now = db.now_text()
        conn.execute('''INSERT INTO prep_answers(session_id,question_id,question_snapshot_json,answer_json,status,note,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(session_id,question_id) DO UPDATE SET
            answer_json=excluded.answer_json,status=excluded.status,note=excluded.note,
            revision=prep_answers.revision+1,updated_at=excluded.updated_at''',
            (sid,qid,json.dumps(q,ensure_ascii=False),json.dumps(value,ensure_ascii=False),status,note,now,now))
        aid = conn.execute('SELECT id FROM prep_answers WHERE session_id=? AND question_id=?', (sid,qid)).fetchone()[0]
        if status in OPEN_STATUSES:
            conn.execute('''INSERT INTO prep_issues(answer_id,title,status,note,created_at,updated_at) VALUES (?,?,?,?,?,?)
                ON CONFLICT(answer_id) DO UPDATE SET status=excluded.status,note=excluded.note,resolved_at=NULL,updated_at=excluded.updated_at''',
                (aid,q['question_text'],status,note,now,now))
        else:
            conn.execute('UPDATE prep_issues SET status=?,note=?,resolved_at=?,updated_at=? WHERE answer_id=?', (status,note,now,now,aid))
        conn.execute("UPDATE prep_sessions SET current_question_id=?,status='in_progress',updated_at=? WHERE id=?", (qid,now,sid))
        return session_data(conn, sid)


def update_session(sid, payload):
    with connection() as conn:
        conn.execute('BEGIN IMMEDIATE')
        data = session_data(conn, sid)
        status = payload.get('status', data['session']['status'])
        if status not in ('in_progress','completed'): raise ValueError('회차 상태를 확인하세요.')
        qid = payload.get('current_question_id', data['session']['current_question_id'])
        if qid is not None and qid not in data['visible_ids']: raise ValueError('현재 질문 위치를 확인하세요.')
        if status == 'completed' and any(i not in data['answers'] for i in data['visible_ids']):
            raise ValueError('표시된 질문을 모두 저장하세요. 미확인 상태도 저장할 수 있습니다.')
        conn.execute('UPDATE prep_sessions SET status=?,current_question_id=?,updated_at=? WHERE id=?', (status,qid,db.now_text(),sid))
        return session_data(conn, sid)


def list_issues():
    with connection() as conn:
        rows = [dict(r) for r in conn.execute('''SELECT i.*,a.session_id,a.question_id,s.title AS session_title
             FROM prep_issues i JOIN prep_answers a ON a.id=i.answer_id JOIN prep_sessions s ON s.id=a.session_id ORDER BY i.id DESC''')]
        visible = {sid: set(session_data(conn, sid)['visible_ids']) for sid in {r['session_id'] for r in rows}}
        for r in rows: r['applicable'] = r['question_id'] in visible[r['session_id']]
        return rows


def attachment_root():
    return (db.STORAGE_DIR / 'preparation').resolve()


def save_attachment(aid, name, content, description=''):
    # Reject both Windows and POSIX path syntax. Never use a client filename as a storage path.
    if not name or len(name) > 200 or any(c in name for c in '/\\:\x00\r\n') or name in ('.','..'):
        raise ValueError('파일명에 경로 문자를 사용할 수 없습니다.')
    if not 0 < len(content) <= MAX_FILE: raise ValueError('파일은 15MB 이하로 첨부하세요.')
    suffix = Path(name).suffix.lower()
    signatures = {'.jpg': ('image/jpeg', content.startswith(b'\xff\xd8\xff')),
                  '.jpeg': ('image/jpeg', content.startswith(b'\xff\xd8\xff')),
                  '.png': ('image/png', content.startswith(b'\x89PNG\r\n\x1a\n')),
                  '.webp': ('image/webp', content[:4] == b'RIFF' and content[8:12] == b'WEBP'),
                  '.pdf': ('application/pdf', content.startswith(b'%PDF-'))}
    mime, valid = signatures.get(suffix, ('',False))
    if not valid: raise ValueError('실제 JPG·PNG·WEBP 사진 또는 PDF 파일만 첨부할 수 있습니다.')
    if len(description) > 1000: raise ValueError('설명은 1,000자 이내로 입력하세요.')
    root = attachment_root()
    root.mkdir(parents=True, exist_ok=True)
    destination = root / (uuid.uuid4().hex + suffix)
    try:
        with connection() as conn:
            conn.execute('BEGIN IMMEDIATE')
            a = conn.execute('SELECT session_id,question_id FROM prep_answers WHERE id=?', (aid,)).fetchone()
            if not a: raise LookupError('먼저 질문 답변을 저장하세요.')
            if a['question_id'] not in session_data(conn, a['session_id'])['visible_ids']:
                raise ValueError('현재 적용되지 않는 질문에는 첨부할 수 없습니다.')
            with destination.open('xb') as stream: stream.write(content)
            cursor = conn.execute('INSERT INTO prep_attachments(answer_id,stored_path,original_name,mime_type,size_bytes,description,created_at) VALUES (?,?,?,?,?,?,?)',
                (aid,destination.name,name,mime,len(content),description,db.now_text()))
            return {'id':cursor.lastrowid,'original_name':name,'mime_type':mime,'size_bytes':len(content)}
    except Exception:
        destination.unlink(missing_ok=True)
        raise


def get_attachment(attachment_id):
    with connection() as conn:
        row = conn.execute('SELECT * FROM prep_attachments WHERE id=?', (attachment_id,)).fetchone()
    if not row: raise LookupError('첨부파일을 찾을 수 없습니다.')
    root = attachment_root()
    path = (root / row['stored_path']).resolve()
    if root not in path.parents or not path.is_file(): raise LookupError('첨부파일을 찾을 수 없습니다.')
    return path, dict(row)
