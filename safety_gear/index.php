<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';

$user = auth_current_user();
if (!is_array($user)) {
    header('Location: /risk_assessment/task_select.php');
    exit;
}

if (!auth_can_manage($user)) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>보호구 지급관리</title>
        <style>
            body { margin: 0; padding: 32px; font-family: "Malgun Gothic", sans-serif; background: #f3f7fb; color: #122033; }
            .panel { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #d7e0ea; border-radius: 20px; padding: 24px; }
            .button { display: inline-flex; align-items: center; justify-content: center; padding: 10px 14px; border-radius: 12px; background: #0f766e; color: #fff; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="panel">
            <h1>보호구 지급관리</h1>
            <p>이 페이지는 안전관리자 또는 관리 권한이 있는 사용자만 접근할 수 있습니다.</p>
            <a class="button" href="/risk_assessment/work_list.php">작업 목록으로 이동</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>보호구 지급관리</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css">
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
            --warning: #b45309;
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

        a { color: inherit; }

        .page {
            width: calc(100vw - 28px);
            max-width: none;
            margin: 14px auto 28px;
            display: block;
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

        .row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .row > .grow {
            flex: 1 1 220px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .full {
            grid-column: 1 / -1;
        }

        .field {
            display: grid;
            gap: 6px;
        }

        .field label {
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
        }

        input, select, textarea, button {
            font: inherit;
        }

        input[type="text"],
        input[type="date"],
        input[type="number"],
        textarea,
        select {
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

        .button,
        button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 12px;
            padding: 10px 14px;
            text-decoration: none;
            cursor: pointer;
            background: var(--accent);
            color: #fff;
        }

        .button.secondary,
        button.secondary {
            background: var(--secondary);
            color: #0f172a;
        }

        .button.ghost,
        button.ghost {
            background: var(--accent-soft);
            color: #115e59;
        }

        .button.danger,
        button.danger {
            background: var(--danger);
            color: #fff;
        }

        .status-box {
            margin-top: 14px;
            border-radius: 12px;
            padding: 12px 14px;
            background: #f8fafc;
            color: #334155;
            font-size: 13px;
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

        .qr-box {
            display: grid;
            place-items: center;
            border: 1px dashed var(--line);
            border-radius: 16px;
            min-height: 190px;
            background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
            overflow: hidden;
        }

        .qr-box img {
            max-width: 220px;
            width: 100%;
            height: auto;
            display: block;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .count {
            color: var(--muted);
            font-size: 12px;
        }

        .history-list,
        .recent-list {
            display: grid;
            gap: 10px;
            margin-top: 14px;
            max-height: 360px;
            overflow: auto;
        }

        .history-item {
            border-left: 4px solid #14b8a6;
            background: #f8fafc;
            border-radius: 0 12px 12px 0;
            padding: 10px 12px;
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

        .meta {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .bulk-box {
            margin-top: 18px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #f8fafc;
            padding: 14px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .pill.ready { background: #dcfce7; color: #166534; }
        .pill.issued { background: #dbeafe; color: #1d4ed8; }
        .pill.warning { background: #fef3c7; color: #92400e; }
        .pill.hidden { background: #e5e7eb; color: #374151; }

        .workflow {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin: 16px 0;
        }

        .workflow-step {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px 14px;
            background: #f8fafc;
        }

        .workflow-step strong {
            display: block;
            margin-bottom: 3px;
            color: #0f766e;
        }

        .advanced-field,
        .advanced-section,
        .advanced-action,
        .advanced-detail {
            display: none;
        }

        body.show-advanced .advanced-field {
            display: grid;
        }

        body.show-advanced .advanced-section {
            display: block;
        }

        body.show-advanced .advanced-action {
            display: inline-flex;
        }

        body.show-advanced .advanced-detail {
            display: inline;
        }

        .integration-feature {
            display: none !important;
        }

        .check-options {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 18px;
            min-height: 28px;
            padding: 4px 0;
        }

        .check-option {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            padding: 2px 0;
            font-size: 20px;
            line-height: 1.5;
            cursor: pointer;
        }

        .check-option input[type="checkbox"] {
            margin: 0;
        }

        .gear-icon {
            display: inline-flex;
            width: 28px;
            justify-content: center;
            margin-right: 3px;
            color: #0f766e;
            font-size: 22px;
            line-height: 1;
        }

        .check-option:has(input:checked) {
            color: #115e59;
            font-weight: 700;
        }

        .employee-group {
            width: 100%;
            padding: 3px 0 7px;
        }

        .employee-group-title {
            margin-bottom: 4px;
            color: #475569;
            font-size: 20px;
            font-weight: 800;
        }

        .employee-group-options {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 30px;
        }

        .selection-card {
            padding: 16px 18px;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            background: #f8fafc;
        }

        .selection-card > .row:first-child {
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }

        .selection-card > .row:first-child > label:first-child {
            font-size: 20px;
            font-weight: 800;
        }

        .compact-button {
            min-height: 0;
            padding: 4px 8px;
            border-radius: 7px;
            font-size: 12px;
            line-height: 1.2;
        }

        .assignment-preview {
            overflow-x: auto;
        }

        .assignment-preview table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        .assignment-preview th,
        .assignment-preview td {
            padding: 11px 12px;
            border: 1px solid #cbd5e1;
            text-align: left;
            font-size: 16px;
        }

        .assignment-preview th {
            background: #e2e8f0;
            font-weight: 800;
        }

        .advanced-row {
            display: none;
        }

        body.show-advanced .advanced-row {
            display: flex;
        }

        .simple-actions {
            position: sticky;
            bottom: 10px;
            z-index: 10;
            padding: 12px;
            border: 1px solid #b7d9d3;
            border-radius: 14px;
            background: rgba(240, 253, 250, 0.96);
            box-shadow: 0 8px 24px rgba(15, 118, 110, 0.12);
        }

        @media (max-width: 1180px) {
            .page {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .workflow {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="panel">
            <div class="row" style="justify-content:flex-end; margin-bottom:8px;">
                <a class="button secondary" href="/CSM/area.php?group=2&amp;section=2.6">위험관리 2.6</a>
                <a class="button secondary" href="/safety_gear/monthly_receipt_print.php">월별 일괄출력</a>
            </div>

            <h1>보호구 지급관리</h1>
            <p class="lead">보호구와 지급 대상자를 선택한 뒤 저장하세요. 내부 관리번호는 저장할 때 자동으로 생성됩니다.</p>

            <div class="workflow" aria-label="보호구 지급 순서">
                <div class="workflow-step"><strong>1. 보호구 선택</strong><span>종류와 품명을 입력합니다.</span></div>
                <div class="workflow-step"><strong>2. 지급 대상 선택</strong><span>직원과 지급일을 선택합니다.</span></div>
                <div class="workflow-step"><strong>3. 저장</strong><span>저장 버튼으로 지급 내역을 등록합니다.</span></div>
            </div>

            <div class="row" style="margin-top:14px;">
                <a class="button ghost" href="/safety_gear/status.php">보호구 현황</a>
                <a class="button secondary" href="/safety_gear/report.php">이력 조회</a>
                <a class="button secondary" href="/safety_gear/receipt_batch_print.php">수령 확인서 출력</a>
                <button id="showAdvancedButton" type="button" class="secondary" aria-expanded="false">상세 기능 열기</button>
            </div>

            <div class="grid" style="margin-top:16px;">
                <div class="field advanced-field">
                    <label for="template_select">템플릿 선택</label>
                    <select id="template_select">
                        <option value="">선택 안 함</option>
                    </select>
                </div>
                <div class="field advanced-field">
                    <label for="template_name">템플릿 이름</label>
                    <div class="row">
                        <input id="template_name" class="grow" type="text" placeholder="예: 안전모 기본형">
                        <button id="saveTemplateButton" type="button" class="ghost">템플릿 저장</button>
                        <button id="deleteTemplateButton" type="button" class="secondary">템플릿 삭제</button>
                    </div>
                </div>

                <div class="field integration-feature">
                    <label for="identifier_type">식별 방식</label>
                    <select id="identifier_type">
                        <option value="barcode">바코드</option>
                        <option value="qr">QR 코드</option>
                        <option value="internal">내부 관리번호</option>
                        <option value="rfid">RFID</option>
                        <option value="nfc">NFC</option>
                    </select>
                </div>
                <div class="field integration-feature">
                    <label for="identifier_value">식별값</label>
                    <div class="row">
                        <input id="identifier_value" class="grow" type="text" placeholder="스캔하거나 직접 입력">
                        <button id="generateInternalKeyButton" type="button" class="ghost">내부번호 생성</button>
                    </div>
                </div>

                <div class="field full selection-card">
                    <div class="row" style="gap:12px; align-items:center;">
                        <label for="gear_type">보호구 종류</label>
                        <label class="hint"><input id="select_all_gear_types" type="checkbox"> 모두 선택</label>
                        <button id="clearGearTypeSelectionButton" type="button" class="secondary compact-button">선택 해제</button>
                    </div>
                    <div id="gear_type_options" class="check-options"></div>
                    <input id="gear_type" type="hidden">
                    <div class="row advanced-row">
                        <input id="gear_type_new_name" class="grow" type="text" placeholder="새 보호구 종류 입력">
                        <button id="addGearTypeButton" type="button" class="ghost">종류 추가</button>
                        <button id="deleteGearTypeButton" type="button" class="secondary">종류 삭제</button>
                    </div>
                </div>
                <input id="item_name" type="hidden">

                <div class="field advanced-field">
                    <label for="spec_name">규격</label>
                    <input id="spec_name" type="text" list="spec_name_list" placeholder="예: ABS, 방수형, 6면체">
                    <datalist id="spec_name_list"></datalist>
                </div>
                <div class="field advanced-field">
                    <label for="model_name">모델명</label>
                    <input id="model_name" type="text" placeholder="예: K2-01">
                </div>

                <div class="field advanced-field">
                    <label for="kcs_cert_no">KCS 인증번호</label>
                    <input id="kcs_cert_no" type="text" placeholder="예: KCS-2026-000123">
                </div>
                <div class="field advanced-field">
                    <label for="manufacturer_name">제조사</label>
                    <input id="manufacturer_name" type="text" placeholder="예: K2, 3M">
                </div>

                <div class="field advanced-field">
                    <label for="purchase_vendor">구매처</label>
                    <input id="purchase_vendor" type="text" placeholder="예: 안전마트">
                </div>
                <div class="field advanced-field">
                    <label for="purchase_price">구매금액</label>
                    <input id="purchase_price" type="text" inputmode="numeric" placeholder="예: 15000">
                </div>

                <div class="field advanced-field">
                    <label for="purchased_at">구매일</label>
                    <input id="purchased_at" type="date">
                </div>
                <div class="field advanced-field">
                    <label for="status">상태</label>
                    <input id="status" type="text" readonly>
                </div>

                <select id="assigned_team_select" hidden><option value=""></option></select>
                <div class="field full selection-card">
                    <div class="row" style="gap:12px; align-items:center;">
                        <label for="assigned_employee_name">지급 대상자</label>
                        <label class="hint"><input id="select_all_employees" type="checkbox"> 모두 선택</label>
                        <button id="clearEmployeeSelectionButton" type="button" class="secondary compact-button">선택 해제</button>
                    </div>
                    <div id="assigned_employee_options" class="check-options"><span class="hint">직원을 선택하세요.</span></div>
                    <input id="assigned_employee_name" type="hidden">
                    <input id="assigned_employee_id" type="hidden">
                    <button id="clearAssigneeButton" type="button" class="secondary advanced-action">지급정보 비우기</button>
                </div>
                <div class="field full selection-card">
                    <div class="row" style="gap:12px; align-items:center;">
                        <label>품목별 수령 예정자</label>
                        <button id="applySelectionButton" type="button">선택 적용</button>
                        <button id="clearSelectionDraftButton" type="button" class="secondary">임시 목록 비우기</button>
                    </div>
                    <div id="assignmentPreview" class="assignment-preview hint">보호구와 지급 대상자를 선택한 뒤 선택 적용을 눌러주세요.</div>
                </div>
                <div class="field integration-feature">
                    <label for="assigned_team">지급 부서명</label>
                    <input id="assigned_team" type="text" placeholder="직접 입력 가능">
                </div>

                <div class="field">
                    <label for="assigned_at">지급일</label>
                    <input id="assigned_at" type="date">
                </div>
                <div class="field full">
                    <label for="notes">메모</label>
                    <textarea id="notes" placeholder="추가 메모를 입력하세요"></textarea>
                </div>
            </div>

            <div class="row simple-actions" style="margin-top:14px;">
                <button id="saveButton" type="button">저장</button>
                <button id="newButton" type="button" class="secondary">신규 입력</button>
                <button id="findButton" type="button" class="secondary integration-feature">식별값 조회</button>
                <button id="initialIssueButton" type="button" class="ghost advanced-action">현재 품목 지급 처리</button>
                <button id="deleteButton" type="button" class="danger advanced-action">삭제</button>
                <label class="hint advanced-action" style="align-items:center; gap:6px; margin-left:4px;">
                    <input id="continuousMode" type="checkbox">
                    연속 등록 모드
                </label>
            </div>

            <div class="scanner integration-feature">
                <video id="scannerVideo" playsinline muted></video>
                <div class="scanner-bar">
                    <div class="row">
                        <button id="startScanButton" type="button">카메라 스캔 시작</button>
                        <button id="stopScanButton" type="button" class="secondary">카메라 중지</button>
                    </div>
                    <div class="hint" style="margin-top:8px; color:#cbd5e1;">지원되는 브라우저에서는 바코드와 QR 코드를 바로 읽을 수 있습니다. 지원되지 않으면 식별값을 직접 입력하세요.</div>
                </div>
            </div>

            <div id="statusBox" class="status-box">보호구 정보를 입력하거나 최근 등록 목록에서 품목을 선택하세요.</div>

            <div class="advanced-section">
            <h3 style="margin-top:22px;">이력 관리</h3>
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
                <div class="field">
                    <label for="history_at">이력 날짜</label>
                    <input id="history_at" type="date">
                </div>
                <div class="field full">
                    <label for="history_note">이력 내용</label>
                    <div class="row">
                        <input id="history_note" class="grow" type="text" placeholder="예: 손상 점검 완료, 폐기 처리">
                        <button id="addHistoryButton" type="button" class="ghost">이력 추가</button>
                        <button id="cancelHistoryEditButton" type="button" class="secondary" hidden>수정 취소</button>
                    </div>
                </div>
            </div>
            <div id="historyList" class="history-list"></div>
            </div>

            <div class="bulk-box advanced-section">
                <h3>수량 기준 입고 등록</h3>
                <p class="hint" style="margin:8px 0 12px;">현재 입력한 보호구 정보를 기준으로 내부 관리번호를 자동 생성해 여러 건을 한 번에 등록합니다.</p>
                <div class="field">
                    <label for="bulk_quantity">입고 수량</label>
                    <input id="bulk_quantity" type="number" min="1" step="1" value="1" placeholder="예: 10">
                </div>
                <div class="row" style="margin-top:10px;">
                    <button id="bulkReceiveButton" type="button" class="ghost">수량 기준 입고 등록</button>
                </div>
            </div>

            <div class="bulk-box integration-feature">
                <h3>기존 지급품 일괄 등록</h3>
                <p class="hint" style="margin:8px 0 12px;">식별값을 줄마다 입력하면 현재 기본정보와 지급 대상자를 기준으로 여러 건을 지급 상태로 등록합니다.</p>
                <div class="field">
                    <label for="bulk_identifiers">식별값 목록</label>
                    <textarea id="bulk_identifiers" placeholder="한 줄에 하나씩 입력&#10;BARCODE-001&#10;BARCODE-002&#10;BARCODE-003"></textarea>
                </div>
                <div class="row" style="margin-top:10px;">
                    <button id="bulkInitialIssueButton" type="button" class="ghost">기존 지급품 일괄 등록</button>
                    <button id="bulkClearButton" type="button" class="secondary">입력 비우기</button>
                </div>
            </div>
        </section>

        <aside class="panel integration-feature">
            <div class="section-head">
                <h2>선택 품목</h2>
                <div id="currentItemBadge" class="badge">신규 등록 모드</div>
            </div>

            <div class="qr-box integration-feature" style="margin-top:14px;">
                <img id="qrImage" alt="QR 코드" hidden>
                <div id="qrEmpty" class="hint">식별값을 입력하면 QR 코드가 여기에 표시됩니다.</div>
            </div>

            <div class="section-head" style="margin-top:22px;">
                <h3>최근 등록 목록</h3>
                <span id="recentCount" class="count"></span>
            </div>
            <div class="row" style="margin-top:10px;">
                <input id="searchInput" class="grow" type="text" placeholder="보호구 종류, 품명, 지급 대상자 검색">
                <button id="searchButton" type="button" class="secondary">검색</button>
                <button id="searchResetButton" type="button" class="secondary">초기화</button>
                <button id="exportButton" type="button" class="ghost">CSV 다운로드</button>
            </div>
            <div class="hint" style="margin-top:8px;">목록의 품목을 누르면 수정 모드로 불러옵니다.</div>
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
            gearTypes: [],
            assignmentDrafts: [],
            currentItemId: '',
            currentTemplateId: '',
            currentHistoryId: 0,
            searchQuery: '',
            stream: null,
            scanTimer: null
        };

        const fields = {
            templateSelect: document.getElementById('template_select'),
            templateName: document.getElementById('template_name'),
            identifierType: document.getElementById('identifier_type'),
            identifierValue: document.getElementById('identifier_value'),
            gearType: document.getElementById('gear_type'),
            gearTypeOptions: document.getElementById('gear_type_options'),
            selectAllGearTypes: document.getElementById('select_all_gear_types'),
            gearTypeNewName: document.getElementById('gear_type_new_name'),
            itemName: document.getElementById('item_name'),
            specName: document.getElementById('spec_name'),
            specNameList: document.getElementById('spec_name_list'),
            modelName: document.getElementById('model_name'),
            kcsCertNo: document.getElementById('kcs_cert_no'),
            manufacturerName: document.getElementById('manufacturer_name'),
            purchaseVendor: document.getElementById('purchase_vendor'),
            purchasePrice: document.getElementById('purchase_price'),
            purchasedAt: document.getElementById('purchased_at'),
            status: document.getElementById('status'),
            assignedEmployeeId: document.getElementById('assigned_employee_id'),
            assignedEmployeeName: document.getElementById('assigned_employee_name'),
            assignedEmployeeOptions: document.getElementById('assigned_employee_options'),
            selectAllEmployees: document.getElementById('select_all_employees'),
            assignmentPreview: document.getElementById('assignmentPreview'),
            assignedTeam: document.getElementById('assigned_team'),
            assignedTeamSelect: document.getElementById('assigned_team_select'),
            assignedAt: document.getElementById('assigned_at'),
            notes: document.getElementById('notes'),
            historyType: document.getElementById('history_type'),
            historyAt: document.getElementById('history_at'),
            historyNote: document.getElementById('history_note'),
            addHistoryButton: document.getElementById('addHistoryButton'),
            cancelHistoryEditButton: document.getElementById('cancelHistoryEditButton'),
            statusBox: document.getElementById('statusBox'),
            historyList: document.getElementById('historyList'),
            recentList: document.getElementById('recentList'),
            recentCount: document.getElementById('recentCount'),
            currentItemBadge: document.getElementById('currentItemBadge'),
            qrImage: document.getElementById('qrImage'),
            qrEmpty: document.getElementById('qrEmpty'),
            searchInput: document.getElementById('searchInput'),
            bulkIdentifiers: document.getElementById('bulk_identifiers'),
            bulkQuantity: document.getElementById('bulk_quantity'),
            continuousMode: document.getElementById('continuousMode'),
            scannerVideo: document.getElementById('scannerVideo')
        };

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatNumberWithComma(value) {
            const digits = String(value || '').replace(/[^\d]/g, '');
            if (!digits) {
                return '';
            }
            return Number(digits).toLocaleString('ko-KR');
        }

        function removeCommas(value) {
            return String(value || '').replace(/[^\d.]/g, '');
        }

        function getTodayDate() {
            return new Date().toISOString().slice(0, 10);
        }

        function formatDateOnly(value) {
            const text = String(value || '').trim();
            if (!text) {
                return '';
            }
            return text.slice(0, 10);
        }

        function setStatus(message, isError) {
            fields.statusBox.textContent = String(message || '');
            fields.statusBox.style.background = isError ? '#fee2e2' : '#f8fafc';
            fields.statusBox.style.color = isError ? '#991b1b' : '#334155';
        }

        async function apiRequest(params, method = 'GET') {
            const requestMethod = String(method || 'GET').toUpperCase();
            let url = apiEndpoint;
            const options = { method: requestMethod };

            if (requestMethod === 'GET') {
                const searchParams = new URLSearchParams();
                Object.keys(params || {}).forEach(function (key) {
                    if (params[key] !== undefined && params[key] !== null) {
                        searchParams.set(key, String(params[key]));
                    }
                });
                url += '?' + searchParams.toString();
            } else {
                const formData = new URLSearchParams();
                Object.keys(params || {}).forEach(function (key) {
                    if (params[key] !== undefined && params[key] !== null) {
                        formData.set(key, String(params[key]));
                    }
                });
                options.headers = {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                };
                options.body = formData.toString();
            }

            const response = await fetch(url, options);
            let payload = null;
            try {
                payload = await response.json();
            } catch (error) {
                throw new Error('?쒕쾭 ?묐떟???댁꽍?섏? 紐삵뻽?듬땲??');
            }

            if (!response.ok || !payload || payload.ok === false) {
                throw new Error((payload && payload.message) ? payload.message : '?붿껌 泥섎━ 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.');
            }

            return payload;
        }

        function renderTemplates() {
            const currentValue = String(state.currentTemplateId || '');
            fields.templateSelect.innerHTML = '<option value="">선택 안 함</option>';

            state.templates.forEach(function (template) {
                const option = document.createElement('option');
                option.value = String(template.id || '');
                option.textContent = template.template_name || '(?대쫫 ?놁쓬)';
                fields.templateSelect.appendChild(option);
            });

            fields.templateSelect.value = currentValue;
        }

        function buildTeamOptions() {
            const teams = Array.from(new Set(state.employees
                .map(function (employee) { return String(employee.team || '').trim(); })
                .filter(Boolean)))
                .sort(function (a, b) { return a.localeCompare(b, 'ko'); });

            const currentValue = String(fields.assignedTeam.value || '').trim();
            fields.assignedTeamSelect.innerHTML = '<option value="">선택 안 함</option>';

            teams.forEach(function (team) {
                const option = document.createElement('option');
                option.value = team;
                option.textContent = team;
                fields.assignedTeamSelect.appendChild(option);
            });

            if (currentValue) {
                fields.assignedTeamSelect.value = currentValue;
            }
        }

        function cleanEmployeeDisplayName(name) {
            return String(name || '')
                .replace(/\s*\(작업지휘자\)/g, '')
                .replace(/\s*\(관리감독자\)/g, '')
                .trim();
        }

        function renderEmployees() {
            const currentEmployeeIds = new Set(
                String(fields.assignedEmployeeId.value || '').split(',').map(function (value) { return value.trim(); }).filter(Boolean)
            );
            fields.assignedEmployeeOptions.innerHTML = '';
            const grouped = new Map();
            state.employees.forEach(function (employee) {
                const team = String(employee.team || '').trim();
                if (!team) return;
                if (!grouped.has(team)) grouped.set(team, []);
                grouped.get(team).push(employee);
            });
            Array.from(grouped.keys()).sort(function (a, b) { return a.localeCompare(b, 'ko'); }).forEach(function (team) {
                const group = document.createElement('div');
                group.className = 'employee-group';
                const title = document.createElement('div');
                title.className = 'employee-group-title';
                title.classList.add('row');
                title.style.gap = '10px';
                title.style.alignItems = 'center';
                const titleText = document.createElement('span');
                titleText.textContent = team;
                const teamSelectLabel = document.createElement('label');
                teamSelectLabel.className = 'hint';
                const teamSelectAll = document.createElement('input');
                teamSelectAll.type = 'checkbox';
                teamSelectAll.dataset.teamSelectAll = team;
                teamSelectLabel.appendChild(teamSelectAll);
                teamSelectLabel.appendChild(document.createTextNode(' 모두 선택'));
                title.appendChild(titleText);
                title.appendChild(teamSelectLabel);
                const options = document.createElement('div');
                options.className = 'employee-group-options';
                grouped.get(team).forEach(function (employee) {
                    const displayName = cleanEmployeeDisplayName(employee.name);
                    const label = document.createElement('label');
                    label.className = 'check-option';
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.value = String(employee.id || '');
                    checkbox.dataset.name = displayName;
                    checkbox.dataset.team = employee.team || '';
                    checkbox.checked = currentEmployeeIds.has(checkbox.value);
                    checkbox.addEventListener('change', function () {
                        syncSelectedEmployees();
                        updateEmployeeSelectAllStates();
                    });
                    label.appendChild(document.createTextNode(displayName || '이름 없음'));
                    label.appendChild(checkbox);
                    options.appendChild(label);
                });
                group.appendChild(title);
                group.appendChild(options);
                fields.assignedEmployeeOptions.appendChild(group);
                teamSelectAll.addEventListener('change', function () {
                    options.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
                        checkbox.checked = teamSelectAll.checked;
                    });
                    syncSelectedEmployees();
                    updateEmployeeSelectAllStates();
                });
            });
            if (!grouped.size) fields.assignedEmployeeOptions.innerHTML = '<span class="hint">부서가 지정된 직원이 없습니다.</span>';
            updateEmployeeSelectAllStates();
        }

        function syncSelectedEmployees() {
            const selected = Array.from(fields.assignedEmployeeOptions.querySelectorAll('.employee-group-options input[type="checkbox"]:checked'));
            fields.assignedEmployeeId.value = selected.map(function (checkbox) { return checkbox.value; }).join(',');
            fields.assignedEmployeeName.value = selected.map(function (checkbox) { return checkbox.dataset.name || ''; }).join(', ');
            fields.assignedTeam.value = Array.from(new Set(selected.map(function (checkbox) { return checkbox.dataset.team || ''; }).filter(Boolean))).join(', ');
            if (selected.length && !fields.assignedAt.value) fields.assignedAt.value = getTodayDate();
        }

        function updateEmployeeSelectAllStates() {
            const all = Array.from(fields.assignedEmployeeOptions.querySelectorAll('.employee-group-options input[type="checkbox"]'));
            const checked = all.filter(function (checkbox) { return checkbox.checked; });
            fields.selectAllEmployees.checked = all.length > 0 && checked.length === all.length;
            fields.selectAllEmployees.indeterminate = checked.length > 0 && checked.length < all.length;
            fields.assignedEmployeeOptions.querySelectorAll('[data-team-select-all]').forEach(function (teamToggle) {
                const teamChecks = Array.from(teamToggle.closest('.employee-group').querySelectorAll('.employee-group-options input[type="checkbox"]'));
                const teamChecked = teamChecks.filter(function (checkbox) { return checkbox.checked; });
                teamToggle.checked = teamChecks.length > 0 && teamChecked.length === teamChecks.length;
                teamToggle.indeterminate = teamChecked.length > 0 && teamChecked.length < teamChecks.length;
            });
        }

        function renderGearTypes() {
            const currentValue = String(fields.gearType.value || '');
            const currentValues = new Set(currentValue.split(',').map(function (value) { return value.trim(); }).filter(Boolean));
            fields.gearTypeOptions.innerHTML = '';

            const priorityOrder = ['안전모', '안전조끼', '안전화', '보안경', '방진마스크', '장갑'];
            const sortedGearTypes = state.gearTypes.slice().sort(function (a, b) {
                const aName = String(a.name || '').trim();
                const bName = String(b.name || '').trim();
                const aIndex = priorityOrder.indexOf(aName);
                const bIndex = priorityOrder.indexOf(bName);
                if (aIndex !== -1 || bIndex !== -1) {
                    if (aIndex === -1) return 1;
                    if (bIndex === -1) return -1;
                    return aIndex - bIndex;
                }
                return aName.localeCompare(bName, 'ko');
            });

            sortedGearTypes.forEach(function (type) {
                const typeName = String(type.name || '');
                const label = document.createElement('label');
                label.className = 'check-option';
                const icon = document.createElement('i');
                icon.className = 'gear-icon ' + gearTypeIcon(typeName);
                icon.setAttribute('aria-hidden', 'true');
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.value = typeName;
                checkbox.checked = currentValues.has(typeName);
                checkbox.addEventListener('change', function () {
                    fields.gearType.value = Array.from(fields.gearTypeOptions.querySelectorAll('input[type="checkbox"]:checked'))
                        .map(function (input) { return input.value; }).join(', ');
                    updateGearSelectAllState();
                });
                label.appendChild(icon);
                label.appendChild(document.createTextNode(typeName));
                label.appendChild(checkbox);
                fields.gearTypeOptions.appendChild(label);
            });
            if (!state.gearTypes.length) {
                fields.gearTypeOptions.innerHTML = '<span class="hint">등록된 보호구 종류가 없습니다.</span>';
            }
            updateGearSelectAllState();
        }

        function gearTypeIcon(typeName) {
            const name = String(typeName || '').replace(/\s+/g, '');
            if (name.includes('안전모') || name.includes('헬멧')) return 'fa-solid fa-helmet-safety';
            if (name.includes('조끼') || name.includes('안전대')) return 'fa-solid fa-vest';
            if (name.includes('안전화') || name.includes('장화') || name.includes('신발')) return 'fa-solid fa-shoe-prints';
            if (name.includes('보안경') || name.includes('고글') || name.includes('안면')) return 'fa-solid fa-glasses';
            if (name.includes('마스크') || name.includes('방독') || name.includes('호흡')) return 'fa-solid fa-mask-face';
            if (name.includes('장갑')) return 'fa-solid fa-mitten';
            if (name.includes('귀마개') || name.includes('귀덮개')) return 'fa-solid fa-headphones';
            if (name.includes('용접')) return 'fa-solid fa-fire-flame-curved';
            if (name.includes('우의') || name.includes('방수')) return 'fa-solid fa-umbrella';
            return 'fa-solid fa-shield-halved';
        }

        function updateGearSelectAllState() {
            const all = Array.from(fields.gearTypeOptions.querySelectorAll('input[type="checkbox"]'));
            const checked = all.filter(function (checkbox) { return checkbox.checked; });
            fields.selectAllGearTypes.checked = all.length > 0 && checked.length === all.length;
            fields.selectAllGearTypes.indeterminate = checked.length > 0 && checked.length < all.length;
        }

        function clearCurrentSelection() {
            fields.gearTypeOptions.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
                checkbox.checked = false;
            });
            fields.assignedEmployeeOptions.querySelectorAll('.employee-group-options input[type="checkbox"]').forEach(function (checkbox) {
                checkbox.checked = false;
            });
            fields.gearType.value = '';
            syncSelectedEmployees();
            updateGearSelectAllState();
            updateEmployeeSelectAllStates();
        }

        function applySelectionToDraft() {
            const gearTypes = Array.from(fields.gearTypeOptions.querySelectorAll('input[type="checkbox"]:checked'))
                .map(function (checkbox) { return checkbox.value; });
            const employees = Array.from(fields.assignedEmployeeOptions.querySelectorAll('.employee-group-options input[type="checkbox"]:checked'))
                .map(function (checkbox) {
                    return { id: checkbox.value, name: checkbox.dataset.name || '', team: checkbox.dataset.team || '' };
                });
            if (!gearTypes.length) {
                throw new Error('보호구 종류를 하나 이상 선택해 주세요.');
            }

            gearTypes.forEach(function (gearType) {
                let draft = state.assignmentDrafts.find(function (entry) { return entry.gearType === gearType; });
                if (!draft) {
                    draft = { gearType: gearType, employees: [] };
                    state.assignmentDrafts.push(draft);
                }
                employees.forEach(function (employee) {
                    if (!draft.employees.some(function (saved) { return saved.id === employee.id; })) {
                        draft.employees.push(employee);
                    }
                });
            });
            renderAssignmentPreview();
        }

        function renderAssignmentPreview() {
            if (!state.assignmentDrafts.length) {
                fields.assignmentPreview.innerHTML = '<span class="hint">보호구와 지급 대상자를 선택한 뒤 선택 적용을 눌러주세요.</span>';
                return;
            }
            const rows = state.assignmentDrafts.map(function (draft) {
                const recipientText = draft.employees.length
                    ? draft.employees.map(function (employee) {
                    return escapeHtml(employee.name + (employee.team ? ' (' + employee.team + ')' : ''));
                    }).join(', ')
                    : '지급 대상자 없음(재고 등록)';
                return '<tr><td><strong>' + escapeHtml(draft.gearType) + '</strong></td><td>' + recipientText + '</td></tr>';
            }).join('');
            fields.assignmentPreview.innerHTML =
                '<table><thead><tr><th style="width:22%;">보호구 품목</th><th>수령 예정자</th></tr></thead><tbody>' + rows + '</tbody></table>';
        }

        function refreshSpecSuggestions() {
            const candidates = new Set();

            state.items.forEach(function (item) {
                const spec = String(item.spec_name || '').trim();
                if (spec) {
                    candidates.add(spec);
                }
            });

            state.templates.forEach(function (template) {
                const spec = String(template.spec_name || '').trim();
                if (spec) {
                    candidates.add(spec);
                }
            });

            fields.specNameList.innerHTML = '';
            Array.from(candidates).sort(function (a, b) {
                return a.localeCompare(b, 'ko');
            }).forEach(function (spec) {
                const option = document.createElement('option');
                option.value = spec;
                fields.specNameList.appendChild(option);
            });
        }

        function statusPillClass(status) {
            const value = String(status || '').trim();
            if (value === '사용 가능') {
                return 'ready';
            }
            if (value === '지급됨') {
                return 'issued';
            }
            if (value === '폐기' || value === '반납') {
                return 'hidden';
            }
            return 'warning';
        }

        function renderHistory(history) {
            fields.historyList.innerHTML = '';
            const entries = Array.isArray(history) ? history.slice() : [];

            if (!entries.length) {
                fields.historyList.innerHTML = '<div class="history-item"><div class="recent-title">?깅줉???대젰???놁뒿?덈떎.</div><div class="meta">??ぉ????ν븯嫄곕굹 ?대젰??異붽???二쇱꽭??</div></div>';
                return;
            }

            entries.forEach(function (entry) {
                const item = document.createElement('div');
                item.className = 'history-item';
                item.innerHTML =
                    '<div class="row" style="justify-content:space-between; align-items:flex-start;">' +
                        '<div>' +
                            '<div class="recent-title">' + escapeHtml(entry.type || '-') + '</div>' +
                            '<div class="meta">' + escapeHtml(formatDateOnly(entry.timestamp || '') || '-') + '</div>' +
                        '</div>' +
                        '<button type="button" class="secondary" data-history-edit="' + escapeHtml(String(entry.history_id || 0)) + '">?섏젙</button>' +
                    '</div>' +
                    '<div class="meta" style="margin-top:8px;">' +
                        escapeHtml(entry.note || '-') +
                        ((entry.employee_name || entry.employee_team) ? ('\n?대떦: ' + escapeHtml([entry.employee_name || '', entry.employee_team || ''].filter(Boolean).join(' / '))) : '') +
                    '</div>';
                fields.historyList.appendChild(item);
            });

            fields.historyList.querySelectorAll('[data-history-edit]').forEach(function (button) {
                button.addEventListener('click', function () {
                    const historyId = Number(this.getAttribute('data-history-edit') || '0');
                    const target = entries.find(function (entry) {
                        return Number(entry.history_id || 0) === historyId;
                    }) || null;
                    if (target) {
                        startHistoryEdit(target);
                    }
                });
            });
        }

        function renderRecentList() {
            const list = state.searchQuery
                ? state.items.slice()
                : state.items.slice(0, 100);

            fields.recentList.innerHTML = '';
            fields.recentCount.textContent = '총 ' + list.length + '건';

            if (!list.length) {
                fields.recentList.innerHTML = '<div class="recent-item"><div class="recent-title">?쒖떆????ぉ???놁뒿?덈떎.</div><div class="meta">寃??議곌굔??諛붽씀嫄곕굹 ????ぉ???깅줉?섏꽭??</div></div>';
                return;
            }

            list.forEach(function (item) {
                const node = document.createElement('div');
                node.className = 'recent-item';
                node.innerHTML =
                    '<div class="row" style="justify-content:space-between; align-items:flex-start;">' +
                        '<div class="recent-title">' + escapeHtml(item.product_name || item.item_name || item.gear_type || '미분류') + '</div>' +
                        '<span class="pill ' + statusPillClass(item.status) + '">' + escapeHtml(item.status || '-') + '</span>' +
                    '</div>' +
                    '<div class="meta">' +
                        '<span class="advanced-detail">관리번호: ' + escapeHtml(item.identifier_value || '-') + '<br></span>' +
                        '종류: ' + escapeHtml(item.gear_type || '-') + '<br>' +
                        '구매처: ' + escapeHtml(item.purchase_vendor || '-') + '<br>' +
                        '지급 대상자: ' + escapeHtml(item.assigned_employee_name || '-') + '<br>' +
                        '최종 수정: ' + escapeHtml(item.updated_at || '-') +
                    '</div>';
                node.addEventListener('click', function () {
                    fillForm(item);
                    setStatus('??ぉ??遺덈윭?붿뒿?덈떎.', false);
                });
                fields.recentList.appendChild(node);
            });
        }

        function updateQrPreview() {
            const value = String(fields.identifierValue.value || '').trim();
            if (!value) {
                fields.qrImage.hidden = true;
                fields.qrImage.removeAttribute('src');
                fields.qrEmpty.hidden = false;
                return;
            }

            fields.qrImage.src = qrEndpoint + '?data=' + encodeURIComponent(value);
            fields.qrImage.hidden = false;
            fields.qrEmpty.hidden = true;
        }

        function resetHistoryForm() {
            state.currentHistoryId = 0;
            fields.historyType.value = '?낃퀬';
            fields.historyAt.value = getTodayDate();
            fields.historyNote.value = '';
            fields.addHistoryButton.textContent = '?대젰 異붽?';
            fields.cancelHistoryEditButton.hidden = true;
        }

        function clearAssigneeSelection() {
            fields.assignedEmployeeId.value = '';
            fields.assignedEmployeeName.value = '';
            fields.assignedTeam.value = '';
            fields.assignedTeamSelect.value = '';
            fields.assignedAt.value = '';
            renderEmployees();
        }

        function clearForm() {
            state.currentItemId = '';
            state.currentHistoryId = 0;
            state.currentTemplateId = '';

            fields.templateSelect.value = '';
            fields.templateName.value = '';
            fields.identifierType.value = 'internal';
            fields.identifierValue.value = '';
            fields.gearType.value = '';
            renderGearTypes();
            fields.gearTypeNewName.value = '';
            fields.itemName.value = '';
            fields.specName.value = '';
            fields.modelName.value = '';
            fields.kcsCertNo.value = '';
            fields.manufacturerName.value = '';
            fields.purchaseVendor.value = '';
            fields.purchasePrice.value = '';
            fields.purchasedAt.value = '';
            fields.status.value = '사용 가능';
            clearAssigneeSelection();
            fields.notes.value = '';
            state.assignmentDrafts = [];
            renderAssignmentPreview();

            resetHistoryForm();
            renderHistory([]);
            updateQrPreview();
            fields.currentItemBadge.textContent = '신규 등록 모드';
        }

        function clearForContinuousRegistration() {
            state.currentItemId = '';
            fields.identifierType.value = 'internal';
            fields.identifierValue.value = '';
            fields.status.value = '사용 가능';
            clearAssigneeSelection();
            resetHistoryForm();
            renderHistory([]);
            updateQrPreview();
            fields.currentItemBadge.textContent = '연속 등록 대기';
            const firstGearType = fields.gearTypeOptions.querySelector('input[type="checkbox"]');
            if (firstGearType) firstGearType.focus();
        }

        function fillForm(item) {
            const entry = item || {};
            state.currentItemId = String(entry.id || '');

            fields.identifierType.value = entry.identifier_type || 'barcode';
            fields.identifierValue.value = entry.identifier_value || '';
            fields.gearType.value = entry.gear_type || '';
            renderGearTypes();
            fields.itemName.value = entry.item_name || '';
            fields.specName.value = entry.spec_name || '';
            fields.modelName.value = entry.model_name || '';
            fields.kcsCertNo.value = entry.kcs_cert_no || '';
            fields.manufacturerName.value = entry.manufacturer_name || '';
            fields.purchaseVendor.value = entry.purchase_vendor || '';
            fields.purchasePrice.value = formatNumberWithComma(entry.purchase_price || '');
            fields.purchasedAt.value = formatDateOnly(entry.purchased_at || '');
            fields.status.value = entry.status || '사용 가능';
            fields.assignedEmployeeId.value = entry.assigned_employee_id || '';
            fields.assignedEmployeeName.value = entry.assigned_employee_name || '';
            fields.assignedTeam.value = entry.assigned_team || '';
            fields.assignedTeamSelect.value = entry.assigned_team || '';
            renderEmployees();
            fields.assignedTeamSelect.value = entry.assigned_team || '';
            fields.assignedAt.value = formatDateOnly(entry.assigned_at || '');
            fields.notes.value = entry.notes || '';

            resetHistoryForm();
            renderHistory(entry.history || []);
            updateQrPreview();
            fields.currentItemBadge.textContent = '?깅줉 ??ぉ ?섏젙 紐⑤뱶';
        }

        function applyTemplate(template) {
            const entry = template || null;
            state.currentTemplateId = entry ? String(entry.id || '') : '';
            fields.templateSelect.value = state.currentTemplateId;
            fields.templateName.value = entry ? (entry.template_name || '') : '';

            if (!entry) {
                return;
            }

            fields.gearType.value = entry.gear_type || '';
            renderGearTypes();
            fields.itemName.value = entry.item_name || '';
            fields.specName.value = entry.spec_name || '';
            fields.modelName.value = entry.model_name || '';
            fields.kcsCertNo.value = entry.kcs_cert_no || '';
            fields.manufacturerName.value = entry.manufacturer_name || '';
            fields.purchaseVendor.value = entry.purchase_vendor || '';
            fields.purchasePrice.value = formatNumberWithComma(entry.purchase_price || '');
            fields.status.value = entry.status || '사용 가능';
            fields.notes.value = entry.notes || '';
        }

        function startHistoryEdit(entry) {
            state.currentHistoryId = Number(entry.history_id || 0);
            fields.historyType.value = entry.type || '입고';
            fields.historyAt.value = formatDateOnly(entry.timestamp || '') || getTodayDate();
            fields.historyNote.value = entry.note || '';
            fields.addHistoryButton.textContent = '이력 수정 저장';
            fields.cancelHistoryEditButton.hidden = false;
            fields.historyNote.focus();
        }

        async function loadEmployees() {
            const payload = await apiRequest({ action: 'employees' });
            state.employees = Array.isArray(payload.employees) ? payload.employees : [];
            renderEmployees();
        }

        async function loadGearTypes() {
            const payload = await apiRequest({ action: 'gear_types' });
            state.gearTypes = Array.isArray(payload.gear_types) ? payload.gear_types : [];
            renderGearTypes();
        }

        async function loadTemplates() {
            const payload = await apiRequest({ action: 'templates' });
            state.templates = Array.isArray(payload.templates) ? payload.templates : [];
            renderTemplates();
            refreshSpecSuggestions();
        }

        async function loadItems() {
            const payload = await apiRequest({
                action: 'list',
                q: state.searchQuery
            });
            state.items = Array.isArray(payload.items) ? payload.items : [];
            renderRecentList();
            refreshSpecSuggestions();
        }

        async function loadItemById(id) {
            const payload = await apiRequest({
                action: 'get',
                id: id
            });
            fillForm(payload.item || null);
        }

        async function findByIdentifier() {
            const identifier = String(fields.identifierValue.value || '').trim();
            if (!identifier) {
                setStatus('癒쇱? ?앸퀎媛믪쓣 ?낅젰??二쇱꽭??', true);
                return;
            }

            const payload = await apiRequest({
                action: 'find',
                identifier: identifier
            });

            if (!payload.found) {
                state.currentItemId = '';
                renderHistory([]);
                fields.currentItemBadge.textContent = '?좉퇋 ?깅줉 紐⑤뱶';
                updateQrPreview();
                setStatus('?깅줉?섏? ?딆? ?앸퀎媛믪엯?덈떎. ????ぉ?쇰줈 ??ν븷 ???덉뒿?덈떎.', false);
                return;
            }

            fillForm(payload.item || null);
            setStatus('湲곗〈 ?깅줉 ??ぉ??李얠븯?듬땲??', false);
        }

        async function createInternalKey() {
            const payload = await apiRequest({
                action: 'create_internal_key',
                purchased_at: fields.purchasedAt.value
            });
            fields.identifierType.value = payload.identifier_type || 'internal';
            fields.identifierValue.value = payload.identifier_value || '';
            updateQrPreview();
            setStatus('?대? 愿由щ쾲?몃? ?앹꽦?덉뒿?덈떎.', false);
        }

        async function saveCurrentItem() {
            if (!state.assignmentDrafts.length) throw new Error('보호구와 지급 대상자를 선택한 뒤 선택 적용을 눌러주세요.');
            const combinations = [];
            state.assignmentDrafts.forEach(function (draft) {
                const assignees = draft.employees.length ? draft.employees : [{ id: '', name: '', team: '' }];
                assignees.forEach(function (employee) { combinations.push({ gearType: draft.gearType, employee: employee }); });
            });

            let lastPayload = null;
            for (let index = 0; index < combinations.length; index += 1) {
                const combination = combinations[index];
                const updateExisting = combinations.length === 1 && state.currentItemId;
                lastPayload = await apiRequest({
                    action: 'save_item', compact: '1', id: updateExisting ? state.currentItemId : '',
                    identifier_type: updateExisting ? (fields.identifierType.value || 'internal') : 'internal',
                    identifier_value: updateExisting ? fields.identifierValue.value.trim() : '',
                    gear_type: combination.gearType,
                    item_name: combination.gearType,
                    spec_name: fields.specName.value.trim(), model_name: fields.modelName.value.trim(),
                    kcs_cert_no: fields.kcsCertNo.value.trim(), manufacturer_name: fields.manufacturerName.value.trim(),
                    purchase_vendor: fields.purchaseVendor.value.trim(), purchase_price: removeCommas(fields.purchasePrice.value),
                    purchased_at: fields.purchasedAt.value, status: fields.status.value.trim(),
                    assigned_employee_id: combination.employee.id, assigned_employee_name: combination.employee.name,
                    assigned_team: combination.employee.team, assigned_at: combination.employee.id ? (fields.assignedAt.value || getTodayDate()) : '',
                    notes: fields.notes.value.trim()
                }, 'POST');
            }

            if (lastPayload && lastPayload.item && combinations.length === 1) fillForm(lastPayload.item);

            if (fields.continuousMode.checked) {
                clearForContinuousRegistration();
            }

            state.assignmentDrafts = [];
            renderAssignmentPreview();
            setStatus(combinations.length + '건의 지급 기록을 저장했습니다.', false);
        }

        async function runInitialIssue() {
            if (!state.currentItemId) {
                setStatus('癒쇱? ??λ맂 ??ぉ???좏깮??二쇱꽭??', true);
                return;
            }

            const payload = await apiRequest({
                action: 'initial_issue',
                id: state.currentItemId,
                assigned_employee_id: fields.assignedEmployeeId.value,
                assigned_employee_name: fields.assignedEmployeeName.value.trim(),
                assigned_team: fields.assignedTeam.value.trim(),
                assigned_at: fields.assignedAt.value || getTodayDate(),
                history_note: fields.historyNote.value.trim()
            }, 'POST');

            state.items = Array.isArray(payload.items) ? payload.items : [];
            renderRecentList();
            fillForm(payload.item || null);
            setStatus('吏湲?泥섎━瑜??꾨즺?덉뒿?덈떎.', false);
        }

        async function runBulkInitialIssue() {
            const payload = await apiRequest({
                action: 'bulk_initial_issue',
                identifier_type: fields.identifierType.value,
                identifiers: fields.bulkIdentifiers.value,
                gear_type: fields.gearType.value.trim(),
                item_name: fields.itemName.value.trim(),
                spec_name: fields.specName.value.trim(),
                model_name: fields.modelName.value.trim(),
                kcs_cert_no: fields.kcsCertNo.value.trim(),
                manufacturer_name: fields.manufacturerName.value.trim(),
                purchase_vendor: fields.purchaseVendor.value.trim(),
                purchase_price: removeCommas(fields.purchasePrice.value),
                purchased_at: fields.purchasedAt.value,
                notes: fields.notes.value.trim(),
                assigned_employee_id: fields.assignedEmployeeId.value,
                assigned_employee_name: fields.assignedEmployeeName.value.trim(),
                assigned_team: fields.assignedTeam.value.trim(),
                assigned_at: fields.assignedAt.value || getTodayDate()
            }, 'POST');

            state.items = Array.isArray(payload.items) ? payload.items : [];
            renderRecentList();
            fields.bulkIdentifiers.value = '';
            refreshSpecSuggestions();
            setStatus('湲곗〈 吏湲됲뭹 ?쇨큵 ?깅줉???꾨즺?덉뒿?덈떎.', false);
        }

        async function runBulkStockReceive() {
            const payload = await apiRequest({
                action: 'bulk_receive_stock',
                quantity: fields.bulkQuantity.value,
                gear_type: fields.gearType.value.trim(),
                item_name: fields.itemName.value.trim(),
                spec_name: fields.specName.value.trim(),
                model_name: fields.modelName.value.trim(),
                kcs_cert_no: fields.kcsCertNo.value.trim(),
                manufacturer_name: fields.manufacturerName.value.trim(),
                purchase_vendor: fields.purchaseVendor.value.trim(),
                purchase_price: removeCommas(fields.purchasePrice.value),
                purchased_at: fields.purchasedAt.value,
                notes: fields.notes.value.trim()
            }, 'POST');

            state.items = Array.isArray(payload.items) ? payload.items : [];
            renderRecentList();
            refreshSpecSuggestions();
            fields.bulkQuantity.value = '1';
            fields.identifierType.value = 'internal';
            fields.identifierValue.value = Array.isArray(payload.created_identifiers) && payload.created_identifiers.length
                ? payload.created_identifiers[0]
                : '';
            updateQrPreview();
            setStatus('?섎웾 湲곗? ?낃퀬 ?깅줉???꾨즺?덉뒿?덈떎.', false);
        }

        async function addHistory() {
            if (!state.currentItemId) {
                setStatus('癒쇱? ??λ맂 ??ぉ???좏깮??二쇱꽭??', true);
                return;
            }

            const action = state.currentHistoryId > 0 ? 'update_history' : 'add_history';
            const payload = await apiRequest({
                action: action,
                id: state.currentItemId,
                history_id: state.currentHistoryId,
                history_type: fields.historyType.value,
                history_note: fields.historyNote.value.trim(),
                history_at: fields.historyAt.value || getTodayDate(),
                status: fields.status.value.trim(),
                assigned_employee_id: fields.assignedEmployeeId.value,
                assigned_employee_name: fields.assignedEmployeeName.value.trim(),
                assigned_team: fields.assignedTeam.value.trim()
            }, 'POST');

            state.items = Array.isArray(payload.items) ? payload.items : [];
            renderRecentList();
            fillForm(payload.item || null);
            resetHistoryForm();
            setStatus(state.currentHistoryId > 0 ? '?대젰???섏젙?덉뒿?덈떎.' : '?대젰??異붽??덉뒿?덈떎.', false);
        }

        async function saveTemplate() {
            const payload = await apiRequest({
                action: 'save_template',
                template_id: state.currentTemplateId,
                template_name: fields.templateName.value.trim(),
                gear_type: fields.gearType.value.trim(),
                item_name: fields.itemName.value.trim(),
                spec_name: fields.specName.value.trim(),
                model_name: fields.modelName.value.trim(),
                kcs_cert_no: fields.kcsCertNo.value.trim(),
                manufacturer_name: fields.manufacturerName.value.trim(),
                purchase_vendor: fields.purchaseVendor.value.trim(),
                purchase_price: removeCommas(fields.purchasePrice.value),
                status: fields.status.value.trim(),
                notes: fields.notes.value.trim()
            }, 'POST');

            state.templates = Array.isArray(payload.templates) ? payload.templates : [];
            const savedTemplate = state.templates.find(function (template) {
                return template.template_name === fields.templateName.value.trim();
            }) || null;
            state.currentTemplateId = savedTemplate ? String(savedTemplate.id || '') : '';
            renderTemplates();
            refreshSpecSuggestions();
            setStatus('?쒗뵆由우쓣 ??ν뻽?듬땲??', false);
        }

        async function deleteTemplate() {
            if (!state.currentTemplateId) {
                setStatus('??젣???쒗뵆由우쓣 癒쇱? ?좏깮??二쇱꽭??', true);
                return;
            }
            if (!window.confirm('?좏깮???쒗뵆由우쓣 ??젣?좉퉴??')) {
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
            refreshSpecSuggestions();
            setStatus('?쒗뵆由우쓣 ??젣?덉뒿?덈떎.', false);
        }

        async function saveGearType() {
            const typeName = String(fields.gearTypeNewName.value || '').trim();
            if (!typeName) {
                setStatus('異붽???蹂댄샇援?醫낅쪟紐낆쓣 ?낅젰??二쇱꽭??', true);
                fields.gearTypeNewName.focus();
                return;
            }

            const payload = await apiRequest({
                action: 'save_gear_type',
                type_name: typeName
            }, 'POST');

            state.gearTypes = Array.isArray(payload.gear_types) ? payload.gear_types : [];
            renderGearTypes();
            fields.gearType.value = typeName;
            renderGearTypes();
            fields.gearTypeNewName.value = '';
            setStatus('蹂댄샇援?醫낅쪟瑜???ν뻽?듬땲??', false);
        }

        async function deleteGearType() {
            const typeName = String(fields.gearType.value || '').trim();
            const selected = state.gearTypes.find(function (type) {
                return String(type.name || '').trim() === typeName;
            }) || null;
            const typeId = selected ? String(selected.id || '') : '';

            if (!typeName) {
                setStatus('??젣??蹂댄샇援?醫낅쪟瑜?癒쇱? ?좏깮??二쇱꽭??', true);
                return;
            }
            if (!window.confirm('?좏깮??蹂댄샇援?醫낅쪟瑜???젣?좉퉴??')) {
                return;
            }

            const payload = await apiRequest({
                action: 'delete_gear_type',
                type_id: typeId,
                type_name: typeName
            }, 'POST');

            state.gearTypes = Array.isArray(payload.gear_types) ? payload.gear_types : [];
            fields.gearType.value = '';
            renderGearTypes();
            setStatus('蹂댄샇援?醫낅쪟瑜???젣?덉뒿?덈떎.', false);
        }

        async function deleteCurrentItem() {
            if (!state.currentItemId) {
                setStatus('??젣????ぉ??癒쇱? ?좏깮??二쇱꽭??', true);
                return;
            }
            if (!window.confirm('?좏깮??蹂댄샇援???ぉ????젣?좉퉴??')) {
                return;
            }

            const payload = await apiRequest({
                action: 'delete_item',
                id: state.currentItemId
            }, 'POST');

            state.items = Array.isArray(payload.items) ? payload.items : [];
            clearForm();
            renderRecentList();
            setStatus('??ぉ????젣?덉뒿?덈떎.', false);
        }

        function applySearch() {
            state.searchQuery = String(fields.searchInput.value || '').trim();
            return loadItems().then(function () {
                setStatus(state.searchQuery ? '寃??寃곌낵瑜?遺덈윭?붿뒿?덈떎.' : '?꾩껜 紐⑸줉??遺덈윭?붿뒿?덈떎.', false);
            });
        }

        function resetSearch() {
            fields.searchInput.value = '';
            state.searchQuery = '';
            return loadItems().then(function () {
                setStatus('寃??議곌굔??珥덇린?뷀뻽?듬땲??', false);
            });
        }

        function downloadExport() {
            const query = String(fields.searchInput.value || '').trim();
            const url = exportEndpoint + (query ? ('?q=' + encodeURIComponent(query)) : '');
            window.location.href = url;
        }

        async function startScanner() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                setStatus('??釉뚮씪?곗???移대찓?쇰? 吏?먰븯吏 ?딆뒿?덈떎.', true);
                return;
            }
            if (!('BarcodeDetector' in window)) {
                setStatus('??釉뚮씪?곗????ㅼ떆媛?諛붿퐫???ㅼ틪??吏?먰븯吏 ?딆뒿?덈떎. ?앸퀎媛믪쓣 吏곸젒 ?낅젰??二쇱꽭??', true);
                return;
            }

            stopScanner();

            try {
                const detector = new BarcodeDetector({
                    formats: ['qr_code', 'code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a', 'upc_e']
                });

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

                        const rawValue = String(codes[0].rawValue || '').trim();
                        if (!rawValue) {
                            return;
                        }

                        fields.identifierValue.value = rawValue;
                        fields.identifierType.value = String(codes[0].format || '').toLowerCase() === 'qr_code' ? 'qr' : 'barcode';
                        updateQrPreview();
                        stopScanner();
                        setStatus('肄붾뱶瑜??몄떇?덉뒿?덈떎. 湲곗〈 ?깅줉 ?щ?瑜?議고쉶?⑸땲??', false);
                        await findByIdentifier();
                    } catch (error) {
                        setStatus('?ㅼ틪 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
                    }
                }, 700);

                setStatus('移대찓?쇰? ?쒖옉?덉뒿?덈떎. 肄붾뱶瑜??붾㈃ 以묒븰??留욎떠 二쇱꽭??', false);
            } catch (error) {
                setStatus('移대찓?쇰? ?쒖옉?섏? 紐삵뻽?듬땲??', true);
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
        fields.purchasePrice.addEventListener('input', function () {
            this.value = formatNumberWithComma(this.value);
        });
        fields.selectAllGearTypes.addEventListener('change', function () {
            fields.gearTypeOptions.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
                checkbox.checked = fields.selectAllGearTypes.checked;
            });
            fields.gearType.value = Array.from(fields.gearTypeOptions.querySelectorAll('input[type="checkbox"]:checked'))
                .map(function (checkbox) { return checkbox.value; }).join(', ');
            updateGearSelectAllState();
        });
        fields.selectAllEmployees.addEventListener('change', function () {
            fields.assignedEmployeeOptions.querySelectorAll('.employee-group-options input[type="checkbox"]').forEach(function (checkbox) {
                checkbox.checked = fields.selectAllEmployees.checked;
            });
            syncSelectedEmployees();
            updateEmployeeSelectAllStates();
        });
        fields.assignedTeamSelect.addEventListener('change', function () {
            fields.assignedTeam.value = this.value;
            fields.assignedEmployeeId.value = '';
            fields.assignedEmployeeName.value = '';
            renderEmployees();
        });
        fields.historyType.addEventListener('change', function () {
            const map = {
                '입고': '사용 가능',
                '지급': '지급됨',
                '회수': '반납',
                '점검': '점검 필요',
                '수리': '수리 중',
                '폐기': '폐기'
            };
            if (map[this.value]) {
                fields.status.value = map[this.value];
            }
        });

        document.getElementById('showAdvancedButton').addEventListener('click', function () {
            const open = document.body.classList.toggle('show-advanced');
            this.textContent = open ? '상세 기능 닫기' : '상세 기능 열기';
            this.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        document.getElementById('saveButton').addEventListener('click', function () {
            saveCurrentItem().catch(function (error) {
                setStatus(error.message || '저장 중 오류가 발생했습니다.', true);
            });
        });

        document.getElementById('applySelectionButton').addEventListener('click', function () {
            try {
                applySelectionToDraft();
                clearCurrentSelection();
                setStatus('선택한 품목별 수령 예정자를 임시 목록에 추가했습니다.', false);
            } catch (error) {
                setStatus(error.message || '선택 적용 중 오류가 발생했습니다.', true);
            }
        });

        document.getElementById('clearSelectionDraftButton').addEventListener('click', function () {
            state.assignmentDrafts = [];
            renderAssignmentPreview();
            setStatus('임시 수령 예정자 목록을 비웠습니다.', false);
        });

        document.getElementById('clearGearTypeSelectionButton').addEventListener('click', function () {
            fields.gearTypeOptions.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
                checkbox.checked = false;
            });
            fields.gearType.value = '';
            updateGearSelectAllState();
            setStatus('보호구 종류 선택을 해제했습니다. 적용된 임시 목록은 유지됩니다.', false);
        });

        document.getElementById('clearEmployeeSelectionButton').addEventListener('click', function () {
            fields.assignedEmployeeOptions.querySelectorAll('.employee-group-options input[type="checkbox"]').forEach(function (checkbox) {
                checkbox.checked = false;
            });
            syncSelectedEmployees();
            updateEmployeeSelectAllStates();
            setStatus('지급 대상자 선택을 해제했습니다. 적용된 임시 목록은 유지됩니다.', false);
        });

        document.getElementById('newButton').addEventListener('click', function () {
            clearForm();
            setStatus('신규 입력 모드로 전환했습니다.', false);
        });

        document.getElementById('findButton').addEventListener('click', function () {
            findByIdentifier().catch(function (error) {
                setStatus(error.message || '조회 중 오류가 발생했습니다.', true);
            });
        });

        document.getElementById('initialIssueButton').addEventListener('click', function () {
            runInitialIssue().catch(function (error) {
                setStatus(error.message || '吏湲?泥섎━ 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        document.getElementById('deleteButton').addEventListener('click', function () {
            deleteCurrentItem().catch(function (error) {
                setStatus(error.message || '??젣 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        fields.addHistoryButton.addEventListener('click', function () {
            addHistory().catch(function (error) {
                setStatus(error.message || '?대젰 ???以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        fields.cancelHistoryEditButton.addEventListener('click', function () {
            resetHistoryForm();
            setStatus('?대젰 ?섏젙 紐⑤뱶瑜?痍⑥냼?덉뒿?덈떎.', false);
        });

        document.getElementById('generateInternalKeyButton').addEventListener('click', function () {
            createInternalKey().catch(function (error) {
                setStatus(error.message || '?대?踰덊샇 ?앹꽦 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        document.getElementById('saveTemplateButton').addEventListener('click', function () {
            saveTemplate().catch(function (error) {
                setStatus(error.message || '?쒗뵆由????以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        document.getElementById('deleteTemplateButton').addEventListener('click', function () {
            deleteTemplate().catch(function (error) {
                setStatus(error.message || '?쒗뵆由???젣 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        document.getElementById('addGearTypeButton').addEventListener('click', function () {
            saveGearType().catch(function (error) {
                setStatus(error.message || '蹂댄샇援?醫낅쪟 ???以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        document.getElementById('deleteGearTypeButton').addEventListener('click', function () {
            deleteGearType().catch(function (error) {
                setStatus(error.message || '蹂댄샇援?醫낅쪟 ??젣 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        document.getElementById('searchButton').addEventListener('click', function () {
            applySearch().catch(function (error) {
                setStatus(error.message || '寃??以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        document.getElementById('searchResetButton').addEventListener('click', function () {
            resetSearch().catch(function (error) {
                setStatus(error.message || '珥덇린??以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        document.getElementById('exportButton').addEventListener('click', downloadExport);

        document.getElementById('bulkInitialIssueButton').addEventListener('click', function () {
            runBulkInitialIssue().catch(function (error) {
                setStatus(error.message || '湲곗〈 吏湲됲뭹 ?쇨큵 ?깅줉 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        document.getElementById('bulkReceiveButton').addEventListener('click', function () {
            runBulkStockReceive().catch(function (error) {
                setStatus(error.message || '?섎웾 湲곗? ?낃퀬 ?깅줉 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        document.getElementById('bulkClearButton').addEventListener('click', function () {
            fields.bulkIdentifiers.value = '';
        });

        document.getElementById('clearAssigneeButton').addEventListener('click', function () {
            clearAssigneeSelection();
            setStatus('吏湲???곸옄 ?뺣낫瑜?鍮꾩썱?듬땲??', false);
        });

        document.getElementById('startScanButton').addEventListener('click', startScanner);
        document.getElementById('stopScanButton').addEventListener('click', function () {
            stopScanner();
            setStatus('移대찓?쇰? 以묒??덉뒿?덈떎.', false);
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
                return String(template.id || '') === state.currentTemplateId;
            }) || null;
            applyTemplate(selected);
            if (selected) {
                setStatus('?쒗뵆由??댁슜??遺덈윭?붿뒿?덈떎.', false);
            }
        });

        window.addEventListener('beforeunload', stopScanner);

        (async function init() {
            clearForm();
            try {
                await Promise.all([
                    loadEmployees(),
                    loadGearTypes(),
                    loadTemplates(),
                ]);

                const params = new URLSearchParams(window.location.search);
                const gearUid = String(params.get('gear_uid') || '').trim();
                if (gearUid) {
                    await loadItemById(gearUid);
                    setStatus('?붿껌????ぉ??遺덈윭?붿뒿?덈떎.', false);
                    return;
                }

                setStatus('보호구 종류와 지급 대상자를 입력해 주세요.', false);
            } catch (error) {
                setStatus(error.message || '珥덇린 ?곗씠?곕? 遺덈윭?ㅼ? 紐삵뻽?듬땲??', true);
            }
        })();
    </script>
</body>
</html>
