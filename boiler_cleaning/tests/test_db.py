import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import db


class DatabaseTests(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        root = Path(self.temp_dir.name)
        self.patches = [
            patch.object(db, "DATA_DIR", root / "data"),
            patch.object(db, "STORAGE_DIR", root / "storage"),
            patch.object(db, "DB_PATH", root / "data" / "test.db"),
            patch.object(db, "SOURCE_DIR", root / "missing-source"),
        ]
        for active_patch in self.patches:
            active_patch.start()
        db.initialize()

    def tearDown(self):
        for active_patch in reversed(self.patches):
            active_patch.stop()
        self.temp_dir.cleanup()

    def test_seed_counts_and_score(self):
        summary = db.dashboard()
        self.assertEqual(summary["form_total"], 20)
        self.assertEqual(summary["evidence_total"], 12)
        self.assertEqual(summary["current_score"], 89)
        self.assertEqual(summary["max_score"], 100)

    def test_document_update_is_persisted(self):
        document = db.list_documents("form")[0]
        updated = db.update_document(document["id"], {"status": "완료", "note": "점검 완료"})
        self.assertEqual(updated["status"], "완료")
        self.assertEqual(updated["note"], "점검 완료")
        self.assertEqual(db.dashboard()["form_done"], 1)

    def test_score_is_limited_to_maximum(self):
        document = db.list_documents("evidence")[0]
        updated = db.update_document(document["id"], {"current_score": 99})
        self.assertEqual(updated["current_score"], updated["max_score"])

    def test_upload_rejects_unsupported_extension(self):
        document = db.list_documents("form")[0]
        with self.assertRaises(ValueError):
            db.save_upload(document["id"], "malware.exe", b"test")

    def test_form_entry_crud(self):
        document = db.list_documents("form")[0]
        created = db.create_form_entry(document["id"], {
            "record_date": "2026-09-14",
            "record_title": "예비 위험성평가",
            "data": {"target_work": "보일러 세정"},
        })
        self.assertEqual(created["data"]["target_work"], "보일러 세정")
        self.assertEqual(db.get_document(document["id"])["status"], "작성 중")
        updated = db.update_form_entry(created["id"], {"status": "완료", "data": {"target_work": "폐열보일러 세정"}})
        self.assertEqual(updated["status"], "완료")
        self.assertEqual(len(db.list_form_entries(document["id"])), 1)
        self.assertTrue(db.delete_form_entry(created["id"]))
        self.assertEqual(db.list_form_entries(document["id"]), [])


if __name__ == "__main__":
    unittest.main()
