<?php
/**
 * 기상청 날씨 프록시 (단기예보 1~3일 + 중기예보 4~7일)
 * 1시간 파일 캐시 적용
 *
 * 디버그: weather.php?debug=1 로 원인 확인 가능
 */
$isDebug = isset($_GET['debug']) && $_GET['debug'] === '1';
if (!$isDebug) {
    header('Content-Type: application/json; charset=utf-8');
}

// ─── 설정 ────────────────────────────────────────────────────────────────────
define('API_KEY',      'dXiidlDJTeC4onZQyQ3gDQ');
define('NX',           95);
define('NY',           129);
define('MID_LAND_REG', '11D20000');   // 강원 영동 중기육상예보
define('MID_TA_REG',   '11D20501');   // 강릉 중기기온예보

$cacheFile = __DIR__ . '/weather_cache.json';

// ─── 캐시 반환 (디버그 모드에서는 캐시 무시) ─────────────────────────────────
if (!$isDebug && file_exists($cacheFile) && time() - filemtime($cacheFile) < 3600) {
    $cached = file_get_contents($cacheFile);
    if ($cached !== false && $cached !== '') {
        echo $cached;
        exit;
    }
}

// ─── 시간 계산 ───────────────────────────────────────────────────────────────
$tz  = new DateTimeZone('Asia/Seoul');
$now = new DateTime('now', $tz);
$hh  = (int)$now->format('H');
$mm  = (int)$now->format('i');
$curMin = $hh * 60 + $mm;

// 단기예보 발표시각 (+10분 이후 사용 가능)
$slots = [
    [2 * 60 + 10, '0200'], [5 * 60 + 10, '0500'], [8 * 60 + 10,  '0800'],
    [11 * 60 + 10, '1100'], [14 * 60 + 10, '1400'], [17 * 60 + 10, '1700'],
    [20 * 60 + 10, '2000'], [23 * 60 + 10, '2300'],
];
$shortBaseDate = $now->format('Ymd');
$shortBaseTime = '2300';
$usePrevDay    = true;

foreach (array_reverse($slots) as $slot) {
    if ($curMin >= $slot[0]) {
        $shortBaseTime = $slot[1];
        $usePrevDay    = false;
        break;
    }
}
if ($usePrevDay) {
    $prev = clone $now;
    $prev->modify('-1 day');
    $shortBaseDate = $prev->format('Ymd');
}

// 중기예보 발표시각 (06:10, 18:10 이후 사용 가능)
if ($curMin < 6 * 60 + 10) {
    $midDay = clone $now;
    $midDay->modify('-1 day');
    $tmFc = $midDay->format('Ymd') . '1800';
} elseif ($curMin < 18 * 60 + 10) {
    $tmFc = $now->format('Ymd') . '0600';
} else {
    $tmFc = $now->format('Ymd') . '1800';
}
$midFcstBaseDate = new DateTime(substr($tmFc, 0, 8), $tz);

// ─── API 호출 헬퍼 (curl 우선, 실패 시 file_get_contents) ────────────────────
function kmaFetch(string $url): array
{
    $body  = false;
    $error = '';

    // curl 시도
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        unset($ch);

        if ($body === false || $curlErr) {
            $error = "curl error: {$curlErr}";
            $body  = false;
        } elseif ($httpCode !== 200) {
            $error = "HTTP {$httpCode}";
        }
    }

    // file_get_contents 폴백
    if ($body === false && ini_get('allow_url_fopen')) {
        $ctx  = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            $error .= ' / file_get_contents failed';
        }
    }

    if ($body === false) {
        return ['_error' => $error ?: 'fetch failed', '_url' => $url, '_raw' => '', '_data' => null];
    }

    $raw  = substr($body, 0, 600);
    $json = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['_error' => 'json_decode: ' . json_last_error_msg(), '_url' => $url, '_raw' => $raw, '_data' => null];
    }

    // 기상청 API 허브 오류 형식: {"result":{"status":403,"message":"..."}}
    if (isset($json['result']['status']) && $json['result']['status'] !== 200) {
        $msg = $json['result']['message'] ?? '';
        return ['_error' => "HTTP {$json['result']['status']}: {$msg}", '_url' => $url, '_raw' => $raw, '_data' => null];
    }

    // data.go.kr 형식 오류 코드 확인
    $resultCode = $json['response']['header']['resultCode'] ?? ($json['header']['resultCode'] ?? '');
    $resultMsg  = $json['response']['header']['resultMsg'] ?? ($json['header']['resultMsg'] ?? '');
    if ($resultCode !== '' && $resultCode !== '00' && $resultCode !== '0000') {
        return ['_error' => "API {$resultCode}: {$resultMsg}", '_url' => $url, '_raw' => $raw, '_data' => $json];
    }

    return ['_error' => null, '_url' => $url, '_raw' => $raw, '_data' => $json];
}

$KEY  = urlencode(API_KEY);
$BASE = 'https://apihub.kma.go.kr/api/typ02/openApi';

$shortUrl   = "{$BASE}/VilageFcstInfoService_2.0/getVilageFcst"
            . "?authKey={$KEY}&numOfRows=1000&pageNo=1&dataType=JSON"
            . "&base_date={$shortBaseDate}&base_time={$shortBaseTime}"
            . "&nx=" . NX . "&ny=" . NY;

$midLandUrl = "{$BASE}/MidFcstInfoService/getMidLandFcst"
            . "?authKey={$KEY}&numOfRows=10&pageNo=1&dataType=JSON"
            . "&regId=" . MID_LAND_REG . "&tmFc={$tmFc}";

$midTaUrl   = "{$BASE}/MidFcstInfoService/getMidTa"
            . "?authKey={$KEY}&numOfRows=10&pageNo=1&dataType=JSON"
            . "&regId=" . MID_TA_REG . "&tmFc={$tmFc}";

$shortResult   = kmaFetch($shortUrl);
$midLandResult = kmaFetch($midLandUrl);
$midTaResult   = kmaFetch($midTaUrl);

// ─── 디버그 출력 ──────────────────────────────────────────────────────────────
if ($isDebug) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="font-size:13px">';
    echo "=== 시간 계산 ===\n";
    echo "현재 시각: " . $now->format('Y-m-d H:i') . "\n";
    echo "단기예보 base_date={$shortBaseDate}, base_time={$shortBaseTime}\n";
    echo "중기예보 tmFc={$tmFc}\n\n";

    foreach ([
        ['단기예보',     $shortUrl,   $shortResult],
        ['중기육상예보', $midLandUrl, $midLandResult],
        ['중기기온예보', $midTaUrl,   $midTaResult],
    ] as [$label, $url, $res]) {
        echo "\n=== {$label} URL ===\n" . htmlspecialchars($url) . "\n\n";
        echo "오류: " . ($res['_error'] ?? '없음') . "\n";
        echo "원본 응답(600자): " . htmlspecialchars($res['_raw'] ?? '') . "\n";

        if ($res['_data']) {
            $items = $res['_data']['response']['body']['items']['item'] ?? [];
            if (is_array($items)) {
                echo "수신 항목 수: " . count($items) . "\n";
                $first = is_array($items) ? ($items[0] ?? $items) : [];
                $show = array_intersect_key((array)$first, array_flip(
                    ['wf3Am','wf3Pm','rnSt3Am','rnSt3Pm','taMin3','taMax3','fcstDate','category']
                ));
                if ($show) { echo "샘플: " . json_encode($show, JSON_UNESCAPED_UNICODE) . "\n"; }
            }
        }
    }

    echo '</pre>';
    exit;
}

// ─── 단기예보 파싱 ───────────────────────────────────────────────────────────
function parseShort(?array $raw): array
{
    $byDate = [];
    $items  = $raw['response']['body']['items']['item'] ?? [];
    if (!is_array($items)) {
        return [];
    }

    foreach ($items as $it) {
        $d   = $it['fcstDate']  ?? '';
        $t   = $it['fcstTime']  ?? '';
        $cat = $it['category']  ?? '';
        $val = $it['fcstValue'] ?? '';
        if (!$d) {
            continue;
        }
        if (!isset($byDate[$d])) {
            $byDate[$d] = ['tmpList' => []];
        }
        switch ($cat) {
            case 'SKY': if ($t === '1200') { $byDate[$d]['sky'] = (int)$val; } break;
            case 'PTY': if ($t === '1200') { $byDate[$d]['pty'] = (int)$val; } break;
            case 'TMN': $byDate[$d]['tMin'] = (float)$val; break;
            case 'TMX': $byDate[$d]['tMax'] = (float)$val; break;
            case 'POP': if ($t === '1200') { $byDate[$d]['pop'] = (int)$val; } break;
            case 'TMP': $byDate[$d]['tmpList'][] = (float)$val; break;
        }
    }

    $result = [];
    foreach ($byDate as $d => $info) {
        $tmpList = $info['tmpList'] ?? [];
        $tMin    = $info['tMin'] ?? (count($tmpList) ? min($tmpList) : null);
        $tMax    = $info['tMax'] ?? (count($tmpList) ? max($tmpList) : null);
        $result[$d] = [
            'sky'  => $info['sky'] ?? 1,
            'pty'  => $info['pty'] ?? 0,
            'tMin' => $tMin,
            'tMax' => $tMax,
            'pop'  => $info['pop'] ?? 0,
        ];
    }
    return $result;
}

function skyPtyToCode(int $sky, int $pty): string
{
    if ($pty === 1) { return 'RAIN'; }
    if ($pty === 2) { return 'SNOW_RAIN'; }
    if ($pty === 3) { return 'SNOW'; }
    if ($pty === 4) { return 'SHOWER'; }
    if ($sky === 4) { return 'CLOUDY'; }
    if ($sky === 3) { return 'MOSTLY_CLOUDY'; }
    return 'CLEAR';
}

function textToCode(string $text): string
{
    if (mb_strpos($text, '소나기')   !== false) { return 'SHOWER'; }
    if (mb_strpos($text, '눈')  !== false && mb_strpos($text, '비') !== false) { return 'SNOW_RAIN'; }
    if (mb_strpos($text, '눈')       !== false) { return 'SNOW'; }
    if (mb_strpos($text, '비')       !== false) { return 'RAIN'; }
    if (mb_strpos($text, '흐림')     !== false) { return 'CLOUDY'; }
    if (mb_strpos($text, '구름많')   !== false) { return 'MOSTLY_CLOUDY'; }
    if (mb_strpos($text, '구름조금') !== false) { return 'PARTLY_CLOUDY'; }
    return 'CLEAR';
}

// ─── 중기예보 파싱 ───────────────────────────────────────────────────────────
function parseMid(?array $landRaw, ?array $taRaw, DateTime $baseDate): array
{
    $landItem = $landRaw['response']['body']['items']['item'][0] ?? null;
    $taItem   = $taRaw['response']['body']['items']['item'][0]   ?? null;
    if (!$landItem) {
        return [];
    }

    $result = [];
    for ($d = 3; $d <= 7; $d++) {
        $dt = clone $baseDate;
        $dt->modify("+{$d} days");
        $dateKey = $dt->format('Ymd');

        $amWf   = (string)($landItem["wf{$d}Am"] ?? '');
        $pmWf   = (string)($landItem["wf{$d}Pm"] ?? $landItem["wf{$d}"] ?? '');
        $wfText = ($pmWf !== '') ? $pmWf : $amWf;

        $amPop = (int)($landItem["rnSt{$d}Am"] ?? 0);
        $pmPop = (int)($landItem["rnSt{$d}Pm"] ?? $landItem["rnSt{$d}"] ?? 0);

        $tMin = ($taItem && isset($taItem["taMin{$d}"])) ? (float)$taItem["taMin{$d}"] : null;
        $tMax = ($taItem && isset($taItem["taMax{$d}"])) ? (float)$taItem["taMax{$d}"] : null;

        $result[$dateKey] = [
            'code'   => textToCode($wfText),
            'tMin'   => $tMin,
            'tMax'   => $tMax,
            'pop'    => max($amPop, $pmPop),
            'source' => 'mid',
        ];
    }
    return $result;
}

// ─── 결과 합치기 ─────────────────────────────────────────────────────────────
$shortParsed = parseShort($shortResult['_data']);
$midParsed   = parseMid($midLandResult['_data'], $midTaResult['_data'], $midFcstBaseDate);

$weather = [];

foreach ($shortParsed as $d => $info) {
    $dateKey = substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
    $weather[$dateKey] = [
        'code'   => skyPtyToCode($info['sky'], $info['pty']),
        'tMin'   => $info['tMin'],
        'tMax'   => $info['tMax'],
        'pop'    => $info['pop'],
        'source' => 'short',
    ];
}

foreach ($midParsed as $d => $info) {
    $dateKey = substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
    if (!isset($weather[$dateKey])) {
        $weather[$dateKey] = $info;
    }
}

$output = json_encode(['weather' => $weather], JSON_UNESCAPED_UNICODE);

// 데이터가 있을 때만 캐시 저장
if (!empty($weather)) {
    @file_put_contents($cacheFile, $output);
}

echo $output;
