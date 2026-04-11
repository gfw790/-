<?php
declare(strict_types=1);

/**
 * TBM 뉴스 검색/본문 추출 모듈
 * - 네이버 뉴스 검색 API로 기사 1건 선택
 * - 실제 기사 URL만 사용
 * - 기사 본문을 최대한 추출
 */

if (!function_exists('tbm_news_load_env')) {
    function tbm_news_load_env(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $envFile = __DIR__ . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                [$k, $v] = explode('=', $line, 2) + ['', ''];
                $k = trim($k);
                $v = trim($v);
                $v = preg_replace('/^([\'\"])(.*)\1$/', '$2', $v);

                putenv($k . '=' . $v);
                $_ENV[$k] = $v;
            }
        }

        $loaded = true;
    }
}

function tbm_news_strip_html(string $html): string
{
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function tbm_news_clean_url(string $url): string
{
    $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $url = trim($url);
    $url = preg_replace('/[\x00-\x1F\x7F]/u', '', $url);

    if ($url === '') {
        return '';
    }

    if (!preg_match('~^https?://~i', $url)) {
        return '';
    }

    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    return $url;
}

function tbm_news_extract_meta_content(string $html, array $keys): string
{
    foreach ($keys as $key) {
        if (preg_match('/<meta[^>]+(?:property|name)=["\']' . preg_quote($key, '/') . '["\'][^>]*content=["\'](.*?)["\']/isu', $html, $m)) {
            $v = tbm_news_strip_html($m[1]);
            if ($v !== '') {
                return $v;
            }
        }

        if (preg_match('/<meta[^>]+content=["\'](.*?)["\'][^>]*(?:property|name)=["\']' . preg_quote($key, '/') . '["\']/isu', $html, $m)) {
            $v = tbm_news_strip_html($m[1]);
            if ($v !== '') {
                return $v;
            }
        }
    }

    return '';
}

function tbm_news_extract_image_url(string $html): string
{
    $image = tbm_news_extract_meta_content($html, [
        'og:image',
        'twitter:image',
        'twitter:image:src'
    ]);

    return tbm_news_clean_url($image);
}

function tbm_news_is_usable_image_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }

    $badKeywords = [
        'graph',
        'chart',
        'logo',
        'banner',
        'icon',
        'default',
        'thumb',
        'thumbnail',
        'og_default',
        'profile'
    ];

    $lower = mb_strtolower($url, 'UTF-8');

    foreach ($badKeywords as $bad) {
        if (mb_strpos($lower, $bad) !== false) {
            return false;
        }
    }

    return true;
}

function tbm_news_fetch_url(string $url): string
{
    $url = tbm_news_clean_url($url);
    if ($url === '') {
        return '';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
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

    if ($response === false || $err !== '') {
        return '';
    }

    if ($httpCode >= 400) {
        return '';
    }

    return is_string($response) ? $response : '';
}

function tbm_news_extract_article_body(string $html): string
{
    // 1차: 정규식 기반 추출 (빠름)
    $patterns = [
        '/<article[^>]*>(.*?)<\/article>/isu',
        '/<div[^>]+id=["\'][^"\']*(?:article|newsct_article|dic_area|articeBody|articleBody|newsEndContents)[^"\']*["\'][^>]*>(.*?)<\/div>/isu',
        '/<div[^>]+class=["\'][^"\']*(?:article|article_body|news_body|story-body|article_txt|newsct_article)[^"\']*["\'][^>]*>(.*?)<\/div>/isu',
        '/<section[^>]+class=["\'][^"\']*(?:article|news|content)[^"\']*["\'][^>]*>(.*?)<\/section>/isu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $body = tbm_news_strip_html(end($m));
            if (mb_strlen($body, 'UTF-8') >= 120) {
                return $body;
            }
        }
    }

    // 2차: DOMDocument 기반 추출 (중첩 태그 처리 가능)
    if (extension_loaded('dom') && class_exists('DOMDocument')) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . mb_substr($html, 0, 500000, 'UTF-8'));
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $selectors = [
            '//article',
            '//*[contains(@id,"article") or contains(@id,"newsct_article") or contains(@id,"dic_area")]',
            '//*[contains(@class,"article_body") or contains(@class,"news_body") or contains(@class,"article_txt")]',
        ];

        foreach ($selectors as $sel) {
            $nodes = $xpath->query($sel);
            if ($nodes && $nodes->length > 0) {
                $body = tbm_news_strip_html($nodes->item(0)->textContent ?? '');
                if (mb_strlen($body, 'UTF-8') >= 120) {
                    return $body;
                }
            }
        }
    }

    // 3차: og:description 폴백
    $desc = tbm_news_extract_meta_content($html, ['og:description', 'description']);
    if ($desc !== '') {
        return $desc;
    }

    return '';
}

function tbm_news_extract_accident_date(string $text, ?string $publishedAt = null): ?string
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    $patterns = [
        '/(20\d{2})\s*[년.\-\/]\s*(\d{1,2})\s*[월.\-\/]\s*(\d{1,2})\s*일?/u',
        '/(20\d{2})\s*(\d{2})\s*(\d{2})/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
    }

    if (preg_match('/(\d{1,2})\s*월\s*(\d{1,2})\s*일/u', $text, $m)) {
        $year = null;
        if ($publishedAt && preg_match('/^(20\d{2})-\d{2}-\d{2}$/', $publishedAt, $ym)) {
            $year = (int)$ym[1];
        } else {
            $year = (int)date('Y');
        }
        return sprintf('%04d-%02d-%02d', $year, (int)$m[1], (int)$m[2]);
    }

    return null;
}

function tbm_news_has_clear_accident_context(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }

    $hasDate = tbm_news_extract_accident_date($text) !== null;
    $hasAccidentWord = preg_match('/(사고|사망|부상|발생|숨지|감전|추락|끼임|질식|화재|붕괴|폭발|깔림|협착)/u', $text) === 1;

    return $hasDate && $hasAccidentWord;
}

/**
 * 사건 서술 패턴이 본문에 존재하는지 검증
 * "~하던 중", "작업 중", "현장에서" 등 실제 사고 발생 문장 패턴을 확인
 */
function tbm_news_has_incident_narrative(string $body): bool
{
    $body = trim($body);
    if ($body === '') {
        return false;
    }

    // 사고 발생 동작 패턴 (실제 사건 서술의 핵심 표지)
    $narrativePatterns = [
        '/작업\s*(중|하던\s*중|을\s*하다가|도중)/u',
        '/하던\s*중\s*(사고|사망|부상|추락|감전|끼임|질식)/u',
        '/현장\s*에서.{0,30}(사고|사망|부상|숨|쓰러)/u',
        '/(추락|감전|끼임|협착|질식|폭발|붕괴|화재)\s*(사고|사망|부상|발생)/u',
        '/(사망|숨진|사망한|목숨을\s*잃은)\s*(것으로|채)/u',
        '/\d+\s*[명인]\s*(이|가|은|는)\s*(사망|부상|숨|다쳐)/u',
        '/(근로자|작업자|노동자|직원)\s*.{0,20}(사망|부상|추락|감전|끼임|질식|숨)/u',
        '/(발을\s*헛디뎌|떨어지면서|쓰러지면서|끼이면서|감전되면서|폭발하면서)/u',
        '/구조\s*(대원|요청|신고).{0,30}(사고|사망|부상)/u',
        '/(병원|응급실)\s*(으로|에)\s*(이송|후송|옮겨)/u',
    ];

    foreach ($narrativePatterns as $pattern) {
        if (preg_match($pattern, $body) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * 분석·정책·통계 기사인지 판별
 * 이런 기사는 사고 사건 기사가 아님
 */
function tbm_news_is_analysis_article(string $title, string $body): bool
{
    $combined = $title . ' ' . mb_substr($body, 0, 400, 'UTF-8');

    // 분석·정책·통계 기사 표지 키워드
    $analysisPatterns = [
        // 제도·정책 류
        '/(법률|시행령|시행규칙|고시|개정|입법|법안|조례)\s*(개정|시행|공포|통과)/u',
        '/(?:중대재해처벌법|산업안전보건법)\s*(시행|개정|논란|적용|대상|효과|현황)/u',
        '/(정부|고용부|안전부|노동부|국토부)\s*(발표|대책|계획|추진|강화|점검)/u',
        '/(안전\s*문화|안전\s*의식|안전\s*교육)\s*(확산|제고|강화|캠페인)/u',
        // 통계·보고서 류
        '/(통계|현황|실태|분석|조사|보고서|자료)\s*(발표|공개|공표|따르면|에\s*따라)/u',
        '/(전년\s*(대비|동기)|증가율|감소율|비율|건수)\s*(상승|하락|증가|감소)/u',
        '/(\d+\s*%|\d+\s*퍼센트).{0,30}(증가|감소|상승|하락)/u',
        '/(\d+\s*년\s*(간|치|동안)).{0,30}(통계|현황|분석)/u',
        // 기획·칼럼·인터뷰 류
        '/(기획|특집|칼럼|인터뷰|좌담|포럼|세미나|학술)/u',
        '/^\[?(기획|특집|칼럼|인터뷰)\]?/u',
        // 판결·수사 결과 (사건 자체가 아닌 사후 처리)
        '/(판결|선고|기소|검찰|재판|항소|상고)\s*(결과|확정|받아)/u',
    ];

    foreach ($analysisPatterns as $pattern) {
        if (preg_match($pattern, $combined) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * 사고 기사의 사건성 강도 점수 (0~10)
 * 점수가 높을수록 구체적인 사고 사건 기사
 */
function tbm_news_incident_score(string $title, string $body): int
{
    $score = 0;
    $combined = $title . ' ' . $body;

    // 사건 발생 동작 서술 (+3)
    if (tbm_news_has_incident_narrative($body)) {
        $score += 3;
    }

    // 구체적 인명 피해 표현 (+2)
    if (preg_match('/\d+\s*[명인]\s*(이|가|은|는)?\s*(사망|부상|다쳐|숨)/u', $combined) === 1) {
        $score += 2;
    }

    // 사고 유형 키워드 제목 포함 (+2)
    $titleKw = ['감전', '추락', '떨어짐', '끼임', '협착', '질식', '화재', '붕괴', '폭발', '깔림', '사망', '사고'];
    foreach ($titleKw as $kw) {
        if (mb_strpos($title, $kw, 0, 'UTF-8') !== false) {
            $score += 2;
            break;
        }
    }

    // 작업 현장 맥락 (+1)
    if (preg_match('/(건설현장|공사현장|공장|사업장|현장|작업장)/u', $combined) === 1) {
        $score += 1;
    }

    // 발생 장소 구체성 (+1)
    if (preg_match('/(서울|부산|인천|대구|광주|대전|울산|경기|강원|충북|충남|전북|전남|경북|경남|제주).{0,10}(시|군|구)/u', $combined) === 1) {
        $score += 1;
    }

    // 분석 기사면 감점 (-5)
    if (tbm_news_is_analysis_article($title, $body)) {
        $score -= 5;
    }

    return max(0, min(10, $score));
}

function tbm_news_normalize_title(string $title): string
{
    $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = preg_replace('/\[[^\]]+\]/u', ' ', $title);
    $title = preg_replace('/\([^)]+\)/u', ' ', $title);
    $title = preg_replace('/["\'“”‘’]/u', '', $title);
    $title = preg_replace('/\s+/u', ' ', $title);
    return trim((string)$title);
}

function tbm_news_extract_item_url(array $item): string
{
    $link = tbm_news_clean_url((string)($item['originallink'] ?? ''));
    if ($link === '') {
        $link = tbm_news_clean_url((string)($item['link'] ?? ''));
    }
    return $link;
}

function tbm_news_parse_pub_date(?string $raw): ?string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }

    $ts = strtotime($raw);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    if (preg_match('/(20\d{2})-(\d{2})-(\d{2})/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }

    return null;
}

function tbm_news_is_within_days(?string $date, int $days, ?string $baseDate = null): bool
{
    $date = trim((string)$date);
    if ($date === '') {
        return false;
    }

    $base = $baseDate ?: date('Y-m-d');
    $a = strtotime($date . ' 00:00:00');
    $b = strtotime($base . ' 00:00:00');
    if ($a === false || $b === false) {
        return false;
    }

    $diffDays = (int)floor(abs($b - $a) / 86400);
    return $diffDays <= max(0, $days);
}

function tbm_news_search_raw(string $query, int $display = 20, int $start = 1, string $sort = 'date'): array
{
    tbm_news_load_env();

    $clientId     = trim((string)(getenv('NAVER_CLIENT_ID') ?: ''));
    $clientSecret = trim((string)(getenv('NAVER_CLIENT_SECRET') ?: ''));

    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException('네이버 뉴스 검색 API 키가 설정되지 않았습니다. .env 파일의 NAVER_CLIENT_ID / NAVER_CLIENT_SECRET 값을 확인하세요.');
    }

    $display = max(1, min(100, $display));
    $start   = max(1, min(1000, $start));
    $sort    = in_array($sort, ['date', 'sim'], true) ? $sort : 'date';

    $url = 'https://openapi.naver.com/v1/search/news.json'
         . '?display=' . $display
         . '&start=' . $start
         . '&sort=' . rawurlencode($sort)
         . '&query=' . rawurlencode($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'X-Naver-Client-Id: ' . $clientId,
            'X-Naver-Client-Secret: ' . $clientSecret,
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($response === false || $err !== '') {
        throw new RuntimeException('네이버 뉴스 검색 호출 실패: ' . $err);
    }

    if ($httpCode !== 200) {
        throw new RuntimeException('네이버 뉴스 검색 API 오류 (HTTP ' . $httpCode . '): ' . mb_substr((string)$response, 0, 300));
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        throw new RuntimeException('네이버 뉴스 검색 응답 파싱 실패');
    }

    return $data['items'];
}

function tbm_news_score_search_item(array $item): int
{
    $score = 0;
    $title = tbm_news_normalize_title((string)($item['title'] ?? ''));
    $desc  = tbm_news_normalize_title((string)($item['description'] ?? ''));
    $url   = tbm_news_extract_item_url($item);

    if ($url !== '') {
        $score += 2;
    }

    foreach (['사고', '사망', '감전', '추락', '떨어짐', '끼임', '질식', '화재', '붕괴', '폭발', '깔림', '협착'] as $kw) {
        if (mb_strpos($title, $kw, 0, 'UTF-8') !== false) {
            $score += 2;
        }
        if (mb_strpos($desc, $kw, 0, 'UTF-8') !== false) {
            $score += 1;
        }
    }

    foreach (['단독', '속보'] as $kw) {
        if (mb_strpos($title, $kw, 0, 'UTF-8') !== false) {
            $score += 1;
        }
    }

    return $score;
}

function tbm_news_search_candidates(string $query, int $display = 20, int $start = 1, string $sort = 'date'): array
{
    $items = tbm_news_search_raw($query, $display, $start, $sort);
    $candidates = [];
    $seen = [];

    foreach ($items as $item) {
        $url = tbm_news_extract_item_url($item);
        if ($url === '') {
            continue;
        }

        $title = tbm_news_normalize_title((string)($item['title'] ?? ''));
        $key = md5(mb_strtolower($url . '|' . $title, 'UTF-8'));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $publishedAt = tbm_news_parse_pub_date((string)($item['pubDate'] ?? ''));

        $score = tbm_news_score_search_item($item);
        if ($publishedAt !== null && tbm_news_is_within_days($publishedAt, 7)) {
            $score += 4;
        }

        $candidates[] = [
            'title'        => $title,
            'url'          => $url,
            'description'  => tbm_news_normalize_title((string)($item['description'] ?? '')),
            'score'        => $score,
            'published_at' => $publishedAt,
            'query'        => $query,
            'start'        => $start,
            'sort'         => $sort,
        ];
    }

    usort($candidates, static fn($a, $b) => (($b['score'] ?? 0) <=> ($a['score'] ?? 0)));

    return $candidates;
}

function tbm_news_fetch_article_by_url(string $title, string $url, ?string $publishedAt = null): array
{
    $html = tbm_news_fetch_url($url);
    $body = $html !== '' ? tbm_news_extract_article_body($html) : '';

    $publishedAtFromHtml = null;
    if ($html !== '') {
        $publishedAtFromHtml = tbm_news_extract_accident_date($html);
    }

    if ($publishedAt === null || $publishedAt === '') {
        $publishedAt = $publishedAtFromHtml;
    }

    $accidentDate = tbm_news_extract_accident_date($body, $publishedAt);

    $imageUrl = $html !== '' ? tbm_news_extract_image_url($html) : '';
    if (!tbm_news_is_usable_image_url($imageUrl)) {
        $imageUrl = '';
    }

    return [
        'article_title'  => trim($title),
        'article_url'    => trim($url),
        'article_body'   => mb_substr(trim($body), 0, 1800, 'UTF-8'),
        'image_url'      => $imageUrl,
        'published_at'   => $publishedAt,
        'accident_date'  => $accidentDate,
        'has_date'       => $accidentDate !== null,
        'has_context'    => tbm_news_has_clear_accident_context($body),
    ];
}

function tbm_news_fetch_article_candidates(
    string $query,
    int $display = 20,
    array $starts = [1, 11, 21],
    array $sorts = ['date', 'sim'],
    int $limit = 12
): array {
    $results = [];
    $seen = [];

    foreach ($sorts as $sort) {
        foreach ($starts as $start) {
            $items = tbm_news_search_candidates($query, $display, $start, $sort);

            foreach ($items as $item) {
                $url = trim((string)$item['url']);
                $title = trim((string)$item['title']);
                $key = md5(mb_strtolower($url . '|' . $title, 'UTF-8'));

                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $article = tbm_news_fetch_article_by_url($title, $url, $item['published_at'] ?? null);
                if (trim($article['article_url']) === '' || trim($article['article_body']) === '') {
                    continue;
                }

                $results[] = [
                    'article_title' => $article['article_title'],
                    'article_url'   => $article['article_url'],
                    'article_body'  => $article['article_body'],
                    'image_url'     => $article['image_url'],
                    'published_at'  => $article['published_at'],
                    'accident_date' => $article['accident_date'],
                    'has_date'      => $article['has_date'],
                    'has_context'   => $article['has_context'],
                    'query'         => $query,
                    'start'         => $start,
                    'sort'          => $sort,
                ];

                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }
    }

    return $results;
}


function tbm_news_fetch_recent_article_candidates(
    string $query,
    int $days = 7,
    int $display = 20,
    array $starts = [1, 11, 21],
    array $sorts = ['date'],
    int $limit = 12,
    ?string $baseDate = null
): array {
    $articles = tbm_news_fetch_article_candidates($query, $display, $starts, $sorts, $limit * 2);
    $filtered = [];

    foreach ($articles as $article) {
        $publishedAt = trim((string)($article['published_at'] ?? ''));
        $accidentDate = trim((string)($article['accident_date'] ?? ''));

        $isRecent = tbm_news_is_within_days($publishedAt, $days, $baseDate);
        if (!$isRecent && $accidentDate !== '') {
            $isRecent = tbm_news_is_within_days($accidentDate, $days, $baseDate);
        }

        if (!$isRecent) {
            continue;
        }

        $filtered[] = $article;
        if (count($filtered) >= $limit) {
            break;
        }
    }

    return $filtered;
}


function tbm_news_fetch_recent_week_article_candidates(
    string $query,
    int $display = 20,
    array $starts = [1, 11, 21],
    array $sorts = ['date'],
    int $limit = 12,
    ?string $baseDate = null
): array {
    return tbm_news_fetch_recent_article_candidates($query, 7, $display, $starts, $sorts, $limit, $baseDate);
}

function tbm_news_search(string $query): ?array
{
    $items = tbm_news_search_candidates($query, 20, 1, 'date');
    if ($items === []) {
        return null;
    }

    $first = $items[0];
    return [
        'title' => (string)$first['title'],
        'url'   => (string)$first['url'],
    ];
}

function tbm_news_fetch_article(string $query): array
{
    $items = tbm_news_fetch_article_candidates($query, 20, [1, 11, 21], ['date', 'sim'], 6);

    if ($items === []) {
        return [
            'article_title' => '',
            'article_url'   => '',
            'article_body'  => '',
            'image_url'     => '',
        ];
    }

    usort($items, static function ($a, $b) {
        $lenA = mb_strlen(trim((string)($a['article_body'] ?? '')), 'UTF-8');
        $lenB = mb_strlen(trim((string)($b['article_body'] ?? '')), 'UTF-8');
        return $lenB <=> $lenA;
    });

    return $items[0];
}
