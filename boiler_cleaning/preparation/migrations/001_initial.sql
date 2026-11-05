BEGIN IMMEDIATE;
CREATE TABLE IF NOT EXISTS prep_questions (
 id INTEGER PRIMARY KEY, question_code TEXT NOT NULL UNIQUE,
 stage TEXT NOT NULL, work_type TEXT NOT NULL CHECK(work_type IN ('COMMON','BOILER','ECONOMIZER','SDR','BF','SCR')),
 question_text TEXT NOT NULL, help_text TEXT NOT NULL DEFAULT '',
 answer_type TEXT NOT NULL CHECK(answer_type IN ('text','textarea','number','date','datetime','yes_no','single_choice','multi_choice')),
 required INTEGER NOT NULL DEFAULT 0 CHECK(required IN (0,1)), sort_order INTEGER NOT NULL,
 active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)), version INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS prep_question_options (
 id INTEGER PRIMARY KEY, question_id INTEGER NOT NULL REFERENCES prep_questions(id) ON DELETE RESTRICT,
 value TEXT NOT NULL, label TEXT NOT NULL, sort_order INTEGER NOT NULL,
 UNIQUE(question_id,value)
);
CREATE TABLE IF NOT EXISTS prep_question_rules (
 id INTEGER PRIMARY KEY, question_id INTEGER NOT NULL REFERENCES prep_questions(id) ON DELETE RESTRICT,
 source_question_id INTEGER NOT NULL REFERENCES prep_questions(id) ON DELETE RESTRICT,
 operator TEXT NOT NULL CHECK(operator IN ('eq','ne','contains','in','gt','gte','lt','lte')),
 expected_json TEXT NOT NULL, CHECK(question_id <> source_question_id)
);
CREATE TABLE IF NOT EXISTS prep_sessions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, stage TEXT NOT NULL DEFAULT 'CLIENT_MEETING',
 title TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'in_progress' CHECK(status IN ('in_progress','completed')),
 current_question_id INTEGER REFERENCES prep_questions(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS prep_answers (
 id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER NOT NULL REFERENCES prep_sessions(id) ON DELETE RESTRICT,
 question_id INTEGER NOT NULL REFERENCES prep_questions(id) ON DELETE RESTRICT,
 question_snapshot_json TEXT NOT NULL, answer_json TEXT NOT NULL DEFAULT 'null',
 status TEXT NOT NULL CHECK(status IN ('확인완료','미확인','발주처 재확인','현장확인 필요','내부검토 필요','계획회의 필요','계획반영 완료')),
 note TEXT NOT NULL DEFAULT '', revision INTEGER NOT NULL DEFAULT 1,
 created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(session_id,question_id)
);
CREATE TABLE IF NOT EXISTS prep_issues (
 id INTEGER PRIMARY KEY AUTOINCREMENT, answer_id INTEGER NOT NULL UNIQUE REFERENCES prep_answers(id) ON DELETE RESTRICT,
 title TEXT NOT NULL, status TEXT NOT NULL, note TEXT NOT NULL DEFAULT '',
 resolved_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS prep_attachments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, answer_id INTEGER NOT NULL REFERENCES prep_answers(id) ON DELETE RESTRICT,
 stored_path TEXT NOT NULL UNIQUE, original_name TEXT NOT NULL, mime_type TEXT NOT NULL,
 size_bytes INTEGER NOT NULL CHECK(size_bytes > 0), description TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS prep_questions_order ON prep_questions(stage,active,sort_order);
CREATE INDEX IF NOT EXISTS prep_attachments_answer ON prep_attachments(answer_id);
COMMIT;
