import sqlite3
import sys
import tempfile
import unittest
from pathlib import Path
from contextlib import closing
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import db
from preparation import service, task_analysis


class TaskAnalysisTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(); root=Path(self.temp.name)
        self.patches=[patch.object(db,'DATA_DIR',root/'data'),patch.object(db,'DB_PATH',root/'data/test.db'),
                      patch.object(db,'STORAGE_DIR',root/'storage'),patch.object(db,'SOURCE_DIR',root/'missing')]
        for p in self.patches:p.start()
        db.initialize();service.migrate()

    def tearDown(self):
        for p in reversed(self.patches):p.stop()
        self.temp.cleanup()

    def test_migration_preserves_every_existing_table(self):
        with service.connection() as c:
            tables=[r[0] for r in c.execute("SELECT name FROM sqlite_master WHERE type='table'")]
            before={t:[tuple(r) for r in c.execute('SELECT * FROM '+t)] for t in tables}
        backup=task_analysis.migrate()
        self.assertTrue(Path(backup).is_file())
        self.assertIsNone(task_analysis.migrate())
        with service.connection() as c:
            self.assertEqual(before,{t:[tuple(r) for r in c.execute('SELECT * FROM '+t)] for t in tables})
        result=task_analysis.overview()
        self.assertEqual(len(result['items']),24)
        self.assertEqual({i['work_type'] for i in result['items']},{'COMMON','BOILER','ECONOMIZER','SDR','BF','SCR'})
        self.assertEqual(result['summary']['reviewed'],0)

    def test_edit_resume_validation_and_conflict(self):
        task_analysis.migrate()
        payload={'status':'발주처 재확인','note':'호기별 일정 문의','owner':'담당자','due_date':'2026-10-01','revision':0}
        saved=task_analysis.update(1,payload)
        self.assertEqual(saved['revision'],1)
        self.assertEqual(task_analysis.overview()['items'][0]['note'],'호기별 일정 문의')
        with self.assertRaises(service.Conflict):task_analysis.update(1,payload)
        with self.assertRaises(ValueError):task_analysis.update(1,{**payload,'revision':1,'status':'확인완료','note':''})
        with self.assertRaises(ValueError):task_analysis.update(1,{**payload,'revision':1,'due_date':'2026-99-99'})
        task_analysis.update(1,{**payload,'revision':1,'status':'확인완료','note':'발주처 회의록 확인'})
        self.assertEqual(task_analysis.overview()['summary']['reviewed'],1)
        self.assertEqual(len(service.list_sessions()),0)


if __name__=='__main__':unittest.main()
