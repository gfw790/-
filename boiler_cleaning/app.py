from __future__ import annotations

import argparse
import json
import mimetypes
import os
import sys
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, quote, unquote, urlparse

import billing.expenditure as billing_expenditure
import billing.personnel as billing_personnel
import billing.safety_budget as billing_safety_budget
import billing.service as billing_service
import db
from billing.routes import dispatch as billing_dispatch
from preparation import cost_analysis
from preparation import service as preparation_service
from preparation import task_analysis
from preparation.routes import dispatch as preparation_dispatch


BASE_DIR = Path(__file__).resolve().parent
STATIC_DIR = BASE_DIR / "static"
MAX_UPLOAD_SIZE = 100 * 1024 * 1024
MAX_JSON_SIZE = 30 * 1024 * 1024


class RiskRequestHandler(BaseHTTPRequestHandler):
    server_version = "BoilerCleaningRiskServer/1.0"

    def do_GET(self) -> None:
        if preparation_dispatch(self, 'GET'):
            return
        if billing_dispatch(self, 'GET'):
            return
        parsed = urlparse(self.path)
        path = parsed.path
        try:
            if path == "/health":
                self._json({"status": "ok"})
            elif path == "/api/dashboard":
                self._json(db.dashboard())
            elif path == "/api/project":
                self._json(db.get_project())
            elif path == "/api/documents":
                kind = parse_qs(parsed.query).get("kind", [None])[0]
                self._json(db.list_documents(kind))
            elif path == "/api/logs":
                self._json(db.recent_logs())
            elif path.startswith("/api/documents/") and path.endswith("/entries"):
                self._json(db.list_form_entries(self._document_id(path, "/entries")))
            elif path.startswith("/api/documents/") and path.endswith("/versions"):
                self._json(db.list_versions(self._document_id(path, "/versions")))
            elif path.startswith("/api/documents/") and path.endswith("/download"):
                document_id = self._document_id(path, "/download")
                version_value = parse_qs(parsed.query).get("version", [None])[0]
                self._download(document_id, int(version_value) if version_value else None)
            elif path.startswith("/api/entries/") and path.endswith("/export-xlsx"):
                self._export_entry_excel(self._entry_id(path.removesuffix("/export-xlsx")))
            elif path.startswith("/api/documents/") and path.endswith("/export-xlsx"):
                self._export_document_excel(self._document_id(path, "/export-xlsx"))
            elif path in {"/", "/index.html"}:
                self._static("index.html")
            elif path.startswith("/static/"):
                self._static(path.removeprefix("/static/"))
            else:
                self._error(HTTPStatus.NOT_FOUND, "요청한 주소를 찾을 수 없습니다.")
        except (ValueError, TypeError):
            self._error(HTTPStatus.BAD_REQUEST, "잘못된 요청입니다.")
        except Exception as error:
            self.log_error("GET error: %s", error)
            self._error(HTTPStatus.INTERNAL_SERVER_ERROR, "서버 처리 중 오류가 발생했습니다.")

    def do_PUT(self) -> None:
        if preparation_dispatch(self, 'PUT'):
            return
        if billing_dispatch(self, 'PUT'):
            return
        path = urlparse(self.path).path
        try:
            payload = self._read_json()
            if path == "/api/project":
                self._json(db.update_project(payload))
            elif path.startswith("/api/entries/"):
                entry = db.update_form_entry(self._entry_id(path), payload)
                self._json(entry) if entry else self._error(HTTPStatus.NOT_FOUND, "입력기록을 찾을 수 없습니다.")
            elif path.startswith("/api/documents/"):
                document = db.update_document(self._document_id(path), payload)
                self._json(document) if document else self._error(HTTPStatus.NOT_FOUND, "문서를 찾을 수 없습니다.")
            else:
                self._error(HTTPStatus.NOT_FOUND, "요청한 주소를 찾을 수 없습니다.")
        except (ValueError, TypeError) as error:
            self._error(HTTPStatus.BAD_REQUEST, str(error) or "입력값을 확인해주세요.")
        except Exception as error:
            self.log_error("PUT error: %s", error)
            self._error(HTTPStatus.INTERNAL_SERVER_ERROR, "저장 중 오류가 발생했습니다.")

    def do_POST(self) -> None:
        if preparation_dispatch(self, 'POST'):
            return
        if billing_dispatch(self, 'POST'):
            return
        parsed = urlparse(self.path)
        try:
            if parsed.path.startswith("/api/documents/") and parsed.path.endswith("/entries"):
                entry = db.create_form_entry(self._document_id(parsed.path, "/entries"), self._read_json())
                self._json(entry, HTTPStatus.CREATED)
            elif parsed.path.startswith("/api/documents/") and parsed.path.endswith("/upload"):
                document_id = self._document_id(parsed.path, "/upload")
                content_length = int(self.headers.get("Content-Length", "0"))
                if content_length <= 0 or content_length > MAX_UPLOAD_SIZE:
                    raise ValueError("파일 크기는 100MB 이하여야 합니다.")
                file_name = parse_qs(parsed.query).get("filename", [""])[0]
                if not file_name:
                    raise ValueError("파일명이 없습니다.")
                document = db.save_upload(document_id, unquote(file_name), self.rfile.read(content_length))
                self._json(document, HTTPStatus.CREATED) if document else self._error(HTTPStatus.NOT_FOUND, "문서를 찾을 수 없습니다.")
            else:
                self._error(HTTPStatus.NOT_FOUND, "요청한 주소를 찾을 수 없습니다.")
        except ValueError as error:
            self._error(HTTPStatus.BAD_REQUEST, str(error))
        except Exception as error:
            self.log_error("POST error: %s", error)
            self._error(HTTPStatus.INTERNAL_SERVER_ERROR, "업로드 중 오류가 발생했습니다.")

    def do_DELETE(self) -> None:
        if preparation_dispatch(self, 'DELETE'):
            return
        if billing_dispatch(self, 'DELETE'):
            return
        path = urlparse(self.path).path
        try:
            if path.startswith("/api/entries/"):
                if db.delete_form_entry(self._entry_id(path)):
                    self._json({"deleted": True})
                else:
                    self._error(HTTPStatus.NOT_FOUND, "입력기록을 찾을 수 없습니다.")
            else:
                self._error(HTTPStatus.NOT_FOUND, "요청한 주소를 찾을 수 없습니다.")
        except (ValueError, TypeError):
            self._error(HTTPStatus.BAD_REQUEST, "잘못된 요청입니다.")
        except Exception as error:
            self.log_error("DELETE error: %s", error)
            self._error(HTTPStatus.INTERNAL_SERVER_ERROR, "삭제 중 오류가 발생했습니다.")

    def _document_id(self, path: str, suffix: str = "") -> int:
        value = path.removeprefix("/api/documents/")
        if suffix:
            value = value[: -len(suffix)].rstrip("/")
        return int(value)

    def _entry_id(self, path: str) -> int:
        return int(path.removeprefix("/api/entries/").strip("/"))

    def _read_json(self) -> dict:
        content_length = int(self.headers.get("Content-Length", "0"))
        if content_length <= 0 or content_length > MAX_JSON_SIZE:
            raise ValueError("입력 크기를 확인해주세요. (사진 용량이 너무 크면 줄여서 첨부해주세요)")
        return json.loads(self.rfile.read(content_length).decode("utf-8"))

    def _json(self, payload: object, status: HTTPStatus = HTTPStatus.OK) -> None:
        content = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(content)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(content)

    def _error(self, status: HTTPStatus, message: str) -> None:
        self._json({"error": message}, status)

    def _static(self, relative_name: str) -> None:
        path = (STATIC_DIR / relative_name).resolve()
        if STATIC_DIR.resolve() not in path.parents or not path.is_file():
            self._error(HTTPStatus.NOT_FOUND, "파일을 찾을 수 없습니다.")
            return
        content = path.read_bytes()
        content_type = mimetypes.guess_type(path.name)[0] or "application/octet-stream"
        self.send_response(HTTPStatus.OK)
        self.send_header("Content-Type", f"{content_type}; charset=utf-8" if content_type.startswith("text/") else content_type)
        self.send_header("Content-Length", str(len(content)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(content)

    def _download(self, document_id: int, version_id: int | None) -> None:
        result = db.resolve_document_file(document_id, version_id)
        if result is None:
            self._error(HTTPStatus.NOT_FOUND, "등록된 파일을 찾을 수 없습니다.")
            return
        path, file_name = result
        self.send_response(HTTPStatus.OK)
        self.send_header("Content-Type", mimetypes.guess_type(file_name)[0] or "application/octet-stream")
        self.send_header("Content-Length", str(path.stat().st_size))
        self.send_header("Content-Disposition", f"attachment; filename*=UTF-8''{quote(file_name)}")
        self.end_headers()
        with path.open("rb") as stream:
            while chunk := stream.read(1024 * 1024):
                self.wfile.write(chunk)

    def _export_entry_excel(self, entry_id: int) -> None:
        try:
            content, file_name = db.export_form_entry_excel(entry_id)
        except LookupError as error:
            self._error(HTTPStatus.NOT_FOUND, str(error))
            return
        except ValueError as error:
            self._error(HTTPStatus.BAD_REQUEST, str(error))
            return
        self.send_response(HTTPStatus.OK)
        self.send_header("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
        self.send_header("Content-Length", str(len(content)))
        self.send_header("Content-Disposition", f"attachment; filename*=UTF-8''{quote(file_name)}")
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(content)

    def _export_document_excel(self, document_id: int) -> None:
        try:
            content, file_name = db.export_document_ledger_excel(document_id)
        except LookupError as error:
            self._error(HTTPStatus.NOT_FOUND, str(error))
            return
        except ValueError as error:
            self._error(HTTPStatus.BAD_REQUEST, str(error))
            return
        self.send_response(HTTPStatus.OK)
        self.send_header("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
        self.send_header("Content-Length", str(len(content)))
        self.send_header("Content-Disposition", f"attachment; filename*=UTF-8''{quote(file_name)}")
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(content)

    def log_message(self, format_text: str, *args: object) -> None:
        sys.stdout.write(f"{self.log_date_time_string()} {self.client_address[0]} {format_text % args}\n")


def main() -> None:
    parser = argparse.ArgumentParser(description="안전보건 문서 관리 서버")
    parser.add_argument("--host", default=os.environ.get("RISK_SERVER_HOST", "127.0.0.1"))
    parser.add_argument("--port", type=int, default=int(os.environ.get("RISK_SERVER_PORT", "8080")))
    arguments = parser.parse_args()
    backup = preparation_service.migrate()
    if backup:
        print(f'착공 준비 마이그레이션 전 DB 백업: {backup}')
    analysis_backup = task_analysis.migrate()
    if analysis_backup:
        print(f'과업 분석 마이그레이션 전 DB 백업: {analysis_backup}')
    cost_backup = cost_analysis.migrate()
    if cost_backup:
        print(f'원가 분석 마이그레이션 전 DB 백업: {cost_backup}')
    billing_backup = billing_service.migrate()
    if billing_backup:
        print(f'공사 금액 관리 마이그레이션 전 DB 백업: {billing_backup}')
    expenditure_backup = billing_expenditure.migrate()
    if expenditure_backup:
        print(f'집행 관리 마이그레이션 전 DB 백업: {expenditure_backup}')
    personnel_backup = billing_personnel.migrate()
    if personnel_backup:
        print(f'인원 관리 마이그레이션 전 DB 백업: {personnel_backup}')
    safety_budget_backup = billing_safety_budget.migrate()
    if safety_budget_backup:
        print(f'안전관리비 마이그레이션 전 DB 백업: {safety_budget_backup}')
    db.initialize()
    server = ThreadingHTTPServer((arguments.host, arguments.port), RiskRequestHandler)
    print(f"안전보건 문서 관리 서버: http://{arguments.host}:{arguments.port}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
