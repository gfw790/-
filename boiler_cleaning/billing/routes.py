"""Billing-only HTTP dispatcher. Does not alter legacy API behavior."""
import json
import re
from urllib.parse import parse_qs, quote, urlparse

from . import expenditure, personnel, safety_budget, service


def _serve_file(handler, file, metadata):
    handler.send_response(200)
    handler.send_header('Content-Type', metadata['mime_type'])
    handler.send_header('X-Content-Type-Options', 'nosniff')
    handler.send_header('Content-Disposition', "attachment; filename*=UTF-8''" + quote(metadata['original_name']))
    handler.send_header('Content-Length', str(file.stat().st_size))
    handler.send_header('Cache-Control', 'no-store')
    handler.end_headers()
    with file.open('rb') as stream:
        while chunk := stream.read(1024 * 1024):
            handler.wfile.write(chunk)


def _serve_bytes(handler, content, file_name, mime_type):
    handler.send_response(200)
    handler.send_header('Content-Type', mime_type)
    handler.send_header('X-Content-Type-Options', 'nosniff')
    handler.send_header('Content-Disposition', "attachment; filename*=UTF-8''" + quote(file_name))
    handler.send_header('Content-Length', str(len(content)))
    handler.send_header('Cache-Control', 'no-store')
    handler.end_headers()
    handler.wfile.write(content)


def dispatch(handler, method):
    parsed = urlparse(handler.path)
    if not parsed.path.startswith('/api/billing/'):
        return False
    path = parsed.path.removeprefix('/api/billing/')
    try:
        if method != 'GET':
            origin = handler.headers.get('Origin')
            if handler.headers.get('Sec-Fetch-Site') == 'cross-site' or (origin and urlparse(origin).netloc != handler.headers.get('Host')):
                handler._error(403, '다른 사이트에서 보낸 저장 요청은 허용되지 않습니다.')
                return True

        def body():
            size = int(handler.headers.get('Content-Length', '0'))
            if not 0 < size <= 128 * 1024:
                raise ValueError('요청 크기를 확인하세요.')
            if handler.headers.get('Content-Type', '').split(';')[0] != 'application/json':
                raise ValueError('JSON 요청이 필요합니다.')
            value = json.loads(handler.rfile.read(size).decode('utf-8'))
            if not isinstance(value, dict):
                raise ValueError('요청 형식을 확인하세요.')
            return value

        def uploaded_file(max_size):
            size = int(handler.headers.get('Content-Length', '0'))
            if not 0 < size <= max_size:
                raise ValueError('파일은 15MB 이하로 첨부하세요.')
            query = parse_qs(parsed.query)
            content = handler.rfile.read(size)
            if len(content) != size:
                raise ValueError('파일 전송이 완료되지 않았습니다.')
            return content, query.get('filename', [''])[0], query.get('description', [''])[0]

        match_line = re.fullmatch(r'lines/(\d+)', path)
        match_upload = re.fullmatch(r'lines/(\d+)/attachments', path)
        match_file = re.fullmatch(r'attachments/(\d+)/download', path)
        match_exp_line = re.fullmatch(r'expenditures/(\d+)', path)
        match_exp_upload = re.fullmatch(r'expenditures/(\d+)/attachments', path)
        match_exp_file = re.fullmatch(r'expenditure-attachments/(\d+)/download', path)
        match_person = re.fullmatch(r'personnel/(\d+)', path)
        match_person_upload = re.fullmatch(r'personnel/(\d+)/attachments', path)
        match_person_file = re.fullmatch(r'personnel-attachments/(\d+)/download', path)
        match_settlement = re.fullmatch(r'settlements/(\d+)', path)
        match_settlement_upload = re.fullmatch(r'settlements/(\d+)/attachments', path)
        match_settlement_file = re.fullmatch(r'settlement-attachments/(\d+)/download', path)
        match_sb_line = re.fullmatch(r'safety-budget-lines/(\d+)', path)
        match_sb_upload = re.fullmatch(r'safety-budget-lines/(\d+)/attachments', path)
        match_sb_file = re.fullmatch(r'safety-budget-attachments/(\d+)/download', path)

        if path == 'summary' and method == 'GET':
            handler._json(service.summary())
        elif path == 'lines' and method == 'GET':
            handler._json(service.list_lines())
        elif path == 'lines' and method == 'POST':
            handler._json(service.create_line(body()), 201)
        elif match_line and method == 'PUT':
            handler._json(service.update_line(int(match_line[1]), body()))
        elif match_line and method == 'DELETE':
            handler._json({'deleted': service.delete_line(int(match_line[1]))})
        elif match_upload and method == 'POST':
            content, filename, description = uploaded_file(service.MAX_FILE)
            handler._json(service.save_attachment(int(match_upload[1]), filename, content, description), 201)
        elif match_file and method == 'GET':
            _serve_file(handler, *service.get_attachment(int(match_file[1])))

        elif path == 'expenditure-summary' and method == 'GET':
            handler._json(expenditure.summary())
        elif path == 'expenditures' and method == 'GET':
            handler._json(expenditure.list_lines())
        elif path == 'expenditures' and method == 'POST':
            handler._json(expenditure.create_line(body()), 201)
        elif match_exp_line and method == 'PUT':
            handler._json(expenditure.update_line(int(match_exp_line[1]), body()))
        elif match_exp_line and method == 'DELETE':
            handler._json({'deleted': expenditure.delete_line(int(match_exp_line[1]))})
        elif match_exp_upload and method == 'POST':
            content, filename, description = uploaded_file(expenditure.MAX_FILE)
            handler._json(expenditure.save_attachment(int(match_exp_upload[1]), filename, content, description), 201)
        elif match_exp_file and method == 'GET':
            _serve_file(handler, *expenditure.get_attachment(int(match_exp_file[1])))

        elif path == 'personnel-summary' and method == 'GET':
            handler._json(personnel.summary())
        elif path == 'personnel' and method == 'GET':
            handler._json(personnel.list_personnel())
        elif path == 'personnel' and method == 'POST':
            handler._json(personnel.create_personnel(body()), 201)
        elif match_person and method == 'PUT':
            handler._json(personnel.update_personnel(int(match_person[1]), body()))
        elif match_person and method == 'DELETE':
            handler._json({'deleted': personnel.delete_personnel(int(match_person[1]))})
        elif match_person_upload and method == 'POST':
            content, filename, description = uploaded_file(personnel.MAX_FILE)
            handler._json(personnel.save_personnel_attachment(int(match_person_upload[1]), filename, content, description), 201)
        elif match_person_file and method == 'GET':
            _serve_file(handler, *personnel.get_personnel_attachment(int(match_person_file[1])))
        elif path == 'settlements' and method == 'POST':
            handler._json(personnel.create_settlement(body()), 201)
        elif match_settlement and method == 'PUT':
            handler._json(personnel.update_settlement(int(match_settlement[1]), body()))
        elif match_settlement and method == 'DELETE':
            handler._json({'deleted': personnel.delete_settlement(int(match_settlement[1]))})
        elif match_settlement_upload and method == 'POST':
            content, filename, description = uploaded_file(personnel.MAX_FILE)
            handler._json(personnel.save_settlement_attachment(int(match_settlement_upload[1]), filename, content, description), 201)
        elif match_settlement_file and method == 'GET':
            _serve_file(handler, *personnel.get_settlement_attachment(int(match_settlement_file[1])))

        elif path == 'safety-budget-summary' and method == 'GET':
            handler._json(safety_budget.summary())
        elif path == 'safety-budget-header' and method == 'PUT':
            handler._json(safety_budget.header_update(body()))
        elif path == 'safety-budget-lines' and method == 'GET':
            handler._json(safety_budget.list_lines())
        elif path == 'safety-budget-lines' and method == 'POST':
            handler._json(safety_budget.create_line(body()), 201)
        elif match_sb_line and method == 'PUT':
            handler._json(safety_budget.update_line(int(match_sb_line[1]), body()))
        elif match_sb_line and method == 'DELETE':
            handler._json({'deleted': safety_budget.delete_line(int(match_sb_line[1]))})
        elif match_sb_upload and method == 'POST':
            content, filename, description = uploaded_file(safety_budget.MAX_FILE)
            handler._json(safety_budget.save_attachment(int(match_sb_upload[1]), filename, content, description), 201)
        elif match_sb_file and method == 'GET':
            _serve_file(handler, *safety_budget.get_attachment(int(match_sb_file[1])))
        elif path == 'safety-budget-export' and method == 'GET':
            content, file_name = safety_budget.export_excel()
            _serve_bytes(handler, content, file_name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        else:
            handler._error(404, '공사 금액 관리 API를 찾을 수 없습니다.')
    except service.Conflict as error:
        handler._error(409, str(error))
    except LookupError as error:
        handler._error(404, str(error))
    except (ValueError, TypeError, OverflowError) as error:
        handler._error(400, str(error) or '입력값을 확인하세요.')
    except Exception as error:
        handler.log_error('Billing error: %s', error)
        handler._error(500, '공사 금액 관리 처리 중 오류가 발생했습니다. 입력을 유지한 채 다시 시도하세요.')
    return True
