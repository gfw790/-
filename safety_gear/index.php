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
        <title>蹂댄샇援ш?由?/title>
        <style>
            body { font-family: "Malgun Gothic", sans-serif; background:#f3f7fb; color:#122033; margin:0; padding:32px; }
            .panel { max-width:720px; margin:0 auto; background:#fff; border:1px solid #d7e0ea; border-radius:20px; padding:24px; }
            .actions { margin-top:16px; display:flex; gap:10px; flex-wrap:wrap; }
            .button { display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; background:#0f766e; color:#fff; text-decoration:none; }
            .button.secondary { background:#e2e8f0; color:#0f172a; }
        </style>
    </head>
    <body>
        <div class="panel">
            <h1>蹂댄샇援ш?由?/h1>
            <p>???섏씠吏???덉쟾愿由ъ옄 ?먮뒗 愿由ш텒???ъ슜?먮쭔 ?묎렐?????덉뒿?덈떎.</p>
            <div class="actions">
                <a class="button secondary" href="/risk_assessment/work_list.php">?묒뾽紐⑸줉?쇰줈 ?뚯븘媛湲?/a>
            </div>
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
    <title>?덉쟾蹂댄샇援?愿由?/title>
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

        .bulk-box {
            margin-top: 18px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #f8fafc;
            padding: 14px;
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

        @media (max-width: 1024px) {
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
                <a class="btn-secondary" href="/risk_assessment/work_list.php">?묒뾽紐⑸줉</a>
            </div>
            <h1>?덉쟾蹂댄샇援?愿由?/h1>
            <p class="lead">諛붿퐫???먮뒗 QR 肄붾뱶瑜??ㅼ틪???깅줉?섍퀬, 蹂댄샇援?醫낅쪟쨌援щℓ泥샕룰뎄留ㅺ?寃㈑룹?湲됱옄쨌?곹깭쨌?대젰???④퍡 愿由ы빀?덈떎. 援ъ“??RFID/NFC ?뺤옣??媛?ν븳 ?뺥깭濡??댁뼱 ?먯뿀?듬땲??</p>

            <div class="grid" style="margin-top:16px;">
                <div class="field">
                    <label for="template_select">?쒗뭹 ?쒗뵆由?/label>
                    <select id="template_select">
                        <option value="">?좏깮 ????/option>
                    </select>
                </div>
                <div class="field">
                    <label for="template_name">?쒗뵆由??대쫫</label>
                    <div class="row">
                        <input id="template_name" class="grow" type="text" placeholder="?? K2 ?덉쟾紐?湲곕낯??>
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
                        <input id="identifier_value" class="grow" type="text" placeholder="?ㅼ틪 ?먮뒗 吏곸젒 ?낅젰">
                        <button id="generateInternalKeyButton" type="button" class="ghost">?대? ???앹꽦</button>
                    </div>
                </div>
                <div class="field">
                    <label for="gear_type">蹂댄샇援?醫낅쪟</label>
                    <input id="gear_type" type="text" placeholder="?? ?덉쟾紐? ?덉쟾踰⑦듃, 諛⑹쭊留덉뒪??>
                </div>
                <div class="field">
                    <label for="item_name">?덈챸</label>
                    <input id="item_name" type="text" placeholder="?? ?덉쟾紐?>
                </div>
                <div class="field">
                    <label for="model_name">紐⑤뜽</label>
                    <input id="model_name" type="text" placeholder="?? K2 ?붿씠??>
                </div>
                <div class="field">
                    <label for="purchase_vendor">援щℓ泥?/label>
                    <input id="purchase_vendor" type="text" placeholder="?? ?덉쟾留덊듃">
                </div>
                <div class="field">
                    <label for="purchase_price">援щℓ媛寃?/label>
                    <input id="purchase_price" type="text" placeholder="?? 15000" inputmode="numeric">
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
                    <label for="assigned_employee_id">吏湲됱옄</label>
                    <select id="assigned_employee_id">
                        <option value="">?좏깮 ????/option>
                    </select>
                </div>
                <div class="field">
                    <label for="assigned_team">吏湲됲?</label>
                    <input id="assigned_team" type="text" placeholder="吏곸썝 ?좏깮 ???먮룞 ?낅젰">
                </div>
                <div class="field">
                    <label for="assigned_at">吏湲됱씪</label>
                    <select id="assigned_team_select" style="margin-top:6px;">
                        <option value="">? ?좏깮</option>
                    </select>
                    <input id="assigned_at" type="date">
                </div>
                <div class="field">
                    <label for="assigned_employee_name">吏湲됱옄紐?/label>
                    <div class="row">
                        <input id="assigned_employee_name" class="grow" type="text" placeholder="吏곸썝 ?좏깮 ???먮룞 ?낅젰">
                        <button id="clearAssigneeButton" type="button" class="secondary">吏湲됱옄 ??젣</button>
                    </div>
                </div>
                <div class="field full">
                    <label for="notes">硫붾え</label>
                    <textarea id="notes" placeholder="異붽? 硫붾え瑜??낅젰?섏꽭??"></textarea>
                </div>
            </div>

            <div class="row" style="margin-top:14px;">
                <button id="saveButton" type="button">???/button>
                <button id="newButton" type="button" class="secondary">?좉퇋 ?낅젰</button>
                <button id="findButton" type="button" class="secondary">?앸퀎媛?議고쉶</button>
                <button id="initialIssueButton" type="button" class="ghost">珥덇린 吏湲??깅줉</button>
                <button id="deleteButton" type="button" class="danger">??젣</button>
                <a href="/safety_gear/report.php" class="button secondary">?대젰??議고쉶/異쒕젰</a>
                <a href="/safety_gear/receipt_batch_print.php" class="button secondary">媛쒖씤蹂??뺤씤???쇨큵異쒕젰</a>
                <a href="/safety_gear/status.php" class="button secondary">?덉쟾蹂댄샇援??꾪솴</a>
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
                    <div class="hint" style="color:#cbd5e1; margin-top:8px;">BarcodeDetector瑜?吏?먰븯??釉뚮씪?곗??먯꽌??移대찓???ㅼ틪??媛?ν븯怨? 洹몃젃吏 ?딆쑝硫?吏곸젒 ?낅젰?쇰줈???깅줉?????덉뒿?덈떎.</div>
                </div>
            </div>

            <div id="statusBox" class="status">以鍮꾨릺?덉뒿?덈떎. ?ㅼ틪?섍굅???앸퀎媛믪쓣 ?낅젰??二쇱꽭??</div>

            <h3 style="margin-top:22px;">異붿쟻 ?대젰</h3>
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
                        <input id="history_note" class="grow" type="text" placeholder="?? ?띻만??吏湲? ?멸? ?먭? ?꾨즺">
                        <button id="addHistoryButton" type="button" class="ghost">?대젰 異붽?</button>
                        <button id="cancelHistoryEditButton" type="button" class="secondary" hidden>?섏젙 痍⑥냼</button>
                    </div>
                </div>
            </div>
            <div id="historyList" class="history-list"></div>

            <div class="bulk-box">
                <h3>?낃퀬 ?섎웾 ?쇨큵 ?깅줉</h3>
                <p class="hint" style="margin:8px 0 12px;">?꾩옱 ?낅젰??蹂댄샇援?湲곕낯?뺣낫瑜?湲곗??쇰줈, ?섎웾留뚰겮 ?대?肄붾뱶瑜??먮룞 ?앹꽦????踰덉뿉 ?낃퀬 ?깅줉?⑸땲??</p>
                <div class="field">
                    <label for="bulk_quantity">?낃퀬 ?섎웾</label>
                    <input id="bulk_quantity" type="number" min="1" step="1" value="1" placeholder="?? 10">
                </div>
                <div class="row" style="margin-top:10px;">
                    <button id="bulkReceiveButton" type="button" class="ghost">?낃퀬 ?섎웾 ?쇨큵 ?깅줉</button>
                </div>
            </div>

            <div class="bulk-box">
                <h3>湲곗〈 吏湲됲뭹 ?쇨큵 ?깅줉</h3>
                <p class="hint" style="margin:8px 0 12px;">?꾩옱 ?낅젰??蹂댄샇援?湲곕낯?뺣낫? 吏湲됱옄 ?뺣낫瑜?湲곗??쇰줈, ?앸퀎媛믩쭔 ?щ윭 以꾨줈 ?ｌ뼱 ??踰덉뿉 `吏湲됰맖` ?곹깭濡??깅줉?⑸땲??</p>
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
                <div id="qrEmpty" class="hint">?앸퀎媛믪씠 ?덉쑝硫?QR 誘몃━蹂닿린媛 ?쒖떆?⑸땲??</div>
            </div>

            <div class="section-head" style="margin-top:22px;">
                <h3>?깅줉 紐⑸줉</h3>
                <span id="recentCount" class="count"></span>
            </div>
            <div class="row" style="margin-top:10px;">
                <input id="searchInput" class="grow" type="text" placeholder="?앸퀎媛? 蹂댄샇援? 援щℓ泥? 吏湲됱옄 寃??>
                <button id="searchButton" type="button" class="secondary">寃??/button>
                <button id="searchResetButton" type="button" class="secondary">珥덇린??/button>
                <button id="exportButton" type="button" class="ghost">CSV ?ㅼ슫濡쒕뱶</button>
            </div>
            <div class="hint" style="margin-top:8px;">理쒓렐 ?섏젙???쒖꽌濡?蹂댁엯?덈떎. ??ぉ???꾨Ⅴ硫??곸꽭媛 ?쇱そ??梨꾩썙吏묐땲??</div>
            <div id="recentList" class="recent-list"></div>
        </aside>
    </div>

    <script>
        const apiEndpoint = 'api.php';
        const exportEndpoint = 'export.php';
        const qrEndpoint = 'qr.php';

        function ensureGearTypeField() {
            const original = document.getElementById('gear_type');
            if (!original) {
                return null;
            }
            if (original.tagName === 'SELECT') {
                return original;
            }

            const select = document.createElement('select');
            select.id = 'gear_type';
            select.innerHTML = '<option value="">蹂댄샇援?醫낅쪟 ?좏깮</option>';

            if (original.value) {
                const option = document.createElement('option');
                option.value = original.value;
                option.textContent = original.value;
                select.appendChild(option);
                select.value = original.value;
            }

            original.parentNode.replaceChild(select, original);

            const field = select.closest('.field');
            if (field && !document.getElementById('gearTypeTools')) {
                const tools = document.createElement('div');
                tools.id = 'gearTypeTools';
                tools.className = 'row';
                tools.style.marginTop = '6px';
                tools.innerHTML =
                    '<input id="gear_type_new_name" class="grow" type="text" placeholder="??蹂댄샇援?醫낅쪟 ?낅젰">' +
                    '<button id="addGearTypeButton" type="button" class="ghost">紐⑸줉 異붽?</button>' +
                    '<button id="deleteGearTypeButton" type="button" class="secondary">?좏깮 ??젣</button>';
                field.appendChild(tools);
            }

            return select;
        }

        ensureGearTypeField();

        function ensureSpecField() {
            let specInput = document.getElementById('spec_name');
            if (specInput) {
                specInput.setAttribute('list', 'spec_name_list');
                if (!document.getElementById('spec_name_list')) {
                    const existingList = document.createElement('datalist');
                    existingList.id = 'spec_name_list';
                    specInput.parentNode.appendChild(existingList);
                }
                return specInput;
            }

            const modelField = document.getElementById('model_name') ? document.getElementById('model_name').closest('.field') : null;
            if (!modelField || !modelField.parentNode) {
                return null;
            }

            const specField = document.createElement('div');
            specField.className = 'field';

            const specLabel = document.createElement('label');
            specLabel.setAttribute('for', 'spec_name');
            specLabel.textContent = '洹쒓꺽';

            specInput = document.createElement('input');
            specInput.id = 'spec_name';
            specInput.type = 'text';
            specInput.setAttribute('list', 'spec_name_list');
            specInput.placeholder = '?? ABS, 6?몄튂, ?꾩껜??;

            const specList = document.createElement('datalist');
            specList.id = 'spec_name_list';

            specField.appendChild(specLabel);
            specField.appendChild(specInput);
            specField.appendChild(specList);
            modelField.parentNode.insertBefore(specField, modelField);

            return specInput;
        }

        ensureSpecField();

        function ensureKcsField() {
            let kcsInput = document.getElementById('kcs_cert_no');
            if (kcsInput) {
                return kcsInput;
            }

            const purchaseVendorField = document.getElementById('purchase_vendor') ? document.getElementById('purchase_vendor').closest('.field') : null;
            if (!purchaseVendorField || !purchaseVendorField.parentNode) {
                return null;
            }

            const kcsField = document.createElement('div');
            kcsField.className = 'field';

            const kcsLabel = document.createElement('label');
            kcsLabel.setAttribute('for', 'kcs_cert_no');
            kcsLabel.textContent = 'KCS ?덉쟾?몄쬆踰덊샇';

            kcsInput = document.createElement('input');
            kcsInput.id = 'kcs_cert_no';
            kcsInput.type = 'text';
            kcsInput.placeholder = '?? KCS-2026-000123';

            kcsField.appendChild(kcsLabel);
            kcsField.appendChild(kcsInput);
            purchaseVendorField.parentNode.insertBefore(kcsField, purchaseVendorField);

            return kcsInput;
        }

        ensureKcsField();

        function ensureManufacturerField() {
            let manufacturerInput = document.getElementById('manufacturer_name');
            if (manufacturerInput) {
                return manufacturerInput;
            }

            const purchaseVendorField = document.getElementById('purchase_vendor') ? document.getElementById('purchase_vendor').closest('.field') : null;
            if (!purchaseVendorField || !purchaseVendorField.parentNode) {
                return null;
            }

            const manufacturerField = document.createElement('div');
            manufacturerField.className = 'field';

            const manufacturerLabel = document.createElement('label');
            manufacturerLabel.setAttribute('for', 'manufacturer_name');
            manufacturerLabel.textContent = '?쒖“??;

            manufacturerInput = document.createElement('input');
            manufacturerInput.id = 'manufacturer_name';
            manufacturerInput.type = 'text';
            manufacturerInput.placeholder = '?? K2, 3M, ?좏븳?대쾶由?;

            manufacturerField.appendChild(manufacturerLabel);
            manufacturerField.appendChild(manufacturerInput);
            purchaseVendorField.parentNode.insertBefore(manufacturerField, purchaseVendorField);

            return manufacturerInput;
        }

        ensureManufacturerField();

        const state = {
            items: [],
            employees: [],
            templates: [],
            gearTypes: [],
            currentItemId: '',
            currentTemplateId: '',
            currentHistoryId: 0,
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
            gearTypeNewName: document.getElementById('gear_type_new_name'),
            addGearTypeButton: document.getElementById('addGearTypeButton'),
            deleteGearTypeButton: document.getElementById('deleteGearTypeButton'),
            itemName: document.getElementById('item_name'),
            specName: document.getElementById('spec_name'),
            modelName: document.getElementById('model_name'),
            kcsCertNo: document.getElementById('kcs_cert_no'),
            manufacturerName: document.getElementById('manufacturer_name'),
            purchaseVendor: document.getElementById('purchase_vendor'),
            purchasePrice: document.getElementById('purchase_price'),
            purchasedAt: document.getElementById('purchased_at'),
            status: document.getElementById('status'),
            assignedEmployeeId: document.getElementById('assigned_employee_id'),
            assignedEmployeeName: document.getElementById('assigned_employee_name'),
            clearAssigneeButton: document.getElementById('clearAssigneeButton'),
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
            scannerVideo: document.getElementById('scannerVideo'),
            searchInput: document.getElementById('searchInput'),
            continuousMode: document.getElementById('continuousMode'),
            bulkIdentifiers: document.getElementById('bulk_identifiers'),
            bulkQuantity: document.getElementById('bulk_quantity')
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
                throw new Error(payload && payload.message ? payload.message : '?붿껌 泥섎━ 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.');
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

        function formatDateOnly(value) {
            const raw = String(value || '').trim();
            if (!raw) {
                return '';
            }
            return raw.slice(0, 10);
        }

        function getTodayDate() {
            return new Date().toISOString().slice(0, 10);
        }

        function formatNumberWithComma(num) {
            const str = String(num || '').replace(/,/g, '').trim();
            if (!str || isNaN(str)) {
                return '';
            }
            return Math.floor(Number(str)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function removeCommas(str) {
            return String(str || '').replace(/,/g, '').trim();
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
            select.innerHTML = '<option value="">?좏깮 ????/option>';
            state.employees.forEach(function (employee) {
                const option = document.createElement('option');
                option.value = employee.id;
                option.textContent = '[' + (employee.team || '-') + '] ' + employee.name + (employee.position ? ' / ' + employee.position : '');
                option.dataset.name = employee.name || '';
                option.dataset.team = employee.team || '';
                select.appendChild(option);
            });
        }

        function ensureAssignedTeamSelectPlacement() {
            if (!fields.assignedTeamSelect || !fields.assignedTeam || !fields.assignedTeam.parentNode) {
                return;
            }
            if (fields.assignedTeam.nextElementSibling !== fields.assignedTeamSelect) {
                fields.assignedTeam.parentNode.insertBefore(fields.assignedTeamSelect, fields.assignedTeam.nextSibling);
            }
        }

        function renderAssignedTeams() {
            if (!fields.assignedTeamSelect) {
                return;
            }

            const currentValue = String(fields.assignedTeam.value || '').trim();
            const teams = [];
            const seen = new Set();

            state.employees.forEach(function (employee) {
                const teamName = String(employee.team || '').trim();
                if (!teamName || seen.has(teamName)) {
                    return;
                }
                seen.add(teamName);
                teams.push(teamName);
            });

            teams.sort(function (a, b) {
                return a.localeCompare(b, 'ko');
            });

            fields.assignedTeamSelect.innerHTML = '<option value="">? ?좏깮</option>';
            teams.forEach(function (teamName) {
                const option = document.createElement('option');
                option.value = teamName;
                option.textContent = teamName;
                fields.assignedTeamSelect.appendChild(option);
            });

            fields.assignedTeamSelect.value = seen.has(currentValue) ? currentValue : '';
        }

        function renderTemplates() {
            fields.templateSelect.innerHTML = '<option value="">?좏깮 ????/option>';
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

        function renderGearTypes() {
            const select = fields.gearType;
            const currentValue = String(select.value || '').trim();
            select.innerHTML = '<option value="">蹂댄샇援?醫낅쪟 ?좏깮</option>';

            state.gearTypes.forEach(function (type) {
                const option = document.createElement('option');
                option.value = type.name || '';
                option.textContent = type.name || '';
                option.dataset.id = type.id || '';
                option.dataset.inUse = type.in_use ? '1' : '0';
                select.appendChild(option);
            });

            if (currentValue) {
                const hasOption = state.gearTypes.some(function (type) {
                    return String(type.name || '') === currentValue;
                });
                if (!hasOption) {
                    const option = document.createElement('option');
                    option.value = currentValue;
                    option.textContent = currentValue;
                    option.dataset.id = '';
                    option.dataset.inUse = '1';
                    select.appendChild(option);
                }
                select.value = currentValue;
            }
        }

        function renderSpecOptions() {
            const dataList = document.getElementById('spec_name_list');
            if (!dataList) {
                return;
            }

            const currentValue = String(fields.specName.value || '').trim();
            const candidates = new Set();

            state.items.forEach(function (item) {
                const specName = String(item.spec_name || '').trim();
                const modelName = String(item.model_name || '').trim();
                if (specName) {
                    candidates.add(specName);
                }
                if (modelName) {
                    candidates.add(modelName);
                }
            });

            state.templates.forEach(function (template) {
                const specName = String(template.spec_name || '').trim();
                const modelName = String(template.model_name || '').trim();
                if (specName) {
                    candidates.add(specName);
                }
                if (modelName) {
                    candidates.add(modelName);
                }
            });

            const sorted = Array.from(candidates).sort(function (a, b) {
                return a.localeCompare(b, 'ko');
            });

            dataList.innerHTML = '';
            sorted.forEach(function (value) {
                const option = document.createElement('option');
                option.value = value;
                dataList.appendChild(option);
            });

            if (currentValue) {
                fields.specName.value = currentValue;
            }
        }

        function renderHistory(history) {
            fields.historyList.innerHTML = '';
            const items = Array.isArray(history) ? history : [];
            if (!items.length) {
                fields.historyList.innerHTML = '<div class="history-item"><div class="history-meta">?대젰???놁뒿?덈떎.</div><div>??????대젰??異붽??????덉뒿?덈떎.</div></div>';
                return;
            }

            items.forEach(function (entry) {
                const element = document.createElement('div');
                element.className = 'history-item';
                element.innerHTML =
                    '<div class="history-meta">' + escapeHtml(entry.timestamp || '') + ' / ' + escapeHtml(entry.type || '') + '</div>' +
                    '<div>' + escapeHtml(entry.note || '') + '</div>' +
                    '<div class="row" style="margin-top:8px;"><button type="button" class="secondary history-edit-button">?섏젙</button></div>';
                const editButton = element.querySelector('.history-edit-button');
                if (editButton) {
                    editButton.addEventListener('click', function () {
                        startHistoryEdit(entry);
                    });
                }
                fields.historyList.appendChild(element);
            });
        }

        function resetHistoryForm() {
            state.currentHistoryId = 0;
            fields.historyType.value = '?낃퀬';
            fields.historyAt.value = getTodayDate();
            fields.historyNote.value = '';
            fields.addHistoryButton.textContent = '?대젰 異붽?';
            fields.cancelHistoryEditButton.hidden = true;
        }

        function startHistoryEdit(entry) {
            if (!entry || !entry.history_id) {
                return;
            }

            state.currentHistoryId = Number(entry.history_id) || 0;
            fields.historyType.value = entry.type || '?낃퀬';
            fields.historyAt.value = formatDateOnly(entry.timestamp || '') || getTodayDate();
            fields.historyNote.value = entry.note || '';
            fields.addHistoryButton.textContent = '?대젰 ?섏젙 ???;
            fields.cancelHistoryEditButton.hidden = false;
            fields.historyNote.focus();
        }

        function clearForm() {
            state.currentItemId = '';
            fields.identifierType.value = 'barcode';
            fields.identifierValue.value = '';
            fields.gearType.value = '';
            fields.itemName.value = '';
            fields.specName.value = '';
            fields.modelName.value = '';
            fields.kcsCertNo.value = '';
            fields.manufacturerName.value = '';
            fields.purchaseVendor.value = '';
            fields.purchasePrice.value = '';
            fields.purchasedAt.value = '';
            fields.status.value = '?ъ슜 媛??;
            fields.assignedEmployeeId.value = '';
            fields.assignedEmployeeName.value = '';
            fields.assignedTeam.value = '';
            if (fields.assignedTeamSelect) {
                fields.assignedTeamSelect.value = '';
            }
            fields.assignedAt.value = '';
            fields.notes.value = '';
            resetHistoryForm();
            fields.currentItemBadge.textContent = '?좉퇋 ?깅줉 紐⑤뱶';
            renderHistory([]);
            updateQrPreview();
        }

        function clearForContinuousRegistration() {
            state.currentItemId = '';
            fields.identifierValue.value = '';
            fields.assignedEmployeeId.value = '';
            fields.assignedEmployeeName.value = '';
            fields.assignedTeam.value = '';
            if (fields.assignedTeamSelect) {
                fields.assignedTeamSelect.value = '';
            }
            fields.assignedAt.value = '';
            resetHistoryForm();
            fields.currentItemBadge.textContent = '?곗냽 ?깅줉 ?湲?;
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
            fields.itemName.value = entry.item_name || '';
            fields.specName.value = entry.spec_name || '';
            fields.modelName.value = entry.model_name || '';
            fields.kcsCertNo.value = entry.kcs_cert_no || '';
            fields.manufacturerName.value = entry.manufacturer_name || '';
            fields.purchaseVendor.value = entry.purchase_vendor || '';
            fields.purchasePrice.value = formatNumberWithComma(entry.purchase_price || '');
            fields.purchasedAt.value = entry.purchased_at || '';
            fields.status.value = entry.status || '?ъ슜 媛??;
            fields.assignedEmployeeId.value = entry.assigned_employee_id || '';
            fields.assignedEmployeeName.value = entry.assigned_employee_name || '';
            fields.assignedTeam.value = entry.assigned_team || '';
            if (fields.assignedTeamSelect) {
                fields.assignedTeamSelect.value = entry.assigned_team || '';
            }
            fields.assignedAt.value = formatDateOnly(entry.assigned_at || '');
            fields.notes.value = entry.notes || '';
            resetHistoryForm();
            fields.currentItemBadge.textContent = state.currentItemId ? '?깅줉 ??ぉ ?섏젙 紐⑤뱶' : '?좉퇋 ?깅줉 紐⑤뱶';
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

        function renderRecentList() {
            const visibleItems = state.searchQuery ? state.items.slice() : state.items.filter(function (item) {
                return String(item.status || '').trim() !== '吏湲됰맖';
            });
            fields.recentList.innerHTML = '';
            fields.recentCount.textContent = '珥?' + visibleItems.length + '嫄?;
            fields.recentCount.textContent = '珥?' + state.items.length + '嫄?;

            fields.recentCount.textContent = '珥?' + visibleItems.length + '嫄?;
            if (!visibleItems.length) {
                fields.recentList.innerHTML = '<div class="recent-item"><div class="recent-title">?깅줉??蹂댄샇援ш? ?놁뒿?덈떎.</div><div class="recent-meta">泥???ぉ???깅줉??二쇱꽭??</div></div>';
                return;
            }

            visibleItems.forEach(function (item) {
                const node = document.createElement('div');
                node.className = 'recent-item';
                node.innerHTML =
                    '<div class="recent-title">' + escapeHtml(item.gear_type || '誘몃텇瑜?) + '</div>' +
                    '<div class="recent-meta">' +
                    '?앸퀎媛? ' + escapeHtml(item.identifier_value || '') + '<br>' +
                    '援щℓ泥? ' + escapeHtml(item.purchase_vendor || '-') + '<br>' +
                    '吏湲됱옄: ' + escapeHtml(item.assigned_employee_name || '-') + '<br>' +
                    '?곹깭: ' + escapeHtml(item.status || '-') +
                    '</div>';
                node.querySelector('.recent-meta').insertAdjacentHTML('afterbegin', '援щℓ?? ' + escapeHtml(item.purchased_at || '-') + '<br>');
                node.addEventListener('click', function () {
                    fillForm(item);
                    setStatus('??ぉ??遺덈윭?붿뒿?덈떎.', false);
                });
                fields.recentList.appendChild(node);
            });
        }

        async function loadEmployees() {
            const payload = await apiRequest({ action: 'employees' });
            state.employees = Array.isArray(payload.employees) ? payload.employees : [];
            renderEmployees();
            ensureAssignedTeamSelectPlacement();
            renderAssignedTeams();
        }

        async function loadTemplates() {
            const payload = await apiRequest({ action: 'templates' });
            state.templates = Array.isArray(payload.templates) ? payload.templates : [];
            renderTemplates();
            renderSpecOptions();
        }

        async function loadGearTypes() {
            const payload = await apiRequest({ action: 'gear_types' });
            state.gearTypes = Array.isArray(payload.gear_types) ? payload.gear_types : [];
            renderGearTypes();
        }

        async function loadItems() {
            const payload = await apiRequest({ action: 'list', q: state.searchQuery });
            state.items = Array.isArray(payload.items) ? payload.items : [];
            renderRecentList();
            renderSpecOptions();
        }

        async function loadItemById(itemId) {
            const payload = await apiRequest({ action: 'get', id: itemId });
            fillForm(payload.item || null);
            setStatus('??ぉ??遺덈윭?붿뒿?덈떎.', false);
        }

        function buildSaveParams() {
            return {
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
                assigned_employee_id: fields.assignedEmployeeId.value,
                assigned_employee_name: fields.assignedEmployeeName.value.trim(),
                assigned_team: fields.assignedTeam.value.trim(),
                assigned_at: fields.assignedAt.value || '',
                notes: fields.notes.value.trim()
            };
        }

        async function saveCurrentItem() {
            const payload = await apiRequest(buildSaveParams(), 'POST');
            state.items = Array.isArray(payload.items) ? payload.items : [];
            renderRecentList();
            if (fields.continuousMode.checked) {
                clearForContinuousRegistration();
                setStatus('??λ릺?덉뒿?덈떎. ?ㅼ쓬 ?앸퀎媛믪쓣 諛붾줈 ?ㅼ틪?섍굅???낅젰??二쇱꽭??', false);
                return;
            }
            fillForm(payload.item || null);
            setStatus(payload.message || '??λ릺?덉뒿?덈떎.', false);
        }

        async function runInitialIssue() {
            if (!state.currentItemId) {
                setStatus('珥덇린 吏湲?泥섎━????ぉ??癒쇱? 遺덈윭? 二쇱꽭??', true);
                return;
            }

            const payload = await apiRequest({
                action: 'initial_issue',
                id: state.currentItemId,
                assigned_employee_id: fields.assignedEmployeeId.value,
                assigned_employee_name: fields.assignedEmployeeName.value.trim(),
                assigned_team: fields.assignedTeam.value.trim(),
                assigned_at: fields.assignedAt.value || '',
                history_note: '?쒖뒪???꾩엯 ??吏湲??꾨즺???덈ぉ??珥덇린 ?깅줉'
            }, 'POST');

            state.items = Array.isArray(payload.items) ? payload.items : [];
            fillForm(payload.item || null);
            renderRecentList();
            setStatus(payload.message || '珥덇린 吏湲??깅줉???꾨즺?섏뿀?듬땲??', false);
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
                assigned_at: fields.assignedAt.value || ''
            }, 'POST');

            state.items = Array.isArray(payload.items) ? payload.items : [];
            renderRecentList();
            fields.bulkIdentifiers.value = '';
            setStatus(payload.message || '湲곗〈 吏湲됲뭹 ?쇨큵 ?깅줉???꾨즺?섏뿀?듬땲??', false);
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
            fields.bulkQuantity.value = '1';
            fields.identifierType.value = 'internal';
            fields.identifierValue.value = Array.isArray(payload.created_identifiers) && payload.created_identifiers.length
                ? payload.created_identifiers[0]
                : '';
            updateQrPreview();
            setStatus(payload.message || '?낃퀬 ?섎웾 ?쇨큵 ?깅줉???꾨즺?섏뿀?듬땲??', false);
        }

        async function findByIdentifier() {
            const identifier = fields.identifierValue.value.trim();
            if (!identifier) {
                setStatus('癒쇱? ?앸퀎媛믪쓣 ?낅젰??二쇱꽭??', true);
                return;
            }

            const payload = await apiRequest({ action: 'find', identifier: identifier });
            if (!payload.found) {
                state.currentItemId = '';
                fields.currentItemBadge.textContent = '?좉퇋 ?깅줉 紐⑤뱶';
                renderHistory([]);
                updateQrPreview();
                setStatus('?깅줉?섏? ?딆? ?앸퀎媛믪엯?덈떎. ?좉퇋 ?뺣낫濡???ν븷 ???덉뒿?덈떎.', false);
                return;
            }

            fillForm(payload.item || null);
            setStatus('湲곗〈 ?깅줉 ??ぉ??李얠븯?듬땲??', false);
        }

        async function addHistory() {
            if (!state.currentItemId) {
                setStatus('癒쇱? 蹂댄샇援щ? ??ν빐 二쇱꽭??', true);
                return;
            }

            const isEditingHistory = state.currentHistoryId > 0;
            const payload = await apiRequest({
                action: isEditingHistory ? 'update_history' : 'add_history',
                id: state.currentItemId,
                history_id: isEditingHistory ? state.currentHistoryId : '',
                history_type: fields.historyType.value,
                history_at: fields.historyAt.value || '',
                history_note: fields.historyNote.value.trim(),
                status: fields.status.value
            }, 'POST');

            state.items = Array.isArray(payload.items) ? payload.items : [];
            fillForm(payload.item || null);
            renderRecentList();
            resetHistoryForm();
            setStatus(payload.message || (isEditingHistory ? '?대젰???섏젙?섏뿀?듬땲??' : '?대젰??異붽??섏뿀?듬땲??'), false);
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
            setStatus(payload.message || '??젣?섏뿀?듬땲??', false);
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
                status: fields.status.value,
                notes: fields.notes.value.trim()
            }, 'POST');

            state.templates = Array.isArray(payload.templates) ? payload.templates : [];
            const savedTemplate = state.templates.find(function (template) {
                return template.template_name === fields.templateName.value.trim();
            }) || null;
            state.currentTemplateId = savedTemplate ? savedTemplate.id : '';
            renderTemplates();
            setStatus(payload.message || '?쒗뵆由우씠 ??λ릺?덉뒿?덈떎.', false);
        }

        async function saveGearType() {
            const typeName = String(fields.gearTypeNewName.value || '').trim();
            if (!typeName) {
                setStatus('??蹂댄샇援?醫낅쪟紐낆쓣 ?낅젰??二쇱꽭??', true);
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
            setStatus(payload.message || '蹂댄샇援?醫낅쪟媛 ??λ릺?덉뒿?덈떎.', false);
        }

        async function deleteGearType() {
            const selected = fields.gearType.options[fields.gearType.selectedIndex];
            const typeName = String(fields.gearType.value || '').trim();
            const typeId = selected ? String(selected.dataset.id || '') : '';

            if (!typeName) {
                setStatus('??젣??蹂댄샇援?醫낅쪟瑜?癒쇱? ?좏깮??二쇱꽭??', true);
                return;
            }

            if (!window.confirm('?좏깮??蹂댄샇援?醫낅쪟瑜?紐⑸줉?먯꽌 ??젣?좉퉴??')) {
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
            setStatus(payload.message || '蹂댄샇援?醫낅쪟媛 ??젣?섏뿀?듬땲??', false);
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
            setStatus(payload.message || '?쒗뵆由우씠 ??젣?섏뿀?듬땲??', false);
        }

        async function createInternalKey() {
            const payload = await apiRequest({
                action: 'create_internal_key',
                purchased_at: fields.purchasedAt.value
            });
            fields.identifierType.value = payload.identifier_type || 'internal';
            fields.identifierValue.value = payload.identifier_value || '';
            updateQrPreview();
            setStatus('?대? 愿由ы궎瑜??앹꽦?덉뒿?덈떎.', false);
        }

        async function applySearch() {
            state.searchQuery = String(fields.searchInput.value || '').trim();
            await loadItems();
            setStatus(state.searchQuery ? '寃??寃곌낵瑜?遺덈윭?붿뒿?덈떎.' : '?꾩껜 紐⑸줉??遺덈윭?붿뒿?덈떎.', false);
        }

        function resetSearch() {
            fields.searchInput.value = '';
            state.searchQuery = '';
            loadItems().then(function () {
                setStatus('寃?됱쓣 珥덇린?뷀뻽?듬땲??', false);
            }).catch(function (error) {
                setStatus(error.message || '紐⑸줉???ㅼ떆 遺덈윭?ㅼ? 紐삵뻽?듬땲??', true);
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
                setStatus('??釉뚮씪?곗???BarcodeDetector瑜?吏?먰븯吏 ?딆뒿?덈떎. 吏곸젒 ?낅젰?쇰줈 ?깅줉??二쇱꽭??', true);
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
                        setStatus('肄붾뱶瑜??몄떇?덉뒿?덈떎. 湲곗〈 ?깅줉 ?щ?瑜?議고쉶?⑸땲??', false);
                        await findByIdentifier();
                    } catch (error) {
                        setStatus('移대찓???ㅼ틪 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
                    }
                }, 700);

                setStatus('移대찓?쇨? ?쒖옉?섏뿀?듬땲?? 肄붾뱶瑜??붾㈃ 以묒븰??留욎떠 二쇱꽭??', false);
            } catch (error) {
                setStatus('移대찓??沅뚰븳???녾굅???쒖옉???ㅽ뙣?덉뒿?덈떎.', true);
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
            const cursorPos = this.selectionStart;
            const oldValue = this.value;
            const digitsOnly = this.value.replace(/[^\d]/g, '');
            this.value = digitsOnly ? formatNumberWithComma(digitsOnly) : '';
            
            const newLength = this.value.length;
            const oldLength = oldValue.length;
            const diff = newLength - oldLength;
            
            if (oldValue.length > 0) {
                const newPos = Math.max(0, cursorPos + diff);
                this.setSelectionRange(newPos, newPos);
            }
        });

        function clearAssigneeSelection() {
            fields.assignedEmployeeId.value = '';
            fields.assignedEmployeeName.value = '';
            fields.assignedTeam.value = '';
            fields.assignedAt.value = '';
            if (fields.assignedTeamSelect) {
                fields.assignedTeamSelect.value = '';
            }
        }

        fields.assignedEmployeeId.addEventListener('change', function () {
            const selected = this.options[this.selectedIndex];
            if (!selected || !this.value) {
                clearAssigneeSelection();
                return;
            }
            fields.assignedEmployeeName.value = selected.dataset.name || '';
            fields.assignedTeam.value = selected.dataset.team || '';
            if (fields.assignedTeamSelect) {
                fields.assignedTeamSelect.value = fields.assignedTeam.value || '';
            }
            if (!fields.assignedAt.value) {
                fields.assignedAt.value = new Date().toISOString().slice(0, 10);
            }
        });

        function applyManualAssigneeState() {
            const hasManualAssigneeName = String(fields.assignedEmployeeName.value || '').trim() !== '';
            if (!hasManualAssigneeName) {
                clearAssigneeSelection();
                return;
            }

            const hasManualAssignee = hasManualAssigneeName
                || String(fields.assignedTeam.value || '').trim() !== '';
            if (!hasManualAssignee) {
                return;
            }
            fields.assignedEmployeeId.value = '';
            if (!fields.assignedAt.value) {
                fields.assignedAt.value = new Date().toISOString().slice(0, 10);
            }
        }

        fields.assignedEmployeeName.addEventListener('input', applyManualAssigneeState);
        fields.assignedTeam.addEventListener('input', applyManualAssigneeState);
        fields.clearAssigneeButton.addEventListener('click', function () {
            clearAssigneeSelection();
            setStatus('吏湲됱옄 ?뺣낫媛 鍮꾩썙議뚯뒿?덈떎. ??ν븯硫?臾쇳뭹? ?좎??섍퀬 吏湲됱옄留???젣?⑸땲??', false);
        });
        fields.historyType.addEventListener('change', function () {
            const historyStatusMap = {
                '??': '?? ??',
                '??': '???',
                '??': '??',
                '??': '?? ??',
                '??': '?? ?',
                '??': '??'
            };
            if (historyStatusMap[this.value]) {
                fields.status.value = historyStatusMap[this.value];
            }
        });
                if (fields.assignedTeamSelect) {
            fields.assignedTeamSelect.addEventListener('change', function () {
                if (!this.value) {
                    return;
                }
                fields.assignedTeam.value = this.value;
                applyManualAssigneeState();
            });
        }

        document.getElementById('saveButton').addEventListener('click', async function () {
            try {
                await saveCurrentItem();
            } catch (error) {
                setStatus(error.message || '???以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            }
        });

        document.getElementById('newButton').addEventListener('click', function () {
            clearForm();
            fields.templateName.value = '';
            state.currentTemplateId = '';
            renderTemplates();
            setStatus('?좉퇋 ?낅젰 紐⑤뱶濡??꾪솚?덉뒿?덈떎.', false);
        });

        document.getElementById('findButton').addEventListener('click', async function () {
            try {
                await findByIdentifier();
            } catch (error) {
                setStatus(error.message || '議고쉶 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            }
        });

        document.getElementById('initialIssueButton').addEventListener('click', async function () {
            try {
                await runInitialIssue();
            } catch (error) {
                setStatus(error.message || '珥덇린 吏湲??깅줉 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            }
        });

        document.getElementById('deleteButton').addEventListener('click', async function () {
            try {
                await deleteCurrentItem();
            } catch (error) {
                setStatus(error.message || '??젣 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            }
        });

        fields.addHistoryButton.addEventListener('click', async function () {
            try {
                await addHistory();
            } catch (error) {
                setStatus(error.message || '?대젰 異붽? 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            }
        });

        fields.cancelHistoryEditButton.addEventListener('click', function () {
            resetHistoryForm();
            setStatus('?대젰 ?섏젙??痍⑥냼?섏뿀?듬땲??', false);
        });

        document.getElementById('generateInternalKeyButton').addEventListener('click', async function () {
            try {
                await createInternalKey();
            } catch (error) {
                setStatus(error.message || '?대? ???앹꽦 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            }
        });

        document.getElementById('searchButton').addEventListener('click', async function () {
            try {
                await applySearch();
            } catch (error) {
                setStatus(error.message || '寃??以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            }
        });

        document.getElementById('searchResetButton').addEventListener('click', resetSearch);
        document.getElementById('exportButton').addEventListener('click', downloadExport);
        document.getElementById('bulkInitialIssueButton').addEventListener('click', async function () {
            try {
                await runBulkInitialIssue();
            } catch (error) {
                setStatus(error.message || '湲곗〈 吏湲됲뭹 ?쇨큵 ?깅줉 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            }
        });
        document.getElementById('bulkReceiveButton').addEventListener('click', async function () {
            try {
                await runBulkStockReceive();
            } catch (error) {
                setStatus(error.message || '?낃퀬 ?섎웾 ?쇨큵 ?깅줉 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            }
        });
        document.getElementById('bulkClearButton').addEventListener('click', function () {
            fields.bulkIdentifiers.value = '';
        });
        document.getElementById('saveTemplateButton').addEventListener('click', async function () {
            try {
                await saveTemplate();
            } catch (error) {
                setStatus(error.message || '?쒗뵆由????以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            }
        });
        fields.addGearTypeButton.addEventListener('click', async function () {
            try {
                await saveGearType();
            } catch (error) {
                setStatus(error.message || '蹂댄샇援?醫낅쪟 ??μ쓣 泥섎━?섏? 紐삵뻽?듬땲??', true);
            }
        });
        fields.deleteGearTypeButton.addEventListener('click', async function () {
            try {
                await deleteGearType();
            } catch (error) {
                setStatus(error.message || '蹂댄샇援?醫낅쪟 ??젣瑜?泥섎━?섏? 紐삵뻽?듬땲??', true);
            }
        });
        document.getElementById('deleteTemplateButton').addEventListener('click', async function () {
            try {
                await deleteTemplate();
            } catch (error) {
                setStatus(error.message || '?쒗뵆由???젣 以??ㅻ쪟媛 諛쒖깮?덉뒿?덈떎.', true);
            }
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
                return template.id === state.currentTemplateId;
            }) || null;
            applyTemplate(selected);
            if (selected) {
                setStatus('?쒗뵆由??댁슜??遺덈윭?붿뒿?덈떎. ?앸퀎媛믩쭔 ?낅젰?섎㈃ 諛붾줈 ?깅줉?????덉뒿?덈떎.', false);
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
                    return;
                }
                setStatus('以鍮꾨릺?덉뒿?덈떎. ?ㅼ틪?섍굅???앸퀎媛믪쓣 ?낅젰??二쇱꽭??', false);
            } catch (error) {
                setStatus(error.message || '珥덇린 ?곗씠?곕? 遺덈윭?ㅼ? 紐삵뻽?듬땲??', true);
            }
        })();
    </script>
</body>
</html>
