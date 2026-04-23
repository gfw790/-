<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('CSI_COOKIE_JAR', sys_get_temp_dir() . '/csi_cookie.txt');
const CSI_BASE = 'https://www.csi.go.kr';

$_CURL_OPTS = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_COOKIEJAR      => CSI_COOKIE_JAR,
    CURLOPT_COOKIEFILE     => CSI_COOKIE_JAR,
    CURLOPT_ENCODING       => 'gzip, deflate, br',
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
];

function csi_headers($referer) {
    return [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8',
        'Cache-Control: no-cache',
        'Connection: keep-alive',
        "Referer: {$referer}",
        'Upgrade-Insecure-Requests: 1',
    ];
}

function csi_request($url, $postFields = null, $referer = null) {
    global $_CURL_OPTS;
    if ($referer === null) $referer = CSI_BASE . '/';
    $ch = curl_init($url);
    $opts = $_CURL_OPTS + [CURLOPT_HTTPHEADER => csi_headers($referer)];
    if ($postFields !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($postFields);
        $opts[CURLOPT_HTTPHEADER] = [...csi_headers($referer), 'Content-Type: application/x-www-form-urlencoded'];
    }
    curl_setopt_array($ch, $opts);
    $res   = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    if ($errno) return ['html' => false, 'error' => "cURL {$errno}: {$error}"];
    if ($code >= 400) return ['html' => false, 'error' => "HTTP {$code}"];
    return ['html' => $res, 'error' => null];
}

// td-head 레이블 다음 td 값 추출
function csi_td($html, $label) {
    if (preg_match('/<td[^>]*>\s*' . preg_quote($label, '/') . '\s*<\/td>\s*<td[^>]*>(.*?)<\/td>/si', $html, $m)) {
        return trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'))));
    }
    return '';
}

function csi_do_search($q, $maxPages = 3, $detailLimit = 15) {
    if ($q === '') return ['items' => []];

    // 세션 쿠키 취득
    csi_request(CSI_BASE . '/');

    $rowPattern = '/<td>\s*<a href="javascript:goDetail\(\'(\d+)\'\)">([\d]+)<\/a>\s*<\/td>\s*<td class="t-left">\s*<a href="javascript:goDetail\(\'\d+\'\)">(.*?)<\/a>\s*<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>/s';
    $allMatches = [];

    for ($page = 1; $page <= $maxPages; $page++) {
        $r = csi_request(
            CSI_BASE . '/acd/acdCaseList.do',
            ['biz_id' => 'acdDB', 'page_count' => (string)$page, 'search_key' => '1', 'search_val' => $q, 'search_dead_yn' => ''],
            CSI_BASE . '/acd/acdCaseList.do'
        );
        if ($r['html'] === false || trim((string)$r['html']) === '') {
            if ($page === 1) return ['items' => [], 'error' => '검색 실패(cURL): ' . $r['error']];
            break;
        }
        if ($page === 1) file_put_contents(__DIR__ . '/debug_csi.html', $r['html']);
        if (!preg_match_all($rowPattern, $r['html'], $matches, PREG_SET_ORDER)) break;
        $allMatches = array_merge($allMatches, $matches);
        // 페이지에 결과가 없거나 10건 미만이면 마지막 페이지
        if (count($matches) < 10) break;
    }

    if (empty($allMatches)) return ['items' => []];

    $items = [];
    foreach ($allMatches as $idx => $m) {
        $caseNo     = $m[1];
        $accidentNo = trim($m[2]);
        $title      = trim(strip_tags($m[3]));
        $location   = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[4]), ENT_QUOTES, 'UTF-8')));
        $date       = trim(strip_tags($m[5]));

        // 상세 페이지: 처음 $detailLimit 건만 가져옴 (속도 제한)
        $cause = $circumstances = $prevention = '';
        if ($idx < $detailLimit) {
            $dr = csi_request(
                CSI_BASE . '/acd/acdCaseView.do',
                ['biz_id' => 'acdDB', 'case_no' => $caseNo],
                CSI_BASE . '/acd/acdCaseList.do'
            );
            if ($dr['html'] !== false) {
                $circumstances = csi_td($dr['html'], '사고경위');
                $cause         = csi_td($dr['html'], '구체적 사고원인');
                $prevention    = csi_td($dr['html'], '재발방지대책');
            }
        }

        $items[] = [
            'title'         => $title,
            'url'           => CSI_BASE . '/acd/acdCaseView.do?case_no=' . $caseNo,
            'accident_no'   => $accidentNo,
            'accident_date' => $date,
            'accident_place'=> $location,
            'accident_situation' => implode(' ', array_filter([$circumstances, $cause])),
            'circumstances' => $circumstances,
            'cause'         => $cause,
            'prevention'    => $prevention,
        ];
    }

    return ['items' => $items];
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header('Content-Type: application/json; charset=UTF-8');
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    echo json_encode(csi_do_search($q));
}
