<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>안전보호구 관리</title>
    <style>
        :root {
            --bg: #edf3f8;
            --panel: #ffffff;
            --line: #d7e0ea;
            --text: #122033;
            --muted: #64748b;
            --accent: #0f766e;
            --accent-soft: #ccfbf1;
            --secondary: #e2e8f0;
            --danger: #b91c1c;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Malgun Gothic", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top right, rgba(20, 184, 166, 0.14), transparent 22%),
                linear-gradient(180deg, #f8fbfd 0%, var(--bg) 100%);
        }

        .page {
            width: min(1360px, calc(100vw - 28px));
            margin: 18px auto 28px;
            display: grid;
            grid-template-columns: minmax(0, 820px) 480px;
            gap: 18px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            padding: 18px;
        }

        h1, h2, h3 { margin: 0; }
        .lead {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .full { grid-column: 1 / -1; }
        .field { display: grid; gap: 6px; }
        .field label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 700;
        }

        input, select, textarea, button { font: inherit; }
        input[type="text"], input[type="date"], input[type="datetime-local"], input[type="number"], select, textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
            background: #fff;
        }

        textarea {
            min-height: 92px;
            resize: vertical;
        }

        button {
            border: 0;
            border-radius: 12px;
            padding: 10px 14px;
            cursor: pointer;
            background: var(--accent);
            color: #fff;
        }

        button.secondary { background: var(--secondary); color: #0f172a; }
        button.ghost { background: var(--accent-soft); color: #115e59; }
        button.danger { background: var(--danger); }

        .row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .row > .grow {
            flex: 1 1 220px;
        }

        .status {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #f8fafc;
            color: #334155;
            font-size: 13px;
        }

        .scanner {
            margin-top: 16px;
            border: 1px solid var(--line);
            border-radius: 16px;
            overflow: hidden;
            background: #0f172a;
        }

        .scanner video {
            width: 100%;
            display: block;
            min-height: 220px;
            background: #111827;
        }

        .scanner-bar {
            padding: 12px;
            background: rgba(15, 23, 42, 0.92);
            color: #e2e8f0;
            font-size: 13px;
        }

        .hint {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            background: #ecfeff;
            color: #155e75;
            font-size: 12px;
            font-weight: 700;
        }

        .qr-box {
            display: grid;
            place-items: center;
            border: 1px dashed var(--line);
            border-radius: 16px;
            min-height: 190px;
            background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
        }

        .qr-box img {
            max-width: 220px;
            width: 100%;
            height: auto;
            display: block;
        }

        .history-list, .recent-list {
            display: grid;
            gap: 10px;
            margin-top: 14px;
            max-height: 320px;
            overflow: auto;
        }

        .history-item {
            border-left: 4px solid #14b8a6;
            background: #f8fafc;
            border-radius: 0 12px 12px 0;
            padding: 10px 12px;
        }

        .history-meta, .recent-meta {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.5;
        }

        .recent-item {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px;
            background: #fbfdff;
            cursor: pointer;
        }

        .recent-item:hover {
            border-color: #94a3b8;
        }

        .recent-title {
            font-weight: 700;
            margin-bottom: 4px;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .section-head .count {
            color: var(--muted);
            font-size: 12px;
        }

        @media (max-width: 1160px) {
            .page {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="panel">
            <h1>안전보호구 관리</h1>
            <p class="lead">바코드 또는 QR 코드를 스캔해 등록하고, 보호구 종류·구매처·구매가격·지급자·상태·이력을 함께 관리합니다. 구조는 RFID/NFC 확장도 가능한 형태로 열어 두었습니다.</p>

            <div class="grid" style="margin-top:16px;">
                <div class="field">
                    <label for="template_select">제품 템플릿</label>
                    <select id="template_select">
                        <option value="">선택 안 함</option>
                    </select>
                </div>
                <div class="field">
                    <label for="template_name">템플릿 이름</label>
                    <div class="row">
                        <input id="template_name" class="grow" type="text" placeholder="예: K2 안전모 기본형">
                        <button id="saveTemplateButton" type="button" class="ghost">템플릿 저장</button>
                        <button id="deleteTemplateButton" type="button" class="secondary">템플릿 삭제</button>
                    </div>
                </div>
                <div class="field">
                    <label for="identifier_type">식별 방식</label>
                    <select id="identifier_type">
                        <option value="barcode">바코드</option>
                        <option value="qr">QR 코드</option>
                        <option value="internal">내부 관리번호</option>
                        <option value="rfid">RFID</option>
                        <option value="nfc">NFC</option>
                    </select>
                </div>
                <div class="field">
                    <label for="identifier_value">식별값</label>
                    <div class="row">
                        <input id="identifier_value" class="grow" type="text" placeholder="스캔 또는 직접 입력">
                        <button id="generateInternalKeyButton" type="button" class="ghost">내부 키 생성</button>
                    </div>
                </div>
                <div class="field">
                    <label for="gear_type">보호구 종류</label>
                    <input id="gear_type" type="text" placeholder="예: 안전모, 안전벨트, 방진마스크">
                </div>
                <div class="field">
                    <label for="product_name">품명/모델</label>
                    <input id="product_name" type="text" placeholder="예: K2 안전모 화이트">
                </div>
                <div class="field">
                    <label for="purchase_vendor">구매처</label>
                    <input id="purchase_vendor" type="text" placeholder="예: 안전마트">
                </div>
                <div class="field">
                    <label for="purchase_price">구매가격</label>
                    <input id="purchase_price" type="number" min="0" step="1" placeholder="예: 15000">
                </div>
                <div class="field">
                    <label for="purchased_at">구매일</label>
                    <input id="purchased_at" type="date">
                </div>
                <div class="field">
                    <label for="status">상태</label>
                    <select id="status">
                        <option value="사용 가능">사용 가능</option>
                        <option value="지급됨">지급됨</option>
                        <option value="점검 필요">점검 필요</option>
                        <option value="수리 중">수리 중</option>
                        <option value="폐기">폐기</option>
                    </select>
                </div>
                <div class="field">
                    <label for="assigned_employee_id">지급자</label>
                    <select id="assigned_employee_id">
                        <option value="">선택 안 함</option>
                    </select>
                </div>
                <div class="field">
                    <label for="assigned_team">지급팀</label>
                    <input id="assigned_team" type="text" placeholder="직원 선택 시 자동 입력">
                </div>
                <div class="field">
                    <label for="assigned_at">지급일시</label>
                    <input id="assigned_at" type="datetime-local">
                </div>
                <div class="field">
                    <label for="assigned_employee_name">지급자명</label>
                    <input id="assigned_employee_name" type="text" placeholder="직원 선택 시 자동 입력">
                </div>
                <div class="field full">
                    <label for="notes">메모</label>
                    <textarea id="notes" placeholder="추가 메모를 입력하세요."></textarea>
                </div>
            </div>

            <div class="row" style="margin-top:14px;">
                <button id="saveButton" type="button">저장</button>
                <button id="newButton" type="button" class="secondary">신규 입력</button>
                <button id="findButton" type="button" class="secondary">식별값 조회</button>
                <button id="deleteButton" type="button" class="danger">삭제</button>
                <label class="hint" style="display:flex; align-items:center; gap:6px; margin-left:4px;">
                    <input id="continuousMode" type="checkbox">
                    연속 등록 모드
                </label>
            </div>

            <div class="scanner">
                <video id="scannerVideo" playsinline muted></video>
                <div class="scanner-bar">
                    <div class="row">
                        <button id="startScanButton" type="button">카메라 스캔 시작</button>
                        <button id="stopScanButton" type="button" class="secondary">카메라 중지</button>
                    </div>
                    <div class="hint" style="color:#cbd5e1; margin-top:8px;">BarcodeDetector를 지원하는 브라우저에서는 카메라 스캔이 가능하고, 그렇지 않으면 직접 입력으로도 등록할 수 있습니다.</div>
                </div>
            </div>

            <div id="statusBox" class="status">준비되었습니다. 스캔하거나 식별값을 입력해 주세요.</div>

            <h3 style="margin-top:22px;">추적 이력</h3>
            <div class="grid" style="margin-top:12px;">
                <div class="field">
                    <label for="history_type">이력 종류</label>
                    <select id="history_type">
                        <option value="입고">입고</option>
                        <option value="지급">지급</option>
                        <option value="회수">회수</option>
                        <option value="점검">점검</option>
                        <option value="수리">수리</option>
                        <option value="폐기">폐기</option>
                        <option value="비고">비고</option>
                    </select>
                </div>
                <div class="field full">
                    <label for="history_note">이력 내용</label>
                    <div class="row">
                        <input id="history_note" class="grow" type="text" placeholder="예: 홍길동 지급, 외관 점검 완료">
                        <button id="addHistoryButton" type="button" class="ghost">이력 추가</button>
                    </div>
                </div>
            </div>
            <div id="historyList" class="history-list"></div>
        </section>

        <aside class="panel">
            <div class="section-head">
                <h2>선택 항목</h2>
                <div id="currentItemBadge" class="badge">신규 등록 모드</div>
            </div>
            <div class="qr-box" style="margin-top:14px;">
                <img id="qrImage" alt="QR 코드" hidden>
                <div id="qrEmpty" class="hint">식별값이 있으면 QR 미리보기가 표시됩니다.</div>
            </div>

            <div class="section-head" style="margin-top:22px;">
                <h3>등록 목록</h3>
                <span id="recentCount" class="count"></span>
            </div>
            <div class="row" style="margin-top:10px;">
                <input id="searchInput" class="grow" type="text" placeholder="식별값, 보호구, 구매처, 지급자 검색">
                <button id="searchButton" type="button" class="secondary">검색</button>
                <button id="searchResetButton" type="button" class="secondary">초기화</button>
                <button id="exportButton" type="button" class="ghost">CSV 다운로드</button>
            </div>
            <div class="hint" style="margin-top:8px;">최근 수정된 순서로 보입니다. 항목을 누르면 상세가 왼쪽에 채워집니다.</div>
            <div id="recentList" class="recent-list"></div>
        </aside>
    </div>

    <script>
        const apiEndpoint = 'api.php';
        const exportEndpoint = 'export.php';
        const qrEndpoint = 'qr.php';
        const state = {
            items: [],
            employees: [],
            templates: [],
            currentItemId: '',
            currentTemplateId: '',
            stream: null,
            scanTimer: null,
            searchQuery: ''
        };

        const fields = {
            templateSelect: document.getElementById('template_select'),
            templateName: document.getElementById('template_name'),
            identifierType: document.getElementById('identifier_type'),
            identifierValue: document.getElementById('identifier_value'),
            gearType: document.getElementById('gear_type'),
            productName: document.getElementById('product_name'),
            purchaseVendor: document.getElementById('purchase_vendor'),
            purchasePrice: document.getElementById('purchase_price'),
            purchasedAt: document.getElementById('purchased_at'),
            status: document.getElementById('status'),
            assignedEmployeeId: document.getElementById('assigned_employee_id'),
            assignedEmployeeName: document.getElementById('assigned_employee_name'),
            assignedTeam: document.getElementById('assigned_team'),
            assignedAt: document.getElementById('assigned_at'),
            notes: document.getElementById('notes'),
            historyType: document.getElementById('history_type'),
            historyNote: document.getElementById('history_note'),
            statusBox: document.getElementById('statusBox'),
            historyList: document.getElementById('historyList'),
            recentList: document.getElementById('recentList'),
            recentCount: document.getElementById('recentCount'),
            currentItemBadge: document.getElementById('currentItemBadge'),
            qrImage: document.getElementById('qrImage'),
            qrEmpty: document.getElementById('qrEmpty'),
            scannerVideo: document.getElementById('scannerVideo'),
            searchInput: document.getElementById('searchInput'),
            continuousMode: document.getElementById('continuousMode')
        };

        function setStatus(message, isError) {
            fields.statusBox.textContent = message;
            fields.statusBox.style.color = isError ? '#991b1b' : '#334155';
            fields.statusBox.style.background = isError ? '#fee2e2' : '#f8fafc';
        }

        async function apiRequest(params, method) {
            const requestMethod = method || 'GET';
            let url = apiEndpoint;
            const options = {
                method: requestMethod,
                headers: { 'Accept': 'application/json' }
            };

            if (requestMethod === 'GET') {
                url += '?' + new URLSearchParams(params || {}).toString();
            } else {
                options.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
                options.body = new URLSearchParams(params || {}).toString();
            }

            const response = await fetch(url, options);
            const payload = await response.json();
            if (!response.ok || !payload || payload.ok !== true) {
                throw new Error(payload && payload.message ? payload.message : '요청 처리 중 오류가 발생했습니다.');
            }
            return payload;
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatDatetimeLocal(value) {
            const raw = String(value || '').trim();
            if (!raw) {
                return '';
            }
            return raw.slice(0, 16).replace(' ', 'T');
        }

        function updateQrPreview() {
            const value = String(fields.identifierValue.value || '').trim();
            if (!value) {
                fields.qrImage.hidden = true;
                fields.qrEmpty.hidden = false;
                return;
            }
            fields.qrImage.src = qrEndpoint + '?data=' + encodeURIComponent(value);
            fields.qrImage.hidden = false;
            fields.qrEmpty.hidden = true;
        }

        function renderEmployees() {
            const select = fields.assignedEmployeeId;
            select.innerHTML = '<option value="">선택 안 함</option>';
            state.employees.forEach(function (employee) {
                const option = document.createElement('option');
                option.value = employee.id;
                option.textContent = '[' + (employee.team || '-') + '] ' + employee.name + (employee.position ? ' / ' + employee.position : '');
                option.dataset.name = employee.name || '';
                option.dataset.team = employee.team || '';
                select.appendChild(option);
            });
        }

        function renderTemplates() {
            fields.templateSelect.innerHTML = '<option value="">선택 안 함</option>';
            state.templates.forEach(function (template) {
                const option = document.createElement('option');
                option.value = template.id;
                option.textContent = template.template_name + ' / ' + template.gear_type;
                fields.templateSelect.appendChild(option);
            });
            if (state.currentTemplateId) {
                fields.templateSelect.value = state.currentTemplateId;
            }
        }

        function renderHistory(history) {
            fields.historyList.innerHTML = '';
            const items = Array.isArray(history) ? history : [];
            if (!items.length) {
                fields.historyList.innerHTML = '<div class="history-item"><div class="history-meta">이력이 없습니다.</div><div>저장 후 이력을 추가할 수 있습니다.</div></div>';
                return;
            }

            items.forEach(function (entry) {
                const element = document.createElement('div');
                element.className = 'history-item';
                element.innerHTML =
                    '<div class="history-meta">' + escapeHtml(entry.timestamp || '') + ' / ' + escapeHtml(entry.type || '') + '</div>' +
                    '<div>' + escapeHtml(entry.note || '') + '</div>';
                fields.historyList.appendChild(element);
            });
        }

        function clearForm() {
            state.currentItemId = '';
            fields.identifierType.value = 'barcode';
            fields.identifierValue.value = '';
            fields.gearType.value = '';
            fields.productName.value = '';
            fields.purchaseVendor.value = '';
            fields.purchasePrice.value = '';
            fields.purchasedAt.value = '';
            fields.status.value = '사용 가능';
            fields.assignedEmployeeId.value = '';
            fields.assignedEmployeeName.value = '';
            fields.assignedTeam.value = '';
            fields.assignedAt.value = '';
            fields.notes.value = '';
            fields.historyNote.value = '';
            fields.currentItemBadge.textContent = '신규 등록 모드';
            renderHistory([]);
            updateQrPreview();
        }

        function clearForContinuousRegistration() {
            state.currentItemId = '';
            fields.identifierValue.value = '';
            fields.assignedEmployeeId.value = '';
            fields.assignedEmployeeName.value = '';
            fields.assignedTeam.value = '';
            fields.assignedAt.value = '';
            fields.historyNote.value = '';
            fields.currentItemBadge.textContent = '연속 등록 대기';
            renderHistory([]);
            updateQrPreview();
            fields.identifierValue.focus();
        }

        function fillForm(item) {
            const entry = item || {};
            state.currentItemId = entry.id || '';
            fields.identifierType.value = entry.identifier_type || 'barcode';
            fields.identifierValue.value = entry.identifier_value || '';
            fields.gearType.value = entry.gear_type || '';
            fields.productName.value = entry.product_name || '';
            fields.purchaseVendor.value = entry.purchase_vendor || '';
            fields.purchasePrice.value = entry.purchase_price || '';
            fields.purchasedAt.value = entry.purchased_at || '';
            fields.status.value = entry.status || '사용 가능';
            fields.assignedEmployeeId.value = entry.assigned_employee_id || '';
            fields.assignedEmployeeName.value = entry.assigned_employee_name || '';
            fields.assignedTeam.value = entry.assigned_team || '';
            fields.assignedAt.value = formatDatetimeLocal(entry.assigned_at || '');
            fields.notes.value = entry.notes || '';
            fields.currentItemBadge.textContent = state.currentItemId ? '등록 항목 수정 모드' : '신규 등록 모드';
            renderHistory(entry.history || []);
            updateQrPreview();
        }

        function applyTemplate(template) {
            const entry = template || null;
            state.currentTemplateId = entry ? (entry.id || '') : '';
            fields.templateSelect.value = state.currentTemplateId;
            fields.templateName.value = entry ? (entry.template_name || '') : '';
            if (!entry) {
                return;
            }
            fields.gearType.value = entry.gear_type || '';
            fields.productName.value = entry.product_name || '';
            fields.purchaseVendor.value = entry.purchase_vendor || '';
            fields.purchasePrice.value = entry.purchase_price || '';
            fields.status.value = entry.status || '사용 가능';
            fields.notes.value = entry.notes || '';
        }

        function renderRecentList() {
            fields.recentList.innerHTML = '';
            fields.recentCount.textContent = '총 ' + state.items.length + '건';

            if (!state.items.length) {
                fields.recentList.innerHTML = '<div class="recent-item"><div class="recent-title">등록된 보호구가 없습니다.</div><div class="recent-meta">첫 항목을 등록해 주세요.</div></div>';
                return;
            }

            state.items.forEach(function (item) {
                const node = document.createElement('div');
                node.className = 'recent-item';
                node.innerHTML =
                    '<div class="recent-title">' + escapeHtml(item.gear_type || '미분류') + '</div>' +
                    '<div class="recent-meta">' +
                    '식별값: ' + escapeHtml(item.identifier_value || '') + '<br>' +
                    '구매처: ' + escapeHtml(item.purchase_vendor || '-') + '<br>' +
                    '지급자: ' + escapeHtml(item.assigned_employee_name || '-') + '<br>' +
                    '상태: ' + escapeHtml(item.status || '-') +
                    '</div>';
                node.addEventListener('click', function () {
                    fillForm(item);
                    setStatus('항목을 불러왔습니다.', false);
                });
                fields.recentList.appendChild(node);
            });
        }

        async function loadEmployees() {
            const payload = await apiRequest({ action: 'employees' });
            state.employees = Array.isArray(payload.employees) ? payload.employees : [];
            renderEmployees();
        }

        async function loadTemplates() {
            const payload = await apiRequest({ action: 'templates' });
            state.templates = Array.isArray(payload.templates) ? payload.templates : [];
            renderTemplates();
        }

        async function loadItems() {
            const payload = await apiRequest({ action: 'list', q: state.searchQuery });
            state.items = Array.isArray(payload.items) ? payload.items : [];
            renderRecentList();
        }

        function buildSaveParams() {
            return {
                action: 'save_item',
                id: state.currentItemId,
                identifier_type: fields.identifierType.value,
                identifier_value: fields.identifierValue.value.trim(),
                gear_type: fields.gearType.value.trim(),
                product_name: fields.productName.value.trim(),
                purchase_vendor: fields.purchaseVendor.value.trim(),
                purchase_price: fields.purchasePrice.value.trim(),
                purchased_at: fields.purchasedAt.value,
                status: fields.status.value,
                assigned_employee_id: fields.assignedEmployeeId.value,
                assigned_employee_name: fields.assignedEmployeeName.value.trim(),
                assigned_team: fields.assignedTeam.value.trim(),
                assigned_at: fields.assignedAt.value ? fields.assignedAt.value.replace('T', ' ') + ':00' : '',
                notes: fields.notes.value.trim()
            };
        }

        async function saveCurrentItem() {
            const payload = await apiRequest(buildSaveParams(), 'POST');
            state.items = Array.isArray(payload.items) ? payload.items : [];
            renderRecentList();
            if (fields.continuousMode.checked) {
                clearForContinuousRegistration();
                setStatus('저장되었습니다. 다음 식별값을 바로 스캔하거나 입력해 주세요.', false);
                return;
            }
            fillForm(payload.item || null);
            setStatus(payload.message || '저장되었습니다.', false);
        }

        async function findByIdentifier() {
            const identifier = fields.identifierValue.value.trim();
            if (!identifier) {
                setStatus('먼저 식별값을 입력해 주세요.', true);
                return;
            }

            const payload = await apiRequest({ action: 'find', identifier: identifier });
            if (!payload.found) {
                state.currentItemId = '';
                fields.currentItemBadge.textContent = '신규 등록 모드';
                renderHistory([]);
                updateQrPreview();
                setStatus('등록되지 않은 식별값입니다. 신규 정보로 저장할 수 있습니다.', false);
                return;
            }

            fillForm(payload.item || null);
            setStatus('기존 등록 항목을 찾았습니다.', false);
        }

        async function addHistory() {
            if (!state.currentItemId) {
                setStatus('먼저 보호구를 저장해 주세요.', true);
                return;
            }

            const payload = await apiRequest({
                action: 'add_history',
                id: state.currentItemId,
                history_type: fields.historyType.value,
                history_note: fields.historyNote.value.trim()
            }, 'POST');

            state.items = Array.isArray(payload.items) ? payload.items : [];
            fillForm(payload.item || null);
            renderRecentList();
            fields.historyNote.value = '';
            setStatus(payload.message || '이력이 추가되었습니다.', false);
        }

        async function deleteCurrentItem() {
            if (!state.currentItemId) {
                setStatus('삭제할 항목을 먼저 선택해 주세요.', true);
                return;
            }
            if (!window.confirm('선택한 보호구 항목을 삭제할까요?')) {
                return;
            }

            const payload = await apiRequest({
                action: 'delete_item',
                id: state.currentItemId
            }, 'POST');

            state.items = Array.isArray(payload.items) ? payload.items : [];
            clearForm();
            renderRecentList();
            setStatus(payload.message || '삭제되었습니다.', false);
        }

        async function saveTemplate() {
            const payload = await apiRequest({
                action: 'save_template',
                template_id: state.currentTemplateId,
                template_name: fields.templateName.value.trim(),
                gear_type: fields.gearType.value.trim(),
                product_name: fields.productName.value.trim(),
                purchase_vendor: fields.purchaseVendor.value.trim(),
                purchase_price: fields.purchasePrice.value.trim(),
                status: fields.status.value,
                notes: fields.notes.value.trim()
            }, 'POST');

            state.templates = Array.isArray(payload.templates) ? payload.templates : [];
            const savedTemplate = state.templates.find(function (template) {
                return template.template_name === fields.templateName.value.trim();
            }) || null;
            state.currentTemplateId = savedTemplate ? savedTemplate.id : '';
            renderTemplates();
            setStatus(payload.message || '템플릿이 저장되었습니다.', false);
        }

        async function deleteTemplate() {
            if (!state.currentTemplateId) {
                setStatus('삭제할 템플릿을 먼저 선택해 주세요.', true);
                return;
            }
            if (!window.confirm('선택한 템플릿을 삭제할까요?')) {
                return;
            }

            const payload = await apiRequest({
                action: 'delete_template',
                template_id: state.currentTemplateId
            }, 'POST');

            state.templates = Array.isArray(payload.templates) ? payload.templates : [];
            state.currentTemplateId = '';
            fields.templateName.value = '';
            renderTemplates();
            setStatus(payload.message || '템플릿이 삭제되었습니다.', false);
        }

        async function createInternalKey() {
            const payload = await apiRequest({ action: 'create_internal_key' });
            fields.identifierType.value = payload.identifier_type || 'internal';
            fields.identifierValue.value = payload.identifier_value || '';
            updateQrPreview();
            setStatus('내부 관리키를 생성했습니다.', false);
        }

        async function applySearch() {
            state.searchQuery = String(fields.searchInput.value || '').trim();
            await loadItems();
            setStatus(state.searchQuery ? '검색 결과를 불러왔습니다.' : '전체 목록을 불러왔습니다.', false);
        }

        function resetSearch() {
            fields.searchInput.value = '';
            state.searchQuery = '';
            loadItems().then(function () {
                setStatus('검색을 초기화했습니다.', false);
            }).catch(function (error) {
                setStatus(error.message || '목록을 다시 불러오지 못했습니다.', true);
            });
        }

        function downloadExport() {
            const query = String(fields.searchInput.value || '').trim();
            const url = exportEndpoint + (query ? ('?q=' + encodeURIComponent(query)) : '');
            window.location.href = url;
        }

        async function startScanner() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                setStatus('이 브라우저는 카메라를 지원하지 않습니다.', true);
                return;
            }
            if (!('BarcodeDetector' in window)) {
                setStatus('이 브라우저는 BarcodeDetector를 지원하지 않습니다. 직접 입력으로 등록해 주세요.', true);
                return;
            }

            const detector = new BarcodeDetector({
                formats: ['qr_code', 'code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a', 'upc_e']
            });

            stopScanner();

            try {
                state.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false
                });
                fields.scannerVideo.srcObject = state.stream;
                await fields.scannerVideo.play();

                state.scanTimer = window.setInterval(async function () {
                    try {
                        const codes = await detector.detect(fields.scannerVideo);
                        if (!codes || !codes.length) {
                            return;
                        }

                        const code = codes[0];
                        const rawValue = String(code.rawValue || '').trim();
                        if (!rawValue) {
                            return;
                        }

                        fields.identifierValue.value = rawValue;
                        fields.identifierType.value = (String(code.format || '').toLowerCase() === 'qr_code') ? 'qr' : 'barcode';
                        updateQrPreview();
                        stopScanner();
                        setStatus('코드를 인식했습니다. 기존 등록 여부를 조회합니다.', false);
                        await findByIdentifier();
                    } catch (error) {
                        setStatus('카메라 스캔 중 오류가 발생했습니다.', true);
                    }
                }, 700);

                setStatus('카메라가 시작되었습니다. 코드를 화면 중앙에 맞춰 주세요.', false);
            } catch (error) {
                setStatus('카메라 권한이 없거나 시작에 실패했습니다.', true);
            }
        }

        function stopScanner() {
            if (state.scanTimer) {
                window.clearInterval(state.scanTimer);
                state.scanTimer = null;
            }
            if (state.stream) {
                state.stream.getTracks().forEach(function (track) {
                    track.stop();
                });
                state.stream = null;
            }
            if (fields.scannerVideo.srcObject) {
                fields.scannerVideo.srcObject = null;
            }
        }

        fields.identifierValue.addEventListener('input', updateQrPreview);
        fields.assignedEmployeeId.addEventListener('change', function () {
            const selected = this.options[this.selectedIndex];
            if (!selected || !this.value) {
                fields.assignedEmployeeName.value = '';
                fields.assignedTeam.value = '';
                return;
            }
            fields.assignedEmployeeName.value = selected.dataset.name || '';
            fields.assignedTeam.value = selected.dataset.team || '';
            if (!fields.assignedAt.value) {
                fields.assignedAt.value = new Date().toISOString().slice(0, 16);
            }
        });

        document.getElementById('saveButton').addEventListener('click', async function () {
            try {
                await saveCurrentItem();
            } catch (error) {
                setStatus(error.message || '저장 중 오류가 발생했습니다.', true);
            }
        });

        document.getElementById('newButton').addEventListener('click', function () {
            clearForm();
            fields.templateName.value = '';
            state.currentTemplateId = '';
            renderTemplates();
            setStatus('신규 입력 모드로 전환했습니다.', false);
        });

        document.getElementById('findButton').addEventListener('click', async function () {
            try {
                await findByIdentifier();
            } catch (error) {
                setStatus(error.message || '조회 중 오류가 발생했습니다.', true);
            }
        });

        document.getElementById('deleteButton').addEventListener('click', async function () {
            try {
                await deleteCurrentItem();
            } catch (error) {
                setStatus(error.message || '삭제 중 오류가 발생했습니다.', true);
            }
        });

        document.getElementById('addHistoryButton').addEventListener('click', async function () {
            try {
                await addHistory();
            } catch (error) {
                setStatus(error.message || '이력 추가 중 오류가 발생했습니다.', true);
            }
        });

        document.getElementById('generateInternalKeyButton').addEventListener('click', async function () {
            try {
                await createInternalKey();
            } catch (error) {
                setStatus(error.message || '내부 키 생성 중 오류가 발생했습니다.', true);
            }
        });

        document.getElementById('searchButton').addEventListener('click', async function () {
            try {
                await applySearch();
            } catch (error) {
                setStatus(error.message || '검색 중 오류가 발생했습니다.', true);
            }
        });

        document.getElementById('searchResetButton').addEventListener('click', resetSearch);
        document.getElementById('exportButton').addEventListener('click', downloadExport);
        document.getElementById('saveTemplateButton').addEventListener('click', async function () {
            try {
                await saveTemplate();
            } catch (error) {
                setStatus(error.message || '템플릿 저장 중 오류가 발생했습니다.', true);
            }
        });
        document.getElementById('deleteTemplateButton').addEventListener('click', async function () {
            try {
                await deleteTemplate();
            } catch (error) {
                setStatus(error.message || '템플릿 삭제 중 오류가 발생했습니다.', true);
            }
        });
        document.getElementById('startScanButton').addEventListener('click', startScanner);
        document.getElementById('stopScanButton').addEventListener('click', function () {
            stopScanner();
            setStatus('카메라를 중지했습니다.', false);
        });

        fields.searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                document.getElementById('searchButton').click();
            }
        });
        fields.templateSelect.addEventListener('change', function () {
            state.currentTemplateId = String(this.value || '');
            const selected = state.templates.find(function (template) {
                return template.id === state.currentTemplateId;
            }) || null;
            applyTemplate(selected);
            if (selected) {
                setStatus('템플릿 내용을 불러왔습니다. 식별값만 입력하면 바로 등록할 수 있습니다.', false);
            }
        });

        window.addEventListener('beforeunload', stopScanner);

        (async function init() {
            clearForm();
            try {
                await loadEmployees();
                await loadTemplates();
                await loadItems();
                setStatus('준비되었습니다. 스캔하거나 식별값을 입력해 주세요.', false);
            } catch (error) {
                setStatus(error.message || '초기 데이터를 불러오지 못했습니다.', true);
            }
        })();
    </script>
</body>
</html>
