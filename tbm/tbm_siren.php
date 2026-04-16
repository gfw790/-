<?php

declare(strict_types=1);

/**
 * tbm_siren.php
 *
 * KOSHA 중대재해 사이렌 최근 게시물 수집 + 이미지 OCR 통합 모듈
 *
 * 핵심 추출 대상:
 * - 사고개요(summary)
 * - 예방대책 내용(prevention)
 *
 * 사용 예:
 *   require_once __DIR__ . '/tbm_siren.php';
 *   $items = tbm_siren_get_recent_items(10, true, [
 *       'tesseract_path' => 'A:\\Tesseract-OCR\\tesseract.exe',
 *       'python_path'    => 'python',
 *       'easyocr_script' => __DIR__ . '/ocr_easy.py',
 *   ]);
 *
 * ── 2026-03 API 구조 확정 (DevTools 직접 확인) ──────────────────
 * 엔드포인트 : POST https://portal.kosha.or.kr/api/portal24/bizC/p/CSADV50000/selectImprtnDsstrSirnList
 * Content-Type: application/json
 * 요청 body  : {"crtrDate":"","page":1,"pstSeCd":"","resultPageTotalCnt":0,"rowsPerPage":8}
 *
 * 응답 구조 (DevTools 최종 확인):
 *   {
 *     "tp_code": 200,
 *     "sponse": {
 *       "result": "success",
 *       "message": "중대재해 사이렌 목록 데이터가 조회되었습니다.",
 *       "payload": {
 *         "imprtnDsstrSirnList": [
 *         {
 *           "rnum"                      : 1,
 *           "imprtnDsstrSirnNo"         : 691,
 *           "imprtnDsstrSirnNm"         : "(260327)중대재해 발생 알림(1)",
 *           "inqCnt"                    : 181,
 *           "atcflSrvrStrgDtlPathAddr"  : "/k2b24/file/C/CSA/CSAD/2026/3/27",
 *           "atcflSrvrFileNm"           : "4ca57fd7-...jpg",
 *           "frstRegDt"                 : "2026.03.27",
 *           "thmbAtcflNo"               : "CSA2026032717072227302801",
 *           "atcflNo"                   : null,
 *           "atcflNoCnt"                : 0,
 *           "innerRnum"                 : 1,
 *           "totalCount"                : 687,
 *           "imgSrc"                    : "data:image/jpg;base64,..."
 *         }
 *       ],
 *       "totalCount": 687
 *       }
 *     }
 *   }
 */

// ── 포털 페이지 URL (Referer / 폴백 HTML 스크래핑용) ──────────────
if (!defined('TBM_SIREN_LIST_URL')) {
    define('TBM_SIREN_LIST_URL', 'https://portal.kosha.or.kr/archive/imprtnDsstrAlrame/CSADV50000/CSADV50000M01');
}
if (!defined('TBM_SIREN_LEGACY_LIST_URL')) {
    define('TBM_SIREN_LEGACY_LIST_URL', 'https://labor.moel.go.kr/sasttc/cmmt/bbs_srn_list.do?seCdVal=B1');
}
if (!defined('TBM_SIREN_HTTP_TIMEOUT')) {
    define('TBM_SIREN_HTTP_TIMEOUT', 20);
}
if (!defined('TBM_SIREN_CSI_LIST_URL')) {
    define('TBM_SIREN_CSI_LIST_URL', 'https://www.csi.go.kr/acd/acdCaseList.do');
}

// ── KOSHA API 엔드포인트 (DevTools 직접 확인값) ───────────────────
if (!defined('TBM_SIREN_API_LIST_URL')) {
    define('TBM_SIREN_API_LIST_URL',
        'https://portal.kosha.or.kr/api/portal24/bizC/p/CSADV50000/selectImprtnDsstrSirnList');
}
// 상세 엔드포인트 (동일 패턴으로 추정 — 추후 DevTools 확인 권장)
if (!defined('TBM_SIREN_API_DETAIL_URL')) {
    define('TBM_SIREN_API_DETAIL_URL',
        'https://portal.kosha.or.kr/api/portal24/bizC/p/CSADV50000/selectImprtnDsstrSirnDetail');
}

// ─────────────────────────────────────────────────────────────────
// 공통 HTTP 유틸
// ─────────────────────────────────────────────────────────────────

function tbm_siren_fetch_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => TBM_SIREN_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
        ],
    ]);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $err !== '' || $httpCode >= 400) {
        return '';
    }

    return is_string($response) ? $response : '';
}

// ─────────────────────────────────────────────────────────────────
// [deprecated] HTML 스크래핑 래퍼 — 하위 호환용
// ─────────────────────────────────────────────────────────────────

function tbm_siren_fetch_list_html(): string
{
    $apiResult = tbm_siren_fetch_api_list();
    if (!empty($apiResult)) {
        $encoded = json_encode($apiResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '';
    }

    $html = tbm_siren_fetch_url(TBM_SIREN_LIST_URL);
    if (trim($html) !== '') {
        return $html;
    }
    return tbm_siren_fetch_url(TBM_SIREN_LEGACY_LIST_URL);
}

// ─────────────────────────────────────────────────────────────────
// KOSHA API 직접 호출
// ─────────────────────────────────────────────────────────────────

/**
 * KOSHA 포털 중대재해 사이렌 목록을 JSON API POST 방식으로 호출한다.
 *
 * - Content-Type: application/json  (form-urlencoded 아님 — 405/415 오류 방지)
 * - 응답: result="success" / payload.imprtnDsstrSirnList 배열
 * - imgSrc 필드에 base64 인코딩 이미지가 포함됨 (URL 아님)
 *
 * @param  int $pageIndex  1-based 페이지 번호
 * @param  int $pageSize   한 페이지 항목 수 (기본 8, 최대 30)
 * @return array 정규화된 아이템 배열. 실패 시 빈 배열.
 */
function tbm_siren_fetch_api_list(int $pageIndex = 1, int $pageSize = 8): array
{
    $pageIndex = max(1, $pageIndex);
    $pageSize  = max(1, min(30, $pageSize));

    // ── JSON body (application/json) ─────────────────────────────
    $body = json_encode([
        'crtrDate'           => '',
        'page'               => $pageIndex,
        'pstSeCd'            => '',
        'resultPageTotalCnt' => 0,
        'rowsPerPage'        => $pageSize,
    ], JSON_UNESCAPED_UNICODE);

    if ($body === false) {
        return [];
    }

    $ch = curl_init(TBM_SIREN_API_LIST_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => TBM_SIREN_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7',
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . TBM_SIREN_LIST_URL,
            'Origin: https://portal.kosha.or.kr',
            'Cache-Control: no-cache',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlErr !== '' || $httpCode >= 400 || !is_string($response)) {
        error_log('[TBM SIREN] API POST 실패 — HTTP ' . $httpCode . ' / cURL: ' . $curlErr);
        return [];
    }

    $raw = trim($response);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_log('[TBM SIREN] API 응답이 JSON이 아님. 앞 200자: ' . mb_substr($raw, 0, 200, 'UTF-8'));
        return [];
    }

    // ── 실제 확인된 최상위 구조: $decoded['sponse'] 안에 내용이 있음 ──
    // { "tp_code": 200, "sponse": { "result": "success", "payload": { ... } } }
    $sponse = $decoded['sponse'] ?? $decoded;   // sponse 없으면 최상위 직접 사용

    // ── 결과 코드 확인 (실제 확인값: sponse.result = "success") ──
    $result = (string)($sponse['result'] ?? '');
    if ($result !== 'success' && $result !== '') {
        error_log('[TBM SIREN] API result 오류: ' . $result . ' / message: ' . ($sponse['message'] ?? ''));
        return [];
    }

    // ── 목록 추출 (실제 확인값: sponse.payload.imprtnDsstrSirnList) ─
    $list = $sponse['payload']['imprtnDsstrSirnList'] ?? null;

    // 폴백: 구버전 또는 구조 변경 대비
    if (!is_array($list)) {
        foreach (['list', 'resultList', 'data', 'items', 'rows'] as $key) {
            if (isset($sponse[$key]) && is_array($sponse[$key])) {
                $list = $sponse[$key];
                break;
            }
            if (isset($sponse['payload'][$key]) && is_array($sponse['payload'][$key])) {
                $list = $sponse['payload'][$key];
                break;
            }
        }
    }

    if (!is_array($list)) {
        error_log('[TBM SIREN] API 응답에서 목록을 찾지 못함. 최상위 키: ' . implode(', ', array_keys($decoded)));
        return [];
    }

    // ── 각 row 정규화 ─────────────────────────────────────────────
    $items = [];
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        $item = tbm_siren_normalize_api_item($row);
        if ($item['title'] !== '' || $item['detail_url'] !== '') {
            $items[] = $item;
        }
    }

    return $items;
}

// ─────────────────────────────────────────────────────────────────
// API row 정규화
// ─────────────────────────────────────────────────────────────────

/**
 * API 응답 row 1건을 내부 표준 구조로 변환한다.
 *
 * 실제 확인된 row 예시 (2026-03-29 DevTools):
 *   {
 *     "rnum"                     : 1,
 *     "imprtnDsstrSirnNo"        : 691,
 *     "imprtnDsstrSirnNm"        : "(260327)중대재해 발생 알림(1)",
 *     "inqCnt"                   : 181,
 *     "atcflSrvrStrgDtlPathAddr" : "/k2b24/file/C/CSA/CSAD/2026/3/27",
 *     "atcflSrvrFileNm"          : "4ca57fd7-...jpg",
 *     "frstRegDt"                : "2026.03.27",
 *     "thmbAtcflNo"              : "CSA2026032717072227302801",
 *     "atcflNo"                  : null,
 *     "atcflNoCnt"               : 0,
 *     "innerRnum"                : 1,
 *     "totalCount"               : 687,
 *     "imgSrc"                   : "data:image/jpg;base64,..."
 *   }
 *
 * @param  array $row  API 응답 단일 레코드
 * @return array 내부 표준 아이템 구조
 */
function tbm_siren_normalize_api_item(array $row): array
{
    // ── 제목 ──────────────────────────────────────────────────────
    // 확정 키: imprtnDsstrSirnNm / 폴백 순서 유지
    $title = '';
    foreach (['imprtnDsstrSirnNm', 'nttSj', 'title', 'subject', 'ttl', 'sj', 'nttTitle', 'bbsSj'] as $k) {
        if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') {
            $title = tbm_siren_clean_text($row[$k]);
            break;
        }
    }

    // ── 날짜 ──────────────────────────────────────────────────────
    // 확정 키: frstRegDt (형식: "2026.03.27")
    $postedDate = '';
    foreach (['frstRegDt', 'regDt', 'regDate', 'creatDt', 'wrtDt', 'registDt', 'date'] as $k) {
        if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') {
            $postedDate = tbm_siren_extract_date($row[$k]);
            if ($postedDate !== '') {
                break;
            }
        }
    }

    // ── 상세 URL ──────────────────────────────────────────────────
    // 확정 키: imprtnDsstrSirnNo (게시물 번호)
    $detailUrl = '';
    foreach (['detailUrl', 'detail_url', 'url', 'link', 'href'] as $k) {
        if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') {
            $detailUrl = tbm_siren_abs_url(TBM_SIREN_LIST_URL, trim($row[$k]));
            break;
        }
    }
    if ($detailUrl === '') {
        $sirnNo = trim((string)($row['imprtnDsstrSirnNo'] ?? $row['nttId'] ?? $row['ntcId'] ?? $row['id'] ?? $row['seq'] ?? ''));
        if ($sirnNo !== '') {
            $detailUrl = TBM_SIREN_LIST_URL . '?imprtnDsstrSirnNo=' . rawurlencode($sirnNo);
        }
    }

    // ── 이미지 ────────────────────────────────────────────────────
    // 확정: imgSrc = "data:image/jpg;base64,..." (base64 통째로 포함)
    // 보조: atcflSrvrStrgDtlPathAddr + atcflSrvrFileNm 으로 URL 구성 가능
    $imageUrl    = '';
    $imageBase64 = '';

    // 1순위: imgSrc base64
    $imgSrc = trim((string)($row['imgSrc'] ?? ''));
    if (str_starts_with($imgSrc, 'data:image')) {
        $imageBase64 = $imgSrc;   // data URI 그대로 보존
    }

    // 2순위: 서버 경로 조합 URL
    if ($imageBase64 === '') {
        $pathAddr = trim((string)($row['atcflSrvrStrgDtlPathAddr'] ?? ''));
        $fileNm   = trim((string)($row['atcflSrvrFileNm'] ?? ''));
        if ($pathAddr !== '' && $fileNm !== '') {
            $imageUrl = 'https://portal.kosha.or.kr' . rtrim($pathAddr, '/') . '/' . $fileNm;
        }
    }

    // 3순위: thmbAtcflNo 썸네일 (있으면 활용)
    if ($imageUrl === '' && $imageBase64 === '') {
        $thmbNo = trim((string)($row['thmbAtcflNo'] ?? ''));
        if ($thmbNo !== '') {
            $imageUrl = 'https://portal.kosha.or.kr/cmm/fms/getImage.do?atchFileId=' . rawurlencode($thmbNo) . '&fileSn=0';
        }
    }

    // ── 요약 / 예방대책 (API가 직접 텍스트를 제공하는 경우 대비) ──
    $summary = '';
    foreach (['nttCn', 'content', 'contents', 'summary', 'body', 'cn'] as $k) {
        if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') {
            $summary = tbm_siren_clean_text($row[$k]);
            break;
        }
    }

    $prevention = '';
    foreach (['prevention', 'prevCn', 'prevContent', 'preventionCn'] as $k) {
        if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') {
            $prevention = tbm_siren_clean_text($row[$k]);
            break;
        }
    }

    return [
        'title'           => $title,
        'posted_date'     => $postedDate,
        'detail_url'      => $detailUrl,
        'image_url'       => $imageUrl,       // 서버 경로 조합 URL (있을 때)
        'image_base64'    => $imageBase64,    // data:image/jpg;base64,... (있을 때)
        'summary'         => $summary,
        'prevention'      => $prevention,
        'search_keywords' => tbm_siren_build_queries_from_title($title),
        '_raw_api'        => $row,            // 디버그용 원본 (하위 소비자에서 제거)
    ];
}

// ─────────────────────────────────────────────────────────────────
// API 결과 → 내부 아이템 배열 변환
// ─────────────────────────────────────────────────────────────────

function tbm_siren_parse_recent_items_from_api(array $apiRows, int $limit = 10): array
{
    $limit = max(1, min(30, $limit));
    $items = [];
    $seen  = [];

    foreach ($apiRows as $row) {
        if (isset($row['title']) && isset($row['detail_url'])) {
            $item = $row;
        } else {
            $item = tbm_siren_normalize_api_item($row);
        }

        if ($item['title'] === '' && $item['detail_url'] === '') {
            continue;
        }

        $key = md5(mb_strtolower($item['title'] . '|' . $item['detail_url'], 'UTF-8'));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        unset($item['_raw_api']);
        $items[] = $item;

        if (count($items) >= $limit) {
            break;
        }
    }

    return $items;
}

// ─────────────────────────────────────────────────────────────────
// 텍스트 유틸
// ─────────────────────────────────────────────────────────────────

function tbm_siren_clean_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\x{00A0}/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text);
}

function tbm_siren_abs_url(string $baseUrl, string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }
    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    $parts = parse_url($baseUrl);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }

    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }
    if (str_starts_with($url, '/')) {
        return $origin . $url;
    }

    $path = $parts['path'] ?? '/';
    $dir  = preg_replace('~/[^/]*$~', '/', $path);
    return $origin . $dir . $url;
}

function tbm_siren_extract_date(string $text): string
{
    $text = tbm_siren_clean_text($text);

    // "2026.03.27" 형식 (KOSHA 실제 확인값)
    if (preg_match('/\b(20\d{2})[.\-\/](\d{1,2})[.\-\/](\d{1,2})\b/u', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/\b(\d{2})[.\-\/](\d{1,2})[.\-\/](\d{1,2})\b/u', $text, $m)) {
        $year = (int)$m[1];
        $year += ($year >= 70 ? 1900 : 2000);
        return sprintf('%04d-%02d-%02d', $year, (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/\b(20\d{2})(\d{2})(\d{2})\b/u', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    return '';
}

function tbm_siren_is_useful_title(string $title): bool
{
    $title = tbm_siren_clean_text($title);
    if ($title === '' || mb_strlen($title, 'UTF-8') < 4) {
        return false;
    }

    $badPatterns = ['prev', 'next', '처음', '마지막', '다음', '이전', '목록', '검색', '조회', '페이지', '등록', '수정', '삭제'];
    $lower = mb_strtolower($title, 'UTF-8');
    foreach ($badPatterns as $bad) {
        if ($lower === mb_strtolower($bad, 'UTF-8')) {
            return false;
        }
    }
    return true;
}

function tbm_siren_build_queries_from_title(string $title): array
{
    $title = tbm_siren_clean_text($title);
    if ($title === '') {
        return [];
    }

    $clean = $title;
    $clean = preg_replace('/^\(?\d{6,8}\)?\s*/u', '', $clean);
    $clean = preg_replace('/^\[.*?\]\s*/u', '', $clean);
    $clean = preg_replace('/중대재해\s*발생\s*알림/iu', '', $clean);
    $clean = preg_replace('/중대재해\s*사이렌/iu', '', $clean);
    $clean = preg_replace('/\(\d+\)/u', '', $clean);
    $clean = trim((string)$clean, " \t\n\r\0\x0B-_:·[]()");

    $queries = [];
    if ($clean !== '') {
        $queries[] = $clean . ' 사고';
        $queries[] = $clean . ' 중대재해';
        $queries[] = $clean;
    }

    foreach (['감전', '추락', '떨어짐', '끼임', '화재', '폭발', '붕괴', '질식', '매몰', '깔림', '협착', '전도', '천공', '절단', '사망'] as $kw) {
        if (mb_strpos($title, $kw, 0, 'UTF-8') !== false) {
            $queries[] = $clean . ' ' . $kw . ' 사고';
            $queries[] = $kw . ' 중대재해';
        }
    }

    $queries[] = $title . ' 사고';
    $queries = array_values(array_unique(array_filter(array_map(static fn($v) => trim((string)$v), $queries))));
    return array_slice($queries, 0, 5);
}

// ─────────────────────────────────────────────────────────────────
// 상세 페이지 파싱 (HTML 폴백)
// ─────────────────────────────────────────────────────────────────

function tbm_siren_parse_detail(string $detailUrl): array
{
    $result = [
        'title'      => '',
        'image_url'  => '',
        'summary'    => '',
        'prevention' => '',
    ];

    $html = tbm_siren_fetch_url($detailUrl);
    if (trim($html) === '') {
        return $result;
    }

    if (preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m)) {
        $result['title'] = tbm_siren_clean_text($m[1]);
    }

    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](.*?)["\']/isu', $html, $m)
        || preg_match('/<img[^>]+src=["\'](.*?getImage\.do.*?)["\']/isu', $html, $m)) {
        $result['image_url'] = tbm_siren_abs_url($detailUrl, $m[1]);
    }

    $text = tbm_siren_clean_text($html);
    if ($text !== '') {
        if (preg_match('/사고\s*개요[:：]?\s*(.{20,300}?)(예방대책|재발방지|$)/u', $text, $m)) {
            $result['summary'] = trim($m[1]);
        }
        if (preg_match('/(예방대책|재발방지\s*대책)[:：]?\s*(.{20,300}?)(첨부|목록|$)/u', $text, $m)) {
            $result['prevention'] = trim($m[2]);
        }
    }

    return $result;
}

// ─────────────────────────────────────────────────────────────────
// HTML 파싱 (폴백)
// ─────────────────────────────────────────────────────────────────

function tbm_siren_parse_recent_items(string $html, int $limit = 10): array
{
    $html = trim($html);
    if ($html === '') {
        return [];
    }

    $firstChar = $html[0] ?? '';
    if ($firstChar === '[' || $firstChar === '{') {
        $decoded = json_decode($html, true);
        if (is_array($decoded)) {
            if (isset($decoded[0])) {
                return tbm_siren_parse_recent_items_from_api($decoded, $limit);
            }
            // 새 구조: payload.imprtnDsstrSirnList
            if (isset($decoded['payload']['imprtnDsstrSirnList']) && is_array($decoded['payload']['imprtnDsstrSirnList'])) {
                return tbm_siren_parse_recent_items_from_api($decoded['payload']['imprtnDsstrSirnList'], $limit);
            }
            foreach (['list', 'resultList', 'data', 'items', 'rows'] as $key) {
                if (isset($decoded[$key]) && is_array($decoded[$key])) {
                    return tbm_siren_parse_recent_items_from_api($decoded[$key], $limit);
                }
            }
            return tbm_siren_parse_recent_items_from_api([$decoded], $limit);
        }
    }

    // ── HTML DOMDocument 파싱 ─────────────────────────────────────
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    if (!$loaded) {
        return tbm_siren_parse_recent_items_by_regex($html, $limit);
    }

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//a[@href]');
    if (!$nodes instanceof DOMNodeList || $nodes->length === 0) {
        return tbm_siren_parse_recent_items_by_regex($html, $limit);
    }

    $items = [];
    $seen  = [];

    foreach ($nodes as $a) {
        $href  = trim((string)$a->getAttribute('href'));
        $title = tbm_siren_clean_text($a->textContent ?? '');
        if (!tbm_siren_is_useful_title($title)) {
            continue;
        }

        $hrefLower = mb_strtolower($href, 'UTF-8');
        $titleLooksRelevant = preg_match('/중대재해|사이렌|알림|사고|사망|추락|감전|끼임|화재/u', $title) === 1;
        $linkLooksRelevant  = preg_match('/bbs|board|view|detail|article|archive|CSADV|do\?/iu', $hrefLower) === 1;
        if (!$titleLooksRelevant && !$linkLooksRelevant) {
            continue;
        }

        $containerText = '';
        $parent = $a->parentNode;
        if ($parent instanceof DOMNode) {
            $containerText = tbm_siren_clean_text($parent->textContent ?? '');
            if ($parent->parentNode instanceof DOMNode) {
                $containerText .= ' ' . tbm_siren_clean_text($parent->parentNode->textContent ?? '');
            }
        }

        $postedDate = tbm_siren_extract_date($containerText);
        $detailUrl  = tbm_siren_abs_url(TBM_SIREN_LIST_URL, $href);
        $key        = md5(mb_strtolower($title . '|' . $detailUrl, 'UTF-8'));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $items[] = [
            'title'           => $title,
            'posted_date'     => $postedDate,
            'detail_url'      => $detailUrl,
            'image_url'       => '',
            'image_base64'    => '',
            'summary'         => '',
            'prevention'      => '',
            'search_keywords' => tbm_siren_build_queries_from_title($title),
        ];

        if (count($items) >= $limit) {
            break;
        }
    }

    return $items;
}

function tbm_siren_parse_recent_items_by_regex(string $html, int $limit = 10): array
{
    $items = [];
    $seen  = [];

    if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $href  = trim((string)$m[1]);
            $title = tbm_siren_clean_text((string)$m[2]);
            if (!tbm_siren_is_useful_title($title)) {
                continue;
            }
            if (preg_match('/중대재해|사이렌|알림|사고|사망|추락|감전|끼임|화재/u', $title) !== 1) {
                continue;
            }

            $detailUrl = tbm_siren_abs_url(TBM_SIREN_LIST_URL, $href);
            $key       = md5(mb_strtolower($title . '|' . $detailUrl, 'UTF-8'));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $items[] = [
                'title'           => $title,
                'posted_date'     => '',
                'detail_url'      => $detailUrl,
                'image_url'       => '',
                'image_base64'    => '',
                'summary'         => '',
                'prevention'      => '',
                'search_keywords' => tbm_siren_build_queries_from_title($title),
            ];

            if (count($items) >= $limit) {
                break;
            }
        }
    }

    return $items;
}

function tbm_siren_save_data_uri_image(string $dataUri, string $prefix = 'siren'): ?string
{
    $dataUri = trim($dataUri);
    if ($dataUri === '' || !str_starts_with($dataUri, 'data:image')) {
        return null;
    }

    if (!preg_match('~^data:image/([a-zA-Z0-9.+-]+);base64,(.+)$~s', $dataUri, $m)) {
        return null;
    }

    $ext = strtolower($m[1]);
    $base64 = trim($m[2]);

    $extMap = [
        'jpeg' => 'jpg',
        'jpg'  => 'jpg',
        'png'  => 'png',
        'webp' => 'webp',
        'gif'  => 'gif',
    ];
    $ext = $extMap[$ext] ?? 'jpg';

    $binary = base64_decode($base64, true);
    if ($binary === false || strlen($binary) < 1000) {
        return null;
    }

    $saveDir = __DIR__ . '/output/images';
    if (!is_dir($saveDir) && !mkdir($saveDir, 0777, true) && !is_dir($saveDir)) {
        return null;
    }

    $fileName = $prefix . '_' . substr(md5($binary), 0, 16) . '.' . $ext;
    $savePath = $saveDir . DIRECTORY_SEPARATOR . $fileName;

    // 동일 이미지가 이미 저장되어 있으면 재저장하지 않음
    if (is_file($savePath) && filesize($savePath) > 1000) {
        return 'output/images/' . $fileName;
    }

    if (file_put_contents($savePath, $binary) === false) {
        return null;
    }

    $info = @getimagesize($savePath);
    if (!$info) {
        @unlink($savePath);
        return null;
    }

    [$width, $height] = $info;
    if ($width < 120 || $height < 120) {
        @unlink($savePath);
        return null;
    }

    return 'output/images/' . $fileName;
}

// ─────────────────────────────────────────────────────────────────
// CSI 사고사례 폴백 (KOSHA 전체 실패 시)
// https://www.csi.go.kr/acd/acdCaseList.do
// ─────────────────────────────────────────────────────────────────

function tbm_siren_csi_fetch(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => TBM_SIREN_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8',
            'Referer: https://www.csi.go.kr/',
            'Cache-Control: no-cache',
        ],
    ]);

    $response = curl_exec($ch);
    $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($response === false || $err !== '' || $code >= 400 || !is_string($response)) {
        return '';
    }

    return $response;
}

/**
 * CSI 사고사례 목록 페이지 HTML을 파싱해 case_no, title, location, occurred_at 목록을 반환한다.
 */
function tbm_siren_csi_parse_list(string $html, int $limit = 10): array
{
    if ($html === '' || !extension_loaded('dom') || !class_exists('DOMDocument')) {
        return [];
    }

    $items = [];
    $seen  = [];

    // 1순위: caption 기반 정규식 (구조 고정적일 때 빠름)
    if (preg_match('/<caption>[^<]*사고사례[^<]*<\/caption>.*?<tbody>(.*?)<\/tbody>/isu', $html, $tm)) {
        preg_match_all(
            '/javascript\s*:\s*goDetail\s*\(\s*[\'"]?(\d+)[\'"]?\s*\)/iu',
            $tm[1],
            $caseMatches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($caseMatches[1] as [$caseNo, $offset]) {
            if (isset($seen[$caseNo])) {
                continue;
            }
            // 해당 offset 전후 ~300자에서 셀 텍스트 추출
            $snippet = substr($tm[1], max(0, $offset - 50), 500);
            preg_match_all('/<td[^>]*>(.*?)<\/td>/isu', $snippet, $cells);
            $cellTexts = array_map(
                static fn($c) => trim(strip_tags(html_entity_decode((string)$c, ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
                $cells[1] ?? []
            );

            // 빈 셀 제거
            $cellTexts = array_values(array_filter($cellTexts, static fn($t) => $t !== ''));

            $title      = $cellTexts[1] ?? ($cellTexts[0] ?? '');
            $location   = $cellTexts[2] ?? '';
            $occurredAt = $cellTexts[3] ?? '';

            if ($title === '' || $title === $caseNo) {
                $title = $cellTexts[0] ?? '';
            }

            if ($title === '') {
                continue;
            }

            $seen[$caseNo] = true;
            $items[] = [
                'case_no'     => $caseNo,
                'title'       => tbm_siren_clean_text($title),
                'location'    => tbm_siren_clean_text($location),
                'occurred_at' => tbm_siren_clean_text($occurredAt),
                'detail_url'  => 'https://www.csi.go.kr/acd/acdCaseView.do?case_no=' . rawurlencode($caseNo),
            ];

            if (count($items) >= $limit) {
                return $items;
            }
        }
    }

    if (!empty($items)) {
        return $items;
    }

    // 2순위: DOM 파싱 폴백
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . mb_substr($html, 0, 600000, 'UTF-8'));
    libxml_clear_errors();

    $xpath   = new DOMXPath($dom);
    $trNodes = $xpath->query('//tr[.//a[contains(@href,"goDetail")]]');
    if (!$trNodes) {
        return $items;
    }

    foreach ($trNodes as $tr) {
        $caseNo = '';

        $anchors = $xpath->query('.//a[@href]', $tr);
        if ($anchors) {
            foreach ($anchors as $a) {
                if (!$a instanceof DOMElement) {
                    continue;
                }
                $href = trim((string)$a->getAttribute('href'));
                if (preg_match('/goDetail\s*\(\s*[\'"]?(\d+)[\'"]?\s*\)/i', $href, $m)) {
                    $caseNo = $m[1];
                    break;
                }
            }
        }

        if ($caseNo === '' || isset($seen[$caseNo])) {
            continue;
        }

        $tdNodes = $xpath->query('./td', $tr);
        if (!$tdNodes) {
            continue;
        }

        $cells = [];
        foreach ($tdNodes as $td) {
            $cells[] = trim(preg_replace('/\s+/u', ' ', (string)$td->textContent));
        }

        $title      = $cells[1] ?? ($cells[0] ?? '');
        $location   = $cells[2] ?? '';
        $occurredAt = $cells[3] ?? '';

        if ($title === '') {
            continue;
        }

        $seen[$caseNo] = true;
        $items[] = [
            'case_no'     => $caseNo,
            'title'       => tbm_siren_clean_text($title),
            'location'    => tbm_siren_clean_text($location),
            'occurred_at' => tbm_siren_clean_text($occurredAt),
            'detail_url'  => 'https://www.csi.go.kr/acd/acdCaseView.do?case_no=' . rawurlencode($caseNo),
        ];

        if (count($items) >= $limit) {
            break;
        }
    }

    return $items;
}

/**
 * CSI 사고사례 상세 페이지 HTML에서 핵심 필드를 추출한다.
 *
 * @return array ['title','accident_date','incident_overview','incident_cause','prevention']
 */
function tbm_siren_csi_parse_detail(string $html): array
{
    $result = [
        'title'             => '',
        'accident_date'     => '',
        'incident_overview' => '',
        'incident_cause'    => '',
        'prevention'        => '',
    ];

    if ($html === '') {
        return $result;
    }

    // 정규식으로 th-td 쌍 추출
    $labelMap = [
        '사고명'       => 'title',
        '발생일시'     => 'accident_date',
        '사고경위'     => 'incident_overview',
        '사고원인'     => 'incident_cause',
        '구체적 사고원인' => 'incident_cause',
        '재발방지대책' => 'prevention',
    ];

    // <th>레이블</th>\n?<td>값</td> 패턴
    if (preg_match_all(
        '/<(?:th|td)[^>]*class=["\'][^"\']*td-head[^"\']*["\'][^>]*>\s*(.*?)\s*<\/(?:th|td)>\s*<(?:th|td)[^>]*>(.*?)<\/(?:th|td)>/isu',
        $html,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $match) {
            $label = tbm_siren_clean_text((string)$match[1]);
            $raw   = (string)$match[2];
            $raw   = preg_replace('/<br\s*\/?>/iu', ' ', $raw) ?? $raw;
            $value = tbm_siren_clean_text($raw);

            foreach ($labelMap as $key => $field) {
                if ($result[$field] === '' && str_contains($label, $key)) {
                    $result[$field] = $value;
                }
            }
        }
    }

    // DOM 폴백 (정규식으로 못 잡은 경우)
    $needsMore = array_filter($result, static fn($v) => $v === '');
    if (!empty($needsMore) && extension_loaded('dom') && class_exists('DOMDocument')) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . mb_substr($html, 0, 700000, 'UTF-8'));
        libxml_clear_errors();

        $xpath    = new DOMXPath($dom);
        $rowNodes = $xpath->query(
            '//section[@id="content"]//table//tr | //table[contains(@class,"table-bordered")]//tr'
        );

        if ($rowNodes) {
            foreach ($rowNodes as $row) {
                $thNodes = $xpath->query('./th|./td[contains(@class,"td-head")]', $row);
                $tdNodes = $xpath->query('./td[not(contains(@class,"td-head"))]', $row);

                if (!$thNodes || !$tdNodes || $thNodes->length === 0 || $tdNodes->length === 0) {
                    continue;
                }

                $label = tbm_siren_clean_text((string)$thNodes->item(0)->textContent);

                // 값 추출 (br → 공백)
                $td      = $tdNodes->item(0);
                $inner   = '';
                if ($td instanceof DOMElement) {
                    foreach ($td->childNodes as $child) {
                        $inner .= $dom->saveHTML($child);
                    }
                }
                $inner = preg_replace('/<br\s*\/?>/iu', ' ', $inner) ?? '';
                $value = tbm_siren_clean_text($inner);

                foreach ($labelMap as $key => $field) {
                    if ($result[$field] === '' && str_contains($label, $key)) {
                        $result[$field] = $value;
                    }
                }
            }
        }
    }

    return $result;
}

/**
 * CSI 목록 아이템 + 상세 데이터를 siren 표준 형식으로 변환한다.
 */
function tbm_siren_csi_build_item(array $listItem, array $detail): array
{
    $title = trim((string)($detail['title'] ?? $listItem['title'] ?? ''));
    $rawDate = trim((string)($detail['accident_date'] ?? $listItem['occurred_at'] ?? ''));
    $date  = tbm_siren_extract_date($rawDate);
    if ($date === '') {
        $date = $rawDate;
    }

    $summaryParts = [];
    foreach (['incident_overview', 'incident_cause'] as $key) {
        $v = trim((string)($detail[$key] ?? ''));
        if ($v !== '' && $v !== '-') {
            $summaryParts[] = $v;
        }
    }
    $summary    = implode(' ', $summaryParts);
    $prevention = trim((string)($detail['prevention'] ?? ''));
    $detailUrl  = trim((string)($listItem['detail_url'] ?? ''));

    return [
        'title'           => $title,
        'posted_date'     => $date,
        'detail_url'      => $detailUrl,
        'image_url'       => '',
        'image_base64'    => '',
        'summary'         => tbm_siren_clean_text($summary),
        'prevention'      => tbm_siren_clean_text($prevention),
        'search_keywords' => tbm_siren_build_queries_from_title($title),
        '_source'         => 'csi',
    ];
}

/**
 * CSI 사고사례 목록을 가져와 siren 아이템 형식으로 반환한다.
 * KOSHA 전체 실패 시 폴백 소스로 사용된다.
 *
 * @param  int $limit  최대 아이템 수
 * @return array       siren 표준 형식 아이템 배열
 */
function tbm_siren_fetch_csi_items(int $limit = 10): array
{
    $limit = max(1, min(20, $limit));

    $listHtml = tbm_siren_csi_fetch(TBM_SIREN_CSI_LIST_URL);
    if (trim($listHtml) === '') {
        error_log('[TBM SIREN] CSI 목록 페이지 로드 실패');
        return [];
    }

    $listItems = tbm_siren_csi_parse_list($listHtml, max($limit + 2, 8));
    if (empty($listItems)) {
        error_log('[TBM SIREN] CSI 목록 파싱 결과 없음');
        return [];
    }

    $items = [];
    foreach ($listItems as $listItem) {
        if (count($items) >= $limit) {
            break;
        }

        $detailUrl = trim((string)($listItem['detail_url'] ?? ''));
        if ($detailUrl === '') {
            continue;
        }

        $detailHtml = tbm_siren_csi_fetch($detailUrl);
        if (trim($detailHtml) === '') {
            continue;
        }

        $detail = tbm_siren_csi_parse_detail($detailHtml);
        $item   = tbm_siren_csi_build_item($listItem, $detail);

        if ($item['title'] !== '') {
            $items[] = $item;
        }
    }

    error_log('[TBM SIREN] CSI 폴백 결과: ' . count($items) . '건');
    return $items;
}

// ─────────────────────────────────────────────────────────────────
// 메인 진입점
// ─────────────────────────────────────────────────────────────────

function tbm_siren_get_recent_items(int $limit = 10, bool $withDetail = false, array $ocrOptions = []): array
{
    $limit = max(1, min(30, $limit));

    // 1순위: KOSHA API 직접 호출
    $apiRows = tbm_siren_fetch_api_list(1, max($limit, 8));
    if (!empty($apiRows)) {
        $items = tbm_siren_parse_recent_items_from_api($apiRows, $limit);
    } else {
        // 2순위 폴백: KOSHA HTML 스크래핑
        error_log('[TBM SIREN] API 실패 — HTML 스크래핑 폴백 사용');
        $html = tbm_siren_fetch_url(TBM_SIREN_LIST_URL);
        if (trim($html) === '') {
            $html = tbm_siren_fetch_url(TBM_SIREN_LEGACY_LIST_URL);
        }
        $items = trim($html) !== '' ? tbm_siren_parse_recent_items($html, $limit) : [];

        // 3순위 폴백: CSI 사고사례 (https://www.csi.go.kr/acd/acdCaseList.do)
        if (empty($items)) {
            error_log('[TBM SIREN] KOSHA 전체 실패 — CSI 사고사례 폴백 사용');
            $items = tbm_siren_fetch_csi_items($limit);
        }

        if (empty($items)) {
            return [];
        }
    }

    if (empty($items) || !$withDetail) {
        return $items;
    }

    foreach ($items as &$item) {
        // ── 이미지 소스 결정 ──────────────────────────────────────
        // base64가 있으면 OCR 직접 처리 가능, URL이 있으면 다운로드 후 처리
        $hasBase64 = trim((string)($item['image_base64'] ?? '')) !== '';
        $imageUrl  = trim((string)($item['image_url'] ?? ''));

        // 상세 URL HTML 파싱 (summary/prevention 텍스트 추출 시도)
        $detailUrl = trim((string)($item['detail_url'] ?? ''));
        if ($detailUrl !== '') {
            $detail = tbm_siren_parse_detail($detailUrl);
            if ($item['title'] === '' && $detail['title'] !== '') {
                $item['title'] = $detail['title'];
            }
            if ($detail['image_url'] !== '' && $imageUrl === '' && !$hasBase64) {
                $item['image_url'] = $detail['image_url'];
                $imageUrl = $detail['image_url'];
            }
            if ($detail['summary'] !== '') {
                $item['summary'] = $detail['summary'];
            }
            if ($detail['prevention'] !== '') {
                $item['prevention'] = $detail['prevention'];
            }
        }

        // ── OCR: base64 이미지 처리 ───────────────────────────────
        if (($item['summary'] === '' || $item['prevention'] === '') && $hasBase64) {
            $ocr = tbm_siren_extract_summary_prevention_from_base64(
                (string)$item['image_base64'],
                $ocrOptions
            );
            if (!empty($ocr['summary']) && $item['summary'] === '') {
                $item['summary'] = (string)$ocr['summary'];
            }
            if (!empty($ocr['prevention']) && $item['prevention'] === '') {
                $item['prevention'] = (string)$ocr['prevention'];
            }
            $item['ocr_ok']                  = (bool)($ocr['ok'] ?? false);
            $item['ocr_engine_summary']      = (string)($ocr['ocr_engine_summary'] ?? '');
            $item['ocr_engine_prevention']   = (string)($ocr['ocr_engine_prevention'] ?? '');
            $item['ocr_score_summary']       = (int)($ocr['ocr_score_summary'] ?? 0);
            $item['ocr_score_prevention']    = (int)($ocr['ocr_score_prevention'] ?? 0);
        }

        // ── OCR: URL 이미지 처리 (base64 없을 때) ─────────────────
        if (($item['summary'] === '' || $item['prevention'] === '') && $imageUrl !== '' && !$hasBase64) {
            $ocr = tbm_siren_extract_summary_prevention_from_image($imageUrl, $ocrOptions);
            if (!empty($ocr['summary']) && $item['summary'] === '') {
                $item['summary'] = (string)$ocr['summary'];
            }
            if (!empty($ocr['prevention']) && $item['prevention'] === '') {
                $item['prevention'] = (string)$ocr['prevention'];
            }
            $item['ocr_ok']                  = (bool)($ocr['ok'] ?? false);
            $item['ocr_engine_summary']      = (string)($ocr['ocr_engine_summary'] ?? '');
            $item['ocr_engine_prevention']   = (string)($ocr['ocr_engine_prevention'] ?? '');
            $item['ocr_score_summary']       = (int)($ocr['ocr_score_summary'] ?? 0);
            $item['ocr_score_prevention']    = (int)($ocr['ocr_score_prevention'] ?? 0);
            if (!empty($ocr['debug_files'])) {
                $item['ocr_debug_files'] = $ocr['debug_files'];
            }
        }

        if (empty($item['search_keywords'])) {
            $item['search_keywords'] = tbm_siren_build_queries_from_title((string)$item['title']);
        }
    }
    unset($item);

    return $items;
}

// ─────────────────────────────────────────────────────────────────
// OCR — base64 이미지 직접 처리 (신규)
// ─────────────────────────────────────────────────────────────────

/**
 * imgSrc base64 데이터를 임시 파일로 저장 후 OCR 처리한다.
 *
 * @param  string $base64DataUri  "data:image/jpg;base64,..." 형식
 * @param  array  $options        OCR 옵션
 * @return array  OCR 결과 (summary, prevention 등)
 */
function tbm_siren_extract_summary_prevention_from_base64(string $base64DataUri, array $options = []): array
{
    if (!extension_loaded('gd')) {
        return ['ok' => false, 'error' => 'GD extension not enabled'];
    }

    // data URI에서 base64 부분만 추출
    if (preg_match('/^data:image\/[^;]+;base64,(.+)$/s', $base64DataUri, $m)) {
        $imgData = base64_decode($m[1], true);
    } else {
        return ['ok' => false, 'error' => 'invalid base64 data URI'];
    }

    if ($imgData === false || strlen($imgData) < 100) {
        return ['ok' => false, 'error' => 'base64 decode failed'];
    }

    $options  = array_replace(tbm_siren_ocr_default_options(), $options);
    tbm_siren_ensure_dir($options['download_dir']);

    // 임시 파일 저장
    $tmpPath = rtrim($options['download_dir'], DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'siren_b64_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';

    if (file_put_contents($tmpPath, $imgData) === false) {
        return ['ok' => false, 'error' => 'temp file write failed'];
    }

    // 저장된 파일로 OCR → Gemini Vision 폴백 파이프라인 호출
    $result = tbm_siren_extract_text_with_fallback($tmpPath, $options);

    // 임시 파일 정리 (debug 모드가 아닐 때)
    if (empty($options['keep_debug_files']) && is_file($tmpPath)) {
        @unlink($tmpPath);
    }

    return $result;
}

// ─────────────────────────────────────────────────────────────────
// OCR — URL 이미지 처리
// ─────────────────────────────────────────────────────────────────

function tbm_siren_extract_summary_prevention_from_image(string $imageUrl, array $options = []): array
{
    if (!extension_loaded('gd')) {
        return ['ok' => false, 'error' => 'GD extension not enabled', 'image_url' => $imageUrl];
    }

    $options = array_replace(tbm_siren_ocr_default_options(), $options);
    tbm_siren_ensure_dir($options['download_dir']);
    tbm_siren_ensure_dir($options['work_dir']);

    $imagePath = tbm_siren_download_image($imageUrl, $options['download_dir']);
    if ($imagePath === null) {
        return ['ok' => false, 'error' => 'image download failed', 'image_url' => $imageUrl];
    }

    $result = tbm_siren_extract_text_with_fallback($imagePath, $options);
    $result['image_url'] = $imageUrl;
    return $result;
}



/**
 * 로컬 이미지 경로를 받아 OCR crop → 처리 → 결과 반환.
 * base64 경로와 URL 경로 양쪽에서 공통으로 사용.
 */
function tbm_siren_extract_summary_prevention_from_image_path(string $imagePath, array $options): array
{
    tbm_siren_ensure_dir($options['work_dir']);

    $base        = pathinfo($imagePath, PATHINFO_FILENAME);
    $summaryCrop = $options['work_dir'] . DIRECTORY_SEPARATOR . $base . '_summary_crop.jpg';
    $preventCrop = $options['work_dir'] . DIRECTORY_SEPARATOR . $base . '_prevent_crop.jpg';
    $summaryPrep = $options['work_dir'] . DIRECTORY_SEPARATOR . $base . '_summary_prep.jpg';
    $preventPrep = $options['work_dir'] . DIRECTORY_SEPARATOR . $base . '_prevent_prep.jpg';

    $summaryCropPath = tbm_siren_crop_region($imagePath, $options['crop_summary'], $summaryCrop);
    $preventCropPath = tbm_siren_crop_region($imagePath, $options['crop_prevention'], $preventCrop);
    if ($summaryCropPath === null || $preventCropPath === null) {
        return ['ok' => false, 'error' => 'crop failed', 'local_image_path' => $imagePath];
    }

    $summaryPrepPath = tbm_siren_preprocess_for_ocr($summaryCropPath, $summaryPrep, 2) ?? $summaryCropPath;
    $preventPrepPath = tbm_siren_preprocess_for_ocr($preventCropPath, $preventPrep, 2) ?? $preventCropPath;

    $summary    = tbm_siren_ocr_region($summaryPrepPath, $options, 'summary');
    $prevention = tbm_siren_ocr_region($preventPrepPath, $options, 'prevention');

    $result = [
        'ok'                    => trim((string)$summary['text']) !== '' || trim((string)$prevention['text']) !== '',
        'local_image_path'      => $imagePath,
        'summary'               => trim((string)$summary['text']),
        'prevention'            => trim((string)$prevention['text']),
        'raw_summary'           => (string)$summary['text'],
        'raw_prevention'        => (string)$prevention['text'],
        'ocr_engine_summary'    => (string)($summary['engine'] ?? ''),
        'ocr_engine_prevention' => (string)($prevention['engine'] ?? ''),
        'ocr_score_summary'     => (int)($summary['score'] ?? 0),
        'ocr_score_prevention'  => (int)($prevention['score'] ?? 0),
        'debug_files' => [
            'summary_crop'   => $summaryCropPath,
            'summary_prep'   => $summaryPrepPath,
            'prevention_crop'=> $preventCropPath,
            'prevention_prep'=> $preventPrepPath,
        ],
    ];

    if (empty($options['keep_debug_files'])) {
        foreach ($result['debug_files'] as $f) {
            if (is_string($f) && is_file($f)) {
                @unlink($f);
            }
        }
        unset($result['debug_files']);
    }

    return $result;
}





// ─────────────────────────────────────────────────────────────────
// OCR 헬퍼
// ─────────────────────────────────────────────────────────────────

function tbm_siren_ocr_default_options(): array
{
    return [
        'download_dir'         => __DIR__ . '/cache/siren_images',
        'work_dir'             => __DIR__ . '/cache/siren_work',
        'tesseract_path'       => 'A:\\Tesseract-OCR\\tesseract.exe',
        'python_path'          => 'python',
        'easyocr_script'       => __DIR__ . '/ocr_easy.py',
        'min_score_summary'    => 6,
        'min_score_prevention' => 5,
        'keep_debug_files'     => true,
        'crop_summary'         => ['x' => 0.075, 'y' => 0.305, 'w' => 0.86, 'h' => 0.205],
        'crop_prevention'      => ['x' => 0.255, 'y' => 0.835, 'w' => 0.655, 'h' => 0.115],
        'crop_main_image'      => ['x' => 0.107, 'y' => 0.457, 'w' => 0.785, 'h' => 0.335],
    ];
}

function tbm_siren_ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('디렉터리 생성 실패: ' . $dir);
    }
}

function tbm_siren_download_image(string $url, string $saveDir): ?string
{
    tbm_siren_ensure_dir($saveDir);
    $path = rtrim($saveDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'siren_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';

    $ch = curl_init($url);
    $fp = fopen($path, 'wb');
    if ($fp === false) {
        curl_close($ch);
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);

    $ok   = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if ($ok === false || $code >= 400 || !is_file($path) || filesize($path) < 1000) {
        @unlink($path);
        return null;
    }
    return $path;
}

function tbm_siren_load_image_resource(string $imagePath)
{
    $info = @getimagesize($imagePath);
    if (!is_array($info)) {
        return null;
    }

    return match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($imagePath),
        IMAGETYPE_PNG  => @imagecreatefrompng($imagePath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($imagePath) : null,
        default => null,
    };
}

function tbm_siren_crop_region(string $imagePath, array $rect, string $outPath): ?string
{
    $src = tbm_siren_load_image_resource($imagePath);
    if (!$src) {
        return null;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    $x = max(0, (int)round($srcW * (float)$rect['x']));
    $y = max(0, (int)round($srcH * (float)$rect['y']));
    $w = max(1, (int)round($srcW * (float)$rect['w']));
    $h = max(1, (int)round($srcH * (float)$rect['h']));

    if ($x + $w > $srcW) { $w = $srcW - $x; }
    if ($y + $h > $srcH) { $h = $srcH - $y; }

    $crop = imagecrop($src, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
    imagedestroy($src);
    if (!$crop) {
        return null;
    }

    imagejpeg($crop, $outPath, 95);
    imagedestroy($crop);
    return is_file($outPath) ? $outPath : null;
}

function tbm_siren_preprocess_for_ocr(string $imagePath, string $outPath, int $scale = 2): ?string
{
    $src = tbm_siren_load_image_resource($imagePath);
    if (!$src) {
        return null;
    }

    $w  = imagesx($src);
    $h  = imagesy($src);
    $nw = max(1, $w * $scale);
    $nh = max(1, $h * $scale);

    $canvas = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($canvas, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    imagefilter($canvas, IMG_FILTER_GRAYSCALE);
    imagefilter($canvas, IMG_FILTER_CONTRAST, -35);
    imagefilter($canvas, IMG_FILTER_BRIGHTNESS, 12);
    @imagefilter($canvas, IMG_FILTER_EDGEDETECT);
    imagefilter($canvas, IMG_FILTER_GRAYSCALE);

    $tw = imagesx($canvas);
    $th = imagesy($canvas);
    for ($y = 0; $y < $th; $y++) {
        for ($x = 0; $x < $tw; $x++) {
            $rgb  = imagecolorat($canvas, $x, $y);
            $r    = ($rgb >> 16) & 0xFF;
            $g    = ($rgb >> 8) & 0xFF;
            $b    = $rgb & 0xFF;
            $gray = (int)(($r + $g + $b) / 3);
            $v    = $gray > 180 ? 255 : 0;
            $color = imagecolorallocate($canvas, $v, $v, $v);
            imagesetpixel($canvas, $x, $y, $color);
        }
    }

    imagejpeg($canvas, $outPath, 100);
    imagedestroy($canvas);
    return is_file($outPath) ? $outPath : null;
}

function tbm_siren_cleanup_ocr_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
    $text = preg_replace('#[\|\\\\/\[\]{}]+#u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function tbm_siren_cleanup_summary(string $text): string
{
    $text = tbm_siren_cleanup_ocr_text($text);
    $text = preg_replace('/^중대재해\s*발생\s*알림\s*/u', '', $text) ?? $text;
    $text = preg_replace('/^중대재해\s*사이렌\s*/u', '', $text) ?? $text;
    return trim($text);
}

function tbm_siren_cleanup_prevention(string $text): string
{
    $text  = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = preg_split('/\n+/u', $text) ?: [];
    $kept  = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^예\s*방\s*대\s*책$/u', $line)) {
            continue;
        }
        if (preg_match('/^예방\s*대책$/u', $line)) {
            continue;
        }
        if (preg_match('/^(대책|예방)$/u', $line)) {
            continue;
        }
        if (mb_strlen($line, 'UTF-8') <= 2 && preg_match('/^[0-9]+$/u', $line)) {
            continue;
        }
        $kept[] = $line;
    }

    $text = implode(' ', $kept);
    $text = tbm_siren_cleanup_ocr_text($text);
    $text = preg_replace('/\s*[•·▪■▶>]+\s*/u', ' ', $text) ?? $text;
    return trim($text);
}

function tbm_siren_score_domain_text(string $text, string $type): int
{
    $score = 0;
    $len   = mb_strlen(trim($text), 'UTF-8');

    if ($type === 'summary') {
        if ($len >= 45) $score += 4;
        elseif ($len >= 25) $score += 2;
        foreach (['사망', '떨어', '추락', '이동', '작업대', '현장', '치료'] as $kw) {
            if (mb_strpos($text, $kw, 0, 'UTF-8') !== false) $score += 1;
        }
    } else {
        if ($len >= 50) $score += 4;
        elseif ($len >= 30) $score += 2;
        foreach (['안전대', '부착설비', '설치', '착용', '이동', '상태', '작업대'] as $kw) {
            if (mb_strpos($text, $kw, 0, 'UTF-8') !== false) $score += 1;
        }
    }
    return $score;
}



function tbm_siren_run_tesseract_region(string $imagePath, array $options, string $type): array
{
    $tesseractPath = $options['tesseract_path'] ?? 'tesseract';
    $tmpBase       = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tbm_siren_' . uniqid('', true);
    $cmd           = '"' . $tesseractPath . '" ' . escapeshellarg($imagePath) . ' ' . escapeshellarg($tmpBase) . ' -l kor+eng --psm 6 2>&1';

    exec($cmd, $output, $code);
    $txtPath = $tmpBase . '.txt';
    $text    = is_file($txtPath) ? (string)file_get_contents($txtPath) : '';
    if (is_file($txtPath)) {
        @unlink($txtPath);
    }

    $text = $type === 'summary' ? tbm_siren_cleanup_summary($text) : tbm_siren_cleanup_prevention($text);

    return [
        'engine'     => 'tesseract',
        'ok'         => $code === 0 || trim($text) !== '',
        'text'       => $text,
        'score'      => tbm_siren_score_domain_text($text, $type),
        'raw_output' => implode("\n", $output),
    ];
}

function tbm_siren_run_easyocr_region(string $imagePath, array $options, string $type): array
{
    $pythonPath = $options['python_path'] ?? 'python';
    $scriptPath = $options['easyocr_script'] ?? (__DIR__ . '/ocr_easy.py');
    if (!is_file($scriptPath)) {
        return ['engine' => 'easyocr', 'ok' => false, 'text' => '', 'score' => 0, 'raw_output' => 'ocr_easy.py not found'];
    }

    $cmd  = '"' . $pythonPath . '" ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($imagePath) . ' 2>&1';
    exec($cmd, $output, $code);
    $raw  = implode("\n", $output);
    $json = json_decode($raw, true);

    if (!is_array($json) || empty($json['ok'])) {
        return ['engine' => 'easyocr', 'ok' => false, 'text' => '', 'score' => 0, 'raw_output' => $raw];
    }

    $text = (string)($json['text'] ?? '');
    $text = $type === 'summary' ? tbm_siren_cleanup_summary($text) : tbm_siren_cleanup_prevention($text);

    return [
        'engine'     => 'easyocr',
        'ok'         => trim($text) !== '',
        'text'       => $text,
        'score'      => tbm_siren_score_domain_text($text, $type),
        'raw_output' => $raw,
    ];
}

function tbm_siren_ocr_region(string $imagePath, array $options, string $type): array
{
    $minScore = $type === 'summary'
        ? (int)($options['min_score_summary'] ?? 6)
        : (int)($options['min_score_prevention'] ?? 5);

    $t = tbm_siren_run_tesseract_region($imagePath, $options, $type);
    if ($t['ok'] && $t['score'] >= $minScore) {
        return $t;
    }

    $e = tbm_siren_run_easyocr_region($imagePath, $options, $type);
    if ($e['ok'] && $e['score'] >= $t['score']) {
        return $e;
    }

    return $t['score'] >= $e['score'] ? $t : $e;
}

// ─────────────────────────────────────────────────────────────────
// Gemini Vision — 이미지에서 사고개요/예방대책 직접 추출
// ─────────────────────────────────────────────────────────────────

/**
 * 로컬 이미지 파일을 Gemini Vision API에 전달해 사고개요/예방대책을 추출한다.
 *
 * OCR(Tesseract/EasyOCR) 점수가 임계값 미달일 때 폴백으로 사용.
 * Gemini API 키는 환경변수 GEMINI_API_KEY 또는 상수 GEMINI_API_KEY 로 읽는다.
 *
 * @param  string $imagePath  로컬 이미지 파일 경로
 * @return array  ['summary'=>string, 'prevention'=>string, 'engine'=>'gemini', 'ok'=>bool, 'error'=>string]
 */
function tbm_siren_gemini_vision_extract(string $imagePath): array
{
    $empty = ['summary' => '', 'prevention' => '', 'engine' => 'gemini', 'ok' => false, 'error' => ''];

    // API 키 확인 (상수 또는 환경변수)
    $apiKey = '';
    if (defined('GEMINI_API_KEY') && trim((string)constant('GEMINI_API_KEY')) !== '') {
        $apiKey = trim((string)constant('GEMINI_API_KEY'));
    } else {
        $apiKey = trim((string)getenv('GEMINI_API_KEY'));
    }
    if ($apiKey === '') {
        return array_merge($empty, ['error' => 'GEMINI_API_KEY 없음']);
    }

    // 이미지 파일 읽기 및 base64 인코딩
    if (!is_file($imagePath) || filesize($imagePath) < 100) {
        return array_merge($empty, ['error' => '이미지 파일 없음: ' . $imagePath]);
    }
    $imgData  = file_get_contents($imagePath);
    if ($imgData === false) {
        return array_merge($empty, ['error' => '이미지 읽기 실패']);
    }
    $b64      = base64_encode($imgData);
    $mimeType = 'image/jpeg';
    $ext      = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
    if ($ext === 'png') $mimeType = 'image/png';
    if ($ext === 'webp') $mimeType = 'image/webp';

    // Gemini Vision 프롬프트
    $prompt = <<<PROMPT
이 이미지는 KOSHA(한국산업안전보건공단) 중대재해 사이렌 포스터입니다.
포스터에서 아래 두 항목을 추출해 JSON으로만 응답해 주세요. 다른 텍스트는 출력하지 마세요.

{
  "summary": "사고개요 전체 내용",
  "prevention": "예방대책 전체 내용"
}

- summary: 포스터의 사고개요(사고내용) 영역 텍스트 전체
- prevention: 포스터의 예방대책 영역 텍스트 전체
- 헤더("사고개요", "예방대책" 등 제목 문구)는 제외하고 내용만 추출
- 텍스트가 없으면 빈 문자열("")로 응답
PROMPT;

    // Gemini API 모델 (상수 있으면 사용, 없으면 기본값)
    $model = defined('GEMINI_MODEL') ? (string)constant('GEMINI_MODEL') : 'gemini-1.5-flash';

    $payload = json_encode([
        'contents' => [[
            'parts' => [
                ['text' => $prompt],
                ['inline_data' => ['mime_type' => $mimeType, 'data' => $b64]],
            ],
        ]],
        'generationConfig' => [
            'temperature'     => 0.1,
            'maxOutputTokens' => 8192,
            'thinkingConfig'  => [
                'thinkingBudget' => 1024,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return array_merge($empty, ['error' => 'payload JSON 인코딩 실패']);
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $apiKey;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $resp     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $curlErr !== '' || $httpCode >= 400) {
        return array_merge($empty, ['error' => 'Gemini API 오류 HTTP ' . $httpCode . ' / ' . $curlErr]);
    }

    $decoded = json_decode((string)$resp, true);

    // gemini-2.5-flash thinking 모델: thought=true 파트를 건너뛰고 실제 응답만 추출
    $text = '';
    $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
    foreach ($parts as $part) {
        if (!empty($part['thought'])) {
            continue; // thinking 파트 건너뛰기
        }
        if (isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }
    $text = trim($text);

    // JSON 블록 추출 (```json ... ``` 감싸인 경우 처리)
    if (preg_match('/```json\s*(.*?)\s*```/s', $text, $m)) {
        $text = $m[1];
    } elseif (preg_match('/\{.*\}/s', $text, $m)) {
        $text = $m[0];
    }

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        return array_merge($empty, ['error' => 'Gemini 응답 JSON 파싱 실패: ' . mb_substr($text, 0, 100, 'UTF-8')]);
    }

    $summary    = tbm_siren_clean_text((string)($parsed['summary']    ?? ''));
    $prevention = tbm_siren_clean_text((string)($parsed['prevention'] ?? ''));

    return [
        'summary'    => $summary,
        'prevention' => $prevention,
        'engine'     => 'gemini',
        'ok'         => $summary !== '' || $prevention !== '',
        'error'      => '',
    ];
}

/**
 * OCR → Gemini Vision 순서로 텍스트 추출을 시도한다.
 *
 * 1순위: Tesseract OCR (점수 임계값 이상)
 * 2순위: EasyOCR (점수 임계값 이상)
 * 3순위: Gemini Vision (OCR 모두 실패/저점수 시)
 *
 * @param  string $imagePath  로컬 이미지 파일 경로 (전체 포스터)
 * @param  array  $options    OCR 옵션
 * @return array  ['summary'=>string, 'prevention'=>string, 'engine'=>string, 'ok'=>bool]
 */
function tbm_siren_extract_text_with_fallback(string $imagePath, array $options = []): array
{
    $options = array_replace(tbm_siren_ocr_default_options(), $options);

    $empty = ['summary' => '', 'prevention' => '', 'engine' => '', 'ok' => false];

    if (!is_file($imagePath)) {
        return array_merge($empty, ['error' => '이미지 없음']);
    }

    tbm_siren_ensure_dir($options['work_dir']);

    $base        = pathinfo($imagePath, PATHINFO_FILENAME);
    $summaryCrop = $options['work_dir'] . DIRECTORY_SEPARATOR . $base . '_sc.jpg';
    $preventCrop = $options['work_dir'] . DIRECTORY_SEPARATOR . $base . '_pc.jpg';
    $summaryPrep = $options['work_dir'] . DIRECTORY_SEPARATOR . $base . '_sp.jpg';
    $preventPrep = $options['work_dir'] . DIRECTORY_SEPARATOR . $base . '_pp.jpg';

    $scPath = tbm_siren_crop_region($imagePath, $options['crop_summary'],    $summaryCrop);
    $pcPath = tbm_siren_crop_region($imagePath, $options['crop_prevention'],  $preventCrop);

    $summaryResult    = ['text' => '', 'score' => 0, 'engine' => ''];
    $preventionResult = ['text' => '', 'score' => 0, 'engine' => ''];

    if ($scPath !== null) {
        $spPath = tbm_siren_preprocess_for_ocr($scPath, $summaryPrep, 2) ?? $scPath;
        $summaryResult = tbm_siren_ocr_region($spPath, $options, 'summary');
    }
    if ($pcPath !== null) {
        $ppPath = tbm_siren_preprocess_for_ocr($pcPath, $preventPrep, 2) ?? $pcPath;
        $preventionResult = tbm_siren_ocr_region($ppPath, $options, 'prevention');
    }

    $minSummary    = (int)($options['min_score_summary']    ?? 3);
    $minPrevention = (int)($options['min_score_prevention'] ?? 2);

    $ocrOk = ($summaryResult['score'] >= $minSummary && trim($summaryResult['text']) !== '')
          || ($preventionResult['score'] >= $minPrevention && trim($preventionResult['text']) !== '');

    // OCR 점수 충분 → OCR 결과 반환
    if ($ocrOk) {
        // 임시 파일 정리
        foreach ([$summaryCrop, $preventCrop, $summaryPrep, $preventPrep] as $f) {
            if (is_string($f) && is_file($f) && empty($options['keep_debug_files'])) {
                @unlink($f);
            }
        }
        return [
            'summary'    => trim((string)$summaryResult['text']),
            'prevention' => trim((string)$preventionResult['text']),
            'engine'     => (string)($summaryResult['engine'] ?? 'ocr'),
            'ok'         => true,
        ];
    }

    // OCR 점수 부족 → Gemini Vision 폴백
    error_log('[TBM SIREN] OCR 점수 부족 (summary=' . $summaryResult['score'] . ', prevention=' . $preventionResult['score'] . ') — Gemini Vision 폴백');
    $vision = tbm_siren_gemini_vision_extract($imagePath);

    // 임시 파일 정리
    foreach ([$summaryCrop, $preventCrop, $summaryPrep, $preventPrep] as $f) {
        if (is_string($f) && is_file($f) && empty($options['keep_debug_files'])) {
            @unlink($f);
        }
    }

    return [
        'summary'    => $vision['summary']    ?? '',
        'prevention' => $vision['prevention'] ?? '',
        'engine'     => 'gemini-vision',
        'ok'         => (bool)($vision['ok'] ?? false),
        'error'      => $vision['error'] ?? '',
    ];
}

// ─────────────────────────────────────────────────────────────────
// OCR 후처리
// ─────────────────────────────────────────────────────────────────

function tbm_normalize_ocr_text(string $text): string
{
    $text  = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = preg_split('/\n+/', $text);
    $clean = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^[\[\]\(\)\|\/\\\\=><0-9\s-]+$/u', $line)) {
            continue;
        }
        if (mb_strlen($line, 'UTF-8') <= 1) {
            continue;
        }
        $line = preg_replace('#^[\s\[\]\(\)\|/\\=:><-]+#u', '', $line);
        $line = preg_replace('#[\s\[\]\(\)\|/\\=:><-]+$#u', '', $line);
        if ($line !== '') {
            $clean[] = $line;
        }
    }

    $text = implode("\n", $clean);

    $replaceMap = [
        '중대재매' => '중대재해',
        '사미렌'   => '사이렌',
        '중북'     => '충북',
        '소새'     => '소재',
        '직임대'   => '작업대',
        '패'       => '',
        '비요'     => '',
    ];
    $text = str_replace(array_keys($replaceMap), array_values($replaceMap), $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/ ?\n ?/u', "\n", $text);

    return trim($text);
}

function tbm_extract_summary_and_prevention_from_ocr(string $rawText): array
{
    $text  = tbm_normalize_ocr_text($rawText);
    $lines = preg_split('/\n+/', $text);

    $summaryLines    = [];
    $preventionLines = [];
    $mode            = 'summary';

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (
            mb_strpos($line, '대책') !== false ||
            mb_strpos($line, '붐대') !== false ||
            mb_strpos($line, '안전대') !== false ||
            mb_strpos($line, '부착설비') !== false ||
            mb_strpos($line, '체결한 상태') !== false
        ) {
            $mode = 'prevention';
        }

        if ($mode === 'summary') {
            if (mb_strpos($line, '중대재해') !== false || mb_strpos($line, '사이렌') !== false) {
                continue;
            }
            $summaryLines[] = $line;
        } else {
            if (preg_match('/대책/u', $line) && mb_strlen($line, 'UTF-8') < 20) {
                continue;
            }
            $preventionLines[] = $line;
        }
    }

    $summary    = preg_replace('/\s+/u', ' ', implode(' ', $summaryLines));
    $prevention = preg_replace('/\s+/u', ' ', implode(' ', $preventionLines));

    return [
        'summary'        => trim($summary),
        'prevention'     => trim($prevention),
        'normalized_raw' => $text,
    ];
}

// ─────────────────────────────────────────────────────────────────
// CLI 직접 실행
// ─────────────────────────────────────────────────────────────────

if (PHP_SAPI === 'cli' && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    $limit = isset($argv[1]) ? (int)$argv[1] : 5;
    $items = tbm_siren_get_recent_items($limit, true);
    echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}

function tbm_siren_crop_main_image(string $imagePath, array $options = []): ?string
{
    if (!is_array($options)) {
        $options = [];
    }

    $options = array_replace(tbm_siren_ocr_default_options(), $options);

    if (!is_file($imagePath)) return null;

    $rect = $options['crop_main_image'] ?? null;
    if (!$rect) return null;

    $src = tbm_siren_load_image_resource($imagePath);
    if (!$src) return null;

    $w = imagesx($src);
    $h = imagesy($src);

    $x = (int)($w * $rect['x']);
    $y = (int)($h * $rect['y']);
    $cw = (int)($w * $rect['w']);
    $ch = (int)($h * $rect['h']);

    $crop = imagecrop($src, [
        'x' => $x,
        'y' => $y,
        'width' => $cw,
        'height' => $ch
    ]);

    imagedestroy($src);

    if (!$crop) return null;

    $newPath = str_replace('.jpg', '_main.jpg', $imagePath);
    imagejpeg($crop, $newPath, 95);
    imagedestroy($crop);

    return $newPath;
}
