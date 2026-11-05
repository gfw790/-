"""Run explicitly: python -B boiler_cleaning/tests/verify_preparation_browser.py.
Requires Playwright Chromium. All mutations use a temporary copy of the real DB/storage.
"""
import io
import json
import shutil
import sqlite3
import sys
import tempfile
import threading
from contextlib import ExitStack, closing
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import app
import db
from preparation import service, task_analysis
from PIL import Image
from playwright.sync_api import sync_playwright, expect


def main():
    original = db.BASE_DIR
    artifacts = original / 'tests' / 'artifacts'
    artifacts.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='boiler_prep_verify_') as temporary, ExitStack() as stack:
        root = Path(temporary).resolve()
        (root/'data').mkdir()
        with closing(sqlite3.connect(db.DB_PATH)) as source, closing(sqlite3.connect(root/'data/risk_management.db')) as target:
            source.backup(target)
        shutil.copytree(original/'storage',root/'storage')
        shutil.copy2(original/task_analysis.SOURCE_NAME,root/task_analysis.SOURCE_NAME)
        for name,value in {'BASE_DIR':root,'DATA_DIR':root/'data','DB_PATH':root/'data/risk_management.db','STORAGE_DIR':root/'storage'}.items():
            stack.enter_context(patch.object(db,name,value))
        service.migrate()
        task_analysis.migrate()
        # Every currently supported legacy Excel exporter must still produce a readable workbook.
        exported = []
        for document in db.list_documents('form'):
            sequence = document['sequence']
            if sequence not in db.FORM_EXCEL_FILLERS and sequence not in db.LEDGER_FORMS: continue
            entry = db.create_form_entry(document['id'],{'record_title':'회귀검증','data':{}})
            content,name = db.export_form_entry_excel(entry['id']) if sequence in db.FORM_EXCEL_FILLERS else db.export_document_ledger_excel(document['id'])
            workbook = db.openpyxl.load_workbook(io.BytesIO(content)); workbook.close()
            exported.append(sequence)
        # Existing document version storage and download resolution, only in the copy.
        document = db.list_documents('form')[0]
        before = len(db.list_versions(document['id']))
        db.save_upload(document['id'],'검증용.pdf',b'%PDF-1.4\n%%EOF')
        assert len(db.list_versions(document['id'])) == before+1
        assert db.resolve_document_file(document['id'])[0].is_file()
        server = app.ThreadingHTTPServer(('127.0.0.1',0),app.RiskRequestHandler)
        thread = threading.Thread(target=server.serve_forever,daemon=True); thread.start()
        try:
            with sync_playwright() as p:
                browser = p.chromium.launch(headless=True)
                context = browser.new_context(viewport={'width':1280,'height':900})
                page = context.new_page(); errors = []
                page.on('pageerror',lambda error:errors.append(str(error)))
                base = f'http://127.0.0.1:{server.server_port}'
                page.goto(base)
                expect(page.locator('#table-container tbody tr')).to_have_count(20)
                page.get_by_role('button',name='평가자료 12개',exact=True).click()
                expect(page.locator('#table-container tbody tr')).to_have_count(12)
                page.get_by_role('button',name='착공 준비',exact=True).click()
                page.get_by_role('button',name='1. 과업·원가 분석',exact=False).click()
                expect(page.locator('.ta-item')).to_have_count(24)
                page.locator('#ta-work').select_option('SDR')
                expect(page.locator('.ta-item:visible')).to_have_count(2)
                page.locator('#ta-work').select_option('')
                page.locator('.ta-item[data-id="1"] summary').click()
                page.locator('#ta-status-1').select_option('발주처 재확인')
                page.locator('#ta-note-1').fill('호기별 일정 확인 요청')
                page.locator('.ta-form[data-id="1"]').get_by_role('button',name='검토 내용 저장').click()
                expect(page.locator('#ta-feedback')).to_have_text('검토 내용을 저장했습니다.')
                page.reload()
                page.get_by_role('button',name='착공 준비',exact=True).click()
                page.get_by_role('button',name='1. 과업·원가 분석',exact=False).click()
                page.locator('.ta-item[data-id="1"] summary').click()
                expect(page.locator('#ta-note-1')).to_have_value('호기별 일정 확인 요청')
                response=context.request.get(base+'/api/preparation/task-analysis/source')
                assert response.status==200 and response.body().startswith(b'%PDF')
                for width in (390,1280):
                    page.set_viewport_size({'width':width,'height':900})
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
                    page.screenshot(path=str(artifacts/f'task-analysis-{width}.png'),full_page=True)
                page.get_by_role('button',name='발주처 미팅으로 이동',exact=True).click()
                page.locator('#prep-title').fill('브라우저 검증 미팅')
                page.get_by_role('button',name='발주처 미팅 시작',exact=True).click()
                expect(page.locator('#prep-question-title')).to_contain_text('연 4회')
                page.locator('#prep-value').fill('3월, 6월, 9월, 12월')
                page.locator('#prep-status').select_option(label='발주처 재확인')
                page.get_by_role('button',name='현재 답변 저장',exact=True).click()
                expect(page.locator('#prep-message')).to_have_text('답변을 저장했습니다.')
                page.locator('#prep-note').fill('정지 일정 재확인')
                page.get_by_role('button',name='현재 답변 저장',exact=True).click()
                expect(page.locator('#prep-message')).to_have_text('답변을 저장했습니다.')
                assert len(service.list_issues()) == 1
                # Close the browser context, then resume from server state in a fresh context.
                page.wait_for_load_state('networkidle')
                context.close()
                context = browser.new_context(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
                page = context.new_page(); page.on('pageerror',lambda error:errors.append(str(error)))
                page.goto(base); page.get_by_role('button',name='착공 준비',exact=True).click()
                page.get_by_role('button',name='이어서 작성',exact=True).first.click()
                expect(page.locator('#prep-value')).to_have_value('3월, 6월, 9월, 12월')
                expect(page.locator('#prep-note')).to_have_value('정지 일정 재확인')
                # Attachment: real PNG, binary on disk and downloadable through the API.
                image = io.BytesIO(); Image.new('RGB',(30,20),'teal').save(image,format='PNG')
                page.locator('#prep-file').set_input_files({'name':'현장사진.png','mimeType':'image/png','buffer':image.getvalue()})
                page.locator('#prep-description').fill('현장 진입로')
                page.get_by_role('button',name='선택한 파일 첨부',exact=True).click()
                expect(page.get_by_role('link',name='현장사진.png')).to_be_visible()
                href = page.get_by_role('link',name='현장사진.png').get_attribute('href')
                assert context.request.get(base+href).body() == image.getvalue()
                page.get_by_role('button',name='저장 후 다음',exact=True).click()
                expect(page.locator('#prep-question-title')).to_contain_text('정지 예정시간')
                page.locator('#prep-value').fill('회차당 48시간')
                page.get_by_role('button',name='저장 후 다음',exact=True).click()
                expect(page.locator('#prep-question-title')).to_contain_text('작업 시작')
                page.locator('#prep-value').fill('2026-10-01T08:00')
                page.get_by_role('button',name='저장 후 다음',exact=True).click()
                expect(page.locator('#prep-question-title')).to_contain_text('재가동')
                page.locator('#prep-value').fill('2026-10-03T08:00')
                page.get_by_role('button',name='저장 후 다음',exact=True).click()
                expect(page.locator('#prep-question-title')).to_contain_text('동시작업이')
                page.get_by_role('radio',name='예',exact=True).check()
                page.locator('#prep-status').select_option(label='확인완료')
                page.get_by_role('button',name='저장 후 다음',exact=True).click()
                expect(page.locator('#prep-question-title')).to_contain_text('동시작업 업체')
                page.locator('#prep-value').fill('설비 정비팀 / 보일러 상부')
                page.locator('#prep-status').select_option(label='현장확인 필요')
                page.get_by_role('button',name='현재 답변 저장',exact=True).click()
                expect(page.locator('#prep-message')).to_have_text('답변을 저장했습니다.')
                for width in (320,390,768,1280):
                    page.set_viewport_size({'width':width,'height':900})
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), f'Horizontal overflow: {width}'
                    assert page.locator('#prep-value').bounding_box()['height'] >= 44
                    if width in (390,1280): page.screenshot(path=str(artifacts/f'preparation-{width}.png'),full_page=True)
                page.get_by_role('button',name='이전',exact=True).click()
                expect(page.locator('#prep-question-title')).to_contain_text('동시작업이')
                page.get_by_role('radio',name='아니오',exact=True).check()
                page.get_by_role('button',name='저장 후 다음',exact=True).click()
                expect(page.locator('#prep-question-title')).to_contain_text('이전 세정작업')
                page.get_by_role('button',name='저장 후 다음',exact=True).click()
                expect(page.locator('#prep-question-title')).to_contain_text('가장 중요')
                page.get_by_role('button',name='저장 후 문답 마침',exact=True).click()
                expect(page.locator('#prep-message')).to_contain_text('문답을 모두 저장')
                page.locator('.prep-stage-list summary').click()
                page.get_by_role('button',name='6. 미확인·쟁점사항',exact=False).click()
                expect(page.locator('#prep-view')).to_contain_text('조건 제외 · 기록 보존')
                page.get_by_role('button',name='안전관리',exact=True).click()
                page.get_by_role('button',name='실행양식 20개',exact=True).click()
                expect(page.locator('#table-container tbody tr')).to_have_count(20)
                assert not errors, errors
                page.wait_for_load_state('networkidle')
                context.close(); browser.close()
                print(json.dumps({'browser':'PASS','widths':[320,390,768,1280],'legacy_excel_sequences':exported,'legacy_version_upload':'PASS','javascript_errors':errors},ensure_ascii=True))
        finally:
            server.shutdown(); server.server_close(); thread.join()


if __name__ == '__main__': main()
