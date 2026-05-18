<?php
declare(strict_types=1);

const TBM_ARTICLE_IMAGE_SLOT_WIDTH_MM = 85.0;
const TBM_ARTICLE_IMAGE_SLOT_HEIGHT_MM = 58.0;
const TBM_ARTICLE_IMAGE_RENDER_DPI = 300;
const TBM_ARTICLE_IMAGE_TARGET_WIDTH_PX = 1004;
const TBM_ARTICLE_IMAGE_TARGET_HEIGHT_PX = 685;

// ============================================================
// tbm_functions.php  —  TBM 렌더링 공통 함수
// ============================================================

// ── 기본 데이터 ──────────────────────────────────────────────

function tbm_can_use_ai_generation(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    if (trim((string)($user['login_id'] ?? '')) === 'admin02') {
        return false;
    }

    $userRole = (string)($user['role'] ?? '');
    return auth_is_admin($user) || $userRole === 'safety_manager';
}

function tbm_default_data(): array
{
    return [
        'doc_date'             => date('Y-m-d'),
        'instructor_name'      => '김남균',
        'instructor_position'  => '과장',
        'instructor_note'      => '',
        'names'                => ['윤택천','엄기준','윤순형','이정민','한지민','이돈희','김종훈',''],
        'today_work_1'         => '',
        'today_work_2'         => '',
        'risk_checks'          => [],
        'risk_rows'            => array_fill(0, 10, [
                                    'work'=>'','hazard'=>'','control'=>'',
                                    'freq'=>'','strength'=>'','risk'=>''
                                  ]),
        'remarks'              => '',
        'edu_title'            => '중대재해 전파(천장 추락 사고)',
        'left_content'         => "2026년 3월 22일 오전 11시경 인천 연수구 송도동 소재 의약품 제조공장에서 배관 보수 작업을 수행하던 20대 작업자가 캐노피 상부에서 추락하는 사고가 발생하였다.\n\n작업자는 천장 누수 보수 작업 중 바닥 마감재(패널)를 밟고 이동하던 중 해당 마감재가 파손되면서 약 3m 아래로 떨어진 것으로 파악된다. 사고 직후 병원으로 이송되었으나 끝내 사망하였으며, 관계 당국은 현장 안전조치 이행 여부와 구조물 안전성 점검 상태를 중심으로 정확한 사고 원인을 조사하고 있다.\n\n캐노피 및 천장 상부 작업 시에는 구조적으로 하중을 지지할 수 없는 마감재 위 보행을 금지하고, 반드시 작업발판이나 이동식 비계를 설치하여야 한다. 또한 추락방호망과 안전난간을 병행 설치하고, 안전대 체결을 의무화하여야 한다.",
        'quiz_1'               => "1. 천장 내부나 지붕 위에서 작업할 때, 추락으로 인한 중대재해를 예방하기 위해 작업자가 최우선으로 착용하고 체결해야 하는 필수 안전 장비는 무엇인가요?\n① 보안경 및 방진마스크\n② 안전모 및 안전대(안전벨트)\n③ 절연 장갑 및 안전화\n④ 반사조끼 및 귀마개",
        'quiz_2'               => "2. 텍스, 석고보드, 슬레이트 등 강도가 약한 천장재 위에서 부득이하게 작업을 진행해야 할 때, 추락 방지를 위한 가장 올바른 조치는 무엇인가요?\n① 천장재를 지탱하는 얇은 철골 구조물만 밟고 이동한다.\n② 최대한 엎드린 자세로 작업한다.\n③ 폭 30cm 이상의 견고한 작업 발판을 설치하고 그 위에서 작업한다.\n④ 작업 인원을 늘려 단시간에 끝낸다.",
        'quiz_3'               => "3. 다음 중 고소(천장) 작업 시 지켜야 할 안전 수칙으로 가장 거리가 먼 것은 무엇인가요?\n① 작업 장소 아래에 추락 방호망을 규정에 맞게 설치한다.\n② 작업 전 안전대를 걸 수 있는 튼튼한 부착 설비가 있는지 확인한다.\n③ 파손되기 쉬운 채광창은 덮개로 가려져 있으므로 안심하고 밟는다.\n④ 이동식 틀비계나 고소작업대를 사용할 때는 안전난간을 설치하고 바퀴를 고정한다.",
        'image_file'           => 'TBM일지_26-03-24_hd1.png',
    ];
}

// ── POST 입력 수집 ────────────────────────────────────────────

function tbm_normalize_display_team_name(string $team): string
{
    $map = [
        '공사팀-전기' => '공사팀',
        '공사팀-전기2' => '공사팀',
        '공사팀-전기3' => '공사팀',
        '공사팀-모터' => '공사팀',
        '공사팀'      => '공사팀',
        '가스팀'      => '가스팀',
        '제조팀'      => '제조팀',
        '삼척팀'      => '제조팀',
        '안전관리'    => '운영자',
    ];

    $team = trim($team);
    return $map[$team] ?? $team;
}

function tbm_output_root_dir(): string
{
    return __DIR__ . '/output';
}

function tbm_output_team_folder_name(?string $team): string
{
    $folder = trim((string)$team);
    if ($folder !== '') {
        $folder = tbm_normalize_display_team_name($folder);
    }

    if ($folder === '') {
        $folder = '공통';
    }

    $folder = preg_replace('/[\\\\\\/:*?"<>|]+/u', '_', $folder);
    $folder = trim((string)$folder, ". \t\n\r\0\x0B");

    return $folder !== '' ? $folder : '공통';
}

function tbm_prepare_output_directory(?string $team): array
{
    $rootDir = tbm_output_root_dir();
    if (!is_dir($rootDir) && !mkdir($rootDir, 0777, true) && !is_dir($rootDir)) {
        throw new RuntimeException('TBM output root directory could not be created: ' . $rootDir);
    }

    $folderName = tbm_output_team_folder_name($team);
    $directory = $rootDir . '/' . $folderName;
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('TBM team output directory could not be created: ' . $directory);
    }

    return [$folderName, $directory];
}

function tbm_normalize_output_relative_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        return '';
    }

    $path = preg_replace('~/+~', '/', $path);
    $path = ltrim((string)$path, '/');

    $segments = [];
    foreach (explode('/', $path) as $segment) {
        $segment = trim($segment);
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return '';
        }
        $segments[] = $segment;
    }

    return implode('/', $segments);
}

function tbm_build_output_relative_path(string $fileName, ?string $team): string
{
    $safeFileName = trim(basename(str_replace('\\', '/', $fileName)));
    if ($safeFileName === '') {
        throw new InvalidArgumentException('TBM output filename is required.');
    }

    return tbm_output_team_folder_name($team) . '/' . $safeFileName;
}

function tbm_resolve_output_full_path(string $relativePath): string
{
    $normalizedPath = tbm_normalize_output_relative_path($relativePath);
    if ($normalizedPath === '') {
        return tbm_output_root_dir();
    }

    return tbm_output_root_dir() . '/' . $normalizedPath;
}

function tbm_request_data(): array
{
    $base = tbm_default_data();

    $base['doc_date']            = trim($_POST['doc_date']           ?? $base['doc_date']);
    $base['instructor_name']     = trim($_POST['instructor_name']    ?? $base['instructor_name']);
    $base['instructor_position'] = trim($_POST['instructor_position'] ?? $base['instructor_position']);
    $base['instructor_note']     = trim($_POST['instructor_note']    ?? '');
    $base['today_work_1']        = trim($_POST['today_work_1']       ?? '');
    $base['today_work_2']        = trim($_POST['today_work_2']       ?? '');
    $base['remarks']             = trim($_POST['remarks']            ?? '');
    $base['edu_title']           = trim($_POST['edu_title']          ?? $base['edu_title']);
    $base['left_content']        = trim($_POST['left_content']       ?? '');
    $base['quiz_1']              = trim($_POST['quiz1']              ?? '');
    $base['quiz_2']              = trim($_POST['quiz2']              ?? '');
    $base['quiz_3']              = trim($_POST['quiz3']              ?? '');
    $base['image_file']          = trim($_POST['image_file']         ?? '') ?: $base['image_file'];
    $base['source_url']          = trim($_POST['source_url']         ?? '');

    $base['risk_checks'] = array_values(
        array_filter($_POST['risk_checks'] ?? [], static fn($v) => trim((string)$v) !== '')
    );

    // 동적으로 추가된 이름 처리
    $names = [];
    foreach ($_POST as $key => $val) {
        if (str_starts_with($key, 'name')) {
            $num = substr($key, 4);
            if (is_numeric($num)) {
                $names[(int)$num] = trim($val);
            }
        }
    }
    ksort($names);
    $base['names'] = array_values($names);
    while (count($base['names']) < 8) {
        $base['names'][] = '';
    }

    $rows    = $_POST['risk_rows'] ?? [];
    $outRows = [];
    for ($i = 0; $i < 10; $i++) {
        $row       = $rows[$i] ?? [];
        $outRows[] = [
            'work'     => trim((string)($row['work']     ?? '')),
            'hazard'   => trim((string)($row['hazard']   ?? '')),
            'control'  => trim((string)($row['control']  ?? '')),
            'freq'     => trim((string)($row['freq']     ?? '')),
            'strength' => trim((string)($row['strength'] ?? '')),
            'risk'     => trim((string)($row['risk']     ?? '')),
        ];
    }
    $base['risk_rows'] = $outRows;

    return $base;
}

function tbm_has_filled_names(array $names): bool
{
    foreach ($names as $name) {
        if (trim((string)$name) !== '') {
            return true;
        }
    }

    return false;
}

function tbm_clean_attendee_name(string $name): string
{
    $name = trim((string)preg_replace('/\s*\([^)]*\)/u', '', $name));
    return $name;
}

function tbm_attendee_names_for_team(string $teamName, array $excludeNames = ['진준철']): array
{
    $teamName = trim($teamName);
    if ($teamName === '') {
        return [];
    }

    $names = [];
    foreach (auth_read_active_teams() as $rawTeamName) {
        $displayName = tbm_normalize_display_team_name($rawTeamName);
        if ($displayName !== $teamName) {
            continue;
        }

        if (auth_team_key($rawTeamName) === auth_team_key('안전관리')) {
            continue;
        }

        foreach (auth_team_member_names($rawTeamName, ['worker', 'leader', 'manager']) as $rawName) {
            $cleanName = tbm_clean_attendee_name($rawName);
            if ($cleanName === '' || in_array($cleanName, $excludeNames, true)) {
                continue;
            }
            $names[] = $cleanName;
        }
    }

    $names = array_values(array_unique($names));
    sort($names, SORT_STRING);

    return $names;
}

function tbm_resolve_attendee_names(array $data, string $selectedTeam, ?array $user = null): array
{
    $postedNames = array_values(array_map(
        static fn($name) => trim((string)$name),
        is_array($data['names'] ?? null) ? $data['names'] : []
    ));

    if (tbm_has_filled_names($postedNames)) {
        while (count($postedNames) < 8) {
            $postedNames[] = '';
        }
        return $postedNames;
    }

    $teamName = trim($selectedTeam);
    if ($teamName === '공통') {
        $teamName = '';
    }

    if ($teamName === '' && is_array($user)) {
        $teamName = tbm_normalize_display_team_name(
            auth_normalize_team_name((string)($user['team'] ?? ''))
        );
    }

    $resolvedNames = tbm_attendee_names_for_team($teamName);
    while (count($resolvedNames) < 8) {
        $resolvedNames[] = '';
    }

    return $resolvedNames;
}

function tbm_load_image_resource(string $imagePath)
{
    $info = @getimagesize($imagePath);
    if (!is_array($info)) {
        return null;
    }

    return match ((int)($info[2] ?? 0)) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($imagePath),
        IMAGETYPE_PNG => @imagecreatefrompng($imagePath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($imagePath) : null,
        default => null,
    };
}

function tbm_store_uploaded_manual_image(?array $file, string $docDate = ''): ?string
{
    if (!is_array($file) || $file === []) {
        return null;
    }

    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException('사진 업로드에 실패했습니다. 파일을 다시 선택해 주세요.');
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('업로드된 사진 파일을 확인할 수 없습니다.');
    }

    $imageInfo = @getimagesize($tmpPath);
    if (!is_array($imageInfo)) {
        throw new RuntimeException('이미지 파일만 업로드할 수 있습니다.');
    }

    $src = tbm_load_image_resource($tmpPath);
    if (!$src) {
        throw new RuntimeException('지원하지 않는 이미지 형식입니다. JPG, PNG, WEBP만 가능합니다.');
    }

    $srcWidth = imagesx($src);
    $srcHeight = imagesy($src);
    if ($srcWidth < 20 || $srcHeight < 20) {
        imagedestroy($src);
        throw new RuntimeException('이미지 크기가 너무 작습니다.');
    }

    $targetRatio = TBM_ARTICLE_IMAGE_SLOT_WIDTH_MM / TBM_ARTICLE_IMAGE_SLOT_HEIGHT_MM;
    $sourceRatio = $srcWidth / max(1, $srcHeight);

    if ($sourceRatio > $targetRatio) {
        $cropHeight = $srcHeight;
        $cropWidth = (int)round($cropHeight * $targetRatio);
        $cropX = (int)floor(($srcWidth - $cropWidth) / 2);
        $cropY = 0;
    } else {
        $cropWidth = $srcWidth;
        $cropHeight = (int)round($cropWidth / $targetRatio);
        $cropX = 0;
        $cropY = (int)floor(($srcHeight - $cropHeight) / 2);
    }

    $cropWidth = max(1, min($cropWidth, $srcWidth));
    $cropHeight = max(1, min($cropHeight, $srcHeight));

    $cropped = imagecrop($src, [
        'x' => max(0, $cropX),
        'y' => max(0, $cropY),
        'width' => $cropWidth,
        'height' => $cropHeight,
    ]);
    imagedestroy($src);

    if (!$cropped) {
        throw new RuntimeException('사진 크롭 처리에 실패했습니다.');
    }

    $outputWidth = TBM_ARTICLE_IMAGE_TARGET_WIDTH_PX;
    $outputHeight = TBM_ARTICLE_IMAGE_TARGET_HEIGHT_PX;
    $canvas = imagecreatetruecolor($outputWidth, $outputHeight);
    if (!$canvas) {
        imagedestroy($cropped);
        throw new RuntimeException('사진 저장용 캔버스를 만들 수 없습니다.');
    }

    imagecopyresampled($canvas, $cropped, 0, 0, 0, 0, $outputWidth, $outputHeight, imagesx($cropped), imagesy($cropped));
    imagedestroy($cropped);

    $imageDir = __DIR__ . '/output/images';
    if (!is_dir($imageDir) && !mkdir($imageDir, 0777, true) && !is_dir($imageDir)) {
        imagedestroy($canvas);
        throw new RuntimeException('사진 저장 폴더를 만들 수 없습니다.');
    }

    $safeDate = preg_replace('/[^0-9]/', '', $docDate);
    if ($safeDate === '') {
        $safeDate = date('Ymd');
    }
    $hash = substr(sha1_file($tmpPath) ?: sha1(uniqid('tbm_manual_', true)), 0, 12);
    $fileName = 'manual_' . $safeDate . '_' . $hash . '.jpg';
    $savePath = $imageDir . '/' . $fileName;

    $saved = imagejpeg($canvas, $savePath, 92);
    imagedestroy($canvas);

    if (!$saved || !is_file($savePath)) {
        throw new RuntimeException('크롭한 사진 저장에 실패했습니다.');
    }

    return 'output/images/' . $fileName;
}

// ── 날짜 포맷 ────────────────────────────────────────────────

function tbm_date_line(string $date): string
{
    $dt       = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime();
    $weekdays = ['일요일','월요일','화요일','수요일','목요일','금요일','토요일'];
    $weekday  = $weekdays[(int)$dt->format('w')] ?? '';

    return sprintf(
        '%d년 &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;%02d월 &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;%02d일 &nbsp;&nbsp;&nbsp;&nbsp;%s',
        (int)$dt->format('Y'),
        (int)$dt->format('m'),
        (int)$dt->format('d'),
        $weekday
    );
}

// ── HTML 이스케이프 ───────────────────────────────────────────

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── 앞면 1페이지 통합 동적 테이블 빌더 ─────────────────────────────

function tbm_build_dynamic_tables(array $data): string {
    $names = $data['names'] ?? [];
    $rowCount = max(2, ceil(count($names) / 4));

    $checks = $data['risk_checks'] ?? [];
    $chk1 = in_array('고소작업', $checks) ? '■' : '□';
    $chk2 = in_array('화기작업', $checks) ? '■' : '□';
    $chk3 = in_array('밀폐구역', $checks) ? '■' : '□';
    $chk4 = in_array('중장비작업', $checks) ? '■' : '□';
    $chk5 = in_array('중대위험요인 없음', $checks) ? '■' : '□';

    // 테이블 테두리 겹침 방지를 위해 margin-top: -1px 적용
    $html = '<style>
        .tbm-dyn-table { width: 183.02mm; border-collapse: collapse; table-layout: fixed; margin: 0; padding: 0; font-family: "맑은 고딕", sans-serif; font-size: 10pt; color: #000; }
        .tbm-dyn-table th, .tbm-dyn-table td { border: 1px solid #000; text-align: center; vertical-align: middle; box-sizing: border-box; font-weight: normal; padding: 0; }
        .tbm-dyn-row { margin-top: -1px; position: relative; z-index: 1; }
        .text-left { text-align: left !important; padding-left: 3.44mm !important; }
    </style>';

    $html .= '<div style="width: 183.02mm; margin: 0; padding: 0;">';

    // 1. 참석자 명단
    $html .= '<table class="tbm-dyn-table tbm-dyn-row" style="z-index: 5;">';
    $html .= '<tr style="height: 8.01mm;">';
    for($i=0; $i<4; $i++) {
        $html .= '<th style="width: 12.5%;">이름</th><th style="width: 12.5%;">서명</th>';
    }
    $html .= '</tr>';
    $nameIdx = 0;
    for ($r = 0; $r < $rowCount; $r++) {
        $html .= '<tr style="height: 8.01mm;">';
        for ($c = 0; $c < 4; $c++) {
            $name = isset($names[$nameIdx]) ? e($names[$nameIdx]) : '';
            $html .= '<td>' . $name . '</td><td></td>';
            $nameIdx++;
        }
        $html .= '</tr>';
    }
    $html .= '</table>';

    // 2. 금일 예정 작업
    $html .= '<table class="tbm-dyn-table tbm-dyn-row" style="z-index: 4;">';
    $html .= '<tr style="height: 9.33mm;">';
    $html .= '<th rowspan="2" style="width: 16.6%;">금일 예정 작업</th>';
    $html .= '<td class="text-left" style="width: 83.4%;">' . e($data['today_work_1'] ?? '') . '</td>';
    $html .= '</tr>';
    $html .= '<tr style="height: 9.33mm;">';
    $html .= '<td class="text-left">' . e($data['today_work_2'] ?? '') . '</td>';
    $html .= '</tr>';
    $html .= '</table>';

    // 3. 위험 작업 체크박스
    $html .= '<table class="tbm-dyn-table tbm-dyn-row" style="z-index: 3;">';
    $html .= '<tr style="height: 12.7mm;">';
    $html .= '<td>';
    $html .= '고소작업 <span style="font-size:14pt; margin-right:15px;">'.$chk1.'</span>';
    $html .= '화기작업 <span style="font-size:14pt; margin-right:15px;">'.$chk2.'</span>';
    $html .= '밀폐구역 <span style="font-size:14pt; margin-right:15px;">'.$chk3.'</span>';
    $html .= '중장비작업 <span style="font-size:14pt; margin-right:20px;">'.$chk4.'</span>';
    $html .= '중대위험요인 없음 <span style="font-size:14pt;">'.$chk5.'</span>';
    $html .= '</td>';
    $html .= '</tr>';
    $html .= '</table>';

    // 4. 위험성 평가 표
    $html .= '<table class="tbm-dyn-table tbm-dyn-row" style="z-index: 2;">';
    $html .= '<tr style="height: 7.94mm;">';
    $html .= '<th style="width: 23.7%;">작업내용</th>';
    $html .= '<th style="width: 15.3%;">위험요인</th>';
    $html .= '<th style="width: 41.5%;">개선대책</th>';
    $html .= '<th style="width: 6.5%;">빈도</th>';
    $html .= '<th style="width: 6.5%;">강도</th>';
    $html .= '<th style="width: 6.5%;">위험도</th>';
    $html .= '</tr>';
    
    $rows = $data['risk_rows'] ?? [];
    for ($i = 0; $i < 10; $i++) {
        $row = $rows[$i] ?? ['work'=>'','hazard'=>'','control'=>'','freq'=>'','strength'=>'','risk'=>''];
        $html .= '<tr style="height: 8.54mm;">';
        $html .= '<td>' . e($row['work']) . '</td>';
        $html .= '<td>' . e($row['hazard']) . '</td>';
        $html .= '<td class="text-left">' . e($row['control']) . '</td>'; 
        $html .= '<td>' . e($row['freq']) . '</td>';
        $html .= '<td>' . e($row['strength']) . '</td>';
        $html .= '<td>' . e($row['risk']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';

    // 5. 하단 그리드 (가이드 + 의견사항: 원래 양식과 똑같은 점선 처리 적용)
    $html .= '<table class="tbm-dyn-table tbm-dyn-row" style="z-index: 1;">';
    $html .= '<tr style="height: 25.98mm;">';
    $html .= '<td class="text-left" style="vertical-align: top; padding: 2mm 2.5mm; line-height: 1.5; font-size: 8pt; border-bottom: none;">';
    $html .= '<span style="font-weight:bold;">★위험성 평가방법 5x4 (위험도 수준 “<span style="color:#000;">보통</span>” 이상 집중관리)</span><br>';
    $html .= '<span style="font-weight:bold;">☐</span> 빈도(가능성): <span style="font-weight:bold;">1</span>(거의없음), <span style="font-weight:bold;">2</span>(낮음), <span style="font-weight:bold;">3</span>(있음), <span style="font-weight:bold;">4</span>(높음), <span style="font-weight:bold;">5</span>(빈번함)<br>';
    $html .= '<span style="font-weight:bold;">☐</span> 강도(중대성): <span style="font-weight:bold;">1</span>(영향없음), <span style="font-weight:bold;">2</span>(휴업불필요), <span style="font-weight:bold;">3</span>(휴업필요), <span style="font-weight:bold;">4</span>(사망/장애발생)<br>';
    $html .= '<span style="font-weight:bold;">☐</span> 위험도산출= <span style="letter-spacing:-0.04em;">빈도</span> <span style="letter-spacing:-0.14em;">x</span> <span style="letter-spacing:-0.04em;">강도</span>. <span style="font-weight:bold;">1~3</span>: 매우낮음(허용가능), <span style="font-weight:bold;">4~6</span>: 낮음(허용가능), <span style="font-weight:bold;">8</span>:보통(<span style="color:red;">허용불가</span>), <span style="font-weight:bold;">9~15</span> 높음(<span style="color:red;">허용불가</span>), <span style="font-weight:bold;">16~20</span> 매우높음(<span style="color:red;">허용불가</span>)';
    $html .= '</td>';
    $html .= '</tr>';
    
    // 의견사항 행 (위 테두리 점선 적용)
    $html .= '<tr style="height: 16.18mm;">';
    $html .= '<td class="text-left" style="vertical-align: top; font-size: 10pt; padding: 2mm 3.53mm; border-top: 1px dashed #999;">의견사항.</td>';
    $html .= '</tr>';
    
    $html .= '</table>';

    $html .= '</div>';

    return $html;
}

// ── 뒷면 빌더 함수들 ─────────────────────────────────────────

require_once __DIR__ . '/phpqrcode/qrlib.php';

function tbm_build_qr_block(string $url): string
{
    $url = trim($url);
    if ($url === '' || $url === 'null') {
        return '';
    }

    ob_start();
    QRcode::png($url, null, QR_ECLEVEL_L, 3, 0);
    $qrImageData = ob_get_clean();
    $base64Qr = 'data:image/png;base64,' . base64_encode($qrImageData);

    $displayUrl = mb_strlen($url, 'UTF-8') > 60
                ? mb_substr($url, 0, 57, 'UTF-8') . '...'
                : $url;

    $top      = 107.00;
    $labelStyle = 'font-family:&quot;맑은 고딕&quot;,sans-serif;font-size:7.5pt;color:#cc0000;font-weight:bold;display:block;margin-bottom:3px;';
    $urlStyle   = 'font-family:&quot;맑은 고딕&quot;,sans-serif;font-size:6.5pt;color:#0000cc;word-break:break-all;display:block;';

    return '<div style="position:absolute;left:0mm;top:' . number_format($top, 2, '.', '') . 'mm;width:85mm;border-top:0.3mm solid #999;padding-top:2mm;">'
         . '  <img src="' . $base64Qr . '" style="float:left; width:13mm; height:13mm; margin-top:1.5mm; margin-right:4mm;">'
         . '  <div style="float:left; width:63mm; padding-top:2mm;">'
         . '    <span style="' . $labelStyle . '">▶ 관련기사 링크</span>'
         . '    <span style="' . $urlStyle . '">' . e($displayUrl) . '</span>'
         . '  </div>'
         . '  <div style="clear:both;"></div>'
         . '</div>';
}

function tbm_build_article_image_url(?string $imageUrl): string
{
    $url = trim((string)$imageUrl);

    if ($url === '' || $url === '__NO_IMAGE__') {
        return '../template/TBM일지%2026-03-24_hd1.png';
    }

    $url = preg_replace('~^output/~', '', $url);
    $fullPath = __DIR__ . '/output/' . ltrim($url, '/');
    if (!is_file($fullPath)) {
        $fullPath = __DIR__ . '/' . ltrim($url, '/');
    }
    if (!is_file($fullPath) && !str_contains($url, '/')) {
        $templatePath = __DIR__ . '/template/' . $url;
        if (is_file($templatePath)) {
            return e('../template/' . rawurlencode($url));
        }
    }

    if (is_file($fullPath) && extension_loaded('gd')) {
        $resized = tbm_resize_image_for_display(
            $fullPath,
            TBM_ARTICLE_IMAGE_TARGET_WIDTH_PX,
            TBM_ARTICLE_IMAGE_TARGET_HEIGHT_PX
        );
        if ($resized !== null) {
            $resizedRel = preg_replace('~^' . preg_quote(__DIR__ . '/output/', '~') . '~', '', $resized);
            return e($resizedRel);
        }
    }
    return e($url);
}

function tbm_resize_image_for_display(string $srcPath, int $maxW, int $maxH): ?string
{
    $info = @getimagesize($srcPath);
    if (!$info) {
        return null;
    }

    [$origW, $origH] = $info;
    $mime = strtolower((string)($info['mime'] ?? ''));

    if ($origW <= $maxW && $origH <= $maxH) {
        return null;
    }

    $ratioW = $maxW / $origW;
    $ratioH = $maxH / $origH;
    $ratio  = min($ratioW, $ratioH);

    $newW = (int)round($origW * $ratio);
    $newH = (int)round($origH * $ratio);

    if ($newW < 50 || $newH < 30) {
        return null;
    }

    $src = null;
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $src = @imagecreatefromjpeg($srcPath);
            break;
        case 'image/png':
            $src = @imagecreatefrompng($srcPath);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $src = @imagecreatefromwebp($srcPath);
            }
            break;
    }

    if (!$src) {
        return null;
    }

    $dst = imagecreatetruecolor($newW, $newH);
    if (!$dst) {
        imagedestroy($src);
        return null;
    }

    if ($mime === 'image/png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

    $saveDir = dirname($srcPath);
    $baseName = pathinfo($srcPath, PATHINFO_FILENAME);
    $savePath = $saveDir . '/' . $baseName . '_fit.jpg';

    $ok = imagejpeg($dst, $savePath, 92);

    imagedestroy($src);
    imagedestroy($dst);

    if (!$ok || !is_file($savePath)) {
        return null;
    }

    return $savePath;
}

function tbm_build_article_image_block(?string $imageFile, float $maxWidth = 82.0, float $maxHeight = 28.0): string
{
    $rawImageFile = trim((string)$imageFile);
    if ($rawImageFile === '' || $rawImageFile === '__NO_IMAGE__') {
        return '<div style="'
             . 'width:' . number_format($maxWidth, 1, '.', '') . 'mm;'
             . 'height:' . number_format($maxHeight, 1, '.', '') . 'mm;'
             . 'overflow:hidden;'
             . 'display:block;'
             . '"></div>';
    }

    $url = tbm_build_article_image_url($imageFile);

    return '<div style="'
         . 'width:' . number_format($maxWidth, 1, '.', '') . 'mm;'
         . 'height:' . number_format($maxHeight, 1, '.', '') . 'mm;'
         . 'overflow:hidden;'
         . 'display:flex;'
         . 'align-items:center;'
         . 'justify-content:center;'
         . '">'
         . '<img src="' . $url . '" style="'
         . 'max-width:100%;'
         . 'max-height:100%;'
         . 'width:auto;'
         . 'height:auto;'
         . 'object-fit:contain;'
         . '">'
         . '</div>';
}

function tbm_trim_body(string $body): string
{
    $body = strip_tags($body);
    $body = str_replace(['\\n\\n', '\\n'], ["\n\n", "\n"], $body);
    $body = preg_replace('/[ \t]+/', ' ', $body);
    $body = preg_replace("/\n{3,}/", "\n\n", (string)$body);

    return trim((string)$body);
}

function tbm_measure_text_units(string $text): float
{
    $text = (string)$text;
    if ($text === '') {
        return 0.0;
    }

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $units = 0.0;

    foreach ($chars as $char) {
        if (preg_match('/\s/u', $char) === 1) {
            $units += 0.38;
            continue;
        }

        if (preg_match('/[A-Za-z0-9]/u', $char) === 1) {
            $units += 0.62;
            continue;
        }

        if (preg_match('/[\x{2460}-\x{2473}]/u', $char) === 1) {
            $units += 0.92;
            continue;
        }

        if (preg_match('/[\.,:;!?()\[\]\-\/]/u', $char) === 1) {
            $units += 0.52;
            continue;
        }

        $units += 1.0;
    }

    return $units;
}

function tbm_wrap_text_line(string $text, int $maxChars): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $lines = [];
    $current = '';

    foreach ($chars as $char) {
        $candidate = $current . $char;
        if ($current !== '' && mb_strlen($candidate, 'UTF-8') > $maxChars) {
            $lines[] = $current;
            $current = $char;
            continue;
        }
        $current = $candidate;
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return $lines;
}

function tbm_wrap_text_by_units(string $text, float $maxUnits): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $lines = [];
    $current = '';

    foreach ($chars as $char) {
        $candidate = $current . $char;
        if ($current !== '' && tbm_measure_text_units($candidate) > $maxUnits) {
            $lines[] = rtrim($current);
            $current = ltrim($char);
            continue;
        }
        $current = $candidate;
    }

    if ($current !== '') {
        $lines[] = rtrim($current);
    }

    return $lines;
}

function tbm_build_edu_body_lines(string $body, int $maxChars): array
{
    $text = str_replace("\r\n", "\n", trim($body));
    $paragraphs = explode("\n", $text);
    $paragraphs = array_map('trim', $paragraphs);

    $last = end($paragraphs);
    if ($last === '[사고내용 및 원인]' || $last === '[예방대책]') {
        array_pop($paragraphs);
    }

    $lines = [];
    $prevWasContent = false;
    foreach ($paragraphs as $paragraph) {
        if ($paragraph === '[예방대책]' && $prevWasContent) {
            $lines[] = '';
        }
        if ($paragraph === '') {
            continue;
        }

        foreach (tbm_wrap_text_line($paragraph, $maxChars) as $line) {
            $lines[] = $line;
        }
        $prevWasContent = true;
    }

    return $lines;
}

function tbm_prepare_quiz_groups(array $quizzes, float $maxUnits): array
{
    $groups = [];
    foreach ($quizzes as $quiz) {
        $quiz = trim(str_replace("\r\n", "\n", (string)$quiz));
        if ($quiz === '') {
            continue;
        }

        $groupLines = [];
        $parts = preg_split("/\n+/", $quiz);
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }

            foreach (tbm_wrap_text_by_units($part, $maxUnits) as $line) {
                $groupLines[] = $line;
            }
        }

        if ($groupLines !== []) {
            $groups[] = $groupLines;
        }
    }

    return $groups;
}

function tbm_normalize_quiz_display_text(string $quiz): string
{
    $quiz = trim(str_replace("\r\n", "\n", $quiz));
    if ($quiz === '') {
        return '';
    }

    return preg_replace('/\n+(?=①)/u', ' ', $quiz, 1) ?? $quiz;
}

function tbm_split_quiz_display_parts(string $quiz): array
{
    $quiz = trim(str_replace(["\r\n", "\r"], "\n", $quiz));
    if ($quiz === '') {
        return ['question' => '', 'choices' => []];
    }

    $quiz = preg_replace('/\s*\n\s*/u', ' ', $quiz) ?? $quiz;
    $parts = preg_split('/(?=①|②|③|④)/u', $quiz) ?: [];
    $parts = array_values(array_filter(array_map(static fn($part) => trim((string)$part), $parts), static fn($part) => $part !== ''));

    if ($parts === []) {
        return ['question' => $quiz, 'choices' => []];
    }

    $question = array_shift($parts);

    return [
        'question' => $question,
        'choices' => $parts,
    ];
}

function tbm_is_csi_content(array $data): bool
{
    $sourceUrl = trim((string)($data['source_url'] ?? ''));
    if ($sourceUrl !== '') {
        $sourceUrlLower = mb_strtolower($sourceUrl, 'UTF-8');
        if (str_contains($sourceUrlLower, 'csi.go.kr')) {
            return true;
        }
    }

    $eduTitle = trim((string)($data['edu_title'] ?? ''));
    return preg_match('/^사고사례\s*(?:\(|$)/u', $eduTitle) === 1;
}

function tbm_build_edu_body_heading(array $data): string
{
    return tbm_is_csi_content($data) ? '♣ 사고사례 전파' : '♣ 중대재해 전파';
}

function tbm_build_edu_body_block(string $body, ?string $heading = null): string
{
    $heading    = trim((string)$heading);
    $layouts = [
        ['maxChars' => 33, 'lineH' => 5.80, 'boxH' => 4.80, 'fontSize' => 9.0, 'availableHeight' => 102.0],
        ['maxChars' => 34, 'lineH' => 5.65, 'boxH' => 4.68, 'fontSize' => 8.9, 'availableHeight' => 102.0],
        ['maxChars' => 35, 'lineH' => 5.40, 'boxH' => 4.50, 'fontSize' => 8.6, 'availableHeight' => 102.0],
        ['maxChars' => 36, 'lineH' => 5.20, 'boxH' => 4.34, 'fontSize' => 8.4, 'availableHeight' => 102.0],
        ['maxChars' => 37, 'lineH' => 5.00, 'boxH' => 4.20, 'fontSize' => 8.2, 'availableHeight' => 102.0],
        ['maxChars' => 38, 'lineH' => 4.80, 'boxH' => 4.05, 'fontSize' => 8.0, 'availableHeight' => 102.0],
        ['maxChars' => 39, 'lineH' => 4.60, 'boxH' => 3.90, 'fontSize' => 7.8, 'availableHeight' => 102.0],
        ['maxChars' => 40, 'lineH' => 4.45, 'boxH' => 3.78, 'fontSize' => 7.6, 'availableHeight' => 102.0],
        ['maxChars' => 41, 'lineH' => 4.30, 'boxH' => 3.66, 'fontSize' => 7.5, 'availableHeight' => 102.0],
        ['maxChars' => 42, 'lineH' => 4.18, 'boxH' => 3.56, 'fontSize' => 7.4, 'availableHeight' => 102.0],
        ['maxChars' => 43, 'lineH' => 4.08, 'boxH' => 3.48, 'fontSize' => 7.3, 'availableHeight' => 102.0],
        ['maxChars' => 44, 'lineH' => 4.00, 'boxH' => 3.40, 'fontSize' => 7.2, 'availableHeight' => 102.0],
    ];

    $chosenLayout = $layouts[count($layouts) - 1];
    $lines = [];
    foreach ($layouts as $layout) {
        $candidateLines = tbm_build_edu_body_lines($body, $layout['maxChars']);
        $candidateMaxLines = max(1, (int)floor($layout['availableHeight'] / $layout['lineH']));
        if (count($candidateLines) <= $candidateMaxLines) {
            $chosenLayout = $layout;
            $lines = $candidateLines;
            break;
        }
        $chosenLayout = $layout;
        $lines = $candidateLines;
    }

    $html  = "\n" . '<div class="hcI">' . "\n";
    $html .= '  <div class="hls ps22" style="line-height:4.20mm;white-space:nowrap;left:0mm;top:0mm;height:4.80mm;width:82.51mm;">'
           . '<span class="hrt cs21">♣ 중대재해 전파</span></div>' . "\n";

    $top = $chosenLayout['lineH'];
    foreach ($lines as $line) {
        $val   = ($line === '') ? '&nbsp;' : e($line);
        $html .= '  <div class="hls ps22" style="line-height:4.20mm;white-space:nowrap;left:0mm;top:'
               . number_format($top, 2, '.', '')
               . 'mm;height:' . number_format($chosenLayout['boxH'], 2, '.', '') . 'mm;width:82.51mm;"><span class="hrt cs23" style="font-size:' . number_format($chosenLayout['fontSize'], 1, '.', '') . 'pt;">'
               . $val . '</span></div>' . "\n";

        $top += $chosenLayout['lineH'];
    }

    $html .= "</div>\n";

    if ($heading !== '' && $heading !== '♣ 중대재해 전파') {
        $html = str_replace('♣ 중대재해 전파', e($heading), $html);
    }

    return $html;
}

function tbm_build_quiz_block(string $quiz1, string $quiz2, string $quiz3): string
{
    $quizzes = [$quiz1, $quiz2, $quiz3];
    $layouts = [
        ['maxUnits' => 29.5, 'lineH' => 4.90, 'boxH' => 4.00, 'fontSize' => 9.0, 'blockGap' => 4.0, 'availableHeight' => 172.0],
        ['maxUnits' => 30.3, 'lineH' => 4.75, 'boxH' => 3.90, 'fontSize' => 8.9, 'blockGap' => 3.8, 'availableHeight' => 172.0],
        ['maxUnits' => 31.2, 'lineH' => 4.55, 'boxH' => 3.70, 'fontSize' => 8.6, 'blockGap' => 3.2, 'availableHeight' => 172.0],
        ['maxUnits' => 32.0, 'lineH' => 4.38, 'boxH' => 3.58, 'fontSize' => 8.4, 'blockGap' => 3.0, 'availableHeight' => 172.0],
        ['maxUnits' => 32.8, 'lineH' => 4.20, 'boxH' => 3.45, 'fontSize' => 8.2, 'blockGap' => 2.6, 'availableHeight' => 172.0],
        ['maxUnits' => 33.6, 'lineH' => 4.05, 'boxH' => 3.32, 'fontSize' => 8.0, 'blockGap' => 2.4, 'availableHeight' => 172.0],
        ['maxUnits' => 34.4, 'lineH' => 3.90, 'boxH' => 3.20, 'fontSize' => 7.8, 'blockGap' => 2.0, 'availableHeight' => 172.0],
        ['maxUnits' => 35.2, 'lineH' => 3.78, 'boxH' => 3.10, 'fontSize' => 7.7, 'blockGap' => 1.9, 'availableHeight' => 172.0],
        ['maxUnits' => 36.0, 'lineH' => 3.68, 'boxH' => 3.02, 'fontSize' => 7.6, 'blockGap' => 1.8, 'availableHeight' => 172.0],
        ['maxUnits' => 36.8, 'lineH' => 3.58, 'boxH' => 2.94, 'fontSize' => 7.5, 'blockGap' => 1.7, 'availableHeight' => 172.0],
        ['maxUnits' => 37.6, 'lineH' => 3.48, 'boxH' => 2.86, 'fontSize' => 7.4, 'blockGap' => 1.6, 'availableHeight' => 172.0],
        ['maxUnits' => 38.4, 'lineH' => 3.40, 'boxH' => 2.80, 'fontSize' => 7.3, 'blockGap' => 1.5, 'availableHeight' => 172.0],
        ['maxUnits' => 39.2, 'lineH' => 3.32, 'boxH' => 2.74, 'fontSize' => 7.2, 'blockGap' => 1.4, 'availableHeight' => 172.0],
    ];

    $chosenLayout = $layouts[count($layouts) - 1];
    $groups = [];
    foreach ($layouts as $layout) {
        $candidateGroups = tbm_prepare_quiz_groups($quizzes, $layout['maxUnits']);
        $lineCount = 0;
        foreach ($candidateGroups as $group) {
            $lineCount += count($group);
        }
        $height = ($lineCount * $layout['lineH']) + (max(0, count($candidateGroups) - 1) * $layout['blockGap']);
        if ($height <= $layout['availableHeight']) {
            $chosenLayout = $layout;
            $groups = $candidateGroups;
            break;
        }
        $chosenLayout = $layout;
        $groups = $candidateGroups;
    }

    $html = '<div style="position:absolute; left:90.51mm; top:0mm; width:79.80mm; overflow:visible;">';
    $html .= '<div style="margin:0 0 2.4mm 0; font-size:9pt; color:#ff0000; font-family:\'맑은 고딕\'; font-weight:bold; letter-spacing:-0.01em; line-height:1.1; text-align:left;">♣ 오늘의 안전 퀴즈</div>';

    foreach ($groups as $groupIndex => $groupLines) {
        $groupMargin = $groupIndex < count($groups) - 1
            ? number_format($chosenLayout['blockGap'], 2, '.', '') . 'mm'
            : '0';

        $quizText = tbm_normalize_quiz_display_text((string)($quizzes[$groupIndex] ?? ''));
        $quizParts = tbm_split_quiz_display_parts($quizText);

          $html .= '<div style="display:block; margin:0 0 ' . $groupMargin . ' 0; padding:0 0.8mm 0 0; width:81.20mm; box-sizing:border-box; font-size:' . number_format($chosenLayout['fontSize'], 1, '.', '') . 'pt; line-height:' . number_format($chosenLayout['lineH'], 2, '.', '') . 'mm; color:#000000; font-family:\'맑은 고딕\'; letter-spacing:0; text-align:justify; text-justify:inter-character; white-space:pre-line; word-break:normal; overflow-wrap:anywhere;">'
              . '<div style="margin:0; padding:0; white-space:normal; word-break:normal; overflow-wrap:anywhere; text-align:justify; text-justify:inter-character;">' . e($quizParts['question']) . '</div>';

        foreach ($quizParts['choices'] as $choiceIndex => $choiceText) {
            $choiceMarginTop = $choiceIndex === 0 ? '1.00mm' : '0.80mm';
            $html .= '<div style="margin:' . $choiceMarginTop . ' 0 0 0; padding:0 0 0 4.8mm; text-indent:-4.8mm; white-space:normal; word-break:normal; overflow-wrap:anywhere; text-align:justify; text-justify:inter-character;">'
                  . e($choiceText)
                  . '</div>';
        }

        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}

// ── 메인 렌더 함수 ────────────────────────────────────────────

function tbm_render_template(array $data): string
{
    $templatePath = __DIR__ . '/template/TBM_template.html';

    if (!is_file($templatePath)) {
        throw new RuntimeException('템플릿 파일이 없습니다: ' . $templatePath);
    }

    $html = file_get_contents($templatePath);
    if ($html === false) {
        throw new RuntimeException('템플릿을 읽을 수 없습니다: ' . $templatePath);
    }

    $dateLine = tbm_date_line($data['doc_date']);

    $replace = [
        '{{TBM_DATE_FRONT}}'      => $dateLine,
        '{{TBM_DATE_BACK}}'       => $dateLine,
        '{{EDU_TITLE}}'           => e($data['edu_title'] ?? ''),
        '{{DYNAMIC_FRONT_TABLES}}'=> tbm_build_dynamic_tables($data), // 동적 테이블 삽입
        '{{EDU_BODY_BLOCK}}'      => tbm_build_edu_body_block(
            tbm_trim_body($data['left_content'] ?? ''),
            tbm_build_edu_body_heading($data)
        ),
        '{{ARTICLE_IMAGE_URL}}'   => tbm_build_article_image_url($data['image_file'] ?? ''),
        '{{ARTICLE_IMAGE_BLOCK}}' => tbm_build_article_image_block($data['image_file'] ?? ''),
        '{{QR_BLOCK}}'            => tbm_build_qr_block($data['source_url'] ?? ''),
        '{{QUIZ_BLOCK}}'          => tbm_build_quiz_block($data['quiz_1'] ?? '', $data['quiz_2'] ?? '', $data['quiz_3'] ?? ''),
        '{{INSTRUCTOR_NAME}}'     => e($data['instructor_name']     ?? ''),
        '{{INSTRUCTOR_POSITION}}' => e($data['instructor_position']  ?? ''),
    ];

    return strtr($html, $replace);
}

function tbm_wrap_lines(string $text, int $maxChars): array
{
    $text = str_replace("\r\n", "\n", trim($text));
    if ($text === '') return [];

    $paragraphs = explode("\n", $text);
    $lines      = [];

    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') { $lines[] = ''; continue; }

        $current = '';
        $chars   = preg_split('//u', $paragraph, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars as $char) {
            $candidate = $current . $char;
            if (mb_strlen($candidate, 'UTF-8') > $maxChars) {
                $lines[] = $current;
                $current = $char;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') $lines[] = $current;
    }
    return $lines;
}
