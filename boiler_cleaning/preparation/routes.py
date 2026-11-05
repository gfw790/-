"""Preparation-only HTTP dispatcher. Does not alter legacy API behavior."""
import json
import re
from urllib.parse import parse_qs, quote, urlparse

from . import cost_analysis, service, task_analysis


def dispatch(handler, method):
    parsed = urlparse(handler.path)
    if not parsed.path.startswith('/api/preparation/'):
        return False
    path = parsed.path.removeprefix('/api/preparation/')
    try:
        # Requests originate from this site's UI; reject cross-site browser writes.
        if method != 'GET':
            origin = handler.headers.get('Origin')
            if handler.headers.get('Sec-Fetch-Site') == 'cross-site' or (origin and urlparse(origin).netloc != handler.headers.get('Host')):
                handler._error(403, '다른 사이트에서 보낸 저장 요청은 허용되지 않습니다.')
                return True
        def body():
            size = int(handler.headers.get('Content-Length', '0'))
            if not 0 < size <= 128 * 1024: raise ValueError('요청 크기를 확인하세요.')
            if handler.headers.get('Content-Type', '').split(';')[0] != 'application/json':
                raise ValueError('JSON 요청이 필요합니다.')
            value = json.loads(handler.rfile.read(size).decode('utf-8'))
            if not isinstance(value, dict): raise ValueError('요청 형식을 확인하세요.')
            return value
        match_session = re.fullmatch(r'sessions/(\d+)', path)
        match_answer = re.fullmatch(r'sessions/(\d+)/answers/(\d+)', path)
        match_upload = re.fullmatch(r'answers/(\d+)/attachments', path)
        match_file = re.fullmatch(r'attachments/(\d+)/download', path)
        match_analysis = re.fullmatch(r'task-analysis/(\d+)', path)
        match_cost = re.fullmatch(r'cost-analysis/(\d+)', path)
        if path == 'task-analysis' and method == 'GET': handler._json(task_analysis.overview())
        elif match_analysis and method == 'PUT': handler._json(task_analysis.update(int(match_analysis[1]),body()))
        elif path == 'task-analysis/source' and method == 'GET':
            source = task_analysis.source_file()
            handler.send_response(200)
            handler.send_header('Content-Type','application/pdf')
            handler.send_header('Content-Disposition', "inline; filename*=UTF-8''" + quote(source.name))
            handler.send_header('Content-Length',str(source.stat().st_size))
            handler.send_header('X-Content-Type-Options','nosniff')
            handler.end_headers()
            with source.open('rb') as stream:
                while chunk := stream.read(1024*1024): handler.wfile.write(chunk)
        elif path == 'cost-analysis' and method == 'GET': handler._json(cost_analysis.overview())
        elif match_cost and method == 'PUT': handler._json(cost_analysis.update(int(match_cost[1]),body()))
        elif path == 'cost-analysis/source' and method == 'GET':
            source = cost_analysis.source_file()
            handler.send_response(200)
            handler.send_header('Content-Type','application/pdf')
            handler.send_header('Content-Disposition', "inline; filename*=UTF-8''" + quote(source.name))
            handler.send_header('Content-Length',str(source.stat().st_size))
            handler.send_header('X-Content-Type-Options','nosniff')
            handler.end_headers()
            with source.open('rb') as stream:
                while chunk := stream.read(1024*1024): handler.wfile.write(chunk)
        elif path == 'questions' and method == 'GET':
            with service.connection() as conn:
                handler._json(service.questions(conn))
        elif path == 'sessions' and method == 'GET': handler._json(service.list_sessions())
        elif path == 'sessions' and method == 'POST': handler._json(service.create_session(body()), 201)
        elif match_session and method == 'GET': handler._json(service.get_session(int(match_session[1])))
        elif match_session and method == 'PUT': handler._json(service.update_session(int(match_session[1]), body()))
        elif match_answer and method == 'PUT': handler._json(service.save_answer(int(match_answer[1]), int(match_answer[2]), body()))
        elif path == 'issues' and method == 'GET': handler._json(service.list_issues())
        elif match_upload and method == 'POST':
            size = int(handler.headers.get('Content-Length', '0'))
            if not 0 < size <= service.MAX_FILE: raise ValueError('파일은 15MB 이하로 첨부하세요.')
            query = parse_qs(parsed.query)
            content = handler.rfile.read(size)
            if len(content) != size: raise ValueError('파일 전송이 완료되지 않았습니다.')
            handler._json(service.save_attachment(int(match_upload[1]), query.get('filename',[''])[0], content, query.get('description',[''])[0]), 201)
        elif match_file and method == 'GET':
            file, metadata = service.get_attachment(int(match_file[1]))
            handler.send_response(200)
            handler.send_header('Content-Type', metadata['mime_type'])
            handler.send_header('X-Content-Type-Options', 'nosniff')
            handler.send_header('Content-Disposition', "attachment; filename*=UTF-8''" + quote(metadata['original_name']))
            handler.send_header('Content-Length', str(file.stat().st_size))
            handler.send_header('Cache-Control', 'no-store')
            handler.end_headers()
            with file.open('rb') as stream:
                while chunk := stream.read(1024 * 1024): handler.wfile.write(chunk)
        else: handler._error(404, '착공 준비 API를 찾을 수 없습니다.')
    except service.Conflict as error: handler._error(409, str(error))
    except LookupError as error: handler._error(404, str(error))
    except (ValueError, TypeError, OverflowError) as error: handler._error(400, str(error) or '입력값을 확인하세요.')
    except Exception as error:
        handler.log_error('Preparation error: %s', error)
        handler._error(500, '착공 준비 처리 중 오류가 발생했습니다. 입력을 유지한 채 다시 시도하세요.')
    return True
