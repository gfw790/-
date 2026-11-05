import io
import json
import sqlite3
import sys
import tempfile
import threading
import unittest
from contextlib import closing
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError
from urllib.request import Request, urlopen

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import db
import app
from preparation import service


class PreparationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        root = Path(self.temp.name)
        self.patches = [patch.object(db, 'DATA_DIR', root/'data'),
                        patch.object(db, 'DB_PATH', root/'data'/'test.db'),
                        patch.object(db, 'STORAGE_DIR', root/'storage'),
                        patch.object(db, 'SOURCE_DIR', root/'missing')]
        for p in self.patches: p.start()
        db.initialize()
        self.legacy = self.snapshot()
        self.backup = service.migrate()
        self.session = service.create_session({'title':'검증 미팅'})
        self.sid = self.session['session']['id']
        self.qs = {q['question_code']:q for q in self.session['questions']}

    def tearDown(self):
        for p in reversed(self.patches): p.stop()
        self.temp.cleanup()

    def snapshot(self):
        with closing(sqlite3.connect(db.DB_PATH)) as conn:
            return {name:conn.execute(f'SELECT * FROM {name} ORDER BY id').fetchall()
                    for name in ('projects','documents','document_versions','form_entries','activity_logs')}

    def save(self, code, value=None, status='미확인', revision=0):
        return service.save_answer(self.sid,self.qs[code]['id'],{'value':value,'status':status,'note':'검토 메모','revision':revision})

    def test_migration_backup_idempotence_and_legacy_preserved(self):
        self.assertTrue(Path(self.backup).is_file())
        self.assertIsNone(service.migrate())
        self.assertEqual(self.legacy,self.snapshot())
        with closing(sqlite3.connect(self.backup)) as conn:
            self.assertEqual(conn.execute('SELECT COUNT(*) FROM documents').fetchone()[0],32)
            self.assertFalse(conn.execute("SELECT name FROM sqlite_master WHERE name='prep_questions'").fetchall())
        self.assertEqual(len(db.list_documents('form')),20)
        self.assertEqual(len(db.list_documents('evidence')),12)
        self.assertEqual(len(service.get_session(self.sid)['questions']),8)

    def test_answer_edit_resume_conflict_and_unique_issue(self):
        first = self.save('DEMO-M01',status='발주처 재확인')
        qid = self.qs['DEMO-M01']['id']
        self.assertEqual(first['answers'][qid]['revision'],1)
        self.save('DEMO-M01','3월 예정',status='내부검토 필요',revision=1)
        self.assertEqual(len(service.list_issues()),1)
        resumed = service.get_session(self.sid)
        self.assertEqual(resumed['answers'][qid]['value'],'3월 예정')
        self.assertEqual(resumed['session']['current_question_id'],qid)
        with self.assertRaises(service.Conflict): self.save('DEMO-M01','오래된 수정',revision=1)
        self.save('DEMO-M01','3월 확정',status='확인완료',revision=2)
        self.assertIsNotNone(service.list_issues()[0]['resolved_at'])
        self.save('DEMO-M01',status='미확인',revision=3)
        self.assertEqual(len(service.list_issues()),1)
        self.assertIsNone(service.list_issues()[0]['resolved_at'])

    def test_conditional_question_preserves_hidden_answers_and_issues(self):
        follow = self.qs['DEMO-M06']['id']
        self.assertNotIn(follow,self.session['visible_ids'])
        shown = self.save('DEMO-M05','yes','확인완료')
        self.assertIn(follow,shown['visible_ids'])
        self.save('DEMO-M06','업체 확인 중')
        hidden = self.save('DEMO-M05','no','확인완료',1)
        self.assertNotIn(follow,hidden['visible_ids'])
        self.assertEqual(hidden['answers'][follow]['value'],'업체 확인 중')
        self.assertFalse(service.list_issues()[0]['applicable'])
        with self.assertRaises(ValueError): self.save('DEMO-M06','hidden',revision=1)
        shown = self.save('DEMO-M05','yes','확인완료',2)
        self.assertIn(follow,shown['visible_ids'])

    def test_required_and_completion(self):
        with self.assertRaises(ValueError): self.save('DEMO-M01',status='확인완료')
        with self.assertRaises(ValueError): service.update_session(self.sid,{'status':'completed'})
        for qid in self.session['visible_ids']:
            service.save_answer(self.sid,qid,{'revision':0,'status':'미확인'})
        result = service.update_session(self.sid,{'status':'completed'})
        self.assertEqual(result['session']['status'],'completed')

    def test_answer_types_and_rule_operators(self):
        base = {'required':1,'options':[{'value':'a'},{'value':'b'}]}
        valid = {'text':'가','textarea':'내용','number':2.5,'date':'2026-09-16','datetime':'2026-09-16T10:00',
                 'yes_no':'yes','single_choice':'a','multi_choice':['a','b']}
        for kind,value in valid.items():
            q = {**base,'answer_type':kind}
            if kind == 'yes_no': q['options']=[]
            self.assertEqual(service.validate_value(q,value,'확인완료'),value)
        with self.assertRaises(ValueError): service.validate_value({**base,'answer_type':'number'},float('nan'),'확인완료')
        with self.assertRaises(ValueError): service.validate_value({**base,'answer_type':'multi_choice'},['unknown'],'확인완료')
        for op,value,expected in [('eq','yes','yes'),('ne','no','yes'),('contains',['a'],'a'),('in','a',['a']),('gt',3,2),('gte',2,2),('lt',1,2),('lte',2,2)]:
            self.assertTrue(service.rule_matches({'operator':op,'expected_json':json.dumps(expected)},value))
        qs = [{'id':1,'active':1,'rules':[{'source_question_id':2,'operator':'eq','expected_json':'1'}]},
              {'id':2,'active':1,'rules':[{'source_question_id':1,'operator':'eq','expected_json':'1'}]}]
        with self.assertRaises(ValueError): service.visible_ids(qs,{})

    def test_attachment_file_metadata_and_traversal(self):
        data = self.save('DEMO-M01')
        aid = data['answers'][self.qs['DEMO-M01']['id']]['id']
        from PIL import Image
        buffer = io.BytesIO(); Image.new('RGB',(2,2)).save(buffer,format='PNG')
        content = buffer.getvalue()
        result = service.save_attachment(aid,'현장.png',content,'진입로')
        path, meta = service.get_attachment(result['id'])
        self.assertEqual(path.read_bytes(),content)
        self.assertEqual(meta['description'],'진입로')
        self.assertEqual(meta['mime_type'],'image/png')
        self.assertNotIn('data:image',str(service.get_session(self.sid)))
        for name in ('../escape.png','..\\escape.png','C:\\escape.png','bad.exe'):
            with self.assertRaises(ValueError): service.save_attachment(aid,name,content)
        with self.assertRaises(ValueError): service.save_attachment(aid,'fake.png',b'html')
        with self.assertRaises(LookupError): service.save_attachment(999,'valid.png',content)
        with service.connection() as conn:
            conn.execute('UPDATE prep_attachments SET stored_path=? WHERE id=?', ('../../outside.png',result['id']))
        with self.assertRaises(LookupError): service.get_attachment(result['id'])

    def test_http_integration_and_legacy_routes(self):
        server = app.ThreadingHTTPServer(('127.0.0.1',0),app.RiskRequestHandler)
        thread = threading.Thread(target=server.serve_forever,daemon=True); thread.start()
        base = f'http://127.0.0.1:{server.server_port}'
        def call(path,method='GET',payload=None,headers=None):
            body = json.dumps(payload).encode() if payload is not None else None
            request = Request(base+path,data=body,method=method,headers=headers or {'Content-Type':'application/json'})
            with urlopen(request) as response: return json.load(response)
        try:
            self.assertEqual(len(call('/api/documents?kind=form')),20)
            self.assertEqual(len(call('/api/documents?kind=evidence')),12)
            self.assertEqual(len(call('/api/preparation/questions')),8)
            new = call('/api/preparation/sessions','POST',{'title':'HTTP 미팅'})
            sid = new['session']['id']; qid = new['visible_ids'][0]
            saved = call(f'/api/preparation/sessions/{sid}/answers/{qid}','PUT',{'value':'상반기','status':'발주처 재확인','revision':0})
            self.assertEqual(saved['answers'][str(qid)]['value'],'상반기')
            self.assertEqual(call(f'/api/preparation/sessions/{sid}')['session']['current_question_id'],qid)
            with self.assertRaises(HTTPError) as error:
                call('/api/preparation/sessions','POST',{}, {'Content-Type':'application/json','Origin':'https://other.example'})
            self.assertEqual(error.exception.code,403)
            error.exception.close()
        finally:
            server.shutdown(); server.server_close(); thread.join()


if __name__ == '__main__': unittest.main()
