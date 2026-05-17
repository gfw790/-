<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/common.php';

const RECEIPT_COMPANY_NAME = '(주)현대기전';
const RECEIPT_SITE_NAME = '옥계면 한라시멘트';
const RECEIPT_PLEDGE_TEXT = '지급받은 보호구를 작업 중 항상 착용하겠으며, 미착용으로 인한 불이익은 본인에게 있음을 확인합니다.';
const RECEIPT_PLEDGE_TEXT_RU = 'Я подтверждаю, что получил(а) указанные средства индивидуальной защиты, обязуюсь постоянно использовать их во время работы и понимаю, что ответственность за последствия неиспользования возлагается на меня.';
const RECEIPT_PLEDGE_TEXT_UZ = 'Men ko‘rsatilgan shaxsiy himoya vositalarini olganimni tasdiqlayman, ish vaqtida ularni doimo taqib yurishga majbur ekanligimni va taqmaslik oqibatlari uchun javobgarlik o‘zimda bo‘lishini tushunaman.';
const RECEIPT_PLEDGE_TEXT_KY = 'Мен көрсөтүлгөн жеке коргонуу каражаттарын алганымды ырастайм, иш учурунда аларды дайыма тагынууга милдеттүү экенимди жана тагынбагандыктын кесепеттери үчүн жоопкерчилик өзүмө жүктөлөрүн түшүнөм.';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function receipt_value($value, string $fallback = '-'): string
{
    $text = sg_normalize_text((string)$value);
    return $text !== '' ? $text : $fallback;
}

function receipt_document_no(array $group, int $index, string $prefix = 'SGR'): string
{
    $datePart = date('Ymd');
    $employeeId = sg_normalize_text($group['employee_id'] ?? '');
    if ($employeeId !== '') {
        return $prefix . '-' . $datePart . '-' . str_pad($employeeId, 4, '0', STR_PAD_LEFT);
    }

    return $prefix . '-' . $datePart . '-' . str_pad((string)$index, 4, '0', STR_PAD_LEFT);
}

function receipt_signature_date(array $items): string
{
    $dates = [];
    foreach ($items as $item) {
        $assignedAt = sg_normalize_text($item['assigned_at'] ?? '');
        if ($assignedAt !== '') {
            $dates[] = substr($assignedAt, 0, 10);
        }
    }

    return !empty($dates) ? (string)max($dates) : date('Y-m-d');
}

function receipt_aggregate_items(array $items): array
{
    $rows = [];
    foreach ($items as $item) {
        $gearType = sg_normalize_text($item['gear_type'] ?? '');
        $itemName = sg_normalize_text($item['item_name'] ?? '');
        $specName = sg_normalize_text($item['spec_name'] ?? '');
        $modelName = sg_normalize_text($item['model_name'] ?? '');
        $manufacturerName = sg_normalize_text($item['manufacturer_name'] ?? '');
        $kcsCertNo = sg_normalize_text($item['kcs_cert_no'] ?? '');
        $detailText = sg_normalize_text($item['detail_text'] ?? sg_build_receipt_detail_text($item));
        $assignedDate = substr(sg_normalize_text($item['assigned_at'] ?? ''), 0, 10);
        $key = implode('|', [$gearType, $itemName, $specName, $modelName, $manufacturerName, $kcsCertNo, $detailText, $assignedDate]);

        if (!isset($rows[$key])) {
            $rows[$key] = [
                'gear_label' => trim($gearType !== '' ? $gearType : $itemName),
                'item_name' => $itemName,
                'spec_name' => $specName,
                'model_name' => $modelName,
                'manufacturer_name' => $manufacturerName,
                'kcs_cert_no' => $kcsCertNo,
                'detail_text' => $detailText,
                'quantity' => 0,
                'assigned_date' => $assignedDate !== '' ? $assignedDate : date('Y-m-d'),
            ];
        }

        $rows[$key]['quantity']++;
    }

    return array_values($rows);
}

function receipt_parse_manual_names(string $raw): array
{
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $names = [];
    foreach ($lines as $line) {
        $name = sg_normalize_text($line);
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return array_values(array_unique($names));
}

function receipt_parse_manual_items(string $raw, string $defaultDate): array
{
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $items = [];
    foreach ($lines as $line) {
        $line = sg_normalize_text($line);
        if ($line === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $line));
        $gearLabel = (string)($parts[0] ?? '');
        $itemName = (string)($parts[1] ?? '');
        $modelName = (string)($parts[2] ?? '');
        $quantity = max(1, (int)($parts[3] ?? 1));
        $assignedDate = sg_normalize_text($parts[4] ?? '');

        $items[] = [
            'gear_label' => $gearLabel !== '' ? $gearLabel : ($itemName !== '' ? $itemName : '보호구'),
            'item_name' => $itemName,
            'spec_name' => '',
            'model_name' => $modelName,
            'manufacturer_name' => '',
            'kcs_cert_no' => '',
            'detail_text' => trim($itemName . ($modelName !== '' ? ' / ' . $modelName : '')),
            'quantity' => $quantity,
            'assigned_date' => $assignedDate !== '' ? $assignedDate : $defaultDate,
        ];
    }

    return $items;
}

function receipt_manual_locale(string $value): string
{
    $value = sg_normalize_text($value);
    return in_array($value, ['ru', 'uz', 'ky'], true) ? $value : 'ko';
}

function receipt_locale_note(string $locale): string
{
    if ($locale === 'ru') {
        return '러시아어 병기를 선택하면 확인서 제목, 주요 항목, 서약 문구, 서명란이 한국어와 러시아어로 함께 출력됩니다.';
    }
    if ($locale === 'uz') {
        return '우즈베크어 병기를 선택하면 확인서 제목, 주요 항목, 서약 문구, 서명란이 한국어와 우즈베크어로 함께 출력됩니다.';
    }
    if ($locale === 'ky') {
        return '키르기스어 병기를 선택하면 확인서 제목, 주요 항목, 서약 문구, 서명란이 한국어와 키르기스어로 함께 출력됩니다.';
    }
    return '';
}

function receipt_translation_set(string $locale): array
{
    $ru = [
        'company' => 'Компания',
        'site' => 'Объект',
        'document_no' => 'Номер документа',
        'written_at' => 'Дата оформления',
        'title' => 'Подтверждение выдачи СИЗ',
        'subtitle' => 'Для иностранного ежедневного работника',
        'team' => 'Подразделение',
        'position' => 'Должность',
        'name' => 'Имя',
        'issued_date' => 'Дата выдачи',
        'items_title' => 'Выданные СИЗ',
        'gear_name' => 'Наименование СИЗ',
        'model' => 'Характеристика / модель',
        'quantity' => 'Количество',
        'pledge_title' => 'Обязательство работника',
        'sign_title' => 'Собственноручная подпись получателя',
        'sign' => 'Подпись',
        'sign_date' => 'Дата подписи',
        'manager_title' => 'Подтверждение ответственного за безопасность',
        'manager_method' => 'Способ проверки: проверен оригинал собственноручной подписи',
        'manager_name' => 'Проверил',
        'manager_date' => 'Дата проверки',
        'note' => 'Примечание: данный документ используется для подтверждения выдачи СИЗ и обязательства работника.',
    ];

    $uz = [
        'company' => 'Kompaniya',
        'site' => 'Obyekt',
        'document_no' => 'Hujjat raqami',
        'written_at' => 'Rasmiylashtirilgan sana',
        'title' => 'ShHV topshirilganligini tasdiqlash',
        'subtitle' => 'Chet ellik kunlik ishchi uchun',
        'team' => 'Bo‘lim',
        'position' => 'Lavozim',
        'name' => 'F.I.Sh.',
        'issued_date' => 'Berilgan sana',
        'items_title' => 'Berilgan ShHV ro‘yxati',
        'gear_name' => 'ShHV nomi',
        'model' => 'Turi / modeli',
        'quantity' => 'Soni',
        'pledge_title' => 'Ishchining majburiyati',
        'sign_title' => 'Qabul qiluvchining imzosi',
        'sign' => 'Imzo',
        'sign_date' => 'Imzo sanasi',
        'manager_title' => 'Xavfsizlik mas’ulining tasdig‘i',
        'manager_method' => 'Tekshiruv usuli: asl qo‘l imzosi tekshirildi',
        'manager_name' => 'Tekshirgan',
        'manager_date' => 'Tekshiruv sanasi',
        'note' => 'Izoh: ushbu hujjat ShHV topshirilganini va ishchining majburiyatini hujjatlashtirish uchun ishlatiladi.',
    ];

    $ky = [
        'company' => 'Компания',
        'site' => 'Объект',
        'document_no' => 'Документ номери',
        'written_at' => 'Түзүлгөн күнү',
        'title' => 'ЖКК берилгенин тастыктоо',
        'subtitle' => 'Чет өлкөлүк күндүк жумушчу үчүн',
        'team' => 'Бөлүм',
        'position' => 'Кызматы',
        'name' => 'Аты-жөнү',
        'issued_date' => 'Берилген күнү',
        'items_title' => 'Берилген ЖКК тизмеси',
        'gear_name' => 'ЖКК аталышы',
        'model' => 'Түрү / модели',
        'quantity' => 'Саны',
        'pledge_title' => 'Жумушчунун милдеттенмеси',
        'sign_title' => 'Алуучунун кол тамгасы',
        'sign' => 'Кол тамга',
        'sign_date' => 'Кол коюлган күн',
        'manager_title' => 'Коопсуздук жооптуусунун ырастоосу',
        'manager_method' => 'Текшерүү ыкмасы: кол тамганын түп нускасы текшерилди',
        'manager_name' => 'Текшерген',
        'manager_date' => 'Текшерилген күн',
        'note' => 'Эскертүү: бул документ ЖКК берилгенин жана жумушчунун милдеттенмесин документтештирүү үчүн колдонулат.',
    ];

    if ($locale === 'ru') {
        return $ru;
    }
    if ($locale === 'uz') {
        return $uz;
    }
    if ($locale === 'ky') {
        return $ky;
    }

    return [];
}

function receipt_pledge_text_by_locale(string $locale): string
{
    if ($locale === 'ru') {
        return RECEIPT_PLEDGE_TEXT . "\n\n" . RECEIPT_PLEDGE_TEXT_RU;
    }
    if ($locale === 'uz') {
        return RECEIPT_PLEDGE_TEXT . "\n\n" . RECEIPT_PLEDGE_TEXT_UZ;
    }
    if ($locale === 'ky') {
        return RECEIPT_PLEDGE_TEXT . "\n\n" . RECEIPT_PLEDGE_TEXT_KY;
    }

    return RECEIPT_PLEDGE_TEXT;
}

function receipt_label_html(string $ko, string $translated, string $locale = 'ko'): string
{
    if ($locale === 'ko' || $translated === '') {
        return h($ko);
    }

    return '<span class="ko-small">' . h($ko) . '</span> <span class="ru-main">' . h($translated) . '</span>';
}

function receipt_pledge_html(string $ko, string $translated, string $locale = 'ko'): string
{
    if ($locale === 'ko' || $translated === '') {
        return nl2br(h($ko));
    }

    return '<div class="ko-small block-gap">' . nl2br(h($ko)) . '</div><div class="ru-main">' . nl2br(h($translated)) . '</div>';
}

function receipt_gear_translation_map(string $locale): array
{
    $maps = [
        'ru' => [
            '보호구' => 'СИЗ',
            '안전모' => 'Защитная каска',
            '안전화' => 'Защитная обувь',
            '안전대' => 'Страховочная привязь',
            '안전벨트' => 'Страховочный пояс',
            '안전조끼' => 'Сигнальный жилет',
            '신호수조끼' => 'Жилет сигнальщика',
            '경광봉' => 'Сигнальный жезл',
            '보안경' => 'Защитные очки',
            '방진마스크' => 'Противопылевая маска',
            '방독마스크' => 'Противогазовая маска',
            '귀마개' => 'Беруши',
            '귀덮개' => 'Защитные наушники',
            '장갑' => 'Защитные перчатки',
            '절연장갑' => 'Диэлектрические перчатки',
            '각반' => 'Защитные гамаши',
            '안면보호구' => 'Защитный щиток',
            '추락방지대' => 'Система защиты от падения',
            '용접면' => 'Сварочный щиток',
            '우의' => 'Защитный дождевик',
        ],
        'uz' => [
            '보호구' => 'ShHV',
            '안전모' => 'Himoya kaskasi',
            '안전화' => 'Himoya oyoq kiyimi',
            '안전대' => 'Sug‘urta belbog‘i',
            '안전벨트' => 'Xavfsizlik kamari',
            '안전조끼' => 'Signal jileti',
            '신호수조끼' => 'Signalchi jileti',
            '경광봉' => 'Signal tayoqchasi',
            '보안경' => 'Himoya ko‘zoynagi',
            '방진마스크' => 'Changdan himoya niqobi',
            '방독마스크' => 'Gazdan himoya niqobi',
            '귀마개' => 'Quloq tiqini',
            '귀덮개' => 'Quloqchin',
            '장갑' => 'Himoya qo‘lqopi',
            '절연장갑' => 'Dielektrik qo‘lqop',
            '각반' => 'Himoya oyoq qoplamasi',
            '안면보호구' => 'Yuzni himoya qiluvchi niqob',
            '추락방지대' => 'Yiqilishdan saqlash moslamasi',
            '용접면' => 'Payvandchi niqobi',
            '우의' => 'Himoya yomg‘irpo‘shi',
        ],
        'ky' => [
            '보호구' => 'ЖКК',
            '안전모' => 'Коргоочу каска',
            '안전화' => 'Коргоочу бут кийим',
            '안전대' => 'Коопсуздук куру',
            '안전벨트' => 'Коопсуздук куру',
            '안전조끼' => 'Сигналдык жилет',
            '신호수조끼' => 'Белги берүүчүнүн жилети',
            '경광봉' => 'Сигналдык таякча',
            '보안경' => 'Коргоочу көз айнек',
            '방진마스크' => 'Чаңдан коргоочу маска',
            '방독마스크' => 'Газдан коргоочу маска',
            '귀마개' => 'Кулак тыгыч',
            '귀덮개' => 'Кулакчын',
            '장갑' => 'Коргоочу мээлей',
            '절연장갑' => 'Диэлектрик мээлей',
            '각반' => 'Коргоочу бут кап',
            '안면보호구' => 'Бетти коргоочу калкан',
            '추락방지대' => 'Кулап кетүүдөн сактоочу шайман',
            '용접면' => 'Ширетүүчү калкан',
            '우의' => 'Коргоочу жамгыр кийим',
        ],
    ];

    return $maps[$locale] ?? [];
}

function receipt_gear_label_html(string $ko, string $locale = 'ko'): string
{
    $ko = receipt_value($ko, '');
    if ($locale === 'ko' || $ko === '') {
        return h(receipt_value($ko));
    }

    $map = receipt_gear_translation_map($locale);
    $translated = $map[sg_normalize_text($ko)] ?? '';
    if ($translated === '') {
        return h($ko);
    }

    return receipt_label_html($ko, $translated, $locale);
}

function receipt_detail_lines(array $item): array
{
    $lines = [];
    $fieldMap = [
        '품명' => sg_normalize_text($item['item_name'] ?? ''),
        '규격' => sg_normalize_text($item['spec_name'] ?? ''),
        '모델명' => sg_normalize_text($item['model_name'] ?? ''),
        '제조사' => sg_normalize_text($item['manufacturer_name'] ?? ''),
        'KCS 안전인증번호' => sg_normalize_text($item['kcs_cert_no'] ?? ''),
    ];

    foreach ($fieldMap as $label => $value) {
        if ($value !== '') {
            $lines[] = ['label' => $label, 'value' => $value];
        }
    }

    if (!empty($lines)) {
        return $lines;
    }

    $detailText = sg_normalize_text($item['detail_text'] ?? '');
    if ($detailText !== '') {
        return [['label' => '세부내역', 'value' => $detailText]];
    }

    return [['label' => '세부내역', 'value' => '-']];
}

function receipt_detail_html(array $item): string
{
    $parts = [];
    foreach (receipt_detail_lines($item) as $line) {
        $parts[] = '<div class="detail-line"><span class="detail-label">' . h($line['label']) . '</span>: ' . h($line['value']) . '</div>';
    }

    return implode('', $parts);
}

function receipt_status_label(string $status): string
{
    return $status === 'confirmed' ? '확인 완료' : '발급 완료';
}

function receipt_fetch_attachment_path(PDO $pdo, int $receiptId): string
{
    $stmt = $pdo->prepare('SELECT attachment_path FROM safety_gear_receipt WHERE receipt_id = :receipt_id');
    $stmt->execute([':receipt_id' => $receiptId]);
    return sg_normalize_text((string)$stmt->fetchColumn());
}

function receipt_build_state(PDO $pdo, array $source): array
{
    $mode = sg_normalize_text($source['mode'] ?? 'employee');
    if ($mode !== 'daily') {
        $mode = 'employee';
    }

    $selectedIds = array_values(array_unique(array_filter(array_map(
        static fn($value): string => sg_normalize_text((string)$value),
        (array)($source['employee_id'] ?? [])
    ))));

    $manualTeam = sg_normalize_text($source['manual_team'] ?? '');
    $manualPosition = sg_normalize_text($source['manual_position'] ?? '');
    $manualDate = sg_normalize_text($source['manual_date'] ?? date('Y-m-d'));
    $manualLocale = receipt_manual_locale((string)($source['manual_locale'] ?? 'ko'));
    $manualPresetId = sg_normalize_text($source['manual_preset_id'] ?? '');
    $manualPresetName = sg_normalize_text($source['manual_preset_name'] ?? '');
    $manualNamesRaw = (string)($source['manual_names'] ?? '');
    $manualItemsRaw = (string)($source['manual_items'] ?? '');
    $loadPreset = sg_normalize_text($source['load_preset'] ?? '') === '1';
    $printMode = sg_normalize_text($source['print'] ?? '') === '1';

    if ($mode === 'daily' && $loadPreset && $manualPresetId !== '') {
        $preset = sg_fetch_receipt_preset_by_id($pdo, $manualPresetId);
        if (is_array($preset)) {
            $manualItemsRaw = (string)($preset['items_text'] ?? '');
            $manualLocale = receipt_manual_locale((string)($preset['locale_code'] ?? 'ko'));
            $manualPresetName = sg_normalize_text($preset['preset_name'] ?? '');
        }
    }

    $selectedGroups = !empty($selectedIds) ? sg_fetch_assigned_items_grouped_by_employee($pdo, $selectedIds) : [];
    $manualNames = receipt_parse_manual_names($manualNamesRaw);
    $manualItems = receipt_parse_manual_items($manualItemsRaw, $manualDate !== '' ? $manualDate : date('Y-m-d'));
    $manualGroups = [];

    if ($mode === 'daily' && !empty($manualNames) && !empty($manualItems)) {
        foreach ($manualNames as $index => $manualName) {
            $manualGroups[] = [
                'employee_id' => '',
                'employee_name' => $manualName,
                'employee_team' => $manualTeam,
                'employee_position' => $manualPosition,
                'manual_rows' => $manualItems,
                'manual_index' => $index + 1,
            ];
        }
    }

    return [
        'mode' => $mode,
        'print_mode' => $printMode,
        'selected_ids' => $selectedIds,
        'selected_groups' => $selectedGroups,
        'manual_team' => $manualTeam,
        'manual_position' => $manualPosition,
        'manual_date' => $manualDate,
        'manual_locale' => $manualLocale,
        'manual_preset_id' => $manualPresetId,
        'manual_preset_name' => $manualPresetName,
        'manual_names_raw' => $manualNamesRaw,
        'manual_items_raw' => $manualItemsRaw,
        'manual_groups' => $manualGroups,
        'print_groups' => $mode === 'daily' ? $manualGroups : $selectedGroups,
    ];
}

function receipt_query_from_state(array $state, array $extras = []): array
{
    $params = ['mode' => $state['mode']];

    if ($state['mode'] === 'daily') {
        $params['manual_team'] = $state['manual_team'];
        $params['manual_position'] = $state['manual_position'];
        $params['manual_date'] = $state['manual_date'];
        $params['manual_locale'] = $state['manual_locale'];
        $params['manual_preset_id'] = $state['manual_preset_id'];
        $params['manual_preset_name'] = $state['manual_preset_name'];
        $params['manual_names'] = $state['manual_names_raw'];
        $params['manual_items'] = $state['manual_items_raw'];
    } elseif (!empty($state['selected_ids'])) {
        $params['employee_id'] = $state['selected_ids'];
    }

    if (!empty($state['print_groups'])) {
        $params['print'] = '1';
    }

    return array_merge($params, $extras);
}

function receipt_hidden_inputs(array $state): string
{
    $html = [];
    $html[] = '<input type="hidden" name="mode" value="' . h($state['mode']) . '">';
    $html[] = '<input type="hidden" name="print" value="1">';

    if ($state['mode'] === 'daily') {
        $html[] = '<input type="hidden" name="manual_team" value="' . h($state['manual_team']) . '">';
        $html[] = '<input type="hidden" name="manual_position" value="' . h($state['manual_position']) . '">';
        $html[] = '<input type="hidden" name="manual_date" value="' . h($state['manual_date']) . '">';
        $html[] = '<input type="hidden" name="manual_locale" value="' . h($state['manual_locale']) . '">';
        $html[] = '<input type="hidden" name="manual_preset_id" value="' . h($state['manual_preset_id']) . '">';
        $html[] = '<input type="hidden" name="manual_preset_name" value="' . h($state['manual_preset_name']) . '">';
        $html[] = '<input type="hidden" name="manual_names" value="' . h($state['manual_names_raw']) . '">';
        $html[] = '<input type="hidden" name="manual_items" value="' . h($state['manual_items_raw']) . '">';
    } else {
        foreach ($state['selected_ids'] as $employeeId) {
            $html[] = '<input type="hidden" name="employee_id[]" value="' . h($employeeId) . '">';
        }
    }

    return implode("\n", $html);
}

function receipt_redirect(array $params): void
{
    header('Location: /safety_gear/receipt_batch_print.php?' . http_build_query($params));
    exit;
}

$user = auth_current_user();
if (!is_array($user)) {
    header('Location: /risk_assessment/task_select.php');
    exit;
}

if (!auth_can_manage($user)) {
    http_response_code(403);
    echo '안전관리자 또는 관리자만 접근할 수 있습니다.';
    exit;
}

$pdo = sg_get_pdo();
$allGroups = sg_fetch_assigned_items_grouped_by_employee($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postState = receipt_build_state($pdo, $_POST);
    $action = sg_normalize_text($_POST['action'] ?? '');

    try {
        if ($action === 'save_batch') {
            if (empty($postState['print_groups'])) {
                throw new RuntimeException('저장할 확인서가 없습니다.');
            }

            $savedCount = 0;
            foreach ($postState['print_groups'] as $index => $group) {
                $assignedItems = (array)($group['assigned_items'] ?? []);
                $documentDate = $postState['mode'] === 'daily'
                    ? ($postState['manual_date'] !== '' ? $postState['manual_date'] : date('Y-m-d'))
                    : receipt_signature_date($assignedItems);
                $rows = $postState['mode'] === 'daily'
                    ? (array)($group['manual_rows'] ?? [])
                    : receipt_aggregate_items($assignedItems);

                sg_create_receipt(
                    $pdo,
                    [
                        'document_no' => receipt_document_no($group, $index + 1, $postState['mode'] === 'daily' ? 'DGR' : 'SGR'),
                        'worker_type' => $postState['mode'] === 'daily' ? 'daily' : 'employee',
                        'employee_id' => $group['employee_id'] ?? '',
                        'worker_name' => $group['employee_name'] ?? '',
                        'worker_team' => $group['employee_team'] ?? '',
                        'worker_position' => $group['employee_position'] ?? '',
                        'company_name' => RECEIPT_COMPANY_NAME,
                        'site_name' => RECEIPT_SITE_NAME,
                        'issue_date' => $documentDate,
                        'pledge_text' => receipt_pledge_text_by_locale($postState['mode'] === 'daily' ? $postState['manual_locale'] : 'ko'),
                    ],
                    $rows,
                    $user
                );
                $savedCount++;
            }

            receipt_redirect(receipt_query_from_state($postState, ['notice' => $savedCount . '건의 확인서를 DB에 저장했습니다.']));
        }

        if ($action === 'save_manual_preset') {
            if ($postState['mode'] !== 'daily') {
                throw new RuntimeException('공통 지급 보호구 목록 preset은 일용근로자 출력에서만 저장할 수 있습니다.');
            }

            sg_save_receipt_preset(
                $pdo,
                (string)($_POST['manual_preset_name'] ?? ''),
                (string)($postState['manual_items_raw'] ?? ''),
                (string)($postState['manual_locale'] ?? 'ko'),
                $user
            );

            receipt_redirect(receipt_query_from_state($postState, ['notice' => '공통 지급 보호구 목록 preset을 저장했습니다.']));
        }

        if ($action === 'attach_receipt' || $action === 'confirm_receipt') {
            $receiptId = max(0, (int)($_POST['receipt_id'] ?? 0));
            if ($receiptId <= 0) {
                throw new RuntimeException('확인서 ID가 올바르지 않습니다.');
            }

            $hasUpload = isset($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            if ($action === 'attach_receipt' && !$hasUpload) {
                throw new RuntimeException('첨부할 서명지 파일을 선택해 주세요.');
            }
            if ($hasUpload) {
                sg_attach_receipt_file($pdo, $receiptId, $_FILES['attachment']);
            }

            if ($action === 'confirm_receipt') {
                $currentAttachment = $hasUpload ? 'uploaded' : receipt_fetch_attachment_path($pdo, $receiptId);
                if ($currentAttachment === '') {
                    throw new RuntimeException('확인 완료 처리 전 서명지 스캔본 또는 사진을 먼저 첨부해 주세요.');
                }

                sg_confirm_receipt($pdo, $receiptId, $user, (string)($_POST['confirm_note'] ?? ''));
                receipt_redirect(['notice' => '확인서를 확인 완료 처리했습니다.']);
            }

            receipt_redirect(['notice' => '첨부파일을 저장했습니다.']);
        }

        if ($action === 'delete_receipt') {
            $receiptId = max(0, (int)($_POST['receipt_id'] ?? 0));
            if ($receiptId <= 0) {
                throw new RuntimeException('삭제할 확인서 ID가 올바르지 않습니다.');
            }

            sg_delete_receipt($pdo, $receiptId);
            receipt_redirect(['notice' => '확인서를 삭제했습니다.']);
        }
    } catch (Throwable $e) {
        receipt_redirect(receipt_query_from_state($postState, ['error' => $e->getMessage()]));
    }
}

$state = receipt_build_state($pdo, $_GET);
$mode = $state['mode'];
$printMode = $state['print_mode'];
$selectedIds = $state['selected_ids'];
$manualTeam = $state['manual_team'];
$manualPosition = $state['manual_position'];
$manualDate = $state['manual_date'];
$manualLocale = $state['manual_locale'];
$manualPresetId = $state['manual_preset_id'];
$manualPresetName = $state['manual_preset_name'];
$manualNamesRaw = $state['manual_names_raw'];
$manualItemsRaw = $state['manual_items_raw'];
$printGroups = $state['print_groups'];
$savedReceipts = sg_fetch_receipts($pdo, 80);
$receiptPresets = sg_fetch_receipt_presets($pdo);
$noticeMessage = sg_normalize_text($_GET['notice'] ?? '');
$errorMessage = sg_normalize_text($_GET['error'] ?? '');
$sheetLocale = $mode === 'daily' ? $manualLocale : 'ko';
$translations = receipt_translation_set($sheetLocale);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>개인별 보호구 확인서 일괄출력</title>
    <style>
        :root {
            --bg: #edf4f8;
            --panel: #ffffff;
            --line: #d7e0ea;
            --text: #132238;
            --muted: #64748b;
            --accent: #0f766e;
            --accent-soft: #ccfbf1;
            --danger: #b91c1c;
            --danger-soft: #fee2e2;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Malgun Gothic", sans-serif;
            color: var(--text);
            background: radial-gradient(circle at top right, rgba(20, 184, 166, 0.12), transparent 22%), linear-gradient(180deg, #f8fbfd 0%, var(--bg) 100%);
        }
        .page { width: min(1280px, calc(100vw - 24px)); margin: 18px auto 28px; display: grid; gap: 18px; }
        .panel, .sheet { background: var(--panel); border: 1px solid var(--line); border-radius: 20px; box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08); padding: 18px; }
        .sheet { width: 210mm; min-height: 297mm; margin: 0 auto; padding: 12mm 14mm 12mm; border-radius: 0; }
        .sheet + .sheet { margin-top: 16px; }
        h1, h2, h3 { margin: 0; }
        .lead { margin: 8px 0 0; color: var(--muted); line-height: 1.6; font-size: 14px; }
        .flash { margin-top: 14px; padding: 12px 14px; border-radius: 14px; font-size: 14px; line-height: 1.6; }
        .flash.notice { background: var(--accent-soft); color: #134e4a; }
        .flash.error { background: var(--danger-soft); color: var(--danger); }
        .actions, .employee-grid, .mode-row, .inline-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .actions, .mode-row { margin-top: 16px; }
        .button, button { display: inline-flex; align-items: center; justify-content: center; padding: 10px 14px; border-radius: 12px; border: 0; cursor: pointer; background: var(--accent); color: #fff; text-decoration: none; font: inherit; }
        .button.secondary, button.secondary { background: #e2e8f0; color: #0f172a; }
        .employee-grid { margin-top: 16px; }
        .employee-option { width: calc(33.333% - 7px); min-width: 260px; border: 1px solid var(--line); border-radius: 16px; padding: 12px 14px; background: #f8fafc; }
        .employee-option label { display: flex; gap: 10px; align-items: flex-start; cursor: pointer; }
        .employee-name { font-weight: 700; }
        .employee-meta { color: var(--muted); font-size: 12px; line-height: 1.6; }
        .form-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-top: 16px; }
        .field { display: grid; gap: 6px; }
        .field label { font-size: 12px; font-weight: 700; color: var(--muted); }
        .field input, .field textarea, .field select { width: 100%; border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; font: inherit; background: #fff; }
        .field textarea { min-height: 140px; resize: vertical; }
        .field.span-4 { grid-column: 1 / -1; }
        .hint { color: var(--muted); font-size: 12px; line-height: 1.7; }
        .locale-note { margin-top: 10px; padding: 10px 12px; border-radius: 12px; background: #f8fafc; color: #334155; font-size: 13px; line-height: 1.7; }
        .doc-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
        .doc-title { text-align: center; margin: 14px 0 18px; font-size: 28px; font-weight: 800; letter-spacing: 0.03em; }
        .doc-subtitle { text-align: center; margin: -8px 0 14px; color: #475569; font-size: 15px; font-weight: 700; }
        .doc-meta { font-size: 13px; line-height: 1.8; }
        .ko-small { font-size: 0.78em; color: #475569; font-weight: 600; }
        .ru-main { font-size: 1em; color: #111827; }
        .block-gap { margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #222; padding: 8px 10px; font-size: 13px; line-height: 1.5; text-align: left; vertical-align: top; }
        th { background: #f8fafc; width: 120px; }
        .section-title { margin-top: 14px; font-size: 16px; font-weight: 700; }
        .pledge-box { margin-top: 8px; border: 1px solid #222; padding: 10px 12px; line-height: 1.65; font-size: 13px; white-space: pre-line; }
        .sign-area { margin-top: 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .sign-box { border: 1px solid #222; min-height: 102px; padding: 10px; }
        .sign-box h3 { font-size: 14px; margin-bottom: 10px; }
        .sign-line { margin-top: 24px; text-align: right; font-size: 13px; }
        .note { margin-top: 10px; color: #475569; font-size: 11px; line-height: 1.55; }
        .empty { padding: 24px 18px; border: 1px dashed var(--line); border-radius: 16px; color: var(--muted); text-align: center; line-height: 1.8; margin-top: 14px; }
        .receipt-list { display: grid; gap: 14px; margin-top: 16px; }
        .receipt-card { border: 1px solid var(--line); border-radius: 18px; padding: 16px; background: #f8fbfd; }
        .receipt-top { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
        .receipt-title { font-size: 16px; font-weight: 800; }
        .receipt-meta { color: var(--muted); font-size: 13px; line-height: 1.7; }
        .badge { display: inline-flex; align-items: center; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .badge.issued { background: #e0f2fe; color: #0c4a6e; }
        .badge.confirmed { background: #dcfce7; color: #166534; }
        .receipt-grid { display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 14px; margin-top: 14px; }
        .sub-panel { border: 1px solid var(--line); border-radius: 14px; padding: 14px; background: #fff; }
        .mini-table { width: 100%; margin-top: 10px; }
        .mini-table th, .mini-table td { font-size: 12px; padding: 7px 8px; }
        .detail-line + .detail-line { margin-top: 3px; }
        .detail-label { display: inline-block; min-width: 92px; font-weight: 700; color: #334155; }
        .attachment-link { display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; color: var(--accent); text-decoration: none; font-weight: 700; }
        .attachment-link:hover { text-decoration: underline; }
        @media (max-width: 980px) {
            .form-grid, .receipt-grid, .sign-area { grid-template-columns: 1fr; }
        }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .page { width: auto; margin: 0; }
            .sheet { width: auto; min-height: auto; margin: 0; box-shadow: none; border: 0; page-break-after: always; }
            .sheet:last-child { page-break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="panel no-print">
            <h1>개인별 보호구 확인서 일괄출력</h1>
            <p class="lead">직원 선택 출력, 일용근로자 직접입력 출력, 확인서 DB 저장, 서명지 스캔본 첨부, 안전관리자 확인 완료까지 한 화면에서 처리합니다.</p>

            <?php if ($noticeMessage !== ''): ?>
                <div class="flash notice"><?= h($noticeMessage) ?></div>
            <?php endif; ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="flash error"><?= h($errorMessage) ?></div>
            <?php endif; ?>

            <form method="get" id="daily-builder-form">
                <div class="actions">
                    <a class="button secondary" href="/safety_gear/index.php">관리 페이지</a>
                    <a class="button secondary" href="/risk_assessment/work_list.php">작업목록</a>
                    <button type="submit" class="button">확인서 생성</button>
                    <?php if ($printMode && !empty($printGroups)): ?>
                        <button type="button" class="button secondary" onclick="window.print()">인쇄</button>
                    <?php endif; ?>
                </div>

                <div class="mode-row">
                    <label><input type="radio" name="mode" value="employee"<?= $mode !== 'daily' ? ' checked' : '' ?>> 직원 선택 출력</label>
                    <label><input type="radio" name="mode" value="daily"<?= $mode === 'daily' ? ' checked' : '' ?>> 일용근로자 직접입력 출력</label>
                </div>

                <?php if ($mode === 'daily'): ?>
                    <div class="form-grid">
                        <div class="field">
                            <label for="manual_team">현장명 / 소속</label>
                            <input id="manual_team" type="text" name="manual_team" value="<?= h($manualTeam) ?>">
                        </div>
                        <div class="field">
                            <label for="manual_position">직급</label>
                            <input id="manual_position" type="text" name="manual_position" value="<?= h($manualPosition) ?>">
                        </div>
                        <div class="field">
                            <label for="manual_date">지급 기준일</label>
                            <input id="manual_date" type="date" name="manual_date" value="<?= h($manualDate) ?>">
                        </div>
                        <div class="field">
                            <label for="manual_locale">출력 언어</label>
                            <select id="manual_locale" name="manual_locale">
                                <option value="ko"<?= $manualLocale === 'ko' ? ' selected' : '' ?>>한국어</option>
                                <option value="ru"<?= $manualLocale === 'ru' ? ' selected' : '' ?>>한국어 + 러시아어 병기</option>
                                <option value="uz"<?= $manualLocale === 'uz' ? ' selected' : '' ?>>한국어 + 우즈베크어 병기</option>
                                <option value="ky"<?= $manualLocale === 'ky' ? ' selected' : '' ?>>한국어 + 키르기스어 병기</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="manual_preset_id">공통 목록 preset</label>
                            <select id="manual_preset_id" name="manual_preset_id">
                                <option value="">직접 입력</option>
                                <?php foreach ($receiptPresets as $preset): ?>
                                    <?php $presetId = (string)($preset['preset_id'] ?? ''); ?>
                                    <option value="<?= h($presetId) ?>"<?= $manualPresetId === $presetId ? ' selected' : '' ?>>
                                        <?= h(receipt_value($preset['preset_name'] ?? 'preset')) ?>
                                        <?php if (sg_normalize_text($preset['locale_code'] ?? 'ko') === 'ru'): ?>
                                            / RU
                                        <?php elseif (sg_normalize_text($preset['locale_code'] ?? 'ko') === 'uz'): ?>
                                            / UZ
                                        <?php elseif (sg_normalize_text($preset['locale_code'] ?? 'ko') === 'ky'): ?>
                                            / KY
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>&nbsp;</label>
                            <button type="submit" name="load_preset" value="1" class="secondary">preset 불러오기</button>
                        </div>
                        <div class="field span-4">
                            <label for="manual_names">일용근로자 성명 목록</label>
                            <textarea id="manual_names" name="manual_names"><?= h($manualNamesRaw) ?></textarea>
                            <div class="hint">한 줄에 한 명씩 입력해 주세요. 외국인 이름도 그대로 입력하면 됩니다.</div>
                        </div>
                        <div class="field span-4">
                            <label for="manual_items">공통 지급 보호구 목록</label>
                            <textarea id="manual_items" name="manual_items"><?= h($manualItemsRaw) ?></textarea>
                            <div class="hint">한 줄 형식: `보호구명칭|품명|모델명|수량|지급일자` 예: `안전모|안전모|K2 화이트|1|<?= h(date('Y-m-d')) ?>`</div>
                        </div>
                    </div>
                    <?php if ($manualLocale !== 'ko'): ?>
                        <div class="locale-note"><?= h(receipt_locale_note($manualLocale)) ?></div>
                    <?php endif; ?>
                    <input type="hidden" name="print" value="1">
                <?php else: ?>
                    <div class="employee-grid">
                        <?php foreach ($allGroups as $group): ?>
                            <?php
                            $employeeId = (string)($group['employee_id'] ?? '');
                            $checked = in_array($employeeId, $selectedIds, true);
                            ?>
                            <div class="employee-option">
                                <label>
                                    <input type="checkbox" name="employee_id[]" value="<?= h($employeeId) ?>"<?= $checked ? ' checked' : '' ?>>
                                    <span>
                                        <div class="employee-name"><?= h(receipt_value($group['employee_name'] ?? '')) ?></div>
                                        <div class="employee-meta">
                                            소속: <?= h(receipt_value($group['employee_team'] ?? '')) ?><br>
                                            직급: <?= h(receipt_value($group['employee_position'] ?? '')) ?><br>
                                            현재 지급 보호구 <?= count((array)($group['assigned_items'] ?? [])) ?>건
                                        </div>
                                    </span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($selectedIds)): ?>
                        <input type="hidden" name="print" value="1">
                    <?php endif; ?>
                <?php endif; ?>
            </form>

            <?php if ($mode === 'daily'): ?>
                <form method="post" id="preset-save-form" style="margin-top: 12px;">
                    <?= receipt_hidden_inputs($state) ?>
                    <input type="hidden" name="action" value="save_manual_preset">
                    <div class="inline-actions">
                        <input type="text" name="manual_preset_name" value="<?= h($manualPresetName) ?>" placeholder="예: 키르기스 일용 기본 지급세트" style="min-width: 280px; border:1px solid var(--line); border-radius:12px; padding:10px 12px; font:inherit;">
                        <button type="submit" class="secondary">현재 목록 preset 저장</button>
                        <span class="hint">현재 `공통 지급 보호구 목록`과 언어 설정을 이름으로 저장해 두고 다시 불러올 수 있습니다.</span>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($printMode && !empty($printGroups)): ?>
                <form method="post" style="margin-top: 14px;">
                    <?= receipt_hidden_inputs($state) ?>
                    <input type="hidden" name="action" value="save_batch">
                    <div class="inline-actions">
                        <button type="submit" class="button">현재 확인서 DB 저장</button>
                        <span class="hint">미리보기로 생성된 확인서를 문서번호와 함께 DB에 남깁니다.</span>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($printMode && empty($printGroups)): ?>
                <div class="empty">현재 조건으로 생성할 확인서가 없습니다.</div>
            <?php elseif ($mode !== 'daily' && empty($allGroups)): ?>
                <div class="empty">현재 지급자 정보가 연결된 보호구가 없습니다.</div>
            <?php endif; ?>
        </section>

        <?php foreach ($printGroups as $index => $group): ?>
            <?php
            $assignedItems = (array)($group['assigned_items'] ?? []);
            $documentNo = receipt_document_no($group, $index + 1, $mode === 'daily' ? 'DGR' : 'SGR');
            $documentDate = $mode === 'daily' ? ($manualDate !== '' ? $manualDate : date('Y-m-d')) : receipt_signature_date($assignedItems);
            $rows = $mode === 'daily' ? (array)($group['manual_rows'] ?? []) : receipt_aggregate_items($assignedItems);
            $sheetLocale = $mode === 'daily' ? $manualLocale : 'ko';
            $t = receipt_translation_set($sheetLocale);
            $pledgeTranslated = $sheetLocale === 'ru' ? RECEIPT_PLEDGE_TEXT_RU : ($sheetLocale === 'uz' ? RECEIPT_PLEDGE_TEXT_UZ : ($sheetLocale === 'ky' ? RECEIPT_PLEDGE_TEXT_KY : ''));
            ?>
            <section class="sheet">
                <div class="doc-head">
                    <div class="doc-meta">
                        <?= receipt_label_html('사업장명', $t['company'] ?? '', $sheetLocale) ?>: <?= h(RECEIPT_COMPANY_NAME) ?><br>
                        <?= receipt_label_html('현장명', $t['site'] ?? '', $sheetLocale) ?>: <?= h(RECEIPT_SITE_NAME) ?>
                    </div>
                    <div class="doc-meta" style="text-align:right;">
                        <?= receipt_label_html('문서번호', $t['document_no'] ?? '', $sheetLocale) ?>: <?= h($documentNo) ?><br>
                        <?= receipt_label_html('작성일', $t['written_at'] ?? '', $sheetLocale) ?>: <?= h($documentDate) ?>
                    </div>
                </div>

                <div class="doc-title"><?= receipt_label_html('보호구 지급 확인서', $t['title'] ?? '', $sheetLocale) ?></div>
                <?php if ($sheetLocale !== 'ko' && !empty($t['subtitle'])): ?>
                    <div class="doc-subtitle"><?= h($t['subtitle']) ?></div>
                <?php endif; ?>

                <table>
                    <tr>
                        <th><?= receipt_label_html('소속', $t['team'] ?? '', $sheetLocale) ?></th>
                        <td><?= h(receipt_value($group['employee_team'] ?? '')) ?></td>
                        <th><?= receipt_label_html('직급', $t['position'] ?? '', $sheetLocale) ?></th>
                        <td><?= h(receipt_value($group['employee_position'] ?? '')) ?></td>
                    </tr>
                    <tr>
                        <th><?= receipt_label_html('성명', $t['name'] ?? '', $sheetLocale) ?></th>
                        <td><?= h(receipt_value($group['employee_name'] ?? '')) ?></td>
                        <th><?= receipt_label_html('지급 일자', $t['issued_date'] ?? '', $sheetLocale) ?></th>
                        <td><?= h($documentDate) ?></td>
                    </tr>
                </table>

                <div class="section-title"><?= receipt_label_html('지급 보호구 상세', $t['items_title'] ?? '', $sheetLocale) ?></div>
                <table>
                    <thead>
                        <tr>
                            <th style="width:48px;">No.</th>
                            <th><?= receipt_label_html('보호구 명칭', $t['gear_name'] ?? '', $sheetLocale) ?></th>
                            <th><?= receipt_label_html('품명', '', $sheetLocale) ?></th>
                            <th><?= receipt_label_html('규격', '', $sheetLocale) ?></th>
                            <th><?= receipt_label_html('모델명', '', $sheetLocale) ?></th>
                            <th><?= receipt_label_html('제조사', '', $sheetLocale) ?></th>
                            <th><?= receipt_label_html('KCS 안전인증번호', '', $sheetLocale) ?></th>
                            <th style="width:88px;"><?= receipt_label_html('지급 수량', $t['quantity'] ?? '', $sheetLocale) ?></th>
                            <th style="width:120px;"><?= receipt_label_html('지급 일자', $t['issued_date'] ?? '', $sheetLocale) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $rowIndex => $row): ?>
                            <tr>
                                <td><?= $rowIndex + 1 ?></td>
                                <td><?= receipt_gear_label_html((string)($row['gear_label'] ?? ''), $sheetLocale) ?></td>
                                <td><?= h(receipt_value($row['item_name'] ?? '')) ?></td>
                                <td><?= h(receipt_value($row['spec_name'] ?? '')) ?></td>
                                <td><?= h(receipt_value($row['model_name'] ?? '')) ?></td>
                                <td><?= h(receipt_value($row['manufacturer_name'] ?? '')) ?></td>
                                <td><?= h(receipt_value($row['kcs_cert_no'] ?? '')) ?></td>
                                <td><?= (int)($row['quantity'] ?? 0) ?></td>
                                <td><?= h(receipt_value($row['assigned_date'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="section-title"><?= receipt_label_html('근로자 준수 서약', $t['pledge_title'] ?? '', $sheetLocale) ?></div>
                <div class="pledge-box"><?= receipt_pledge_html(RECEIPT_PLEDGE_TEXT, $pledgeTranslated, $sheetLocale) ?></div>

                <div class="sign-area">
                    <div class="sign-box">
                        <h3><?= receipt_label_html('수령자 자필 서명', $t['sign_title'] ?? '', $sheetLocale) ?></h3>
                        <div class="sign-line"><?= receipt_label_html('서명', $t['sign'] ?? '', $sheetLocale) ?>: ____________________</div>
                        <div class="sign-line" style="margin-top:18px;"><?= receipt_label_html('서명일', $t['sign_date'] ?? '', $sheetLocale) ?>: ____________________</div>
                    </div>
                    <div class="sign-box">
                        <h3><?= receipt_label_html('안전관리자 확인', $t['manager_title'] ?? '', $sheetLocale) ?></h3>
                        <div><?= receipt_label_html('확인방법: 자필서명 원본 확인', $t['manager_method'] ?? '', $sheetLocale) ?></div>
                        <div class="sign-line"><?= receipt_label_html('확인자', $t['manager_name'] ?? '', $sheetLocale) ?>: ____________________</div>
                        <div class="sign-line" style="margin-top:18px;"><?= receipt_label_html('확인일', $t['manager_date'] ?? '', $sheetLocale) ?>: ____________________</div>
                    </div>
                </div>

                <div class="note"><?= receipt_label_html('비고: 본 확인서는 보호구 지급 사실과 근로자 준수 서약을 문서화하기 위한 용도입니다.', $t['note'] ?? '', $sheetLocale) ?></div>
            </section>
        <?php endforeach; ?>

        <section class="panel no-print">
            <h2>저장된 확인서 관리</h2>
            <p class="lead">출력 후 자필서명을 받은 확인서는 여기에서 스캔본 또는 사진을 첨부하고, 안전관리자 확인 완료로 마감할 수 있습니다.</p>

            <?php if (empty($savedReceipts)): ?>
                <div class="empty">저장된 확인서가 아직 없습니다. 위에서 확인서를 생성한 뒤 `현재 확인서 DB 저장`을 눌러주세요.</div>
            <?php else: ?>
                <div class="receipt-list">
                    <?php foreach ($savedReceipts as $receipt): ?>
                        <?php
                        $receiptId = (int)($receipt['receipt_id'] ?? 0);
                        $status = sg_normalize_text($receipt['status_label'] ?? 'issued');
                        $attachmentPath = sg_normalize_text($receipt['attachment_path'] ?? '');
                        $attachmentName = sg_normalize_text($receipt['attachment_original_name'] ?? '');
                        ?>
                        <article class="receipt-card">
                            <div class="receipt-top">
                                <div>
                                    <div class="receipt-title"><?= h(receipt_value($receipt['document_no'] ?? '')) ?></div>
                                    <div class="receipt-meta">
                                        성명: <?= h(receipt_value($receipt['worker_name'] ?? '')) ?><br>
                                        소속: <?= h(receipt_value($receipt['worker_team'] ?? '')) ?> / 직급: <?= h(receipt_value($receipt['worker_position'] ?? '')) ?><br>
                                        발급일: <?= h(receipt_value($receipt['issue_date'] ?? '')) ?> / 저장일시: <?= h(receipt_value($receipt['created_at'] ?? '')) ?>
                                    </div>
                                </div>
                                <div>
                                    <span class="badge <?= $status === 'confirmed' ? 'confirmed' : 'issued' ?>"><?= h(receipt_status_label($status)) ?></span>
                                    <?php if (sg_normalize_text($receipt['confirmed_at'] ?? '') !== ''): ?>
                                        <div class="receipt-meta" style="margin-top:8px; text-align:right;">
                                            확인자: <?= h(receipt_value($receipt['confirmed_by_name'] ?? '')) ?><br>
                                            확인시각: <?= h(receipt_value($receipt['confirmed_at'] ?? '')) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="receipt-grid">
                                <div class="sub-panel">
                                    <strong>지급 보호구 상세</strong>
                                    <table class="mini-table">
                                        <thead>
                                            <tr>
                                                <th style="width:52px;">No.</th>
                                                <th>보호구 명칭</th>
                                                <th>품명</th>
                                                <th>규격</th>
                                                <th>모델명</th>
                                                <th>제조사</th>
                                                <th>KCS 안전인증번호</th>
                                                <th style="width:72px;">수량</th>
                                                <th style="width:110px;">지급일</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ((array)($receipt['items'] ?? []) as $rowIndex => $item): ?>
                                                <tr>
                                                    <td><?= $rowIndex + 1 ?></td>
                                                    <td><?= h(receipt_value($item['gear_label'] ?? '')) ?></td>
                                                    <td><?= h(receipt_value($item['item_name'] ?? '')) ?></td>
                                                    <td><?= h(receipt_value($item['spec_name'] ?? '')) ?></td>
                                                    <td><?= h(receipt_value($item['model_name'] ?? '')) ?></td>
                                                    <td><?= h(receipt_value($item['manufacturer_name'] ?? '')) ?></td>
                                                    <td><?= h(receipt_value($item['kcs_cert_no'] ?? '')) ?></td>
                                                    <td><?= (int)($item['quantity'] ?? 0) ?></td>
                                                    <td><?= h(receipt_value($item['assigned_date'] ?? '')) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="sub-panel">
                                    <strong>서명지 첨부 / 확인 완료</strong>
                                    <div class="receipt-meta" style="margin-top: 8px;">
                                        서약 문구: <?= nl2br(h(receipt_value($receipt['pledge_text'] ?? RECEIPT_PLEDGE_TEXT))) ?>
                                    </div>

                                    <?php if ($attachmentPath !== ''): ?>
                                        <a class="attachment-link" href="/<?= h($attachmentPath) ?>" target="_blank" rel="noopener">첨부 보기: <?= h(receipt_value($attachmentName, '첨부파일')) ?></a>
                                    <?php else: ?>
                                        <div class="receipt-meta" style="margin-top: 8px;">첨부된 서명지가 아직 없습니다.</div>
                                    <?php endif; ?>

                                    <?php if (sg_normalize_text($receipt['confirm_note'] ?? '') !== ''): ?>
                                        <div class="receipt-meta" style="margin-top: 10px;">확인 메모: <?= nl2br(h(sg_normalize_text($receipt['confirm_note'] ?? ''))) ?></div>
                                    <?php endif; ?>

                                    <form method="post" enctype="multipart/form-data" style="margin-top: 12px; display:grid; gap:10px;">
                                        <input type="hidden" name="receipt_id" value="<?= $receiptId ?>">
                                        <div class="field">
                                            <label for="attachment-<?= $receiptId ?>">서명지 스캔본 / 사진</label>
                                            <input id="attachment-<?= $receiptId ?>" type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf">
                                        </div>
                                        <div class="field">
                                            <label for="confirm-note-<?= $receiptId ?>">안전관리자 확인 메모</label>
                                            <textarea id="confirm-note-<?= $receiptId ?>" name="confirm_note" style="min-height: 96px;"><?= h(sg_normalize_text($receipt['confirm_note'] ?? '')) ?></textarea>
                                        </div>
                                        <div class="inline-actions">
                                            <button type="submit" name="action" value="attach_receipt" class="secondary">첨부만 저장</button>
                                            <button type="submit" name="action" value="confirm_receipt"><?= $status === 'confirmed' ? '확인 완료 갱신' : '확인 완료 처리' ?></button>
                                            <button type="submit" name="action" value="delete_receipt" class="secondary" onclick="return window.confirm('이 확인서를 삭제할까요? 중복 저장분 정리용입니다.');">삭제</button>
                                        </div>
                                        <div class="hint">확인 완료 처리는 첨부파일이 있는 상태에서만 가능합니다. 새 파일을 같이 올리면 첨부 저장 후 즉시 확인 완료됩니다.</div>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <script>
        (function () {
            const builderForm = document.getElementById('daily-builder-form');
            const presetSaveForm = document.getElementById('preset-save-form');
            if (!builderForm || !presetSaveForm) {
                return;
            }

            presetSaveForm.addEventListener('submit', function () {
                ['manual_team', 'manual_position', 'manual_date', 'manual_locale', 'manual_preset_id', 'manual_names', 'manual_items'].forEach(function (name) {
                    const source = builderForm.elements.namedItem(name);
                    const target = presetSaveForm.elements.namedItem(name);
                    if (!source || !target) {
                        return;
                    }
                    target.value = source.value;
                });
            });
        }());
    </script>
</body>
</html>
