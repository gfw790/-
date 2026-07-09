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
        <title>蹂댄샇援?愿由?/title>
        <style>
            body { margin: 0; padding: 32px; font-family: "Malgun Gothic", sans-serif; background: #f3f7fb; color: #122033; }
            .panel { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #d7e0ea; border-radius: 20px; padding: 24px; }
            .button { display: inline-flex; align-items: center; justify-content: center; padding: 10px 14px; border-radius: 12px; background: #0f766e; color: #fff; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="panel">
            <h1>蹂댄샇援?愿由?/h1>
            <p>???섏씠吏??愿由ъ옄 ?먮뒗 愿由?沅뚰븳???덈뒗 ?ъ슜?먮쭔 ?묎렐?????덉뒿?덈떎.</p>
            <a class="button" href="/risk_assessment/work_list.php">?묒뾽 紐⑸줉?쇰줈 ?대룞</a>
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
    <title>蹂댄샇援?愿由?/title>
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
            width: min(1400px, calc(100vw - 28px));
            margin: 18px auto 28px;
            display: grid;
            grid-template-columns: minmax(0, 860px) minmax(320px, 480px);
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

        @media (max-width: 1180px) {
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
            <div class="row" style="justify-content:flex-end; margin-bottom:8px;">
                <a class="button secondary" href="/risk_assessment/work_list.php">?묒뾽 紐⑸줉</a>
                <a class="button secondary" href="/safety_gear/monthly_receipt_print.php">월별 일괄출력</a>
            </div>

            <h1>蹂댄샇援?愿由?/h1>
            <p class="lead">諛붿퐫???먮뒗 QR 湲곕컲?쇰줈 蹂댄샇援щ? ?깅줉?섍퀬 吏湲??대젰源뚯? 愿由ы빀?덈떎. 源⑥쭊 臾몄옄???놁씠 議고쉶, ??? ?쇨큵 ?깅줉??媛?ν븯?꾨줉 ?섏씠吏瑜??ㅼ떆 ?뺣━?덉뒿?덈떎.</p>

            <div class="row" style="margin-top:14px;">
                <a class="button ghost" href="/safety_gear/status.php">蹂댄샇援??꾪솴</a>
                <a class="button secondary" href="/safety_gear/report.php">?대젰 議고쉶</a>
                <a class="button secondary" href="/safety_gear/receipt_batch_print.php">?섎졊 ?뺤씤??異쒕젰</a>
            </div>

            <div class="grid" style="margin-top:16px;">
                <div class="field">
                    <label for="template_select">?쒗뵆由??좏깮</label>
                    <select id="template_select">
                        <option value="">?좏깮 ????/option>
                    </select>
                </div>
                <div class="field">
                    <label for="template_name">?쒗뵆由??대쫫</label>
                    <div class="row">
                        <input id="template_name" class="grow" type="text" placeholder="?? ?덉쟾紐?湲곕낯??>
                        <button id="saveTemplateButton" type="button" class="ghost">?쒗뵆由????/button>
                        <button id="deleteTemplateButton" type="button" class="secondary">?쒗뵆由???젣</button>
                    </div>
                </div>

                <div class="field">
                    <label for="identifier_type">?앸퀎 諛⑹떇</label>
                    <select id="identifier_type">
                        <option value="barcode">諛붿퐫??/option>
                        <option value="qr">QR 肄붾뱶</option>
                        <option value="internal">?대? 愿由щ쾲??/option>
                        <option value="rfid">RFID</option>
                        <option value="nfc">NFC</option>
                    </select>
                </div>
                <div class="field">
                    <label for="identifier_value">?앸퀎媛?/label>
                    <div class="row">
                        <input id="identifier_value" class="grow" type="text" placeholder="?ㅼ틪?섍굅??吏곸젒 ?낅젰">
                        <button id="generateInternalKeyButton" type="button" class="ghost">?대?踰덊샇 ?앹꽦</button>
                    </div>
                </div>

                <div class="field">
                    <label for="gear_type">蹂댄샇援?醫낅쪟</label>
                    <select id="gear_type">
                        <option value="">?좏깮?섏꽭??/option>
                    </select>
                    <div class="row">
                        <input id="gear_type_new_name" class="grow" type="text" placeholder="??蹂댄샇援?醫낅쪟 ?낅젰">
                        <button id="addGearTypeButton" type="button" class="ghost">醫낅쪟 異붽?</button>
                        <button id="deleteGearTypeButton" type="button" class="secondary">醫낅쪟 ??젣</button>
                    </div>
                </div>
                <div class="field">
                    <label for="item_name">?덈챸</label>
                    <input id="item_name" type="text" placeholder="?? ?덉쟾紐?>
                </div>

                <div class="field">
                    <label for="spec_name">洹쒓꺽</label>
                    <input id="spec_name" type="text" list="spec_name_list" placeholder="?? ABS, 諛⑹닔?? 6硫댁껜">
                    <datalist id="spec_name_list"></datalist>
                </div>
                <div class="field">
                    <label for="model_name">紐⑤뜽紐?/label>
                    <input id="model_name" type="text" placeholder="?? K2-01">
                </div>

                <div class="field">
                    <label for="kcs_cert_no">KCS ?몄쬆踰덊샇</label>
                    <input id="kcs_cert_no" type="text" placeholder="?? KCS-2026-000123">
                </div>
                <div class="field">
                    <label for="manufacturer_name">?쒖“??/label>
                    <input id="manufacturer_name" type="text" placeholder="?? K2, 3M">
                </div>

                <div class="field">
                    <label for="purchase_vendor">援щℓ泥?/label>
                    <input id="purchase_vendor" type="text" placeholder="?? ?덉쟾留덊듃">
                </div>
                <div class="field">
                    <label for="purchase_price">援щℓ湲덉븸</label>
                    <input id="purchase_price" type="text" inputmode="numeric" placeholder="?? 15000">
                </div>

                <div class="field">
                    <label for="purchased_at">援щℓ??/label>
                    <input id="purchased_at" type="date">
                </div>
                <div class="field">
                    <label for="status">?곹깭</label>
                    <input id="status" type="text" readonly>
                </div>

                <div class="field">
                    <label for="assigned_employee_id">吏湲???곸옄</label>
                    <select id="assigned_employee_id">
                        <option value="">?좏깮 ????/option>
                    </select>
                </div>
                <div class="field">
                    <label for="assigned_team_select">吏湲?遺??/label>
                    <select id="assigned_team_select">
                        <option value="">?좏깮 ????/option>
                    </select>
                </div>

                <div class="field">
                    <label for="assigned_employee_name">吏湲???곸옄 ?대쫫</label>
                    <div class="row">
                        <input id="assigned_employee_name" class="grow" type="text" placeholder="吏곸젒 ?낅젰 媛??>
                        <button id="clearAssigneeButton" type="button" class="secondary">吏湲??뺣낫 鍮꾩?</button>
                    </div>
                </div>
                <div class="field">
                    <label for="assigned_team">吏湲?遺?쒕챸</label>
                    <input id="assigned_team" type="text" placeholder="吏곸젒 ?낅젰 媛??>
                </div>

                <div class="field">
                    <label for="assigned_at">吏湲됱씪</label>
                    <input id="assigned_at" type="date">
                </div>
                <div class="field full">
                    <label for="notes">硫붾え</label>
                    <textarea id="notes" placeholder="異붽? 硫붾え瑜??낅젰?섏꽭??></textarea>
                </div>
            </div>

            <div class="row" style="margin-top:14px;">
                <button id="saveButton" type="button">???/button>
                <button id="newButton" type="button" class="secondary">?좉퇋 ?낅젰</button>
                <button id="findButton" type="button" class="secondary">?앸퀎媛?議고쉶</button>
                <button id="initialIssueButton" type="button" class="ghost">吏湲???ぉ 吏湲?泥섎━</button>
                <button id="deleteButton" type="button" class="danger">??젣</button>
                <label class="hint" style="display:flex; align-items:center; gap:6px; margin-left:4px;">
                    <input id="continuousMode" type="checkbox">
                    ?곗냽 ?깅줉 紐⑤뱶
                </label>
            </div>

            <div class="scanner">
                <video id="scannerVideo" playsinline muted></video>
                <div class="scanner-bar">
                    <div class="row">
                        <button id="startScanButton" type="button">移대찓???ㅼ틪 ?쒖옉</button>
                        <button id="stopScanButton" type="button" class="secondary">移대찓??以묒?</button>
                    </div>
                    <div class="hint" style="margin-top:8px; color:#cbd5e1;">吏??釉뚮씪?곗??먯꽌??諛붿퐫?쒖? QR??諛붾줈 ?쎌쓣 ???덉뒿?덈떎. 吏?먮릺吏 ?딆쑝硫??앸퀎媛믪쓣 吏곸젒 ?낅젰?대룄 ?⑸땲??</div>
                </div>
            </div>

            <div id="statusBox" class="status-box">以鍮꾨릺?덉뒿?덈떎. ?앸퀎媛믪쓣 ?낅젰?섍굅??理쒓렐 ?깅줉 紐⑸줉?먯꽌 ??ぉ???좏깮?섏꽭??</div>

            <h3 style="margin-top:22px;">?대젰 愿由?/h3>
            <div class="grid" style="margin-top:12px;">
                <div class="field">
                    <label for="history_type">?대젰 醫낅쪟</label>
                    <select id="history_type">
                        <option value="?낃퀬">?낃퀬</option>
                        <option value="吏湲?>吏湲?/option>
                        <option value="?뚯닔">?뚯닔</option>
                        <option value="?먭?">?먭?</option>
                        <option value="?섎━">?섎━</option>
                        <option value="?먭린">?먭린</option>
                        <option value="鍮꾧퀬">鍮꾧퀬</option>
                    </select>
                </div>
                <div class="field">
                    <label for="history_at">?대젰 ?좎쭨</label>
                    <input id="history_at" type="date">
                </div>
                <div class="field full">
                    <label for="history_note">?대젰 ?댁슜</label>
                    <div class="row">
                        <input id="history_note" class="grow" type="text" placeholder="?? ?⑥긽??吏湲? ?몄쿃 ?꾨즺, ?먭린 泥섎━">
                        <button id="addHistoryButton" type="button" class="ghost">?대젰 異붽?</button>
                        <button id="cancelHistoryEditButton" type="button" class="secondary" hidden>?섏젙 痍⑥냼</button>
                    </div>
                </div>
            </div>
            <div id="historyList" class="history-list"></div>

            <div class="bulk-box">
                <h3>?섎웾 湲곗? ?낃퀬 ?깅줉</h3>
                <p class="hint" style="margin:8px 0 12px;">?꾩옱 ?낅젰??蹂댄샇援?湲곕낯 ?뺣낫濡??대? 愿由щ쾲?몃? ?먮룞 ?앹꽦???щ윭 嫄댁쓣 ??踰덉뿉 ?낃퀬 ?깅줉?⑸땲??</p>
                <div class="field">
                    <label for="bulk_quantity">?낃퀬 ?섎웾</label>
                    <input id="bulk_quantity" type="number" min="1" step="1" value="1" placeholder="?? 10">
                </div>
                <div class="row" style="margin-top:10px;">
                    <button id="bulkReceiveButton" type="button" class="ghost">?섎웾 湲곗? ?낃퀬 ?깅줉</button>
                </div>
            </div>

            <div class="bulk-box">
                <h3>湲곗〈 吏湲됲뭹 ?쇨큵 ?깅줉</h3>
                <p class="hint" style="margin:8px 0 12px;">?앸퀎媛믪쓣 以꾨쭏???낅젰?섎㈃ ?꾩옱 湲곕낯 ?뺣낫? 吏湲???곸옄瑜?湲곗??쇰줈 ?щ윭 嫄댁쓣 利됱떆 吏湲??곹깭濡??깅줉?⑸땲??</p>
                <div class="field">
                    <label for="bulk_identifiers">?앸퀎媛?紐⑸줉</label>
                    <textarea id="bulk_identifiers" placeholder="??以꾩뿉 ?섎굹???낅젰&#10;BARCODE-001&#10;BARCODE-002&#10;BARCODE-003"></textarea>
                </div>
                <div class="row" style="margin-top:10px;">
                    <button id="bulkInitialIssueButton" type="button" class="ghost">湲곗〈 吏湲됲뭹 ?쇨큵 ?깅줉</button>
                    <button id="bulkClearButton" type="button" class="secondary">?낅젰 鍮꾩슦湲?/button>
                </div>
            </div>
        </section>

        <aside class="panel">
            <div class="section-head">
                <h2>?좏깮 ??ぉ</h2>
                <div id="currentItemBadge" class="badge">?좉퇋 ?깅줉 紐⑤뱶</div>
            </div>

            <div class="qr-box" style="margin-top:14px;">
                <img id="qrImage" alt="QR 肄붾뱶" hidden>
                <div id="qrEmpty" class="hint">?앸퀎媛믪쓣 ?낅젰?섎㈃ QR 肄붾뱶媛 ?ш린???쒖떆?⑸땲??</div>
            </div>

            <div class="section-head" style="margin-top:22px;">
                <h3>理쒓렐 ?깅줉 紐⑸줉</h3>
                <span id="recentCount" class="count"></span>
            </div>
            <div class="row" style="margin-top:10px;">
                <input id="searchInput" class="grow" type="text" placeholder="?앸퀎媛? 蹂댄샇援?醫낅쪟, 援щℓ泥? 吏湲???곸옄 寃??>
                <button id="searchButton" type="button" class="secondary">寃??/button>
                <button id="searchResetButton" type="button" class="secondary">珥덇린??/button>
                <button id="exportButton" type="button" class="ghost">CSV ?ㅼ슫濡쒕뱶</button>
            </div>
            <div class="hint" style="margin-top:8px;">紐⑸줉 ??ぉ???꾨Ⅴ硫?諛붾줈 ?섏젙 紐⑤뱶濡?遺덈윭?듬땲??</div>
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
            fields.templateSelect.innerHTML = '<option value="">?좏깮 ????/option>';

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
            fields.assignedTeamSelect.innerHTML = '<option value="">?좏깮 ????/option>';

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

        function renderEmployees() {
            const currentEmployeeId = String(fields.assignedEmployeeId.value || '');
            fields.assignedEmployeeId.innerHTML = '<option value="">?좏깮 ????/option>';

            state.employees.forEach(function (employee) {
                const option = document.createElement('option');
                option.value = String(employee.id || '');
                option.textContent = [employee.name || '', employee.team ? '(' + employee.team + ')' : ''].join(' ').trim();
                option.dataset.name = employee.name || '';
                option.dataset.team = employee.team || '';
                fields.assignedEmployeeId.appendChild(option);
            });

            fields.assignedEmployeeId.value = currentEmployeeId;
            buildTeamOptions();
        }

        function renderGearTypes() {
            const currentValue = String(fields.gearType.value || '');
            fields.gearType.innerHTML = '<option value="">?좏깮?섏꽭??/option>';

            state.gearTypes.forEach(function (type) {
                const option = document.createElement('option');
                option.value = type.name || '';
                option.textContent = type.name || '';
                option.dataset.id = type.id || '';
                fields.gearType.appendChild(option);
            });

            if (currentValue) {
                fields.gearType.value = currentValue;
            }
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
            if (value === '?ъ슜 媛??) {
                return 'ready';
            }
            if (value === '吏湲됰맖') {
                return 'issued';
            }
            if (value === '?먭린' || value === '諛섎궔') {
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
            fields.recentCount.textContent = '珥?' + list.length + '嫄?;

            if (!list.length) {
                fields.recentList.innerHTML = '<div class="recent-item"><div class="recent-title">?쒖떆????ぉ???놁뒿?덈떎.</div><div class="meta">寃??議곌굔??諛붽씀嫄곕굹 ????ぉ???깅줉?섏꽭??</div></div>';
                return;
            }

            list.forEach(function (item) {
                const node = document.createElement('div');
                node.className = 'recent-item';
                node.innerHTML =
                    '<div class="row" style="justify-content:space-between; align-items:flex-start;">' +
                        '<div class="recent-title">' + escapeHtml(item.product_name || item.item_name || item.gear_type || '誘몃텇瑜?) + '</div>' +
                        '<span class="pill ' + statusPillClass(item.status) + '">' + escapeHtml(item.status || '-') + '</span>' +
                    '</div>' +
                    '<div class="meta">' +
                        '?앸퀎媛? ' + escapeHtml(item.identifier_value || '-') + '<br>' +
                        '醫낅쪟: ' + escapeHtml(item.gear_type || '-') + '<br>' +
                        '援щℓ泥? ' + escapeHtml(item.purchase_vendor || '-') + '<br>' +
                        '吏湲???곸옄: ' + escapeHtml(item.assigned_employee_name || '-') + '<br>' +
                        '理쒖쥌 ?섏젙: ' + escapeHtml(item.updated_at || '-') +
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
        }

        function clearForm() {
            state.currentItemId = '';
            state.currentHistoryId = 0;
            state.currentTemplateId = '';

            fields.templateSelect.value = '';
            fields.templateName.value = '';
            fields.identifierType.value = 'barcode';
            fields.identifierValue.value = '';
            fields.gearType.value = '';
            fields.gearTypeNewName.value = '';
            fields.itemName.value = '';
            fields.specName.value = '';
            fields.modelName.value = '';
            fields.kcsCertNo.value = '';
            fields.manufacturerName.value = '';
            fields.purchaseVendor.value = '';
            fields.purchasePrice.value = '';
            fields.purchasedAt.value = '';
            fields.status.value = '?ъ슜 媛??;
            clearAssigneeSelection();
            fields.notes.value = '';

            resetHistoryForm();
            renderHistory([]);
            updateQrPreview();
            fields.currentItemBadge.textContent = '?좉퇋 ?깅줉 紐⑤뱶';
        }

        function clearForContinuousRegistration() {
            state.currentItemId = '';
            fields.identifierValue.value = '';
            fields.status.value = '?ъ슜 媛??;
            clearAssigneeSelection();
            resetHistoryForm();
            renderHistory([]);
            updateQrPreview();
            fields.currentItemBadge.textContent = '?곗냽 ?깅줉 ?湲?;
            fields.identifierValue.focus();
        }

        function fillForm(item) {
            const entry = item || {};
            state.currentItemId = String(entry.id || '');

            fields.identifierType.value = entry.identifier_type || 'barcode';
            fields.identifierValue.value = entry.identifier_value || '';
            fields.gearType.value = entry.gear_type || '';
            fields.itemName.value = entry.item_name || '';
            fields.specName.value = entry.spec_name || '';
            fields.modelName.value = entry.model_name || '';
            fields.kcsCertNo.value = entry.kcs_cert_no || '';
            fields.manufacturerName.value = entry.manufacturer_name || '';
            fields.purchaseVendor.value = entry.purchase_vendor || '';
            fields.purchasePrice.value = formatNumberWithComma(entry.purchase_price || '');
            fields.purchasedAt.value = formatDateOnly(entry.purchased_at || '');
            fields.status.value = entry.status || '?ъ슜 媛??;
            fields.assignedEmployeeId.value = entry.assigned_employee_id || '';
            fields.assignedEmployeeName.value = entry.assigned_employee_name || '';
            fields.assignedTeam.value = entry.assigned_team || '';
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
            fields.itemName.value = entry.item_name || '';
            fields.specName.value = entry.spec_name || '';
            fields.modelName.value = entry.model_name || '';
            fields.kcsCertNo.value = entry.kcs_cert_no || '';
            fields.manufacturerName.value = entry.manufacturer_name || '';
            fields.purchaseVendor.value = entry.purchase_vendor || '';
            fields.purchasePrice.value = formatNumberWithComma(entry.purchase_price || '');
            fields.status.value = entry.status || '?ъ슜 媛??;
            fields.notes.value = entry.notes || '';
        }

        function startHistoryEdit(entry) {
            state.currentHistoryId = Number(entry.history_id || 0);
            fields.historyType.value = entry.type || '?낃퀬';
            fields.historyAt.value = formatDateOnly(entry.timestamp || '') || getTodayDate();
            fields.historyNote.value = entry.note || '';
            fields.addHistoryButton.textContent = '?대젰 ?섏젙 ???;
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

        function syncAssigneeFromSelect() {
            const selected = fields.assignedEmployeeId.options[fields.assignedEmployeeId.selectedIndex];
            if (!selected || !fields.assignedEmployeeId.value) {
                return;
            }
            fields.assignedEmployeeName.value = selected.dataset.name || '';
            fields.assignedTeam.value = selected.dataset.team || '';
            if (!fields.assignedAt.value) {
                fields.assignedAt.value = getTodayDate();
            }
            buildTeamOptions();
            fields.assignedTeamSelect.value = fields.assignedTeam.value;
        }

        async function saveCurrentItem() {
            const payload = await apiRequest({
                action: 'save_item',
                id: state.currentItemId,
                identifier_type: fields.identifierType.value,
                identifier_value: fields.identifierValue.value.trim(),
                gear_type: fields.gearType.value.trim(),
                item_name: fields.itemName.value.trim(),
                spec_name: fields.specName.value.trim(),
                model_name: fields.modelName.value.trim(),
                kcs_cert_no: fields.kcsCertNo.value.trim(),
                manufacturer_name: fields.manufacturerName.value.trim(),
                purchase_vendor: fields.purchaseVendor.value.trim(),
                purchase_price: removeCommas(fields.purchasePrice.value),
                purchased_at: fields.purchasedAt.value,
                status: fields.status.value.trim(),
                assigned_employee_id: fields.assignedEmployeeId.value,
                assigned_employee_name: fields.assignedEmployeeName.value.trim(),
                assigned_team: fields.assignedTeam.value.trim(),
                assigned_at: fields.assignedAt.value,
                notes: fields.notes.value.trim()
            }, 'POST');

            state.items = Array.isArray(payload.items) ? payload.items : [];
            renderRecentList();
            refreshSpecSuggestions();

            if (payload.item) {
                fillForm(payload.item);
            }

            if (fields.continuousMode.checked) {
                clearForContinuousRegistration();
            }

            setStatus('??ぉ????ν뻽?듬땲??', false);
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
            fields.gearTypeNewName.value = '';
            setStatus('蹂댄샇援?醫낅쪟瑜???ν뻽?듬땲??', false);
        }

        async function deleteGearType() {
            const selected = fields.gearType.options[fields.gearType.selectedIndex];
            const typeName = String(fields.gearType.value || '').trim();
            const typeId = selected ? String(selected.dataset.id || '') : '';

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
        fields.assignedEmployeeId.addEventListener('change', syncAssigneeFromSelect);
        fields.assignedTeamSelect.addEventListener('change', function () {
            if (this.value) {
                fields.assignedTeam.value = this.value;
            }
        });
        fields.assignedEmployeeName.addEventListener('input', function () {
            if (!this.value.trim()) {
                fields.assignedEmployeeId.value = '';
            }
        });
        fields.historyType.addEventListener('change', function () {
            const map = {
                '?낃퀬': '?ъ슜 媛??,
                '吏湲?: '吏湲됰맖',
                '?뚯닔': '諛섎궔',
                '?먭?': '?먭? ?꾩슂',
                '?섎━': '?섎━ 以?,
                '?먭린': '?먭린'
            };
            if (map[this.value]) {
                fields.status.value = map[this.value];
            }
        });

        document.getElementById('saveButton').addEventListener('click', function () {
            saveCurrentItem().catch(function (error) {
                setStatus(error.message || '???以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            });
        });

        document.getElementById('newButton').addEventListener('click', function () {
            clearForm();
            setStatus('?좉퇋 ?낅젰 紐⑤뱶濡??꾪솚?덉뒿?덈떎.', false);
        });

        document.getElementById('findButton').addEventListener('click', function () {
            findByIdentifier().catch(function (error) {
                setStatus(error.message || '議고쉶 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
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
                await loadEmployees();
                await loadGearTypes();
                await loadTemplates();
                await loadItems();

                const params = new URLSearchParams(window.location.search);
                const gearUid = String(params.get('gear_uid') || '').trim();
                if (gearUid) {
                    await loadItemById(gearUid);
                    setStatus('?붿껌????ぉ??遺덈윭?붿뒿?덈떎.', false);
                    return;
                }

                setStatus('以鍮꾨릺?덉뒿?덈떎. ?앸퀎媛믪쓣 ?낅젰?섍굅??理쒓렐 ?깅줉 紐⑸줉?먯꽌 ??ぉ???좏깮?섏꽭??', false);
            } catch (error) {
                setStatus(error.message || '珥덇린 ?곗씠?곕? 遺덈윭?ㅼ? 紐삵뻽?듬땲??', true);
            }
        })();
    </script>
</body>
</html>
