BEGIN IMMEDIATE;
INSERT OR IGNORE INTO prep_questions
 (question_code,stage,work_type,question_text,help_text,answer_type,required,sort_order) VALUES
 ('DEMO-M01','CLIENT_MEETING','COMMON','연 4회 세정의 예상 작업시기는?','검증용 질문입니다. 실제 횟수와 일정은 과업지시서 및 발주처 확인 후 확정하세요.','textarea',1,10),
 ('DEMO-M02','CLIENT_MEETING','COMMON','회차별 시설 정지 예정시간은?','회차별 정지 기간과 시간 단위를 함께 기록하세요.','text',1,20),
 ('DEMO-M03','CLIENT_MEETING','COMMON','작업 시작 가능시각은?','이번 미팅에서 검토하는 작업 회차의 일시를 입력하세요.','datetime',1,30),
 ('DEMO-M04','CLIENT_MEETING','COMMON','반드시 완료해야 하는 재가동 시각은?','미정인 경우 상태를 미확인으로 저장할 수 있습니다.','datetime',1,40),
 ('DEMO-M05','CLIENT_MEETING','COMMON','다른 업체와 동시작업이 있는가?','예를 선택하면 동시작업 상세 질문이 나타납니다.','yes_no',1,50),
 ('DEMO-M06','CLIENT_MEETING','COMMON','동시작업 업체·작업구역·간섭사항은?','업체명, 겹치는 작업 시간 및 구역을 확인하세요. 관련 사진도 첨부할 수 있습니다.','textarea',1,60),
 ('DEMO-M07','CLIENT_MEETING','COMMON','이전 세정작업에서 발생했던 주요 문제는?','기존 기록 유무와 문제 내용을 구분해 기록하세요.','textarea',0,70),
 ('DEMO-M08','CLIENT_MEETING','COMMON','발주처가 이번 작업에서 가장 중요하게 보는 사항은?','요구사항과 확인 근거를 기록하세요.','textarea',1,80);
INSERT OR IGNORE INTO prep_question_options(question_id,value,label,sort_order)
 SELECT id,'yes','예',1 FROM prep_questions WHERE question_code='DEMO-M05';
INSERT OR IGNORE INTO prep_question_options(question_id,value,label,sort_order)
 SELECT id,'no','아니오',2 FROM prep_questions WHERE question_code='DEMO-M05';
INSERT INTO prep_question_rules(question_id,source_question_id,operator,expected_json)
 SELECT target.id,source.id,'eq','"yes"' FROM prep_questions target, prep_questions source
 WHERE target.question_code='DEMO-M06' AND source.question_code='DEMO-M05'
 AND NOT EXISTS (SELECT 1 FROM prep_question_rules WHERE question_id=target.id);
COMMIT;
