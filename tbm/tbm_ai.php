<?php
declare(strict_types=1);



// OCR 경로 상수 — .env 우선, 없으면 기본값
define('TESSERACT_PATH', getenv('TESSERACT_PATH') ?: 'A:\\Tesseract-OCR\\tesseract.exe');
define('PYTHON_PATH',    getenv('PYTHON_PATH')    ?: 'python');
define('EASYOCR_SCRIPT', getenv('EASYOCR_SCRIPT') ?: __DIR__ . '/ocr_easy.py');

// 🔥 출력 완전 차단 (가장 중요)
ob_start();

// 🔥 에러 화면 출력 금지
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// 🔥 에러는 파일로만 남김
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_error.log');

// ============================================================
// tbm_ai.php  —  Gemini API 호출로 TBM 중대재해 전파 콘텐츠 자동 생성
// 통합 버전:
// - KOSHA 중대재해 사이렌 최근 사례 우선 사용
// - 네이버 뉴스 기사 본문 확보 시 기사 기반 생성
// - 기사 실패 시 사이렌 기반 생성
// - 최근 사용 source_url 제외
// - 날짜별 캐시 지원
// ============================================================

// ── 설정 ─────────────────────────────────────────────────────

$_envFile = __DIR__ . '/.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if ($_line === '' || str_starts_with($_line, '#')) {
            continue;
        }

        [$_k, $_v] = explode('=', $_line, 2) + ['', ''];
        $_k = trim($_k);
        $_v = trim($_v);
        $_v = preg_replace('/^([\'\"])(.*)\1$/', '$2', $_v);

        putenv($_k . '=' . $_v);
        $_ENV[$_k] = $_v;
    }
}

define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_MODEL', 'gemini-2.5-flash');
define('GEMINI_MAX_RETRIES', 5);
define('TBM_AI_CACHE_PREFIX', 'tbm_ai_');
define('TBM_AI_USED_SOURCE_LOOKBACK_DAYS', 45);
define('TBM_AI_SIREN_FETCH_LIMIT', 5);
define('TBM_AI_TOP_CANDIDATES', 5);

require_once __DIR__ . '/tbm_news.php';
require_once __DIR__ . '/tbm_siren.php';
if (is_file(__DIR__ . '/tbm_db.php')) {
    require_once __DIR__ . '/tbm_db.php';
}

// ── 공통 유틸 ────────────────────────────────────────────────

function tbm_strip_code_fence(string $text): string
{
    $text = trim($text);
    $text = preg_replace('/^```json\s*/iu', '', $text);
    $text = preg_replace('/^```\s*/u', '', $text);
    $text = preg_replace('/\s*```$/u', '', $text);
    return trim($text);
}

function tbm_make_fallback_source_url(string $accidentTitle): string
{
    $keyword = trim($accidentTitle);
    if ($keyword === '') {
        return '';
    }

    $keyword = preg_replace('/중대재해\s*전파/u', '', $keyword);
    $keyword = preg_replace('/[()\[\]]/u', '', $keyword);
    $keyword = trim((string)$keyword);

    return 'https://search.naver.com/search.naver?where=news&query=' . rawurlencode($keyword . ' 사고');
}

function tbm_ai_cache_dir(): string
{
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function tbm_ai_cache_path(string $targetDate): string
{
    return tbm_ai_cache_dir() . '/' . TBM_AI_CACHE_PREFIX . preg_replace('/[^0-9\-]/', '', $targetDate) . '.json';
}

function tbm_ai_load_cache(string $targetDate): ?array
{
    $path = tbm_ai_cache_path($targetDate);
    if (!is_file($path)) {
        return null;
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return null;
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function tbm_ai_save_cache(string $targetDate, array $data): void
{
    file_put_contents(
        tbm_ai_cache_path($targetDate),
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

/**
 * 오래된 캐시 파일을 정리한다.
 * cron 또는 생성 시점에 호출하면 디스크 사용량을 관리할 수 있다.
 *
 * @param int $maxAgeDays 보관 일수 (기본 30일)
 * @return int 삭제된 파일 수
 */
function tbm_ai_purge_old_cache(int $maxAgeDays = 30): int
{
    $dir = tbm_ai_cache_dir();
    $threshold = time() - ($maxAgeDays * 86400);
    $deleted = 0;

    $pattern = $dir . '/' . TBM_AI_CACHE_PREFIX . '*.json';
    foreach (glob($pattern) as $file) {
        if (is_file($file) && filemtime($file) < $threshold) {
            if (@unlink($file)) {
                $deleted++;
            }
        }
    }

    if ($deleted > 0) {
        tbm_ai_log_debug(sprintf('[캐시 정리] %d일 이전 캐시 %d건 삭제', $maxAgeDays, $deleted));
    }

    return $deleted;
}

function tbm_ai_log_debug(string $message): void
{
    $logFile = __DIR__ . '/tbm_ai_debug.log';
    $maxSize = 5 * 1024 * 1024; // 5MB

    // 로그 파일이 최대 크기를 초과하면 백업 후 새로 시작
    if (is_file($logFile) && filesize($logFile) > $maxSize) {
        $backup = __DIR__ . '/tbm_ai_debug_old.log';
        @rename($logFile, $backup);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
}

/**
 * 선택되지 않은 후보의 이미지 파일을 정리한다.
 * 한 번의 생성 실행에서 다수의 후보 이미지가 쌓이는 것을 방지한다.
 *
 * @param array  $allCandidates  전체 후보 배열 (각 항목에 image_file 키)
 * @param string $pickedImageFile 최종 선택된 이미지 파일 경로 (상대경로)
 * @return int 삭제된 파일 수
 */
function tbm_ai_cleanup_unused_images(array $allCandidates, string $pickedImageFile): int
{
    $pickedImageFile = trim($pickedImageFile);
    $deleted = 0;

    // 보호 대상: 최종 선택된 이미지와 그 변형
    $protectedFiles = [];
    if ($pickedImageFile !== '') {
        $protectedFiles[] = $pickedImageFile;
        // _fit 변형도 보호
        $protectedFiles[] = preg_replace('/\.[^.]+$/', '_fit.jpg', $pickedImageFile);
    }

    // 각 후보에서 삭제 대상 파일 경로 수집
    $filesToDelete = [];

    foreach ($allCandidates as $candidate) {
        // 현재 image_file (crop된 경로)
        $imageFile = trim((string)($candidate['image_file'] ?? ''));
        if ($imageFile !== '' && !in_array($imageFile, $protectedFiles, true)) {
            $filesToDelete[] = $imageFile;
            // _fit 변형
            $filesToDelete[] = preg_replace('/\.[^.]+$/', '_fit.jpg', $imageFile);
        }

        // 원본 siren 경로 (crop 전 경로)
        $originalFile = trim((string)($candidate['image_file_original'] ?? ''));
        if ($originalFile !== '' && !in_array($originalFile, $protectedFiles, true)) {
            $filesToDelete[] = $originalFile;
            $filesToDelete[] = preg_replace('/\.[^.]+$/', '_fit.jpg', $originalFile);
        }
    }

    // 중복 제거
    $filesToDelete = array_unique($filesToDelete);

    foreach ($filesToDelete as $relPath) {
        $fullPath = __DIR__ . '/' . ltrim($relPath, '/');
        if (is_file($fullPath)) {
            @unlink($fullPath);
            $deleted++;
            tbm_ai_log_debug('[이미지 정리] 삭제: ' . $relPath);
        }
    }

    if ($deleted > 0) {
        tbm_ai_log_debug(sprintf('[이미지 정리] 총 %d개 미사용 파일 삭제', $deleted));
    }

    return $deleted;
}


function tbm_ai_get_siren_ocr_options(): array
{
    $tesseractPath = trim((string)(getenv('TESSERACT_PATH') ?: 'A:\\Tesseract-OCR\\tesseract.exe'));
    $pythonPath    = trim((string)(getenv('PYTHON_PATH') ?: 'python'));
    $easyScript    = trim((string)(getenv('EASYOCR_SCRIPT') ?: (__DIR__ . '/ocr_easy.py')));

    return [
        'tesseract_path' => $tesseractPath,
        'python_path'    => $pythonPath,
        'easyocr_script' => $easyScript,
    ];
}

function tbm_ai_run_easyocr(string $relativeImagePath): string
{
    $pythonPath = trim((string)(getenv('PYTHON_PATH') ?: 'python'));
    $easyScript = trim((string)(getenv('EASYOCR_SCRIPT') ?: (__DIR__ . '/ocr_easy.py')));

    $fullPath = $relativeImagePath;
    if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $fullPath)) {
        $fullPath = __DIR__ . '/' . ltrim($relativeImagePath, '/');
    }

    if (!is_file($fullPath)) {
        return '';
    }
    if (!is_file($easyScript)) {
        return '';
    }

    $cmd = '"' . str_replace('"', '', $pythonPath) . '" '
         . escapeshellarg($easyScript) . ' '
         . escapeshellarg($fullPath) . ' 2>&1';

    $raw = shell_exec($cmd);
    if (!is_string($raw) || trim($raw) === '') {
        return '';
    }

    $decoded = json_decode(trim($raw), true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        return '';
    }

    return trim((string)($decoded['text'] ?? ''));
}

function tbm_ai_is_siren_usable(array $siren): bool
{
    $imageFile  = trim((string)($siren['image_file'] ?? ''));

    // 1순위: 이미지가 있으면 일단 사용 가능 (OCR/Vision이 추출할 기회 제공)
    if ($imageFile !== '') {
        $fullPath = __DIR__ . '/' . ltrim($imageFile, '/');
        if (is_file($fullPath)) {
            return true;
        }
    }

    // 2순위: 또는 summary/prevention 중 하나라도 있으면 OK
    $summary    = trim((string)($siren['summary'] ?? ''));
    $prevention = trim((string)($siren['prevention'] ?? ''));

    return ($summary !== '' || $prevention !== '');
}

function tbm_ai_try_crop_siren_image(array $siren): array
{
    $imageFile = trim((string)($siren['image_file'] ?? ''));
    if ($imageFile === '') {
        return $siren;
    }

    // tbm_siren.php의 통합 crop 함수로 위임
    $fullPath = __DIR__ . '/' . ltrim($imageFile, '/');
    if (!is_file($fullPath)) {
        $siren['image_cropped'] = false;
        return $siren;
    }

    $croppedFullPath = tbm_siren_crop_main_image($fullPath);
    if ($croppedFullPath !== null && is_file($croppedFullPath)) {
        // 절대경로 → 상대경로 변환
        $rootPath = str_replace('\\', '/', __DIR__) . '/';
        $normalized = str_replace('\\', '/', $croppedFullPath);

        if (str_starts_with($normalized, $rootPath)) {
            $relativePath = ltrim(substr($normalized, strlen($rootPath)), '/');
        } else {
            $relativePath = ltrim($normalized, '/');
        }

        // output/images 디렉토리로 복사 (기존 방식과 호환)
        $saveDir = __DIR__ . '/output/images';
        if (!is_dir($saveDir) && !mkdir($saveDir, 0777, true) && !is_dir($saveDir)) {
            $siren['image_cropped'] = false;
            return $siren;
        }

        $fileName = 'crop_' . substr(md5($imageFile . '|' . filesize($fullPath)), 0, 20) . '.jpg';
        $destPath = $saveDir . '/' . $fileName;

        // _main.jpg → output/images/crop_xxx.jpg로 이동
        if ($croppedFullPath !== $destPath) {
            @copy($croppedFullPath, $destPath);
            @unlink($croppedFullPath); // 임시 _main.jpg 정리
        }

        if (is_file($destPath) && filesize($destPath) > 3000) {
            $siren['image_file_original'] = $imageFile; // 원본 siren 경로 보존 (cleanup용)
            $siren['image_file'] = 'output/images/' . $fileName;
            $siren['image_cropped'] = true;
            tbm_ai_log_debug('[KOSHA 이미지 crop 성공] output/images/' . $fileName);
        } else {
            @unlink($destPath);
            $siren['image_cropped'] = false;
            tbm_ai_log_debug('[KOSHA 이미지 crop 실패] 파일 크기 부족');
        }
    } else {
        $siren['image_cropped'] = false;
        tbm_ai_log_debug('[KOSHA 이미지 crop 실패 또는 원본 유지] ' . $imageFile);
    }

    return $siren;
}


function tbm_ai_enrich_siren_from_image(array $siren): array
{
    $options = tbm_ai_get_siren_ocr_options();
    $options['keep_debug_files'] = false;

    tbm_ai_log_debug('[KOSHA enrich 시작] title=' . trim((string)($siren['title'] ?? '')));
    tbm_ai_log_debug(
        '[KOSHA enrich 상태] has_image_file=' . (!empty($siren['image_file']) ? 'Y' : 'N') .
        ' / has_image_base64=' . (!empty($siren['image_base64']) ? 'Y' : 'N')
    );

    // ★ 원본 전체 포스터 이미지 경로를 OCR/Vision용으로 별도 보존
    $posterImageFile = '';

    // 0) base64가 있으면 먼저 출력용 로컬 이미지로 고정 저장
    if (empty($siren['image_file']) && !empty($siren['image_base64'])) {
        try {
            $saved = tbm_siren_save_data_uri_image((string)$siren['image_base64'], 'siren');

            if (is_string($saved) && trim($saved) !== '') {
                $normalized = str_replace('\\', '/', $saved);
                $rootPath   = str_replace('\\', '/', __DIR__) . '/';

                if (str_starts_with($normalized, $rootPath)) {
                    $siren['image_file'] = ltrim(substr($normalized, strlen($rootPath)), '/');
                } else {
                    $siren['image_file'] = ltrim($normalized, '/');
                }

                tbm_ai_log_debug('[KOSHA 이미지 저장 성공] ' . $siren['image_file']);

                // ★ crop 전에 원본 포스터 경로 보존 (OCR/Vision에서 사용)
                $posterImageFile = $siren['image_file'];

                $siren = tbm_ai_try_crop_siren_image($siren);
                tbm_ai_log_debug('[KOSHA crop 시도 완료]');

            } else {
                tbm_ai_log_debug('[KOSHA 이미지 저장 실패] save 함수 반환값 비어있음');
            }

        } catch (Throwable $e) {
            tbm_ai_log_debug('KOSHA base64 이미지 저장 실패: ' . $e->getMessage());
        } 
    } else {
        tbm_ai_log_debug('[KOSHA 저장 스킵] image_file 이미 있거나 image_base64 없음');
        // 기존에 image_file이 있었다면 그것이 원본 포스터
        $posterImageFile = trim((string)($siren['image_file'] ?? ''));
    }

    // ★ image_file_original이 있으면 그것이 진짜 원본 포스터
    if (!empty($siren['image_file_original'])) {
        $posterImageFile = (string)$siren['image_file_original'];
    }

    if (!empty($siren['image_file'])) {
        tbm_ai_log_debug('[KOSHA OCR 시작] image_file=' . $siren['image_file']);
    } else {
        tbm_ai_log_debug('[KOSHA OCR 불가] image_file 없음');
    }
    if ($posterImageFile !== '') {
        tbm_ai_log_debug('[KOSHA OCR 대상] poster_image=' . $posterImageFile);
    }

    // 1) base64 OCR/비전 추출
    if (trim((string)($siren['summary'] ?? '')) === '' || trim((string)($siren['prevention'] ?? '')) === '') {
        if (!empty($siren['image_base64'])) {
            try {
                $ocr = tbm_siren_extract_summary_prevention_from_base64((string)$siren['image_base64'], $options);

                if (trim((string)($siren['summary'] ?? '')) === '' && !empty($ocr['summary'])) {
                    $siren['summary'] = trim((string)$ocr['summary']);
                }
                if (trim((string)($siren['prevention'] ?? '')) === '' && !empty($ocr['prevention'])) {
                    $siren['prevention'] = trim((string)$ocr['prevention']);
                }

                $siren['ocr_ok']                = !empty($ocr['ok']);
                $siren['ocr_engine_summary']    = (string)($ocr['ocr_engine_summary'] ?? $ocr['engine'] ?? '');
                $siren['ocr_engine_prevention'] = (string)($ocr['ocr_engine_prevention'] ?? $ocr['engine'] ?? '');
                $siren['ocr_score_summary']     = (int)($ocr['ocr_score_summary'] ?? 0);
                $siren['ocr_score_prevention']  = (int)($ocr['ocr_score_prevention'] ?? 0);
                $siren['gemini_vision_used']    = (($ocr['engine'] ?? '') === 'gemini');
            } catch (Throwable $e) {
                tbm_ai_log_debug('KOSHA base64 OCR 실패: ' . $e->getMessage());
            }
        }
    }

    // 2) 저장된 로컬 이미지 기준으로 한 번 더 보강
    //    ★ 핵심 수정: crop된 이미지(사고현장 사진)가 아닌 원본 전체 포스터 이미지를 사용
    //      crop 이미지에서 crop_summary/crop_prevention 영역을 다시 자르면 엉뚱한 부분이 됨
    if ((trim((string)($siren['summary'] ?? '')) === '' || trim((string)($siren['prevention'] ?? '')) === '')) {
        // ★ 원본 포스터 경로 우선, 없으면 현재 image_file 사용
        $ocrTargetFile = $posterImageFile !== '' ? $posterImageFile : trim((string)($siren['image_file'] ?? ''));

        if ($ocrTargetFile !== '') {
            try {
                $fullPath = __DIR__ . '/' . ltrim($ocrTargetFile, '/');
                if (is_file($fullPath)) {
                    tbm_ai_log_debug('[KOSHA local OCR 시도] 대상=' . $ocrTargetFile);
                    $ocr = tbm_siren_extract_text_with_fallback($fullPath, $options);

                    if (trim((string)($siren['summary'] ?? '')) === '' && !empty($ocr['summary'])) {
                        $siren['summary'] = trim((string)$ocr['summary']);
                    }
                    if (trim((string)($siren['prevention'] ?? '')) === '' && !empty($ocr['prevention'])) {
                        $siren['prevention'] = trim((string)$ocr['prevention']);
                    }

                    $siren['ocr_ok']             = !empty($ocr['ok']);
                    $siren['gemini_vision_used'] = (($ocr['engine'] ?? '') === 'gemini-vision');

                    tbm_ai_log_debug(sprintf(
                        '[KOSHA local OCR 결과] engine=%s / ok=%s / summary_len=%d / prevention_len=%d',
                        (string)($ocr['engine'] ?? ''),
                        !empty($ocr['ok']) ? 'Y' : 'N',
                        mb_strlen(trim((string)($ocr['summary'] ?? '')), 'UTF-8'),
                        mb_strlen(trim((string)($ocr['prevention'] ?? '')), 'UTF-8')
                    ));
                } else {
                    tbm_ai_log_debug('[KOSHA local OCR 스킵] 파일 없음: ' . $ocrTargetFile);
                }
            } catch (Throwable $e) {
                tbm_ai_log_debug('KOSHA local image OCR 실패: ' . $e->getMessage());
            }
        }
    }

    return $siren;
}


// ── Gemini 호출 ──────────────────────────────────────────────

function tbm_call_gemini_api(array $payload): array
{
    if (trim(GEMINI_API_KEY) === '') {
        throw new RuntimeException('GEMINI_API_KEY가 설정되지 않았습니다. .env 파일에 GEMINI_API_KEY=발급받은키 형식으로 넣어주세요.');
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . rawurlencode(trim(GEMINI_API_KEY));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlErr !== '') {
        throw new RuntimeException('Gemini API cURL 오류: ' . $curlErr);
    }

    if ($httpCode === 429) {
        throw new RuntimeException('GEMINI_QUOTA_EXCEEDED::' . $response);
    }

    if ($httpCode !== 200) {
        throw new RuntimeException(sprintf('Gemini API 오류 (HTTP %d): %s', $httpCode, $response));
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Gemini API 응답 JSON 파싱 실패: ' . mb_substr($response, 0, 500));
    }

    return $decoded;
}

function tbm_extract_gemini_text(array $decoded): string
{
    // finishReason 확인 — MAX_TOKENS이면 응답이 잘린 것
    $finishReason = $decoded['candidates'][0]['finishReason'] ?? '';
    if ($finishReason === 'MAX_TOKENS') {
        throw new RuntimeException('Gemini 응답이 토큰 한도로 잘렸습니다 (finishReason=MAX_TOKENS). maxOutputTokens를 늘려야 합니다.');
    }

    $parts = $decoded['candidates'][0]['content']['parts'] ?? null;
    if (!is_array($parts)) {
        throw new RuntimeException('Gemini 응답에서 content.parts를 찾을 수 없습니다.');
    }

    $texts = [];
    foreach ($parts as $part) {
        // gemini-2.5-flash thinking 모델: thought=true인 파트는 건너뛰기
        if (!empty($part['thought'])) {
            continue;
        }
        if (isset($part['text']) && is_string($part['text'])) {
            $texts[] = $part['text'];
        }
    }

    $text = trim(implode("\n", $texts));
    if ($text === '') {
        throw new RuntimeException('Gemini 응답 텍스트가 비어 있습니다.');
    }

    return $text;
}

function tbm_parse_ai_json(string $text): array
{
    $original = $text;

    // HTML 경고/오류 출력이 섞여 들어오는 경우 제거
    $text = preg_replace('/<[^>]+>/', ' ', $text);
    $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $clean = tbm_strip_code_fence(trim($text));

    $parsed = json_decode($clean, true);
    if (is_array($parsed)) {
        return $parsed;
    }

    $normalized = preg_replace('/^\xEF\xBB\xBF/u', '', $clean);
    $normalized = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $normalized);
    $normalized = preg_replace('/[
\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $normalized);

    $parsed = json_decode($normalized, true);
    if (is_array($parsed)) {
        return $parsed;
    }

    $start = strpos($normalized, '{');
    $end   = strrpos($normalized, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $jsonOnly = substr($normalized, $start, $end - $start + 1);
        $parsed = json_decode($jsonOnly, true);
        if (is_array($parsed)) {
            return $parsed;
        }
        $normalized = $jsonOnly;
    }

    $result = [
        'accident_date'        => '',
        'accident_title'       => '',
        'edu_title'            => '',
        'body_text'            => '',
        'source_url'           => '',
        'image_search_keyword' => '',
        'quiz_1'               => '',
        'quiz_2'               => '',
        'quiz_3'               => '',
    ];

    $keys = array_keys($result);
    foreach ($keys as $i => $key) {
        $nextKeys = array_slice($keys, $i + 1);
        $nextPattern = implode('|', array_map(fn($k) => preg_quote($k, '/'), $nextKeys));
        if ($nextPattern !== '') {
            $pattern = '/"' . preg_quote($key, '/') . '"\s*:\s*"(.*?)"\s*,\s*"(' . $nextPattern . ')"\s*:/su';
        } else {
            $pattern = '/"' . preg_quote($key, '/') . '"\s*:\s*"(.*?)"\s*}\s*$/su';
        }

        if (preg_match($pattern, $normalized, $m)) {
            $value = str_replace(['\\"', '\\\\', '\\/', '\\n', '\\r', '\\t'], ['"', '\\', '/', "\n", "\n", "\t"], $m[1]);
            $result[$key] = trim($value);
        }
    }

    if ($result['accident_title'] !== '' || $result['body_text'] !== '' || $result['edu_title'] !== '') {
        return $result;
    }

    @file_put_contents(__DIR__ . '/gemini_error.txt', "[ORIGINAL]\n" . $original . "\n\n[CLEANED]\n" . $normalized);
    throw new RuntimeException('AI JSON 파싱 실패. json_last_error=' . json_last_error_msg() . ' / 응답 원문: ' . mb_substr($normalized, 0, 800));
}

function tbm_ai_validate_parsed_response(array $parsed): array
{
    $errors = [];
    $bodyText = trim((string)($parsed['body_text'] ?? ''));
    $firstLabel = '[사고내용 및 원인]';
    $secondLabel = '[예방대책]';
    $firstPos = mb_strpos($bodyText, $firstLabel);
    $secondPos = mb_strpos($bodyText, $secondLabel);

    if ($bodyText === '') {
        $errors[] = 'body_text가 비어 있습니다.';
    }

    if ($firstPos === false || $secondPos === false || $secondPos <= $firstPos) {
        $errors[] = 'body_text에 [사고내용 및 원인]과 [예방대책] 문단 레이블이 정확히 포함되어야 합니다.';
    } else {
        $firstBlock = trim(mb_substr($bodyText, $firstPos + mb_strlen($firstLabel, 'UTF-8'), $secondPos - ($firstPos + mb_strlen($firstLabel, 'UTF-8')), 'UTF-8'));
        $secondBlock = trim(mb_substr($bodyText, $secondPos + mb_strlen($secondLabel, 'UTF-8'), null, 'UTF-8'));
        $firstLen = mb_strlen($firstBlock, 'UTF-8');
        $secondLen = mb_strlen($secondBlock, 'UTF-8');

        if ($firstBlock === '') {
            $errors[] = '사고내용 및 원인 본문이 비어 있습니다.';
        }
        if ($secondBlock === '') {
            $errors[] = '예방대책 본문이 비어 있습니다.';
        }
        if ($firstLen < 220 || $firstLen > 250) {
            $errors[] = "사고내용 및 원인 길이가 {$firstLen}자입니다. 220~250자여야 합니다.";
        }
        if ($secondLen < 140 || $secondLen > 160) {
            $errors[] = "예방대책 길이가 {$secondLen}자입니다. 140~160자여야 합니다.";
        }
        if ($firstLen + $secondLen > 420) {
            $errors[] = '사고내용 및 원인 + 예방대책 합계가 ' . ($firstLen + $secondLen) . '자입니다. 420자를 넘지 않아야 합니다.';
        }
    }

    $quiz1 = trim((string)($parsed['quiz_1'] ?? ''));
    $quiz2 = trim((string)($parsed['quiz_2'] ?? ''));
    $quiz3 = trim((string)($parsed['quiz_3'] ?? ''));
    $quizTotal = mb_strlen($quiz1, 'UTF-8') + mb_strlen($quiz2, 'UTF-8') + mb_strlen($quiz3, 'UTF-8');

    if ($quiz1 === '' || $quiz2 === '' || $quiz3 === '') {
        $errors[] = '퀴즈 3개 모두가 입력되어야 합니다.';
    }
    if ($quizTotal < 545 || $quizTotal > 820) {
        $errors[] = "퀴즈 3개 총 글자 수가 {$quizTotal}자입니다. 545~820자여야 합니다.";
    }

    return ['valid' => empty($errors), 'errors' => $errors];
}

function tbm_ai_trim_text_to_limit(string $text, int $maxLen, int $minSentenceCut = 80): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return '';
    }

    if (mb_strlen($text, 'UTF-8') <= $maxLen) {
        return $text;
    }

    $searchStart = min($maxLen, mb_strlen($text, 'UTF-8'));

    // 문장 경계(다./요./됨./함././!/?)에서 자르기
    for ($i = $searchStart; $i >= max(1, $minSentenceCut); $i--) {
        $twoChar = mb_substr($text, $i - 1, 2, 'UTF-8');
        $oneChar = mb_substr($text, $i - 1, 1, 'UTF-8');

        if (in_array($twoChar, ['다.', '요.', '됨.', '함.'], true) || in_array($oneChar, ['.', '!', '?'], true)) {
            $candidate = trim(mb_substr($text, 0, $i, 'UTF-8'));
            if ($candidate !== '' && mb_strlen($candidate, 'UTF-8') <= $maxLen) {
                return $candidate;
            }
        }
    }

    // 단어 경계(공백)에서 자르기 — 문장 경계 미발견 시 단어 중간 절단 방지
    $wordBoundaryMin = max((int)($maxLen * 0.85), max(1, $minSentenceCut));
    for ($i = $searchStart; $i >= $wordBoundaryMin; $i--) {
        if (mb_substr($text, $i - 1, 1, 'UTF-8') === ' ') {
            $candidate = trim(mb_substr($text, 0, $i, 'UTF-8'));
            if ($candidate !== '' && mb_strlen($candidate, 'UTF-8') <= $maxLen) {
                return $candidate;
            }
        }
    }

    $trimmed = trim(mb_substr($text, 0, $maxLen, 'UTF-8'));
    $trimmed = rtrim($trimmed, " ,;:\t\n\r\0\x0B");

    return $trimmed;
}

function tbm_ai_normalize_plain_style(string $text): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }

    $replacements = [
        '해야 합니다.' => '해야 한다.',
        '해야 합니다' => '해야 한다',
        '할 수 있습니다.' => '할 수 있다.',
        '할 수 있습니다' => '할 수 있다',
        '수 있습니다.' => '수 있다.',
        '수 있습니다' => '수 있다',
        '필요합니다.' => '필요하다.',
        '필요합니다' => '필요하다',
        '중요합니다.' => '중요하다.',
        '중요합니다' => '중요하다',
        '가능합니다.' => '가능하다.',
        '가능합니다' => '가능하다',
        '금지됩니다.' => '금지된다.',
        '금지됩니다' => '금지된다',
        '요구됩니다.' => '요구된다.',
        '요구됩니다' => '요구된다',
        '강조됩니다.' => '강조된다.',
        '강조됩니다' => '강조된다',
        '평가됩니다.' => '평가된다.',
        '평가됩니다' => '평가된다',
        '확인됩니다.' => '확인된다.',
        '확인됩니다' => '확인된다',
        '판단됩니다.' => '판단된다.',
        '판단됩니다' => '판단된다',
        '보입니다.' => '보인다.',
        '보입니다' => '보인다',
        '있습니다.' => '있다.',
        '있습니다' => '있다',
        '없습니다.' => '없다.',
        '없습니다' => '없다',
        '됩니다.' => '된다.',
        '됩니다' => '된다',
        '입니다.' => '이다.',
        '입니다' => '이다',
        '합니다.' => '한다.',
        '합니다' => '한다',
    ];

    $text = strtr($text, $replacements);
    $text = preg_replace('/([가-힣])였습니다\./u', '$1였다.', $text);
    $text = preg_replace('/([가-힣])였습니다/u', '$1였다', $text);
    $text = preg_replace('/([가-힣])했습니다\./u', '$1했다.', $text);
    $text = preg_replace('/([가-힣])했습니다/u', '$1했다', $text);

    return trim($text);
}

function tbm_ai_fill_text_to_min(string $text, int $minLen, int $maxLen, array $fallbackSentences): string
{
    $text = trim((string)$text);
    if ($text === '') {
        $text = trim((string)($fallbackSentences[0] ?? ''));
    }

    foreach ($fallbackSentences as $sentence) {
        if (mb_strlen($text, 'UTF-8') >= $minLen) {
            break;
        }

        $sentence = trim((string)$sentence);
        if ($sentence === '') {
            continue;
        }

        $candidate = trim($text === '' ? $sentence : ($text . ' ' . $sentence));
        if (mb_strlen($candidate, 'UTF-8') > $maxLen) {
            $remaining = $maxLen - mb_strlen($text, 'UTF-8') - ($text === '' ? 0 : 1);
            if ($remaining <= 0) {
                break;
            }

            $sentence = tbm_ai_trim_text_to_limit($sentence, $remaining, 10);
            $candidate = trim($text === '' ? $sentence : ($text . ' ' . $sentence));
        }

        if ($candidate !== '') {
            $text = $candidate;
        }
    }

    return trim($text);
}

function tbm_ai_quiz_normalize_lines(string $quiz): array
{
    $quiz = str_replace(["\r\n", "\r"], "\n", trim($quiz));
    if ($quiz === '') {
        return [];
    }

    $lines = preg_split('/\n+/u', $quiz) ?: [];
    $normalized = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        $line = preg_replace('/[ \t]+/u', ' ', $line);
        if ($line !== '') {
            $normalized[] = $line;
        }
    }

    return $normalized;
}

function tbm_ai_quiz_join_lines(array $lines): string
{
    $lines = array_values(array_filter(array_map(static fn($line) => trim((string)$line), $lines), static fn($line) => $line !== ''));
    return implode("\n", $lines);
}

function tbm_ai_sanitize_quiz_line(string $line): string
{
    $line = trim((string)$line);
    if ($line === '') {
        return '';
    }

    $junkPhrases = [
        '현장 안전기준으로 판단한다.',
        '재발 방지 원칙을 함께 고려한다.',
        '작업 전 점검 기준을 반영한다.',
    ];

    foreach ($junkPhrases as $phrase) {
        $line = preg_replace('/\s*' . preg_quote($phrase, '/') . '/u', '', $line);
    }

    $line = preg_replace('/\s{2,}/u', ' ', $line);

    return trim((string)$line);
}

function tbm_ai_sanitize_quiz_text(string $quiz): string
{
    $lines = tbm_ai_quiz_normalize_lines($quiz);
    if ($lines === []) {
        return '';
    }

    if (isset($lines[0])) {
        $lines[0] = tbm_ai_sanitize_quiz_line($lines[0]);
    }

    return tbm_ai_quiz_join_lines($lines);
}

function tbm_ai_autofit_single_quiz(string $quiz, int $targetMin = 183, int $targetMax = 225): string
{
    $lines = tbm_ai_quiz_normalize_lines(tbm_ai_sanitize_quiz_text($quiz));
    if ($lines === []) {
        return '';
    }

    $quiz = tbm_ai_quiz_join_lines($lines);

    if (mb_strlen($quiz, 'UTF-8') > $targetMax) {
        $guard = 0;
        while (mb_strlen($quiz, 'UTF-8') > $targetMax && $guard < 6) {
            $guard++;
            $questionLine = trim((string)($lines[0] ?? ''));
            if ($questionLine === '') {
                break;
            }

            $excess = mb_strlen($quiz, 'UTF-8') - $targetMax;
            $nextLimit = max(52, mb_strlen($questionLine, 'UTF-8') - $excess);
            $trimmed = tbm_ai_trim_text_to_limit($questionLine, $nextLimit, 18);
            if ($trimmed === '' || $trimmed === $questionLine) {
                break;
            }

            $lines[0] = $trimmed;
            $quiz = tbm_ai_quiz_join_lines($lines);
        }
    }

    if (mb_strlen($quiz, 'UTF-8') < $targetMin) {
        $questionFillers = [
            ' 작업 기준으로 가장 적절한 것은?',
            ' 현장 대응으로 옳은 것은?',
            ' 재발 방지 관점에서 맞는 것은?',
            ' 안전수칙 기준으로 적절한 것은?',
        ];

        $guard = 0;
        while (mb_strlen($quiz, 'UTF-8') < $targetMin && $guard < 20) {
            $guard++;
            $changed = false;

            $questionLine = trim((string)($lines[0] ?? ''));
            foreach ($questionFillers as $suffix) {
                if (mb_strlen($quiz, 'UTF-8') >= $targetMin) {
                    break;
                }

                if (mb_strpos($questionLine, trim($suffix), 0, 'UTF-8') !== false) {
                    continue;
                }

                $candidateLine = trim($questionLine . $suffix);
                if (mb_strlen($candidateLine, 'UTF-8') > 110) {
                    continue;
                }

                $lines[0] = $candidateLine;
                $questionLine = $candidateLine;
                $quiz = tbm_ai_quiz_join_lines($lines);
                $changed = true;
                break;
            }

            if (!$changed) {
                break;
            }
        }
    }

    return tbm_ai_sanitize_quiz_text(tbm_ai_quiz_join_lines($lines));
}

function tbm_ai_autofit_quiz_fields(array $parsed): array
{
    $quizKeys = ['quiz_1', 'quiz_2', 'quiz_3'];
    foreach ($quizKeys as $key) {
        $parsed[$key] = tbm_ai_autofit_single_quiz(trim((string)($parsed[$key] ?? '')));
    }

    $quizTotal = 0;
    foreach ($quizKeys as $key) {
        $quizTotal += mb_strlen((string)$parsed[$key], 'UTF-8');
    }

    if ($quizTotal > 820) {
        foreach ($quizKeys as $key) {
            $parsed[$key] = tbm_ai_autofit_single_quiz((string)$parsed[$key], 175, 220);
        }
    }

    $quizTotal = 0;
    foreach ($quizKeys as $key) {
        $quizTotal += mb_strlen((string)$parsed[$key], 'UTF-8');
    }

    if ($quizTotal < 545) {
        foreach ($quizKeys as $key) {
            $parsed[$key] = tbm_ai_autofit_single_quiz((string)$parsed[$key], 186, 200);
        }
    }

    return $parsed;
}

function tbm_ai_autofit_body_text(string $bodyText): string
{
    $bodyText = trim((string)$bodyText);
    $firstLabel = '[사고내용 및 원인]';
    $secondLabel = '[예방대책]';
    $firstPos = mb_strpos($bodyText, $firstLabel);
    $secondPos = mb_strpos($bodyText, $secondLabel);

    if ($bodyText === '' || $firstPos === false || $secondPos === false || $secondPos <= $firstPos) {
        return $bodyText;
    }

    $firstBlock = trim(mb_substr($bodyText, $firstPos + mb_strlen($firstLabel, 'UTF-8'), $secondPos - ($firstPos + mb_strlen($firstLabel, 'UTF-8')), 'UTF-8'));
    $secondBlock = trim(mb_substr($bodyText, $secondPos + mb_strlen($secondLabel, 'UTF-8'), null, 'UTF-8'));

    $firstBlock = tbm_ai_normalize_plain_style($firstBlock);
    $secondBlock = tbm_ai_normalize_plain_style($secondBlock);

    $firstBlock = tbm_ai_trim_text_to_limit($firstBlock, 250, 180);
    $secondBlock = tbm_ai_trim_text_to_limit($secondBlock, 160, 100);

    $firstBlock = tbm_ai_fill_text_to_min($firstBlock, 220, 250, [
        '작업 전에는 공정별 위험요인을 다시 확인하고 관리 책임을 명확히 해야 한다.',
        '현장에서는 기본 안전수칙 미준수와 관리 공백이 겹치면 중대재해로 이어질 수 있다.',
    ]);
    $secondBlock = tbm_ai_fill_text_to_min($secondBlock, 140, 160, [
        '관리자는 작업 전 위험요인을 다시 점검하고 비상 대응 절차를 즉시 실행할 수 있어야 한다.',
        '작업자는 이상 징후 발견 시 즉시 보고하고 보호구와 감시 체계를 유지해야 한다.',
    ]);

    return $firstLabel . "\n" . $firstBlock . "\n\n" . $secondLabel . "\n" . $secondBlock;
}

function tbm_ai_autofit_parsed_response(array $parsed): array
{
    if (isset($parsed['body_text']) && is_string($parsed['body_text'])) {
        $parsed['body_text'] = tbm_ai_autofit_body_text($parsed['body_text']);
    }

    $parsed = tbm_ai_autofit_quiz_fields($parsed);

    return $parsed;
}


// (주석 처리된 tbm_ai_download_image_to_local 제거됨 — tbm_ai_download_and_validate_image로 대체)

function tbm_ai_has_strong_article_body(string $body): bool
{
    $body = trim($body);
    if ($body === '') {
        return false;
    }

    if (mb_strlen($body, "UTF-8") < 220) {
        return false;
    }

    $hasNarrative = function_exists('tbm_news_has_incident_narrative')
        ? tbm_news_has_incident_narrative($body)
        : preg_match('/(작업\s*(중|하던\s*중|도중)|현장\s*에서.{0,30}(사고|사망|부상)|\d+\s*[명인]\s*(이|가|은|는)?\s*(사망|부상))/u', $body) === 1;

    return $hasNarrative;
}

function tbm_ai_is_valid_local_image_file(?string $relativePath): bool
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '') {
        return false;
    }

    $fullPath = __DIR__ . '/' . ltrim($relativePath, '/');
    if (!is_file($fullPath)) {
        return false;
    }

    $info = @getimagesize($fullPath);
    if (!is_array($info)) {
        return false;
    }

    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    return $width >= 120 && $height >= 120;
}

function tbm_ai_article_candidate_is_usable(array $article): bool
{
    $body = trim((string)($article['article_body'] ?? ''));
    $accidentDate = trim((string)($article['accident_date'] ?? ''));

    if (!tbm_ai_has_strong_article_body($body)) {
        return false;
    }

    if ($accidentDate === '') {
        return false;
    }

    return true;
}

function tbm_ai_is_length_validation_error(string $message): bool
{
    return str_contains($message, '길이가')
        || str_contains($message, '합계가')
        || str_contains($message, '퀴즈 3개 총 글자 수');
}

function tbm_ai_get_validation_retry_prompt(bool $shorten = false): string
{
    $text = "\n\n중요: 아래 규칙을 정확히 지키세요.\n"
        . "- 사고내용 및 원인은 220~250자, 예방대책은 140~160자.\n"
        . "- 가장 안전한 목표는 사고내용 및 원인 230~240자, 예방대책 145~155자입니다.\n"
        . "- 각 섹션의 마지막 문장은 반드시 완전하게 끝내세요. 글자 수 제한 안에서 완전한 문장을 끝마칠 수 없다면 더 앞의 문장에서 멈추세요.\n"
        . "- body_text는 두 문단으로만 구성하고, [사고내용 및 원인]과 [예방대책] 레이블을 정확히 포함하세요.\n"
        . "- body_text 본문은 경어체가 아닌 평어체로 작성하세요. 예: '점검해야 합니다' 대신 '점검해야 한다'.\n"
        . "- '-습니다', '-입니다', '-해야 합니다' 같은 경어체 어미는 쓰지 마세요.\n"
        . "- 사고내용+예방대책 합계는 420자를 넘지 않아야 합니다.\n"
        . "- 퀴즈 3개 총 글자 수는 545자 이상이어야 하며, 가장 권장되는 범위는 545~700자입니다. 절대 545자 미만으로 내지 마세요.\n"
        . "- 퀴즈는 각 문항을 대체로 180~195자 정도로 맞추면 합계 범위를 맞추기 쉽습니다. 가장 안전한 목표는 각 문항 185~190자입니다.\n"
        . "- 최종 JSON을 출력하기 직전에 반드시 스스로 글자 수를 다시 세고, 범위를 벗어나면 먼저 문장을 늘이거나 줄인 뒤에만 출력하세요.\n"
        . "- 특히 퀴즈 합계가 545자 미만이면 질문 또는 보기 설명을 조금 더 구체화해서 길이를 채우고, 가능하면 700자 이하를 유지하세요. 다만 820자를 넘기면 반드시 줄이세요.\n"
        . "- JSON 키 이름과 형식을 정확히 유지하세요.\n"
        . "- 순수 JSON 객체만 반환하세요.";

    if ($shorten) {
        $text .= "\n- 이전 출력이 길이를 초과했습니다. 이번에는 더 간결하고 짧게 작성하세요.\n"
            . "- 불필요한 수식어를 제거하고, 요구 길이 내부에서만 답변하세요.\n"
            . "- 사고내용 및 원인은 220~240자, 예방대책은 140~150자로 작성하고 총 420자 이하로 유지하세요.";
    }

    return $text;
}

function tbm_ai_measure_parsed_response(array $parsed): array
{
    $bodyText = trim((string)($parsed['body_text'] ?? ''));
    $firstLabel = '[사고내용 및 원인]';
    $secondLabel = '[예방대책]';
    $firstPos = mb_strpos($bodyText, $firstLabel);
    $secondPos = mb_strpos($bodyText, $secondLabel);

    $firstBlock = '';
    $secondBlock = '';

    if ($firstPos !== false && $secondPos !== false && $secondPos > $firstPos) {
        $firstBlock = trim(mb_substr($bodyText, $firstPos + mb_strlen($firstLabel, 'UTF-8'), $secondPos - ($firstPos + mb_strlen($firstLabel, 'UTF-8')), 'UTF-8'));
        $secondBlock = trim(mb_substr($bodyText, $secondPos + mb_strlen($secondLabel, 'UTF-8'), null, 'UTF-8'));
    }

    $quiz1 = trim((string)($parsed['quiz_1'] ?? ''));
    $quiz2 = trim((string)($parsed['quiz_2'] ?? ''));
    $quiz3 = trim((string)($parsed['quiz_3'] ?? ''));

    return [
        'accident_len' => mb_strlen($firstBlock, 'UTF-8'),
        'prevention_len' => mb_strlen($secondBlock, 'UTF-8'),
        'body_total_len' => mb_strlen($firstBlock, 'UTF-8') + mb_strlen($secondBlock, 'UTF-8'),
        'quiz_1_len' => mb_strlen($quiz1, 'UTF-8'),
        'quiz_2_len' => mb_strlen($quiz2, 'UTF-8'),
        'quiz_3_len' => mb_strlen($quiz3, 'UTF-8'),
        'quiz_total_len' => mb_strlen($quiz1, 'UTF-8') + mb_strlen($quiz2, 'UTF-8') + mb_strlen($quiz3, 'UTF-8'),
    ];
}

function tbm_ai_build_revision_prompt(string $basePrompt, array $parsed, array $validationErrors): string
{
    $metrics = tbm_ai_measure_parsed_response($parsed);
    $json = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json) || trim($json) === '') {
        $json = '{}';
    }

    $errorLines = [];
    foreach ($validationErrors as $error) {
        $errorLines[] = '- ' . $error;
    }

    return $basePrompt
        . "\n\n이전 출력은 JSON 파싱에는 성공했지만 검증에 실패했습니다. 아래 이전 JSON을 버리지 말고, 사실관계와 키 이름은 유지한 채 길이만 조정해서 다시 작성하세요.\n"
        . "수정 목표:\n"
        . "- 사고내용 및 원인: 230~240자 목표, 허용 범위 220~250자\n"
        . "- 예방대책: 145~155자 목표, 허용 범위 140~160자\n"
        . "- 각 섹션의 마지막 문장은 반드시 완전하게 끝내세요. 글자 수 제한 안에서 완전히 끝낼 수 없다면 더 앞의 문장에서 멈추세요.\n"
        . "- 사고내용+예방대책 합계: 380~400자 목표, 절대 420자 초과 금지\n"
        . "- 퀴즈 3개 총 글자 수: 560~640자 목표, 권장 범위 545~700자, 절대 상한 820자\n"
        . "- 퀴즈는 각 문항을 대체로 185~190자 정도로 균형 있게 맞추고, 어느 한 문항만 짧아지지 않게 하세요\n"
        . "- 최종 JSON을 출력하기 직전에 사고내용, 예방대책, quiz_1, quiz_2, quiz_3, 퀴즈 합계를 다시 계산하고 범위에 맞을 때만 출력하세요\n"
        . "- 퀴즈 합계가 545자 미만이면 각 보기 설명을 1~2어절씩 보강하여 길이를 채우고, 가능하면 700자 이하를 유지하세요. 820자를 넘으면 반드시 줄이세요\n"
        . "- body_text 본문은 반드시 평어체로 유지하세요. '-습니다', '-입니다'는 쓰지 말고 '-다', '-이다', '-해야 한다' 형태로 고치세요\n"
        . "- source_url, accident_date가 이미 들어 있으면 그대로 유지하세요\n"
        . "- 새로운 설명문을 붙이지 말고, 최종 JSON 객체만 다시 출력하세요\n"
        . "\n현재 길이 측정값:\n"
        . sprintf(
            "- 사고내용 및 원인: %d자\n- 예방대책: %d자\n- 본문 합계: %d자\n- quiz_1: %d자\n- quiz_2: %d자\n- quiz_3: %d자\n- 퀴즈 합계: %d자\n",
            $metrics['accident_len'],
            $metrics['prevention_len'],
            $metrics['body_total_len'],
            $metrics['quiz_1_len'],
            $metrics['quiz_2_len'],
            $metrics['quiz_3_len'],
            $metrics['quiz_total_len']
        )
        . "\n검증 오류:\n"
        . implode("\n", $errorLines)
        . "\n\n이전 JSON:\n"
        . $json;
}

function tbm_ai_generate_via_gemini(
    string $prompt,
    string $targetDate,
    ?string $sourceUrl = null,
    ?string $imageUrl = null,
    ?string $imageKeyword = null,
    bool $allowKeywordFallbackImage = true
): array
{
    // gemini-2.5-flash는 thinking 모델이므로 maxOutputTokens에 thinking 토큰이 포함됨
    // 4096이면 thinking에 소진되고 실제 출력이 잘림 → 65536으로 확대
    // thinkingConfig.thinkingBudget으로 thinking 토큰을 제한하여 출력 토큰 확보
    $basePrompt = $prompt;
    $payload = [
        'contents' => [[
            'parts' => [['text' => $basePrompt]],
        ]],
        'generationConfig' => [
            'temperature'     => 0.45,
            'maxOutputTokens' => 65536,
            'thinkingConfig'  => [
                'thinkingBudget' => 2048,
            ],
        ],
    ];

    $lastError = null;
    $localImageFile = null;

    if ($imageUrl !== null && trim($imageUrl) !== '') {
        $localImageFile = tbm_ai_download_and_validate_image(trim($imageUrl));
    }
    if ($localImageFile === null && $allowKeywordFallbackImage && $imageKeyword !== null) {
        $localImageFile = tbm_ai_fetch_fallback_image($imageKeyword);
    }

    $maxAttempts = GEMINI_MAX_RETRIES + 1;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $parsed = null;
        try {
            $decoded = tbm_call_gemini_api($payload);
            $text = tbm_extract_gemini_text($decoded);
            file_put_contents(__DIR__ . '/gemini_raw.txt', $text);

            $parsed = tbm_parse_ai_json($text);
            $parsed = tbm_ai_autofit_parsed_response($parsed);
            $validation = tbm_ai_validate_parsed_response($parsed);
            if (!$validation['valid']) {
                throw new RuntimeException('AI 출력 검증 실패: ' . implode('; ', $validation['errors']));
            }
            $accidentTitle = trim((string)($parsed['accident_title'] ?? ''));
            $resolvedSourceUrl = trim((string)($parsed['source_url'] ?? ''));

            if ($resolvedSourceUrl === '') {
                $resolvedSourceUrl = trim((string)$sourceUrl);
            }
            if ($resolvedSourceUrl === '' && $accidentTitle !== '') {
                $resolvedSourceUrl = tbm_make_fallback_source_url($accidentTitle);
            }

            return [
                'accident_date'        => trim((string)($parsed['accident_date'] ?? $targetDate)),
                'accident_title'       => $accidentTitle,
                'edu_title'            => trim((string)($parsed['edu_title'] ?? ('중대재해 전파(' . $accidentTitle . ')'))),
                'body_text'            => trim((string)($parsed['body_text'] ?? '')),
                'image_file'           => $localImageFile,
                'source_url'           => $resolvedSourceUrl,
                'image_search_keyword' => trim((string)($parsed['image_search_keyword'] ?? ($imageKeyword ?? $accidentTitle))),
                'quiz_1'               => trim((string)($parsed['quiz_1'] ?? '')),
                'quiz_2'               => trim((string)($parsed['quiz_2'] ?? '')),
                'quiz_3'               => trim((string)($parsed['quiz_3'] ?? '')),
                'ai_generated'         => 1,
            ];
        } catch (Throwable $e) {
            $lastError = $e;
            @file_put_contents(__DIR__ . '/gemini_error.txt', '[' . date('c') . '] [attempt ' . $attempt . '] ' . $e->getMessage() . "\n", FILE_APPEND);
            if (str_contains($e->getMessage(), 'GEMINI_QUOTA_EXCEEDED::')) {
                break;
            }
            if ($attempt >= $maxAttempts) {
                break;
            }

            $shorten = false;
            $retryPrompt = $basePrompt . tbm_ai_get_validation_retry_prompt(false);
            if (str_contains($e->getMessage(), 'AI 출력 검증 실패')) {
                $shorten = tbm_ai_is_length_validation_error($e->getMessage());
                if (is_array($parsed)) {
                    $validation = tbm_ai_validate_parsed_response($parsed);
                    $retryPrompt = tbm_ai_build_revision_prompt($basePrompt, $parsed, $validation['errors']);
                }
            }

            if ($retryPrompt === $basePrompt . tbm_ai_get_validation_retry_prompt(false) && $shorten) {
                $retryPrompt = $basePrompt . tbm_ai_get_validation_retry_prompt(true);
            }

            $payload['contents'][0]['parts'][0]['text'] = $retryPrompt;
            $payload['generationConfig']['temperature'] = $shorten ? 0.2 : 0.3;
            @file_put_contents(__DIR__ . '/gemini_error.txt', '[' . date('c') . '] [retrying] prompt updated for attempt ' . ($attempt + 1) . "\n", FILE_APPEND);
            usleep(700000);
        }
    }

    throw new RuntimeException('AI 생성 실패: ' . ($lastError ? $lastError->getMessage() : '알 수 없는 오류'));

    throw new RuntimeException('AI 생성 실패: ' . ($lastError ? $lastError->getMessage() : '알 수 없는 오류'));
}

// ── 최근 사용 source_url 필터 ────────────────────────────────

function tbm_ai_get_recent_used_source_urls(int $days = TBM_AI_USED_SOURCE_LOOKBACK_DAYS, int $limit = 300): array
{
    if (!function_exists('tbm_db')) {
        return [];
    }

    try {
        $pdo = tbm_db();
        $stmt = $pdo->prepare(
            'SELECT DISTINCT c.source_url
               FROM tbm_accident_content c
               JOIN tbm_documents d
                 ON d.content_id = c.id
              WHERE c.source_url IS NOT NULL
                AND c.source_url <> ""
                AND d.generation_status = "success"
                AND COALESCE(d.generated_at, d.updated_at) >= DATE_SUB(NOW(), INTERVAL :days DAY)
              ORDER BY COALESCE(d.generated_at, d.updated_at) DESC
              LIMIT :lim'
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $urls = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'source_url');
        return array_values(array_filter(array_map('trim', $urls), static fn($v) => $v !== ''));
    } catch (Throwable $e) {
        tbm_ai_log_debug('최근 사용 source_url 조회 실패: ' . $e->getMessage());
        return [];
    }
}

function tbm_ai_filter_siren_items(array $items): array
{
    $usedUrls = array_fill_keys(tbm_ai_get_recent_used_source_urls(), true);
    $filtered = [];
    $seen = [];

    foreach ($items as $item) {
        $detailUrl = trim((string)($item['detail_url'] ?? ''));
        $imageUrl  = trim((string)($item['image_url'] ?? ''));
        $title     = trim((string)($item['title'] ?? ''));
        $key       = md5(mb_strtolower($title . '|' . $detailUrl . '|' . $imageUrl, 'UTF-8'));

        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        if (($detailUrl !== '' && isset($usedUrls[$detailUrl])) || ($imageUrl !== '' && isset($usedUrls[$imageUrl]))) {
            continue;
        }

        $filtered[] = $item;
    }

    return $filtered;
}

// ── 후보 기사 수집 ────────────────────────────────────────────

function tbm_ai_shuffle_assoc(array $items): array
{
    if (count($items) <= 1) {
        return $items;
    }

    $keys = array_keys($items);
    shuffle($keys);

    $out = [];
    foreach ($keys as $k) {
        $out[] = $items[$k];
    }

    return $out;
}

function tbm_ai_build_search_queries(string $targetDate): array
{
    $baseKeywords = [
        '중대재해 사망 사고',
        '작업자 사망 사고',
        '근로자 사망 사고',
        '현장 작업 중 사망',
        '감전 사망 사고',
        '추락 사망 사고',
        '끼임 사망 사고',
        '협착 사망 사고',
        '질식 사망 사고',
        '붕괴 사망 사고',
        '화재 사망 사고',
        '폭발 사망 사고',
        '깔림 사망 사고',
        '건설현장 작업자 사망',
        '전기공사 감전 사고',
        '공사현장 추락 사고',
        '설비 작업 중 사망',
        '작업 중 숨져',
    ];

    shuffle($baseKeywords);
    return array_slice($baseKeywords, 0, 6);
}


function tbm_ai_normalize_title(string $title): string
{
    $title = trim($title);
    $title = mb_strtolower($title, 'UTF-8');

    // 불필요 단어 제거
    $title = preg_replace('/(사망|숨져|숨진|사고|발생|작업자|근로자)/u', '', $title);

    $title = preg_replace('/\[[^\]]+\]/u', ' ', $title);
    $title = preg_replace('/\([^)]+\)/u', ' ', $title);
    $title = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title);
    $title = preg_replace('/\s+/u', ' ', $title);

    return trim($title);
}

function tbm_ai_get_recent_used_title_map(int $days = TBM_AI_USED_SOURCE_LOOKBACK_DAYS, int $limit = 300): array
{
    if (!function_exists('tbm_db')) {
        return [];
    }

    try {
        $pdo = tbm_db();
        $stmt = $pdo->prepare(
            'SELECT DISTINCT c.accident_title
               FROM tbm_accident_content c
               JOIN tbm_documents d
                 ON d.content_id = c.id
              WHERE c.accident_title IS NOT NULL
                AND c.accident_title <> ""
                AND d.generation_status = "success"
                AND COALESCE(d.generated_at, d.updated_at) >= DATE_SUB(NOW(), INTERVAL :days DAY)
              ORDER BY COALESCE(d.generated_at, d.updated_at) DESC
              LIMIT :lim'
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $title = tbm_ai_normalize_title((string)($row['accident_title'] ?? ''));
            if ($title !== '') {
                $map[$title] = true;
            }
        }
        return $map;
    } catch (Throwable $e) {
        tbm_ai_log_debug('최근 사용 제목 조회 실패: ' . $e->getMessage());
        return [];
    }
}

/**
 * 기사 후보 점수화 — 2단계 재설계: 사건성 중심
 *
 * 점수 구조 (총 최대 약 40점, 최소 0점):
 *
 * [A] 사건성 핵심 (최대 20점) ← 2단계 핵심 개선
 *   A1. 사건 서술 패턴 존재 여부          +8  (가장 중요)
 *   A2. 구체적 인명피해 수치 표현           +5
 *   A3. 사고 유형 키워드 (제목)            +4
 *   A4. 사고 유형 키워드 (본문 다수 존재)   +3
 *
 * [B] 기사 품질 (최대 12점)
 *   B1. 사고일자 확인                      +6
 *   B2. 사고 원인 서술 존재                +3  ← 2단계 신규
 *   B3. 예방/안전조치 언급                 +2  ← 2단계 신규
 *   B4. 이미지 URL 확보                   +1
 *
 * [C] 본문 충실도 (최대 5점) — 보조 지표로 격하
 *   C1. 본문 500자 이상                   +3
 *   C2. 본문 250자 이상                   +2
 *   C3. 본문 150자 미만                   -3  (패널티)
 *
 * [D] 감점 (무제한)
 *   D1. 분석·정책·통계 기사              -15
 *   D2. 사고일자 없음                    -12
 *   D3. has_context 없음                  -8
 *   D4. 사건 서술 패턴 전혀 없음           -6  ← 2단계 신규
 *   D5. 사고 유형 키워드 본문에도 없음     -4  ← 2단계 신규
 */
function tbm_ai_score_article_candidate(array $candidate): int
{
    $score = 0;

    $article  = $candidate['article'] ?? [];
    $title    = trim((string)($article['article_title'] ?? ''));
    $body     = trim((string)($article['article_body'] ?? ''));
    $bodyLen  = mb_strlen($body, 'UTF-8');

    // ════════════════════════════════════════════════════════
    // [A] 사건성 핵심 — 이 블록이 선별의 주 기준
    // ════════════════════════════════════════════════════════

    // A1. 사건 서술 패턴 ("작업 중", "하던 중", "감전되면서" 등)
    $hasNarrative = false;
    if (function_exists('tbm_news_has_incident_narrative')) {
        $hasNarrative = tbm_news_has_incident_narrative($body);
    } else {
        // 폴백: 간이 패턴
        $hasNarrative = preg_match(
            '/(작업\s*(중|하던|도중)|하던\s*중\s*(사고|사망)|현장에서.{0,30}(사고|사망)|'
            . '(추락|감전|끼임|협착)\s*(사고|발생|사망)|(근로자|작업자).{0,20}(사망|부상))/u',
            $body
        ) === 1;
    }

    if ($hasNarrative) {
        $score += 8;                     // A1 가산
    } else {
        $score -= 6;                     // D4 감점
    }

    // A2. 구체적 인명피해 수치 ("2명 사망", "작업자 1명 부상" 등)
    $hasVictimCount = preg_match(
        '/\d+\s*[명인]\s*(이|가|은|는)?\s*(사망|부상|다쳐|숨|사망자|부상자)/u',
        $title . ' ' . $body
    ) === 1;

    if ($hasVictimCount) {
        $score += 5;
    }

    // A3. 사고 유형 키워드 — 제목 포함 (1개당 +4, 최대 1회)
    $accidentTypeKw = ['감전', '추락', '떨어짐', '끼임', '협착', '질식', '화재', '붕괴', '폭발', '깔림'];
    $titleAccidentKwCount = 0;
    foreach ($accidentTypeKw as $kw) {
        if (mb_strpos($title, $kw, 0, 'UTF-8') !== false) {
            $titleAccidentKwCount++;
        }
    }
    // "사망"은 별도 처리 (제목에 사망이 있으면 사건 가능성 높음)
    $titleHasDeath = mb_strpos($title, '사망', 0, 'UTF-8') !== false
                  || mb_strpos($title, '숨진', 0, 'UTF-8') !== false;

    if ($titleAccidentKwCount > 0) {
        $score += 4;
    } elseif ($titleHasDeath) {
        $score += 2;
    }

    // A4. 사고 유형 키워드 — 본문 다수 등장 여부
    $bodyAccidentKwCount = 0;
    $allAccidentKw = array_merge($accidentTypeKw, ['사고', '사망', '부상', '발생', '숨지']);
    foreach ($allAccidentKw as $kw) {
        if (mb_substr_count($body, $kw) >= 2) {
            $bodyAccidentKwCount++;
        }
    }

    if ($bodyAccidentKwCount >= 3) {
        $score += 3;
    } elseif ($bodyAccidentKwCount >= 1) {
        $score += 1;
    } else {
        $score -= 4;                     // D5 감점
    }

    // ════════════════════════════════════════════════════════
    // [B] 기사 품질
    // ════════════════════════════════════════════════════════

    // B1. 사고일자 확보 여부
    $accidentDate = trim((string)($article['accident_date'] ?? ''));
    if ($accidentDate !== '') {
        $score += 6;
    } else {
        $score -= 12;                    // D2 감점
    }

    // B2. 사고 원인 서술 존재 ("안전장치 미설치", "접지 불량", "추락 방지망 없음" 등)
    $hasCausation = preg_match(
        '/(원인|미설치|불량|미비|없어|미흡|부족|소홀|위반|무시|고장).{0,40}(사고|사망|부상|발생)/u',
        $body
    ) === 1 || preg_match(
        '/(사고|사망|부상|발생).{0,40}(원인|미설치|불량|미비|없어|미흡|부족|소홀|위반|무시|고장)/u',
        $body
    ) === 1;

    if ($hasCausation) {
        $score += 3;
    }

    // B3. 예방·안전조치 언급
    $hasPrevention = preg_match(
        '/(예방|안전\s*(조치|장치|대책|교육|점검|확인)|주의\s*(사항|필요)|작업\s*(허가|절차|안전))/u',
        $body
    ) === 1;

    if ($hasPrevention) {
        $score += 2;
    }

    // B4. 이미지 URL 확보
    if (trim((string)($article['image_url'] ?? '')) !== '') {
        $score += 1;
    }

    // has_context 플래그 (기존 로직 유지, 단 가중치 조정)
    if (!empty($article['has_context'])) {
        $score += 2;
    } else {
        $score -= 8;                     // D3 감점
    }

    // ════════════════════════════════════════════════════════
    // [C] 본문 충실도 — 보조 지표 (길이만으로 과도하게 유리해지지 않도록 상한 축소)
    // ════════════════════════════════════════════════════════

    if ($bodyLen >= 500) {
        $score += 3;
    } elseif ($bodyLen >= 250) {
        $score += 2;
    } elseif ($bodyLen < 150) {
        $score -= 3;                     // C3 패널티
    }

    // ════════════════════════════════════════════════════════
    // [D] 분석·정책 기사 감점 — 가장 강력한 패널티
    // ════════════════════════════════════════════════════════

    if (function_exists('tbm_news_is_analysis_article') && tbm_news_is_analysis_article($title, $body)) {
        $score -= 15;                    // D1 감점
    }

    // KOSHA 사이렌 연동 후보면 소폭 가산
    if (!empty($candidate['siren'])) {
        $score += 2;
    }

    // ════════════════════════════════════════════════════════
    // incident_score (1단계에서 저장된 값) — 보조 보정
    // ════════════════════════════════════════════════════════
    $savedIncidentScore = (int)($article['incident_score'] ?? -1);
    if ($savedIncidentScore >= 0) {
        // 0~10 → -4 ~ +4 범위 보정 (주 점수의 보조 역할)
        $score += (int)round(($savedIncidentScore - 5) * 0.8);
    }

    return max(0, $score);
}

function tbm_ai_collect_article_candidates_from_siren(array $sirenItems, int $limit = 15): array
{
    $candidates = [];
    $seen = [];
    $usedUrls = array_fill_keys(tbm_ai_get_recent_used_source_urls(), true);
    $usedTitles = tbm_ai_get_recent_used_title_map();

    foreach ($sirenItems as $siren) {
        $queries = $siren['search_keywords'] ?? [];
        $queries = tbm_ai_shuffle_assoc($queries);

        foreach ($queries as $query) {
            $articles = tbm_news_fetch_article_candidates((string)$query, 20, [1, 11, 21], ['date', 'sim'], 8);

            foreach ($articles as $article) {
                $url = trim((string)($article['article_url'] ?? ''));
                $body = trim((string)($article['article_body'] ?? ''));
                $title = trim((string)($article['article_title'] ?? ''));
                $normalizedTitle = tbm_ai_normalize_title($title);

                if ($url === '' || $body === '') {
                    continue;
                }

                // 최근 사용 제목과 유사하면 제외
                $isUsedSimilar = false;
                foreach ($usedTitles as $usedTitle => $_) {
                    similar_text($normalizedTitle, $usedTitle, $percent);
                    if ($percent > 80) {
                        $isUsedSimilar = true;
                        break;
                    }
                }

                if ($isUsedSimilar) {
                    continue;
                }

                $accidentDate = trim((string)($article['accident_date'] ?? ''));
                if ($accidentDate === '') {
                    continue;
                }

                if (
                    empty($article['has_context']) ||
                    preg_match('/(사고|사망|부상|발생|숨지|감전|추락|끼임|질식|화재|붕괴|폭발|깔림|협착)/u', $body) !== 1
                ) {
                    continue;
                }

                if (function_exists('tbm_news_is_analysis_article') && tbm_news_is_analysis_article($title, $body)) {
                    continue;
                }

                $incidentScore = function_exists('tbm_news_incident_score')
                    ? tbm_news_incident_score($title, $body)
                    : 5;

                if ($incidentScore < 2) {
                    continue;
                }

                $article['incident_score'] = $incidentScore;

                if (isset($usedUrls[$url])) {
                    continue;
                }


                $key = md5(mb_strtolower($url . '|' . $normalizedTitle, 'UTF-8'));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $candidates[] = [
                    'query'   => $query,
                    'article' => $article,
                    'siren'   => $siren,
                ];

                if (count($candidates) >= $limit) {
                    return $candidates;
                }
            }
        }
    }

    return $candidates;
}

function tbm_ai_fetch_csi_list_html(int $page = 1): string
{
    $page = max(1, $page);

    $responses = [];

    // CSI 목록은 goPage(page_count) 폼 전송 기반이라 POST 응답을 우선 시도한다.
    $postHtml = tbm_ai_fetch_csi_list_html_via_post($page);
    if ($postHtml !== '') {
        $responses[] = $postHtml;
    }

    $urls = $page === 1
        ? [
            'https://www.csi.go.kr/acd/acdCaseList.do',
            'https://www.csi.go.kr/acd/acdCaseList.do?pageIndex=1',
        ]
        : [
            'https://www.csi.go.kr/acd/acdCaseList.do?pageIndex=' . $page,
        ];

    foreach ($urls as $url) {
        $html = function_exists('tbm_news_fetch_url') ? tbm_news_fetch_url($url) : '';
        if ($html !== '') {
            $responses[] = $html;
        }
    }

    $bestHtml = '';
    $bestCount = -1;

    foreach ($responses as $html) {
        $itemCount = count(tbm_ai_parse_csi_case_list($html, 12));
        if ($itemCount > $bestCount) {
            $bestCount = $itemCount;
            $bestHtml = $html;
        }
    }

    return $bestHtml;
}

function tbm_ai_fetch_csi_list_html_via_post(int $page = 1): string
{
    $page = max(1, $page);

    $ch = curl_init('https://www.csi.go.kr/acd/acdCaseList.do');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'page_count' => (string)$page,
            'search_dead_yn' => '',
            'start_ymd' => '',
            'end_ymd' => '',
            'search_key' => '',
            'search_val' => '',
        ]),
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Origin: https://www.csi.go.kr',
            'Referer: https://www.csi.go.kr/acd/acdCaseList.do',
        ],
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $err !== '' || $httpCode >= 400 || !is_string($response)) {
        return '';
    }

    return $response;
}

function tbm_ai_extract_csi_case_no(string $text): string
{
    if (preg_match('/\b(\d{4,})\b/', $text, $matches) === 1) {
        return trim((string)$matches[1]);
    }

    return '';
}

function tbm_ai_extract_csi_detail_case_no(string $href): string
{
    $href = trim($href);
    if ($href === '') {
        return '';
    }

    if (preg_match('/case_no\s*=\s*(\d+)/i', $href, $matches) === 1) {
        return trim((string)$matches[1]);
    }

    if (preg_match('/goDetail\(\s*[\'"]?(\d+)[\'"]?\s*\)/i', $href, $matches) === 1) {
        return trim((string)$matches[1]);
    }

    if (preg_match('/\((?:\s*[\'"])?(\d+)(?:[\'"]?\s*)\)/', $href, $matches) === 1) {
        return trim((string)$matches[1]);
    }

    return '';
}

function tbm_ai_csi_clean_text(string $text): string
{
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/<br\s*\/?>/iu', "\n", $text);
    $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags((string)$text);
    $text = preg_replace('/[ \t]+/u', ' ', (string)$text);
    $text = preg_replace('/\s*\n\s*/u', "\n", (string)$text);
    $text = preg_replace('/\n{2,}/u', "\n", (string)$text);

    return trim((string)$text);
}

function tbm_ai_parse_csi_case_list(string $html, int $limit = 10): array
{
    $rows = [];
    $seen = [];

    if ($html === '') {
        return $rows;
    }

    if (preg_match('/<caption>\s*사고사례 목록 테이블입니다\.\s*<\/caption>(.*?)<\/tbody>/isu', $html, $tableMatches) === 1) {
        $tbodyHtml = (string)$tableMatches[1];
        if (preg_match_all(
            '/<tr[^>]*>.*?<a[^>]+href=["\']javascript:goDetail\([\'"]?(\d+)[\'"]?\)["\'][^>]*>\s*(\d+)\s*<\/a>.*?'
            . '<td[^>]*class=["\'][^"\']*t-left[^"\']*["\'][^>]*>\s*<a[^>]*>(.*?)<\/a>\s*<\/td>.*?'
            . '<td[^>]*>(.*?)<\/td>.*?<td[^>]*>(.*?)<\/td>/isu',
            $tbodyHtml,
            $rowMatches,
            PREG_SET_ORDER
        )) {
            foreach ($rowMatches as $match) {
                $caseNo = trim((string)($match[1] ?? ''));
                $accidentNo = trim((string)($match[2] ?? ''));
                $title = tbm_ai_csi_clean_text((string)($match[3] ?? ''));
                $location = tbm_ai_csi_clean_text((string)($match[4] ?? ''));
                $occurredAt = tbm_ai_csi_clean_text((string)($match[5] ?? ''));

                if ($caseNo === '' || $title === '' || isset($seen[$caseNo])) {
                    continue;
                }

                $seen[$caseNo] = true;
                $rows[] = [
                    'case_no' => $caseNo,
                    'accd_no' => $accidentNo,
                    'title' => $title,
                    'location' => $location,
                    'occurred_at' => $occurredAt,
                    'detail_url' => 'https://www.csi.go.kr/acd/acdCaseView.do?case_no=' . rawurlencode($caseNo),
                ];

                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }
    }

    if (!extension_loaded('dom') || !class_exists('DOMDocument')) {
        return $rows;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . mb_substr($html, 0, 600000, 'UTF-8'));
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $trNodes = $xpath->query('//tr[td]');

    if (!$trNodes) {
        return $rows;
    }

    foreach ($trNodes as $trNode) {
        $tdNodes = $xpath->query('./td', $trNode);
        if (!$tdNodes || $tdNodes->length < 4) {
            continue;
        }

        $cells = [];
        foreach ($tdNodes as $tdNode) {
            $cells[] = trim(preg_replace('/\s+/u', ' ', (string)$tdNode->textContent));
        }

        $accidentNo = tbm_ai_extract_csi_case_no((string)($cells[0] ?? ''));
        $caseNo = '';
        $title = trim((string)($cells[1] ?? ''));
        $location = trim((string)($cells[2] ?? ''));
        $occurredAt = trim((string)($cells[3] ?? ''));

        $anchorNodes = $xpath->query('.//a[@href]', $trNode);
        if ($anchorNodes && $anchorNodes->length > 0) {
            foreach ($anchorNodes as $anchorNode) {
                if (!$anchorNode instanceof DOMElement) {
                    continue;
                }

                $href = trim((string)$anchorNode->getAttribute('href'));
                $caseNo = tbm_ai_extract_csi_detail_case_no($href);
                if ($caseNo !== '') {
                    break;
                }
            }
        }

        if ($caseNo === '') {
            $caseNo = $accidentNo;
        }

        if ($caseNo === '' || $title === '') {
            continue;
        }

        if (isset($seen[$caseNo])) {
            continue;
        }
        $seen[$caseNo] = true;

        $rows[] = [
            'case_no' => $caseNo,
            'accd_no' => $accidentNo,
            'title' => $title,
            'location' => $location,
            'occurred_at' => $occurredAt,
            'detail_url' => 'https://www.csi.go.kr/acd/acdCaseView.do?case_no=' . rawurlencode($caseNo),
        ];

        if (count($rows) >= $limit) {
            break;
        }
    }

    return $rows;
}

function tbm_ai_extract_csi_fields_from_dom(string $html): array
{
    $fields = [];

    if ($html === '' || !extension_loaded('dom') || !class_exists('DOMDocument')) {
        return $fields;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . mb_substr($html, 0, 700000, 'UTF-8'));
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $rowNodes = $xpath->query('//section[@id="content"]//table//tr');
    if (!$rowNodes || $rowNodes->length === 0) {
        $rowNodes = $xpath->query('//table[contains(@class, "table-bordered")]//tr');
    }

    if (!$rowNodes) {
        return $fields;
    }

    $activeGroup = '';
    $activeGroupRows = 0;

    foreach ($rowNodes as $rowNode) {
        $cellNodes = $xpath->query('./th|./td', $rowNode);
        if (!$cellNodes || $cellNodes->length === 0) {
            continue;
        }

        $cells = [];
        foreach ($cellNodes as $cellNode) {
            if (!$cellNode instanceof DOMElement) {
                continue;
            }

            $innerHtml = '';
            foreach ($cellNode->childNodes as $childNode) {
                $innerHtml .= $dom->saveHTML($childNode);
            }

            $text = tbm_ai_csi_clean_text($innerHtml !== '' ? $innerHtml : (string)$cellNode->textContent);
            if ($text === '') {
                continue;
            }

            $className = ' ' . trim((string)$cellNode->getAttribute('class')) . ' ';
            $cells[] = [
                'text' => $text,
                'is_header' => str_contains($className, ' td-head ') || strcasecmp($cellNode->tagName, 'th') === 0,
                'rowspan' => max(1, (int)$cellNode->getAttribute('rowspan')),
            ];
        }

        if ($cells === []) {
            continue;
        }

        $groupLabel = '';
        $startIndex = 0;
        if (($cells[0]['is_header'] ?? false) && (($cells[0]['rowspan'] ?? 1) > 1) && count($cells) >= 3) {
            $groupLabel = trim((string)$cells[0]['text']);
            $activeGroup = $groupLabel;
            $activeGroupRows = (int)$cells[0]['rowspan'] - 1;
            $startIndex = 1;
        } elseif ($activeGroupRows > 0) {
            $groupLabel = $activeGroup;
        }

        $pendingLabels = [];
        for ($index = $startIndex; $index < count($cells); $index++) {
            $cell = $cells[$index];
            if (!empty($cell['is_header'])) {
                $pendingLabels[] = trim((string)$cell['text']);
                continue;
            }

            if ($pendingLabels === []) {
                continue;
            }

            $label = trim((string)array_pop($pendingLabels));
            $value = trim((string)$cell['text']);
            if ($label === '' || $value === '') {
                $pendingLabels = [];
                continue;
            }

            if (!isset($fields[$label])) {
                $fields[$label] = $value;
            }

            if ($groupLabel !== '') {
                $groupKey = $groupLabel . ':' . $label;
                if (!isset($fields[$groupKey])) {
                    $fields[$groupKey] = $value;
                }
            }

            $pendingLabels = [];
        }

        if ($startIndex !== 1 && $activeGroupRows > 0) {
            $activeGroupRows--;
            if ($activeGroupRows <= 0) {
                $activeGroup = '';
                $activeGroupRows = 0;
            }
        }
    }

    return $fields;
}

function tbm_ai_extract_csi_field_by_regex(string $html, string $label): string
{
    $labelPattern = preg_quote($label, '/');
    $patterns = [
        '/<(?:th|td)[^>]*>\s*' . $labelPattern . '\s*<\/(?:th|td)>\s*<(?:th|td)[^>]*>(.*?)<\/(?:th|td)>/isu',
        '/<(?:th|td)[^>]*class=["\'][^"\']*(?:td-head|th)[^"\']*["\'][^>]*>\s*' . $labelPattern . '\s*<\/(?:th|td)>\s*<(?:th|td)[^>]*>(.*?)<\/(?:th|td)>/isu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $matches) === 1) {
            return tbm_ai_csi_clean_text((string)$matches[1]);
        }
    }

    return '';
}

function tbm_ai_make_csi_absolute_url(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $url) === 1) {
        return function_exists('tbm_news_clean_url') ? tbm_news_clean_url($url) : $url;
    }

    $url = preg_replace('~^(?:\./|\.\./)+~', '/', $url);
    if ($url === '') {
        return '';
    }

    if ($url[0] !== '/') {
        $url = '/' . ltrim($url, '/');
    }

    return 'https://www.csi.go.kr' . $url;
}

function tbm_ai_fetch_csi_photo_html(string $accdNo): string
{
    static $cache = [];

    $accdNo = trim($accdNo);
    if ($accdNo === '') {
        return '';
    }

    if (array_key_exists($accdNo, $cache)) {
        return $cache[$accdNo];
    }

    if (!function_exists('curl_init')) {
        $cache[$accdNo] = '';
        return '';
    }

    $ch = curl_init('https://www.csi.go.kr/acd/photoInc.do');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'biz_id' => 'acdDB',
            'accd_no' => $accdNo,
            'popup_yn' => '',
            'menu_id' => 'ACD00148',
            'start_ymd' => '',
            'end_ymd' => '',
            'search_key' => '',
            'search_val' => '',
            'page_count' => '1',
        ]),
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Origin: https://www.csi.go.kr',
            'Referer: https://www.csi.go.kr/acd/acdCaseList.do',
        ],
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $err !== '' || $httpCode >= 400 || !is_string($response)) {
        $cache[$accdNo] = '';
        return '';
    }

    $cache[$accdNo] = $response;
    return $response;
}

function tbm_ai_extract_csi_photo_image_url(string $html): string
{
    if ($html === '') {
        return '';
    }

    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/iu', $html, $matches) > 0) {
        foreach (($matches[1] ?? []) as $src) {
            $src = trim((string)$src);
            if ($src === '') {
                continue;
            }

            $lowerSrc = mb_strtolower($src, 'UTF-8');
            if (str_contains($lowerSrc, '/com/img/noimg.jpg')) {
                continue;
            }
            if (!str_contains($lowerSrc, 'imageviewproc.do?file_no=')) {
                continue;
            }

            return tbm_ai_make_csi_absolute_url($src);
        }
    }

    if (preg_match('/data-image-no=["\']([^"\']+)["\']/iu', $html, $matches) === 1) {
        $fileNo = trim(html_entity_decode((string)$matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($fileNo !== '') {
            return 'https://www.csi.go.kr/com/imageViewProc.do?file_no=' . rawurlencode($fileNo);
        }
    }

    return '';
}

function tbm_ai_resolve_csi_image_url(?string $accdNo = null, ?string $caseNo = null, ?string $detailUrl = null): string
{
    static $cache = [];

    $accdNo = trim((string)$accdNo);
    $caseNo = trim((string)$caseNo);
    $detailUrl = trim((string)$detailUrl);
    $cacheKey = $accdNo . '|' . $caseNo . '|' . $detailUrl;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if ($caseNo === '' && $detailUrl !== '') {
        $caseNo = tbm_ai_extract_csi_detail_case_no($detailUrl);
    }

    if ($accdNo === '' && $caseNo !== '') {
        $detail = tbm_ai_fetch_csi_case_detail($caseNo);
        $accdNo = trim((string)($detail['accd_no'] ?? ''));
    }

    if ($accdNo === '') {
        $cache[$cacheKey] = '';
        return '';
    }

    $cache[$cacheKey] = tbm_ai_extract_csi_photo_image_url(tbm_ai_fetch_csi_photo_html($accdNo));
    return $cache[$cacheKey];
}

function tbm_ai_fetch_csi_case_detail(string $caseNo): array
{
    $caseNo = trim($caseNo);
    if ($caseNo === '') {
        return [];
    }

    $url = 'https://www.csi.go.kr/acd/acdCaseView.do?case_no=' . rawurlencode($caseNo);
    $html = function_exists('tbm_news_fetch_url') ? tbm_news_fetch_url($url) : '';
    if ($html === '') {
        return [];
    }

    $fields = tbm_ai_extract_csi_fields_from_dom($html);
    $detail = [
        'case_no' => $caseNo,
        'detail_url' => $url,
    ];

    if (preg_match('/name=["\']accd_no["\'][^>]*value=["\']([^"\']+)["\']/i', $html, $matches) === 1) {
        $detail['accd_no'] = trim((string)$matches[1]);
    }

    $labelMap = [
        'title' => ['사고명'],
        'accident_date' => ['발생일시'],
        'district' => ['시군구 정보'],
        'location_place' => ['사고위치:장소', '장소'],
        'location_part' => ['사고위치:부위', '부위'],
        'incident_overview' => ['사고경위'],
        'incident_cause' => ['사고원인'],
        'incident_cause_detail' => ['구체적 사고원인'],
        'damage_death' => ['피해상황:사망자수(명)', '사망자수(명)'],
        'damage_injury' => ['피해상황:부상자수(명)', '부상자수(명)'],
        'damage_content' => ['피해내용'],
        'after_action' => ['사고발생후 조치사항'],
        'prevention' => ['재발방지대책'],
        'future_plan' => ['향후조치계획'],
    ];

    foreach ($labelMap as $key => $labels) {
        foreach ($labels as $label) {
            $value = trim((string)($fields[$label] ?? ''));
            if ($value === '') {
                $fallbackLabel = str_contains($label, ':')
                    ? (string)substr($label, (int)strrpos($label, ':') + 1)
                    : $label;
                $value = tbm_ai_extract_csi_field_by_regex($html, $fallbackLabel);
            }

            if ($value !== '') {
                $detail[$key] = $value;
                break;
            }
        }
    }

    return $detail;
}

function tbm_ai_build_csi_article(array $listItem, array $detail): array
{
    $title = trim((string)($detail['title'] ?? $listItem['title'] ?? ''));
    $accidentDate = trim((string)($detail['accident_date'] ?? $listItem['occurred_at'] ?? ''));

    $locationParts = [];
    foreach ([
        trim((string)($detail['district'] ?? $listItem['location'] ?? '')),
        trim((string)($detail['location_place'] ?? '')),
        trim((string)($detail['location_part'] ?? '')),
    ] as $value) {
        if ($value !== '' && $value !== '-') {
            $locationParts[] = $value;
        }
    }
    $locationText = implode(' / ', array_values(array_unique($locationParts)));

    $damageParts = [];
    foreach ([
        trim((string)($detail['damage_death'] ?? '')),
        trim((string)($detail['damage_injury'] ?? '')),
        trim((string)($detail['damage_content'] ?? '')),
    ] as $value) {
        if ($value !== '' && $value !== '-') {
            $damageParts[] = $value;
        }
    }
    $damageText = implode(' / ', array_values(array_unique($damageParts)));

    $bodyParts = [];
    if ($locationText !== '') {
        $bodyParts[] = '사고위치: ' . $locationText;
    }
    if ($damageText !== '') {
        $bodyParts[] = '피해상황: ' . $damageText;
    }

    $detailFields = [
        'incident_overview' => '사고경위',
        'incident_cause' => '사고원인',
        'incident_cause_detail' => '구체적 사고원인',
        'after_action' => '사고발생후 조치사항',
        'prevention' => '재발방지대책',
        'future_plan' => '향후조치계획',
    ];

    foreach ($detailFields as $key => $label) {
        $value = trim((string)($detail[$key] ?? ''));
        if ($value !== '' && $value !== '-') {
            $bodyParts[] = $label . ': ' . $value;
        }
    }

    $body = trim(implode("\n", $bodyParts));

    return [
        'article_title' => $title,
        'article_body' => $body,
        'article_url' => trim((string)($detail['detail_url'] ?? $listItem['detail_url'] ?? '')),
        'accident_date' => $accidentDate,
        'published_at' => $accidentDate,
        'image_url' => trim((string)($detail['image_url'] ?? '')),
        'has_context' => $body !== '' ? 1 : 0,
        'incident_score' => 9,
        'source_name' => 'CSI',
        'source_id' => trim((string)($detail['accd_no'] ?? $listItem['accd_no'] ?? '')),
    ];
}

function tbm_ai_collect_csi_candidates(string $targetDate, int $limit = 8): array
{
    $candidates = [];
    $seen = [];
    $seenTitles = [];
    $usedUrls = array_fill_keys(tbm_ai_get_recent_used_source_urls(), true);
    $usedTitles = tbm_ai_get_recent_used_title_map();

    $listPages = [1, 2];
    $detailFetchLimit = max(3, min($limit, 6));

    foreach ($listPages as $page) {
        $listHtml = tbm_ai_fetch_csi_list_html($page);
        if ($listHtml === '') {
            continue;
        }

        $listItems = tbm_ai_parse_csi_case_list($listHtml, $detailFetchLimit);

        foreach ($listItems as $listItem) {
            $detail = tbm_ai_fetch_csi_case_detail((string)($listItem['case_no'] ?? ''));
            if (empty($detail)) {
                continue;
            }

            $article = tbm_ai_build_csi_article($listItem, $detail);
            $url = trim((string)($article['article_url'] ?? ''));
            $body = trim((string)($article['article_body'] ?? ''));
            $title = trim((string)($article['article_title'] ?? ''));
            $normalizedTitle = tbm_ai_normalize_title($title);

            if ($url === '' || $title === '' || $body === '') {
                continue;
            }

            if (isset($usedUrls[$url])) {
                continue;
            }

            $isUsedSimilar = false;
            foreach ($usedTitles as $usedTitle => $_) {
                similar_text($normalizedTitle, $usedTitle, $percent);
                if ($percent > 80) {
                    $isUsedSimilar = true;
                    break;
                }
            }

            if ($isUsedSimilar) {
                continue;
            }

            $isSimilar = false;
            foreach ($seenTitles as $existingTitle) {
                similar_text($normalizedTitle, $existingTitle, $percent);
                if ($percent > 80) {
                    $isSimilar = true;
                    break;
                }
            }

            if ($isSimilar) {
                continue;
            }

            $seenTitles[] = $normalizedTitle;

            $key = md5(mb_strtolower($url . '|' . $normalizedTitle, 'UTF-8'));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $candidates[] = [
                'query' => 'CSI 사고사례',
                'article' => $article,
                'siren' => null,
                'csi_case' => $detail,
                'target_date' => $targetDate,
            ];

            if (count($candidates) >= $limit) {
                return $candidates;
            }
        }
    }

    return $candidates;
}

function tbm_ai_collect_csi_candidates_enhanced(string $targetDate, int $limit = 8): array
{
    $usedUrls = array_fill_keys(tbm_ai_get_recent_used_source_urls(), true);
    $usedTitles = tbm_ai_get_recent_used_title_map();
    $listPages = [1, 2, 3];
    $detailFetchLimit = max(10, min($limit * 2, 12));

    $candidates = tbm_ai_collect_csi_candidates_pass(
        $targetDate,
        $limit,
        $usedUrls,
        $usedTitles,
        $listPages,
        $detailFetchLimit,
        false
    );

    if ($candidates !== []) {
        return $candidates;
    }

    tbm_ai_log_debug('[CSI 후보] 신규 사고사례 없음, 최근 사용 CSI 재사용 허용 폴백');

    return tbm_ai_collect_csi_candidates_pass(
        $targetDate,
        $limit,
        $usedUrls,
        $usedTitles,
        $listPages,
        $detailFetchLimit,
        true
    );
}

function tbm_ai_collect_csi_candidates_pass(
    string $targetDate,
    int $limit,
    array $usedUrls,
    array $usedTitles,
    array $listPages,
    int $detailFetchLimit,
    bool $allowRecentReuse
): array {
    $candidates = [];
    $seen = [];
    $seenTitles = [];
    $seenPageFingerprints = [];

    foreach ($listPages as $page) {
        $listHtml = tbm_ai_fetch_csi_list_html((int)$page);
        if ($listHtml === '') {
            continue;
        }

        $listItems = tbm_ai_parse_csi_case_list($listHtml, $detailFetchLimit);
        if ($listItems === []) {
            continue;
        }

        $pageCaseNos = [];
        foreach ($listItems as $item) {
            $pageCaseNos[] = trim((string)($item['case_no'] ?? ''));
        }
        $pageFingerprint = implode('|', $pageCaseNos);

        if ($pageFingerprint !== '' && isset($seenPageFingerprints[$pageFingerprint])) {
            tbm_ai_log_debug('[CSI 후보] 중복 목록 감지 page=' . $page . ' skip');
            continue;
        }
        $seenPageFingerprints[$pageFingerprint] = true;

        foreach ($listItems as $listItem) {
            $detail = tbm_ai_fetch_csi_case_detail((string)($listItem['case_no'] ?? ''));
            if (empty($detail)) {
                continue;
            }

            $article = tbm_ai_build_csi_article($listItem, $detail);
            $url = trim((string)($article['article_url'] ?? ''));
            $body = trim((string)($article['article_body'] ?? ''));
            $title = trim((string)($article['article_title'] ?? ''));
            $normalizedTitle = tbm_ai_normalize_title($title);

            if ($url === '' || $title === '' || $body === '') {
                continue;
            }

            if (!$allowRecentReuse && isset($usedUrls[$url])) {
                continue;
            }

            $isUsedSimilar = false;
            if (!$allowRecentReuse) {
                foreach ($usedTitles as $usedTitle => $_) {
                    similar_text($normalizedTitle, $usedTitle, $percent);
                    if ($percent > 80) {
                        $isUsedSimilar = true;
                        break;
                    }
                }
            }

            if ($isUsedSimilar) {
                continue;
            }

            $isSimilar = false;
            foreach ($seenTitles as $existingTitle) {
                similar_text($normalizedTitle, $existingTitle, $percent);
                if ($percent > 80) {
                    $isSimilar = true;
                    break;
                }
            }

            if ($isSimilar) {
                continue;
            }

            $seenTitles[] = $normalizedTitle;

            $key = md5(mb_strtolower($url . '|' . $normalizedTitle, 'UTF-8'));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $candidates[] = [
                'query' => 'CSI ?ш퀬?щ?',
                'article' => $article,
                'siren' => null,
                'csi_case' => $detail,
                'target_date' => $targetDate,
            ];

            if (count($candidates) >= $limit) {
                return $candidates;
            }
        }
    }

    return $candidates;
}

function tbm_ai_collect_article_candidates_legacy(string $targetDate, int $limit = 15): array
{
    $candidates = [];
    $seen = [];
    $usedUrls = array_fill_keys(tbm_ai_get_recent_used_source_urls(), true);
    $usedTitles = tbm_ai_get_recent_used_title_map();

    foreach (tbm_ai_build_search_queries($targetDate) as $query) {
        $articles = tbm_news_fetch_article_candidates($query, 20, [1, 11, 21, 31], ['date', 'sim'], 8);

        foreach ($articles as $article) {
            $url = trim((string)($article['article_url'] ?? ''));
            $body = trim((string)($article['article_body'] ?? ''));
            $title = trim((string)($article['article_title'] ?? ''));
            $normalizedTitle = tbm_ai_normalize_title($title);

            if ($url === '' || $body === '') {
                continue;
            }
            if (isset($usedUrls[$url])) {
                continue;
            }
            
            $key = md5(mb_strtolower($url . '|' . $normalizedTitle, 'UTF-8'));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $candidates[] = [
                'query'   => $query,
                'article' => $article,
                'siren'   => null,
            ];

            if (count($candidates) >= $limit) {
                return $candidates;
            }
        }
    }

    return $candidates;
}


function tbm_ai_collect_recent_week_news_candidates(string $targetDate, int $limit = 20): array
{
    $candidates = [];
    $seen = [];
    $seenTitles = [];
    $usedUrls = array_fill_keys(tbm_ai_get_recent_used_source_urls(), true);
    $usedTitles = tbm_ai_get_recent_used_title_map();



    foreach (tbm_ai_build_search_queries($targetDate) as $query) {
        $articles = tbm_news_fetch_recent_week_article_candidates($query, 20, [1,11,21,31], ['date','sim'], 10);

        foreach ($articles as $article) {

            $url   = trim((string)($article['article_url'] ?? ''));
            $body  = trim((string)($article['article_body'] ?? ''));
            $title = trim((string)($article['article_title'] ?? ''));

            $normalizedTitle = tbm_ai_normalize_title($title);

            // 🔥 유사도 체크 (여기로 이동)
            $isSimilar = false;
            foreach ($seenTitles as $existingTitle) {
                similar_text($normalizedTitle, $existingTitle, $percent);
                if ($percent > 80) {
                    $isSimilar = true;
                    break;
                }
            }

            if ($isSimilar) {
                continue;
            }

            $seenTitles[] = $normalizedTitle;

        // ── 기존 필터 그대로 유지 ──
            $publishedAt = trim((string)($article['published_at'] ?? ''));
            $accidentDate = trim((string)($article['accident_date'] ?? ''));

            if ($url === '' || $body === '' || $title === '') {
                continue;
            }

            // 최근 뉴스만
            if ($publishedAt === '') {
                continue;
            }

            // 사고일자 명확해야 함
            if ($accidentDate === '') {
                continue;
            }

            // 본문 너무 짧으면 제외
            if (mb_strlen($body, 'UTF-8') < 220) {
                continue;
            }

            // 사고 문맥 약하면 제외
            if (empty($article['has_context'])) {
                continue;
            }

            $fullText = $title . ' ' . $body;

            // 🔥 1차 강제 사고 필터 (초기 필터) — 제목에 인명피해 키워드 필수
            if (preg_match('/(사망|숨져|숨진|중태|중상|부상|심정지|의식불명)/u', $title) !== 1) {
                continue;
            }

            // 🔥 작업 중 사고 필수 조건
            if (preg_match('/(작업|근로자|작업자|현장)/u', $title . ' ' . $body) !== 1) {
                continue;
            }

            // ── 하드 포함 조건: 실제 중대사고 기사만 통과 ─────────────
            // 사망/중상/숨짐류가 반드시 있어야 함
            if (preg_match('/(사망|숨져|숨진|중상|심정지|의식불명|끝내 숨|병원 이송 후 숨|치료 중 숨)/u', $fullText) !== 1) {
                continue;
            }

            // 사고 유형 키워드도 반드시 포함
            if (preg_match('/(감전|추락|떨어져|끼임|협착|질식|화재|붕괴|폭발|깔림|무너져|감김)/u', $fullText) !== 1) {
                continue;
            }

            // 작업/현장 문맥도 반드시 포함
            if (preg_match('/(작업 중|작업하던|공사현장|건설현장|현장서|현장에서|근로자|작업자|노동자|설비 점검|유지보수|철거 작업|설치 작업)/u', $fullText) !== 1) {
                continue;
            }

            // ── 강한 제외 조건: 정책/예방/통계/일반 기사 제거 ─────────
            if (preg_match('/(예방|대책|통계|현황|전망|캠페인|점검 실시|교육 실시|세미나|토론회|분석|백서|보고서|발표|집계|매뉴얼|가이드라인)/u', $title) === 1) {
                continue;
            }

            // 분석/정책 기사 제거
            if (function_exists('tbm_news_is_analysis_article') && tbm_news_is_analysis_article($title, $body)) {
                tbm_ai_log_debug('[필터] 분석/정책 기사 제외: ' . mb_substr($title, 0, 60, 'UTF-8'));
                continue;
            }

            // 사건 서술 패턴 검증
            $hasNarrative = function_exists('tbm_news_has_incident_narrative')
                ? tbm_news_has_incident_narrative($body)
                : false;

            if (!$hasNarrative) {
                tbm_ai_log_debug('[필터] 사건 서술 부족 제외: ' . mb_substr($title, 0, 60, 'UTF-8'));
                continue;
            }

            // 사건성 점수
            $incidentScore = function_exists('tbm_news_incident_score')
                ? tbm_news_incident_score($title, $body)
                : 5;

            // 기존보다 더 강하게: 3 미만이면 제외
            if ($incidentScore < 3) {
                tbm_ai_log_debug('[필터] 사건성 점수 부족(' . $incidentScore . ') 제외: ' . mb_substr($title, 0, 60, 'UTF-8'));
                continue;
            }

            $article['incident_score'] = $incidentScore;

            if (isset($usedUrls[$url])) {
                continue;
            }

            $key = md5(mb_strtolower($url . '|' . $normalizedTitle, 'UTF-8'));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $candidates[] = [
                'query'   => $query,
                'article' => $article,
                'siren'   => null,
            ];

            if (count($candidates) >= $limit) {
                return $candidates;
            }
        }
    }

    return $candidates;
}

function tbm_ai_pick_best_candidate(array $candidates): ?array
{
    if ($candidates === []) {
        return null;
    }

    foreach ($candidates as &$candidate) {
        $candidate['_score'] = tbm_ai_score_article_candidate($candidate);
    }
    unset($candidate);

    usort($candidates, static fn($a, $b) => (($b['_score'] ?? 0) <=> ($a['_score'] ?? 0)));

    // ── 2단계: 상위 후보 점수 디버그 로그 ───────────────────────
    $logTop = array_slice($candidates, 0, min(5, count($candidates)));
    foreach ($logTop as $i => $c) {
        $t  = mb_substr(trim((string)($c['article']['article_title'] ?? '(제목없음)')), 0, 50, 'UTF-8');
        $is = (int)($c['article']['incident_score'] ?? -1);
        tbm_ai_log_debug(sprintf(
            '[점수 %d위] score=%d  incident_score=%s  title=%s',
            $i + 1,
            (int)($c['_score'] ?? 0),
            $is >= 0 ? (string)$is : 'n/a',
            $t
        ));
    }

    /*$top    = array_slice($candidates, 0, min(TBM_AI_TOP_CANDIDATES, count($candidates)));*/
    
    // 상위 후보 중 점수 가중 랜덤 선택 (고득점 후보가 선택될 확률이 높음)
    $top = array_slice($candidates, 0, min(TBM_AI_TOP_CANDIDATES, count($candidates)));

    // 최소 점수 기준 미달 후보 제거
    $top = array_filter($top, static fn($c) => ($c['_score'] ?? 0) >= 10);
    $top = array_values($top);

    if ($top === []) {
        return null;
    }

    // 점수 기반 가중 랜덤 선택
    $weights = array_map(static fn($c) => max(1, (int)($c['_score'] ?? 0)), $top);
    $totalWeight = array_sum($weights);
    $rand = mt_rand(1, $totalWeight);
    $cumulative = 0;
    $picked = $top[0];

    foreach ($top as $i => $c) {
        $cumulative += $weights[$i];
        if ($rand <= $cumulative) {
            $picked = $c;
            break;
        }
    }

    tbm_ai_log_debug(sprintf(
        '[최종 선택] score=%d  title=%s',
        (int)($picked['_score'] ?? 0),
        mb_substr(trim((string)($picked['article']['article_title'] ?? '')), 0, 60, 'UTF-8')
    ));

    return $picked;
}

// ── 프롬프트 빌더 ────────────────────────────────────────────

function tbm_ai_build_article_prompt(string $targetDate, array $article, ?array $siren = null): string
{
    $articleTitle = trim((string)($article['article_title'] ?? ''));
    $articleBody  = trim((string)($article['article_body'] ?? ''));
    $articleBody  = mb_substr($articleBody, 0, 900, 'UTF-8');
    $articleUrl   = trim((string)($article['article_url'] ?? ''));
    $articleAccidentDate = trim((string)($article['accident_date'] ?? ''));
    $sirenSummary = trim((string)($siren['summary'] ?? ''));
    $sirenPrev    = trim((string)($siren['prevention'] ?? ''));
    $sirenTitle   = trim((string)($siren['title'] ?? ''));

    return <<<PROMPT
다음은 실제 사고 기사 원문과 최근 중대재해 알림 참고 정보입니다.

[기사 제목]
{$articleTitle}

[기사 본문]
{$articleBody}

[기사에서 확인한 사고일자]
{$articleAccidentDate}

[참고: 중대재해 사이렌 제목]
{$sirenTitle}

[참고: 사고 개요]
{$sirenSummary}

[참고: 예방대책]
{$sirenPrev}

위 자료만 근거로 {$targetDate} TBM(Tool Box Meeting)용 "중대재해 전파 교육 자료"를 작성하세요.

반드시 아래 조건을 모두 지키세요.
1. 출력은 순수 JSON 객체 1개만 반환하세요.
2. 설명문, 마크다운, 코드블록, 백틱(```), 머리말, 꼬리말을 절대 넣지 마세요.
3. 기사와 참고 정보에 없는 내용을 추측해서 쓰지 마세요.
4. 본문은 한국어 평어체로 작성하세요. 경어체('-습니다', '-입니다')는 사용하지 마세요.
5. body_text는 반드시 아래 2개 단락으로 구성하세요.
   - [사고내용 및 원인]
   - [예방대책]
6. 사고내용 및 원인은 정확히 220~250자 범위로 작성하세요. 가장 좋은 목표는 230~240자입니다. 반드시 250자를 초과하지 마세요.
   중요: 각 섹션의 마지막 문장은 반드시 완전하게 끝내세요('~한다.' 등으로 마무리). 글자 수 제한 안에서 완전한 문장을 끝마칠 수 없다면, 더 앞의 문장에서 멈추고 새 문장을 시작하지 마세요.
7. 예방대책은 정확히 140~160자 범위로 작성하세요. 가장 좋은 목표는 145~155자입니다. 반드시 160자를 초과하지 마세요.
   (사고내용 + 예방대책 합계가 절대 420자를 넘지 않도록 하세요. 이는 인쇄 양식의 고정 영역 제한입니다.)
8. 퀴즈는 보기 4개가 있는 객관식 문제 3개를 작성하되, 퀴즈 3개의 총 글자 수 합계는 545자 이상으로 작성하고 가능하면 700자 이하를 유지하세요. 가장 권장되는 길이는 각 문제 185~220자 정도입니다.
9. 최종 JSON을 출력하기 직전에 반드시 스스로 다음 길이를 다시 세세요: 사고내용 및 원인, 예방대책, quiz_1, quiz_2, quiz_3, 퀴즈 3개 합계. 하나라도 범위를 벗어나면 먼저 수정한 뒤 출력하세요.
10. 퀴즈 합계가 545자 미만이면 질문 또는 보기 설명을 조금 더 구체적으로 늘려 길이를 채우고, 가능하면 700자 이하를 유지하세요. 다만 820자를 넘으면 중복 표현을 삭제해 반드시 줄이세요.
11. source_url에는 아래 실제 기사 URL을 그대로 넣으세요.
   {$articleUrl}
12. accident_title은 기사 내용을 바탕으로 20자 내외로 간결하게 작성하세요.
13. image_search_keyword에는 사고 유형과 작업 대상을 잘 드러내는 한국어 검색어를 12~30자 내외로 작성하세요.
14. 모든 문자열 값 내부의 줄바꿈은 반드시 \\n 으로만 표현하고, 문자열 안에 큰따옴표(\")를 넣어야 할 경우 반드시 이스케이프(\\\") 처리하세요.
15. 불필요한 수식어는 사용하지 마세요.

반드시 아래 JSON 키 이름을 정확히 그대로 사용하세요.
{
  "accident_date": "{$articleAccidentDate}",
  "accident_title": "사고 제목",
  "edu_title": "중대재해 전파(사고 제목 요약)",
  "body_text": "[사고내용 및 원인]\\n내용...\\n\\n[예방대책]\\n내용...",
  "source_url": "{$articleUrl}",
  "image_search_keyword": "전기실 배전반 감전 사고",
  "quiz_1": "1. 관련 안전퀴즈 질문\\n① 보기1\\n② 보기2\\n③ 보기3\\n④ 보기4",
  "quiz_2": "2. 관련 안전퀴즈 질문\\n① 보기1\\n② 보기2\\n③ 보기3\\n④ 보기4",
  "quiz_3": "3. 관련 안전퀴즈 질문\\n① 보기1\\n② 보기2\\n③ 보기3\\n④ 보기4"
}
PROMPT;
}

function tbm_ai_build_csi_prompt(string $targetDate, array $article): string
{
    $articleTitle = trim((string)($article['article_title'] ?? ''));
    $articleBody  = trim((string)($article['article_body'] ?? ''));
    $articleBody  = mb_substr($articleBody, 0, 900, 'UTF-8');
    $articleUrl   = trim((string)($article['article_url'] ?? ''));
    $articleAccidentDate = trim((string)($article['accident_date'] ?? ''));

    return <<<PROMPT
다음은 CSI 사고사례 상세 자료입니다.

[사고사례 제목]
{$articleTitle}

[사고사례 본문]
{$articleBody}

[자료에서 확인한 사고일자]
{$articleAccidentDate}

위 자료만 근거로 {$targetDate} TBM(Tool Box Meeting)용 "사고사례 교육 자료"를 작성하세요.

반드시 아래 조건을 모두 지키세요.
1. 출력은 순수 JSON 객체 1개만 반환하세요.
2. 설명문, 마크다운, 코드블록, 백틱(```), 머리말/꼬리말을 넣지 마세요.
3. 자료에 없는 내용을 추측해서 쓰지 마세요.
4. 본문은 서술형 평어체로 작성하세요. 경어체("-습니다", "-입니다")는 쓰지 마세요.
5. 이 자료는 "중대재해 전파"가 아니라 "사고사례" 요약 자료입니다. 사고 경위, 원인, 재발방지대책이 현장 공유용으로 잘 드러나도록 정리하세요.
6. edu_title은 반드시 "사고사례(사고 제목 요약)" 형식으로 작성하세요.
7. body_text는 반드시 아래 2개 섹션으로 구성하세요.
   - [사고내용 및 원인]
   - [예방대책]
8. [사고내용 및 원인]은 자료의 사고경위와 사고원인을 중심으로 220~250자 범위로 작성하세요. 가능하면 230~240자를 목표로 하세요.
   중요: 마지막 문장은 반드시 완전하게 끝내세요('~한다.' 등으로 마무리). 글자 수 제한 안에서 완전한 문장을 끝마칠 수 없다면 더 앞의 문장에서 멈추세요.
9. [예방대책]은 자료의 재발방지대책과 후속조치 내용을 중심으로 140~160자 범위로 작성하세요. 가능하면 145~155자를 목표로 하세요.
10. 사고내용 및 원인 + 예방대책 합계는 420자를 넘기지 마세요.
11. 퀴즈는 보기 4개가 있는 객관식 문제 3개를 작성하되, 퀴즈 3개의 총 글자 수 합계는 545자 이상으로 작성하고 가능하면 700자 이하를 유지하세요. 가장 권장되는 길이는 각 문제 185~220자 정도입니다.
12. 최종 JSON을 출력하기 직전에 반드시 스스로 다음 길이를 다시 세세요: 사고내용 및 원인, 예방대책, quiz_1, quiz_2, quiz_3, 퀴즈 3개 합계. 하나라도 범위를 벗어나면 먼저 수정한 뒤 출력하세요.
13. 퀴즈 합계가 545자 미만이면 질문 또는 보기 설명을 조금 더 구체적으로 늘려 길이를 채우고, 가능하면 700자 이하를 유지하세요. 다만 820자를 넘으면 중복 표현을 삭제해 반드시 줄이세요.
14. source_url에는 아래 실제 상세 URL을 그대로 넣으세요.
   {$articleUrl}
15. accident_title은 사고사례 핵심 내용을 20자 이내로 간결하게 작성하세요.
16. image_search_keyword는 사고유형과 작업대상을 함께 드러내는 구체적 검색어를 12~30자 이내로 작성하세요.
17. 모든 줄바꿈은 반드시 \\n 으로만 표현하고, 문자열 안 따옴표는 반드시 \\\" 로 이스케이프하세요.

반드시 아래 JSON 키 이름을 그대로 사용하세요.
{
  "accident_date": "{$articleAccidentDate}",
  "accident_title": "사고 제목",
  "edu_title": "사고사례(사고 제목 요약)",
  "body_text": "[사고내용 및 원인]\\n내용...\\n\\n[예방대책]\\n내용...",
  "source_url": "{$articleUrl}",
  "image_search_keyword": "{$articleTitle}",
  "quiz_1": "1. 관련 안전퀴즈 질문\\n① 보기1\\n② 보기2\\n③ 보기3\\n④ 보기4",
  "quiz_2": "2. 관련 안전퀴즈 질문\\n① 보기1\\n② 보기2\\n③ 보기3\\n④ 보기4",
  "quiz_3": "3. 관련 안전퀴즈 질문\\n① 보기1\\n② 보기2\\n③ 보기3\\n④ 보기4"
}
PROMPT;
}

function tbm_ai_build_siren_prompt(string $targetDate, array $siren): string
{
    $title       = trim((string)($siren['title'] ?? ''));
    $postedDate  = trim((string)($siren['posted_date'] ?? ''));
    $summary     = trim((string)($siren['summary'] ?? ''));
    $prevention  = trim((string)($siren['prevention'] ?? ''));
    $detailUrl   = trim((string)($siren['detail_url'] ?? ''));

    return <<<PROMPT
다음은 최근 중대재해 알림 자료입니다.

[제목]
{$title}

[게시일]
{$postedDate}

[사고 개요]
{$summary}

[예방대책]
{$prevention}

위 자료만 근거로 {$targetDate} TBM(Tool Box Meeting)용 "중대재해 전파 교육 자료"를 작성하세요.

반드시 아래 조건을 모두 지키세요.
1. 출력은 순수 JSON 객체 1개만 반환하세요.
2. 설명문, 마크다운, 코드블록, 백틱(```), 머리말, 꼬리말을 절대 넣지 마세요.
3. 제공된 정보에 없는 내용을 과도하게 추측해서 쓰지 마세요.
4. 본문은 한국어 평어체로 작성하세요. 경어체('-습니다', '-입니다')는 사용하지 마세요.
5. body_text는 반드시 아래 2개 단락으로 구성하세요.
   - [사고내용 및 원인]
   - [예방대책]
6. 사고내용 및 원인은 정확히 220~250자 범위로 작성하세요. 가장 좋은 목표는 230~240자입니다. 반드시 250자를 초과하지 마세요.
   중요: 각 섹션의 마지막 문장은 반드시 완전하게 끝내세요('~한다.' 등으로 마무리). 글자 수 제한 안에서 완전한 문장을 끝마칠 수 없다면, 더 앞의 문장에서 멈추고 새 문장을 시작하지 마세요.
7. 예방대책은 정확히 140~160자 범위로 작성하세요. 가장 좋은 목표는 145~155자입니다. 반드시 160자를 초과하지 마세요.
   (사고내용 + 예방대책 합계가 절대 420자를 넘지 않도록 하세요. 이는 인쇄 양식의 고정 영역 제한입니다.)
8. 퀴즈는 보기 4개가 있는 객관식 문제 3개를 작성하되, 퀴즈 3개의 총 글자 수 합계는 545자 이상으로 작성하고 가능하면 700자 이하를 유지하세요. 가장 권장되는 길이는 각 문제 185~220자 정도입니다.
9. 최종 JSON을 출력하기 직전에 반드시 스스로 다음 길이를 다시 세세요: 사고내용 및 원인, 예방대책, quiz_1, quiz_2, quiz_3, 퀴즈 3개 합계. 하나라도 범위를 벗어나면 먼저 수정한 뒤 출력하세요.
10. 퀴즈 합계가 545자 미만이면 질문 또는 보기 설명을 조금 더 구체적으로 늘려 길이를 채우고, 가능하면 700자 이하를 유지하세요. 다만 820자를 넘으면 중복 표현을 삭제해 반드시 줄이세요.
11. source_url에는 아래 상세 URL을 그대로 넣으세요.
   {$detailUrl}
12. accident_title은 20자 내외로 간결하게 작성하세요.
13. image_search_keyword에는 사고 유형과 작업 대상을 잘 드러내는 한국어 검색어를 12~30자 내외로 작성하세요.
14. 모든 문자열 값 내부의 줄바꿈은 반드시 \\n 으로만 표현하고, 문자열 안에 큰따옴표(\")를 넣어야 할 경우 반드시 이스케이프(\\\") 처리하세요.
15. 불필요한 수식어는 사용하지 마세요.

반드시 아래 JSON 키 이름을 정확히 그대로 사용하세요.
{
  "accident_date": "{$postedDate}",
  "accident_title": "사고 제목",
  "edu_title": "중대재해 전파(사고 제목 요약)",
  "body_text": "[사고내용 및 원인]\\n내용...\\n\\n[예방대책]\\n내용...",
  "source_url": "{$detailUrl}",
  "image_search_keyword": "{$title}",
  "quiz_1": "1. 관련 안전퀴즈 질문\\n① 보기1\\n② 보기2\\n③ 보기3\\n④ 보기4",
  "quiz_2": "2. 관련 안전퀴즈 질문\\n① 보기1\\n② 보기2\\n③ 보기3\\n④ 보기4",
  "quiz_3": "3. 관련 안전퀴즈 질문\\n① 보기1\\n② 보기2\\n③ 보기3\\n④ 보기4"
}
PROMPT;
}

// ── 생성 메인 ────────────────────────────────────────────────

function tbm_ai_bool_from_mixed($value): bool
{
    if (is_bool($value)) return $value;
    $v = strtolower(trim((string)$value));
    return in_array($v, ['1','true','y','yes','on'], true);
}

function tbm_ai_pick_random_top_items(array $items, int $topN = 3): array
{
    if ($items === []) {
        return [];
    }
    $items = array_values($items);
    shuffle($items);
    return array_slice($items, 0, min($topN, count($items)));
}



function tbm_ai_generate_content(string $targetDate, bool $forceNew = false): array
{
    // 오래된 캐시 자동 정리 (30일 이전)
    tbm_ai_purge_old_cache(30);

    if ($forceNew) {
        tbm_ai_log_debug('[시작] forceNew=1 → 캐시 무시');
    } else {
        $cached = tbm_ai_load_cache($targetDate);
        if (is_array($cached)) {
            tbm_ai_log_debug('[시작] 캐시 사용: ' . $targetDate);
            return $cached;
        }
    }

    // ── 1순위: KOSHA 중대재해 발생 알림 ─────────────────────────
    tbm_ai_log_debug('[1순위] KOSHA 사이렌 수집 시작');
    $sirenItems = tbm_siren_get_recent_items(5, false, tbm_ai_get_siren_ocr_options());
    $sirenItems = tbm_ai_filter_siren_items($sirenItems);

    // 후보별 OCR/비전 강제 보강
    $enrichedSirens = [];
    foreach ($sirenItems as $item) {
        $enriched = tbm_ai_enrich_siren_from_image($item);

        tbm_ai_log_debug(sprintf(
            '[KOSHA 후보] title=%s / summary_len=%d / prevention_len=%d / image=%s / ocr_ok=%s / vision=%s',
            mb_substr(trim((string)($enriched['title'] ?? '')), 0, 50, 'UTF-8'),
            mb_strlen(trim((string)($enriched['summary'] ?? '')), 'UTF-8'),
            mb_strlen(trim((string)($enriched['prevention'] ?? '')), 'UTF-8'),
            !empty($enriched['image_file']) ? 'Y' : 'N',
            !empty($enriched['ocr_ok']) ? 'Y' : 'N',
            !empty($enriched['gemini_vision_used']) ? 'Y' : 'N'
        ));

        if (tbm_ai_is_siren_usable($enriched)) {
            $enrichedSirens[] = $enriched;
        }
    }

    $pickedSiren = tbm_ai_pick_best_siren_item($enrichedSirens);

    if (is_array($pickedSiren)) {
        tbm_ai_log_debug(sprintf(
            '[1순위 선택] KOSHA siren_score=%d / title=%s / summary_len=%d / prevention_len=%d',
            (int)($pickedSiren['_siren_score'] ?? 0),
            mb_substr(trim((string)($pickedSiren['title'] ?? '')), 0, 50, 'UTF-8'),
            mb_strlen(trim((string)($pickedSiren['summary'] ?? '')), 'UTF-8'),
            mb_strlen(trim((string)($pickedSiren['prevention'] ?? '')), 'UTF-8')
        ));

        $isCsiSiren = ($pickedSiren['_source'] ?? '') === 'csi';

        $csiSirenImageUrl = null;
        if ($isCsiSiren) {
            $csiSirenImageUrl = tbm_ai_resolve_csi_image_url(
                (string)($pickedSiren['accd_no'] ?? $pickedSiren['source_id'] ?? ''),
                (string)($pickedSiren['case_no'] ?? ''),
                (string)($pickedSiren['detail_url'] ?? '')
            );

            if ($csiSirenImageUrl !== '') {
                tbm_ai_log_debug('[CSI siren 이미지] 원본 이미지 URL 확인: ' . $csiSirenImageUrl);
            }
        }

        $prompt = tbm_ai_build_siren_prompt($targetDate, $pickedSiren);
        $result = tbm_ai_generate_via_gemini(
            $prompt,
            $targetDate,
            trim((string)($pickedSiren['detail_url'] ?? '')),
            $csiSirenImageUrl,
            $isCsiSiren ? null : trim((string)($pickedSiren['title'] ?? '')),
            !$isCsiSiren
        );

        // CSI 폴백 소스인 경우: edu_title 변경 + 원본 이미지 없으면 빈 영역 유지
        if ($isCsiSiren) {
            $accidentTitle = trim((string)($result['accident_title'] ?? ''));
            $result['edu_title'] = '사고사례' . ($accidentTitle !== '' ? '(' . $accidentTitle . ')' : '');

            if (trim((string)($result['image_file'] ?? '')) === '') {
                $result['image_file'] = '__NO_IMAGE__';
                tbm_ai_log_debug('[CSI siren 이미지] 원본 이미지 없음, 외부 fallback 이미지 사용 안 함');
            }
        }

        // Gemini가 생성한 fallback 이미지 경로 (siren 이미지로 덮어씌우기 전)
        $geminiImageFile = trim((string)($result['image_file'] ?? ''));

        if (!empty($pickedSiren['image_file'])) {
            $result['image_file'] = $pickedSiren['image_file'];
        }

        // Gemini fallback 이미지가 최종 선택과 다르면 삭제
        if ($geminiImageFile !== '' && $geminiImageFile !== trim((string)($result['image_file'] ?? ''))) {
            $geminiFullPath = __DIR__ . '/' . ltrim($geminiImageFile, '/');
            if (is_file($geminiFullPath)) {
                @unlink($geminiFullPath);
                tbm_ai_log_debug('[이미지 정리] 미사용 fallback 삭제: ' . $geminiImageFile);
            }
        }

        // 선택되지 않은 후보의 이미지 파일 정리
        tbm_ai_cleanup_unused_images($enrichedSirens, trim((string)($result['image_file'] ?? '')));

        if (!$forceNew) {
            tbm_ai_save_cache($targetDate, $result);
        }
        if (ob_get_level() > 0) { @ob_clean(); }
        return $result;
    }

    // 1순위 실패 시에도 후보 이미지 정리 (선택된 것 없음)
    if (!empty($enrichedSirens)) {
        tbm_ai_cleanup_unused_images($enrichedSirens, '');
    }

    tbm_ai_log_debug('[1순위 실패] KOSHA 사용 가능 항목 없음 → CSI 사고사례로 전환');

    // ── 2순위: CSI 사고사례 ─────────────────────────────────────
    $csiCandidates = tbm_ai_collect_csi_candidates_enhanced($targetDate, 6);
    $pickedCsi = tbm_ai_pick_best_candidate($csiCandidates);

    if (is_array($pickedCsi)) {
        $article = $pickedCsi['article'];
        $article['article_body'] = mb_substr(trim((string)($article['article_body'] ?? '')), 0, 900, 'UTF-8');
        $csiCase = is_array($pickedCsi['csi_case'] ?? null) ? $pickedCsi['csi_case'] : [];
        $article['image_url'] = tbm_ai_resolve_csi_image_url(
            (string)($csiCase['accd_no'] ?? ''),
            (string)($csiCase['case_no'] ?? ''),
            (string)($article['article_url'] ?? '')
        );

        if (trim((string)($article['image_url'] ?? '')) !== '') {
            tbm_ai_log_debug('[CSI 이미지] 원본 이미지 URL 확인: ' . $article['image_url']);
        }

        $prompt = tbm_ai_build_csi_prompt($targetDate, $article);
        $result = tbm_ai_generate_via_gemini(
            $prompt,
            $targetDate,
            trim((string)($article['article_url'] ?? '')),
            trim((string)($article['image_url'] ?? '')) !== '' ? trim((string)($article['image_url'] ?? '')) : null,
            null,
            false
        );

        // CSI 사고사례는 "중대재해 전파" 대신 "사고사례"로 표기
        $accidentTitle = trim((string)($result['accident_title'] ?? ''));
        $result['edu_title'] = '사고사례' . ($accidentTitle !== '' ? '(' . $accidentTitle . ')' : '');

        if (trim((string)($result['image_file'] ?? '')) === '') {
            $result['image_file'] = '__NO_IMAGE__';
            tbm_ai_log_debug('[CSI 이미지] 원본 이미지 없음, 외부 fallback 이미지 사용 안 함');
        }

        if (!$forceNew) {
            tbm_ai_save_cache($targetDate, $result);
        }
        if (ob_get_level() > 0) { @ob_clean(); }
        return $result;
    }

    tbm_ai_log_debug('[2순위 실패] CSI 사고사례 후보 없음 → 최근 7일 뉴스로 전환');

    // ── 3순위: 최근 7일 뉴스 ────────────────────────────────────
    $recentNewsCandidates = tbm_ai_collect_recent_week_news_candidates($targetDate, $forceNew ? 24 : 20);
    $pickedRecent = tbm_ai_pick_best_candidate($recentNewsCandidates);

    if (is_array($pickedRecent)) {
        $score = (int)($pickedRecent['_score'] ?? 0);
        $title = trim((string)($pickedRecent['article']['article_title'] ?? ''));
        $body  = trim((string)($pickedRecent['article']['article_body'] ?? ''));

        if ($score < 6) {
            tbm_ai_log_debug(sprintf(
                '[3순위] 뉴스 후보 품질 미달(score=%d), 일반 fallback 전환: %s',
                $score,
                mb_substr($title, 0, 50, 'UTF-8')
            ));
            $pickedRecent = null;
        }

        if (is_array($pickedRecent)) {
            $accidentDate = trim((string)($pickedRecent['article']['accident_date'] ?? ''));
            $hasNarrative = function_exists('tbm_news_has_incident_narrative')
                ? tbm_news_has_incident_narrative($body)
                : false;

            if ($accidentDate === '' && !$hasNarrative) {
                tbm_ai_log_debug(sprintf(
                    '[3순위] 사고일자+서술 모두 없음, 일반 fallback 전환: %s',
                    mb_substr($title, 0, 50, 'UTF-8')
                ));
                $pickedRecent = null;
            }
        }
    }

    if (is_array($pickedRecent)) {
        $article = $pickedRecent['article'];
        $article['article_body'] = mb_substr(trim((string)($article['article_body'] ?? '')), 0, 900, 'UTF-8');

        $prompt = tbm_ai_build_article_prompt($targetDate, $article, null);
        $result = tbm_ai_generate_via_gemini(
            $prompt,
            $targetDate,
            trim((string)($article['article_url'] ?? '')),
            trim((string)($article['image_url'] ?? '')),
            trim((string)($article['article_title'] ?? ''))
        );

        if (empty($result['image_file']) || !is_file(__DIR__ . '/' . $result['image_file'])) {
            tbm_ai_log_debug('[이미지 없음] 기사 유지, fallback 이미지 사용 예정');
        }

        if (!$forceNew) {
            tbm_ai_save_cache($targetDate, $result);
        }
        if (ob_get_level() > 0) { @ob_clean(); }
        return $result;
    }

    // ── 4순위: 일반 검색 fallback ───────────────────────────────
    tbm_ai_log_debug('[4순위] 일반 검색 fallback 시도');
    $legacyCandidates = tbm_ai_collect_article_candidates_legacy($targetDate, 12);
    $pickedLegacy = tbm_ai_pick_best_candidate($legacyCandidates);

    if (is_array($pickedLegacy)) {
        $article = $pickedLegacy['article'];
        $article['article_body'] = mb_substr(trim((string)($article['article_body'] ?? '')), 0, 900, 'UTF-8');

        $prompt = tbm_ai_build_article_prompt($targetDate, $article, null);
        $result = tbm_ai_generate_via_gemini(
            $prompt,
            $targetDate,
            trim((string)($article['article_url'] ?? '')),
            trim((string)($article['image_url'] ?? '')),
            trim((string)($article['article_title'] ?? ''))
        );

        if (!$forceNew) {
            tbm_ai_save_cache($targetDate, $result);
        }
        if (ob_get_level() > 0) { @ob_clean(); }
        return $result;
    }

    if (ob_get_level() > 0) { @ob_clean(); }
    throw new RuntimeException('KOSHA 자료, CSI 사고사례, 최근 7일 뉴스, 일반 검색 후보를 모두 찾지 못했습니다.');
}

/**
 * KOSHA 사이렌 아이템의 콘텐츠 품질 점수 (0~20)
 * summary/prevention 텍스트 충실도 기반
 */
function tbm_ai_siren_quality_score(array $siren): int
{
    $score = 0;

    $summary    = trim((string)($siren['summary']    ?? ''));
    $prevention = trim((string)($siren['prevention'] ?? ''));
    $title      = trim((string)($siren['title']      ?? ''));

    // summary 충실도
    $sLen = mb_strlen($summary, 'UTF-8');
    if ($sLen >= 80)      $score += 6;
    elseif ($sLen >= 40)  $score += 4;
    elseif ($sLen >= 15)  $score += 2;

    // prevention 충실도
    $pLen = mb_strlen($prevention, 'UTF-8');
    if ($pLen >= 60)      $score += 5;
    elseif ($pLen >= 30)  $score += 3;
    elseif ($pLen >= 10)  $score += 1;

    // 사고 키워드 포함 (+2)
    if (preg_match('/(사망|추락|감전|끼임|협착|질식|폭발|붕괴|화재|깔림)/u', $title . ' ' . $summary) === 1) {
        $score += 2;
    }

    // Gemini Vision 또는 OCR로 실제 텍스트 추출 성공 (+3)
    if (!empty($siren['ocr_ok']) || !empty($siren['gemini_vision_used'])) {
        $score += 3;
    }

    // OCR 점수 보조 반영
    $score += (int)min(4, (int)($siren['ocr_score_summary'] ?? 0) / 2);

    return max(0, min(20, $score));
}

/**
 * KOSHA 사이렌 아이템 목록에서 가장 품질 좋은 아이템 선택
 * summary+prevention 모두 충분한 아이템을 우선, 없으면 최고점 아이템 반환
 */
function tbm_ai_pick_best_siren_item(array $sirenItems): ?array
{
    if ($sirenItems === []) {
        return null;
    }

    // 먼저 모든 항목에 대해 이미지 저장 + OCR 보강 실행
    foreach ($sirenItems as $idx => $item) {
        $sirenItems[$idx] = tbm_ai_enrich_siren_from_image($item);
    }

    // 1순위: summary 또는 prevention 또는 image_file 있는 항목 바로 채택
    foreach ($sirenItems as $item) {
        $summary    = trim((string)($item['summary'] ?? ''));
        $prevention = trim((string)($item['prevention'] ?? ''));
        $imageFile  = trim((string)($item['image_file'] ?? ''));
        $title      = trim((string)($item['title'] ?? ''));

        if ($title === '') {
            continue;
        }

        if ($summary !== '' || $prevention !== '' || $imageFile !== '') {
            return $item;
        }
    }

    // 2순위: 그래도 없으면 첫 번째 반환
    return $sirenItems[0];
}

// (레거시 load_used_articles / save_used_articles 제거됨
//  — DB 기반 tbm_ai_get_recent_used_source_urls()로 완전 대체)


function tbm_ai_download_and_validate_image(string $url): ?string
{
    if ($url === '') return null;

    $dir = __DIR__ . '/output/images/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $filename = 'article_' . md5($url) . '.jpg';
    $filepath = $dir . $filename;

    // 이미 동일 URL의 파일이 존재하면 재다운로드하지 않음
    if (is_file($filepath) && filesize($filepath) > 2000) {
        $info = @getimagesize($filepath);
        if ($info && $info[0] >= 120 && $info[1] >= 120) {
            tbm_ai_log_debug('[이미지 재사용] 기존 파일: ' . $filename);
            return 'output/images/' . $filename;
        }
    }

    // 다운로드
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);

    $data = curl_exec($ch);
    curl_close($ch);

    if (!$data || strlen($data) < 2000) return null; // 너무 작은 이미지 제거

    file_put_contents($filepath, $data);

    // 이미지 검증
    $info = @getimagesize($filepath);
    if (!$info) {
        unlink($filepath);
        return null;
    }

    [$width, $height] = $info;

    // 너무 작은 이미지 제거
    if ($width < 120 || $height < 120) {
        unlink($filepath);
        return null;
    }

    return 'output/images/' . $filename;
}

/**
 * 기사 이미지를 직접 다운로드하지 못했을 때
 * 네이버 이미지 검색으로 fallback 이미지를 확보한다.
 *
 * @param  string $keyword  검색 키워드 (사고 제목 등)
 * @return string|null      로컬 저장 상대경로 또는 null
 */
function tbm_ai_fetch_fallback_image(string $keyword): ?string
{
    $keyword = trim($keyword);
    if ($keyword === '') {
        return null;
    }

    // 검색어 정리: "중대재해 전파" 등 불필요 접두어 제거
    $keyword = preg_replace('/중대재해\s*전파/u', '', $keyword);
    $keyword = preg_replace('/[()\[\]]/u', '', $keyword);
    $keyword = trim((string)$keyword);
    if ($keyword === '') {
        return null;
    }

    $searchQuery = $keyword . ' 사고 현장';

    // 1순위: 네이버 이미지 검색 API
    if (function_exists('tbm_news_load_env')) {
        tbm_news_load_env();

        $clientId     = trim((string)(getenv('NAVER_CLIENT_ID') ?: ''));
        $clientSecret = trim((string)(getenv('NAVER_CLIENT_SECRET') ?: ''));

        if ($clientId !== '' && $clientSecret !== '') {
            $url = 'https://openapi.naver.com/v1/search/image?display=5&sort=sim&query=' . rawurlencode($searchQuery);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => [
                    'X-Naver-Client-Id: ' . $clientId,
                    'X-Naver-Client-Secret: ' . $clientSecret,
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $httpCode === 200) {
                $data = json_decode($response, true);
                $items = $data['items'] ?? [];

                foreach ($items as $item) {
                    $imageUrl = trim((string)($item['link'] ?? ''));
                    if ($imageUrl === '') {
                        continue;
                    }

                    if (function_exists('tbm_news_is_usable_image_url') && !tbm_news_is_usable_image_url($imageUrl)) {
                        continue;
                    }

                    $downloaded = tbm_ai_download_and_validate_image($imageUrl);
                    if ($downloaded !== null) {
                        tbm_ai_log_debug('[fallback 이미지] 네이버 성공: ' . $imageUrl);
                        return $downloaded;
                    }
                }

                tbm_ai_log_debug('[fallback 이미지] 네이버 유효 이미지 없음: ' . $searchQuery);
            } else {
                tbm_ai_log_debug('[fallback 이미지] 네이버 이미지 검색 실패: HTTP ' . $httpCode);
            }
        } else {
            tbm_ai_log_debug('[fallback 이미지] 네이버 API 키 없음 → 뉴스 이미지 fallback 진행');
        }
    }

    // 2순위: 뉴스 기사 대표 이미지 fallback
    if (function_exists('tbm_news_fetch_recent_week_article_candidates')) {
        $newsQueries = [
            $keyword . ' 사고',
            $keyword,
        ];

        foreach ($newsQueries as $newsQuery) {
            try {
                $articles = tbm_news_fetch_recent_week_article_candidates($newsQuery, 12, [1, 11], ['date', 'sim'], 6);
                if ($articles === []) {
                    $articles = tbm_news_fetch_article_candidates($newsQuery, 12, [1, 11], ['date', 'sim'], 6);
                }

                foreach ($articles as $article) {
                    $imageUrl = trim((string)($article['image_url'] ?? ''));
                    if ($imageUrl === '') {
                        continue;
                    }

                    if (function_exists('tbm_news_is_usable_image_url') && !tbm_news_is_usable_image_url($imageUrl)) {
                        continue;
                    }

                    $downloaded = tbm_ai_download_and_validate_image($imageUrl);
                    if ($downloaded !== null) {
                        tbm_ai_log_debug('[fallback 이미지] 뉴스/구글/Bing fallback 성공: ' . $imageUrl);
                        return $downloaded;
                    }
                }
            } catch (Throwable $e) {
                tbm_ai_log_debug('[fallback 이미지] 뉴스/구글/Bing fallback 실패: ' . $e->getMessage());
            }
        }
    }

    tbm_ai_log_debug('[fallback 이미지] 최종 실패: ' . $searchQuery);
    return null;
}
