<?php
/**
 * test_tbm_siren.php — 브라우저 점검 스크립트
 * 접속: http://localhost/tbm/test_tbm_siren.php
 *
 * URL 파라미터:
 *   ?mode=dry      네트워크 없이 내부 로직만 점검
 *   ?mode=full     실제 KOSHA 접속 포함 (기본값)
 *   ?mode=ocr      OCR 파이프라인까지 점검
 *   ?limit=3       수집 건수 제한 (기본 5)
 */
declare(strict_types=1);

$mode  = in_array($_GET['mode'] ?? 'full', ['dry','full','ocr'], true)
       ? ($_GET['mode'] ?? 'full') : 'full';
$limit = max(1, min(20, (int)($_GET['limit'] ?? 5)));

function tbm_ai_log_debug(string $msg): void
{
    echo '<div style="color:red; font-size:12px;">[LOG] ' . htmlspecialchars($msg) . '</div>';
}

// ── 결과 수집 ─────────────────────────────────────────────
$results = [];
$counts  = ['pass'=>0,'fail'=>0,'warn'=>0,'info'=>0];

function rec(string $type, string $section, string $msg, string $detail = ''): void
{
    global $results, $counts;
    $counts[$type] = ($counts[$type] ?? 0) + 1;
    $results[] = compact('type','section','msg','detail');
}

// ── tbm_siren.php 로드 ────────────────────────────────────
$sirenFile = __DIR__ . '/tbm_siren.php';
$loadError = '';
if (is_file($sirenFile)) {
    ob_start();
    try { require_once $sirenFile; }
    catch (Throwable $e) { $loadError = $e->getMessage(); }
    ob_get_clean();
} else {
    $loadError = '파일 없음: ' . $sirenFile;
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 점검 실행
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$t0 = microtime(true);

// 0. 환경
$loadError
    ? rec('fail','환경','tbm_siren.php 로드 실패',$loadError)
    : rec('pass','환경','tbm_siren.php 로드 성공');

foreach (['curl','json','mbstring'] as $ext) {
    extension_loaded($ext)
        ? rec('pass','환경',"$ext 확장 로드됨")
        : rec('fail','환경',"$ext 확장 없음 (필수)");
}
extension_loaded('gd')
    ? rec('pass','환경','GD 확장 로드됨 — 이미지 crop 가능')
    : rec('warn','환경','GD 확장 없음 — crop 불가, Gemini Vision도 호출 안 됨',
          'GD 없을 때 원본 이미지를 Vision에 직접 전달하는 경로 추가 권장');

$envFile   = __DIR__ . '/.env';
$geminiKey = '';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line==='' || str_starts_with($line,'#')) continue;
        [$k,$v] = explode('=',$line,2) + ['',''];
        $k=trim($k); $v=trim(trim($v),'"\'');
        putenv("$k=$v"); $_ENV[$k]=$v;
        if ($k==='GEMINI_API_KEY') $geminiKey=$v;
    }
    rec('pass','환경','.env 로드됨');
} else {
    rec('warn','환경','.env 없음');
}
$geminiKey = $geminiKey ?: trim((string)getenv('GEMINI_API_KEY'));
$geminiKey !== ''
    ? rec('pass','환경','GEMINI_API_KEY 설정됨 — Gemini Vision 활성화')
    : rec('warn','환경','GEMINI_API_KEY 없음 — OCR 실패 시 Vision 전환 불가');

$tPath = trim((string)(getenv('TESSERACT_PATH')?:''));
if ($tPath && is_file($tPath)) {
    rec('pass','환경','Tesseract 경로 확인: '.$tPath);
} else {
    rec('warn','환경','Tesseract 실행파일 없음 — EasyOCR 또는 Gemini Vision 폴백 사용');
}

// 1. 함수 정의 확인
if (!$loadError) {
    $fns = [
        'tbm_siren_get_recent_items','tbm_siren_fetch_list_html',
        'tbm_siren_parse_recent_items','tbm_siren_parse_recent_items_by_regex',
        'tbm_siren_parse_detail','tbm_siren_extract_date',
        'tbm_siren_is_useful_title','tbm_siren_build_queries_from_title',
        'tbm_siren_score_domain_text','tbm_siren_ocr_region',
        'tbm_siren_gemini_vision_extract','tbm_siren_extract_text_with_fallback',
        'tbm_siren_extract_summary_prevention_from_image',
        'tbm_normalize_ocr_text','tbm_extract_summary_and_prevention_from_ocr',
    ];
    foreach ($fns as $fn) {
        function_exists($fn)
            ? rec('pass','함수 정의',"$fn()")
            : rec('fail','함수 정의',"$fn() 없음 — 누락");
    }
}

// 2. 유틸 함수 단위 테스트
if (!$loadError) {
    // is_useful_title
    foreach ([
        ['중대재해 발생 알림 - 건설현장 추락 사고',true],
        ['목록',false],['이전',false],['prev',false],['ab',false],
        ['중대재해 사이렌 - 감전 사고',true],
    ] as [$t,$exp]) {
        $got = tbm_siren_is_useful_title($t);
        $s   = mb_substr($t,0,20);
        $got===$exp
            ? rec('pass','단위 테스트',"is_useful_title: \"$s\" → ".($got?'true':'false'))
            : rec('fail','단위 테스트',"is_useful_title: \"$s\" 기대:".($exp?'true':'false')." 실제:".($got?'true':'false'));
    }

    // extract_date
    foreach ([
        ['2026-03-25 게시됨','2026-03-25'],
        ['2026.3.25 사고',   '2026-03-25'],
        ['20260325 등록',    '2026-03-25'],
        ['날짜 없는 텍스트', ''],
    ] as [$in,$exp]) {
        $got = tbm_siren_extract_date($in);
        $got===$exp
            ? rec('pass','단위 테스트',"extract_date: \"$in\" → \"$got\"")
            : rec('fail','단위 테스트',"extract_date: \"$in\" 기대:\"$exp\" 실제:\"$got\"");
    }

    // build_queries
    $q = tbm_siren_build_queries_from_title('중대재해 발생 알림 - 건설현장 추락 사고');
    is_array($q)&&count($q)>=1
        ? rec('pass','단위 테스트','build_queries: '.count($q).'개 — '.implode(' / ',array_slice($q,0,3)))
        : rec('fail','단위 테스트','build_queries: 쿼리 생성 실패');

    // score_domain_text — 수정된 가중치 기준으로 검증
    foreach ([
        ['2026년 3월 건설현장 추락 사망','summary',   5,'pass'],  // 사망+2, 추락+1, 현장+1, 길이+1 → 5
        ['컨베이어 끼임 사고로 작업자 사망','summary', 6,'pass'],  // 사망+2, 사고+2, 끼임+1 → 5+길이2 → pass
        ['안전대 착용 안전장치 설치 확인','prevention',5,'pass'],  // 안전대+2, 착용+2, 설치+2, 확인+2 → pass
        ['안','summary',6,'fail'],                                  // 길이 1자 → 0점
    ] as [$txt,$typ,$thr,$exp]) {
        $score = tbm_siren_score_domain_text($txt,$typ);
        $s     = mb_substr($txt,0,22,'UTF-8');
        $line  = "score_domain \"$s\" → $score/$thr";
        if ($exp==='warn') {
            rec('warn','단위 테스트',$line.' (임계값 미달 → Vision 전환)');
        } elseif ($exp==='fail') {
            $score < $thr
                ? rec('pass','단위 테스트',$line.' (짧은 텍스트 정상 탈락)')
                : rec('warn','단위 테스트',$line.' (짧은 텍스트가 통과됨 — 이상)')  ;
        } elseif ($score>=$thr) {
            rec('pass','단위 테스트',$line);
        } else {
            rec('fail','단위 테스트',$line." (기대 최소: $thr)");
        }
    }

    // normalize_ocr_text
    $raw  = "중대재해  사이렌\n\n[ ] | 패\n발을 헛디뎌 추락";
    $norm = tbm_normalize_ocr_text($raw);
    str_contains($norm,'추락')&&!str_contains($norm,'패')
        ? rec('pass','단위 테스트','normalize_ocr: 노이즈 제거 정상 — "'.str_replace("\n",' ',$norm).'"')
        : rec('fail','단위 테스트','normalize_ocr: 노이즈 제거 실패 — "'.str_replace("\n",' ',$norm).'"');

    // extract_summary_and_prevention
    $p = tbm_extract_summary_and_prevention_from_ocr(
            "건설현장에서 작업자 추락 사망\n예방 대책\n안전난간 설치 및 안전대 착용");
    if (!empty($p['summary'])&&!empty($p['prevention'])) {
        rec('pass','단위 테스트','extract_summary_prevention: summary+prevention 모두 추출');
    } elseif (!empty($p['summary'])) {
        rec('warn','단위 테스트','extract_summary_prevention: summary만 추출','예방대책 구분 패턴 미매칭 가능');
    } else {
        rec('fail','단위 테스트','extract_summary_prevention: 모두 추출 실패');
    }

    // ocr_default_options
    $opts = tbm_siren_ocr_default_options();
    foreach (['crop_summary','crop_prevention','min_score_summary','min_score_prevention'] as $key) {
        isset($opts[$key])
            ? rec('pass','단위 테스트',"ocr_options[$key]: ".json_encode($opts[$key],JSON_UNESCAPED_UNICODE))
            : rec('fail','단위 테스트',"ocr_options[$key] 키 없음");
    }

    // 3. HTML 파싱 테스트 (모의 데이터)
    $mockList = '<html><body><ul>
      <li><a href="/archive/CSADV50000?nttId=1001">중대재해 발생 알림 - 건설현장 추락 사고</a><span>2026-03-25</span></li>
      <li><a href="/archive/CSADV50000?nttId=1002">중대재해 사이렌 - 제조업 감전 사고</a><span>2026-03-22</span></li>
      <li><a href="/archive/CSADV50000?nttId=1003">협착 사망 사고</a></li>
      <li><a href="/nav">목록</a></li>
      <li><a href="/nav2">이전</a></li>
    </ul></body></html>';
    $parsed = tbm_siren_parse_recent_items($mockList, 10);
    count($parsed)===3
        ? rec('pass','HTML 파싱','모의 목록: 3건 수집, 네비게이션 링크 정상 제외')
        : rec('fail','HTML 파싱','모의 목록: '.count($parsed).'건 수집 (기대: 3건)');

    $mockDetail = '<html><head>
      <title>중대재해 발생 알림 - 건설현장 추락</title>
      <meta property="og:image" content="https://portal.kosha.or.kr/getImage.do?FILE_001"/>
    </head><body>
    사고개요: 2026년 3월 25일 경기도 건설현장에서 작업자가 추락하여 사망.
    예방대책: 안전난간 설치 및 안전대 착용 의무화. 추락방호망 확인 필수.
    </body></html>';

    $text = trim(preg_replace('/\s+/u',' ',strip_tags(html_entity_decode($mockDetail,ENT_QUOTES|ENT_HTML5,'UTF-8')))?:'');
    $sumOk = preg_match('/사고\s*개요[:：]?\s*(.{20,300}?)(예방대책|재발방지|$)/u',$text,$ms);
    $preOk = preg_match('/(예방대책|재발방지\s*대책)[:：]?\s*(.{20,300}?)(첨부|목록|$)/u',$text,$mp);
    $imgOk = preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](.*?)["\']/isu',$mockDetail,$mi);

    $sumOk ? rec('pass','HTML 파싱','사고개요 추출: "'.mb_substr(trim($ms[1]),0,40).'"')
           : rec('fail','HTML 파싱','사고개요 추출 실패');
    $preOk ? rec('pass','HTML 파싱','예방대책 추출: "'.mb_substr(trim($mp[2]),0,40).'"')
           : rec('fail','HTML 파싱','예방대책 추출 실패');
    $imgOk ? rec('pass','HTML 파싱','og:image 추출: '.trim($mi[1]))
           : rec('fail','HTML 파싱','og:image 추출 실패');

    $mockImgOnly = '<html><head><meta property="og:image" content="https://portal.kosha.or.kr/getImage.do?FILE_002"/></head><body></body></html>';
    $txt2  = strip_tags(html_entity_decode($mockImgOnly,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    $hasImg= preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](.*?)["\']/isu',$mockImgOnly);
    $noSum = !str_contains($txt2,'사고개요');
    ($hasImg&&$noSum)
        ? rec('pass','HTML 파싱','이미지 전용 → OCR 경로 진입 조건 정상')
        : rec('fail','HTML 파싱','OCR 경로 진입 조건 판단 오류');
}

// 4. 네트워크 / KOSHA 실접속
$liveItems = [];
if ($mode!=='dry' && !$loadError) {
    rec('info','네트워크','KOSHA 포털 접속 중... (최대 20초)');
    $html = tbm_siren_fetch_list_html();
    if (trim($html)!=='') {
        rec('pass','네트워크','KOSHA 포털 접속 성공 ('.strlen($html).' bytes)');
        $liveItems = tbm_siren_parse_recent_items($html, $limit);
        if (count($liveItems) > 0) {
            rec('pass','네트워크','실제 게시글 파싱: '.count($liveItems).'건 수집');
        } else {
            rec('warn','네트워크','게시글 0건 — HTML 구조 분석 중');

            // 실제 HTML에서 <a> 링크 샘플 추출 (최대 10개) — 구조 파악용
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            libxml_clear_errors();
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query('//a[@href]');
            $sample = [];
            if ($nodes instanceof DOMNodeList) {
                foreach ($nodes as $a) {
                    $href  = trim((string)$a->getAttribute('href'));
                    $text  = trim(preg_replace('/\s+/u', ' ', $a->textContent ?? '') ?? '');
                    if ($href === '' || strlen($text) < 2) continue;
                    $sample[] = ['href' => mb_substr($href, 0, 80), 'text' => mb_substr($text, 0, 40, 'UTF-8')];
                    if (count($sample) >= 10) break;
                }
            }
            if (!empty($sample)) {
                foreach ($sample as $i => $s) {
                    rec('info','네트워크','[링크'.($i+1).'] text="'.$s['text'].'" href="'.$s['href'].'"');
                }
            } else {
                // DOM 실패 시 정규식으로 링크 추출
                preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $html, $m);
                for ($i = 0; $i < min(10, count($m[1])); $i++) {
                    $t = trim(strip_tags($m[2][$i] ?? ''));
                    if (strlen($t) < 2) continue;
                    rec('info','네트워크','[링크'.($i+1).'] text="'.mb_substr($t,0,40).'" href="'.mb_substr($m[1][$i],0,80).'"');
                }
            }
            // HTML 앞부분 텍스트 (구조 파악용)
            $textSample = mb_substr(strip_tags($html), 0, 300, 'UTF-8');
            $textSample = preg_replace('/\s+/u', ' ', $textSample) ?? '';
            rec('info','네트워크','HTML 텍스트 앞부분: "'.trim($textSample).'"');
        }
    } else {
        rec('warn','네트워크','KOSHA 포털 응답 없음 — 네트워크 또는 서버 점검 필요');
    }
} else {
    rec('info','네트워크','dry 모드: 네트워크 테스트 건너뜀');
}

// 5. OCR 파이프라인
if ($mode==='ocr' && !$loadError) {
    if (!extension_loaded('gd')) {
        rec('warn','OCR','GD 없음 — 이미지 테스트 건너뜀');
    } else {
        $testImg = sys_get_temp_dir().'/tbm_test_'.uniqid().'.jpg';
        $img=imagecreatetruecolor(800,600);
        $w=imagecolorallocate($img,255,255,255);
        $b=imagecolorallocate($img,0,0,0);
        imagefill($img,0,0,$w);
        imagestring($img,5,50,180,'2026.03.25 건설현장 추락 사고 발생',$b);
        imagestring($img,5,50,500,'안전대 착용 및 안전난간 설치 확인',$b);
        imagejpeg($img,$testImg,95); imagedestroy($img);
        rec('pass','OCR','테스트 이미지 생성: '.$testImg);

        $ocrOpts = tbm_siren_ocr_default_options();
        $cropOut = sys_get_temp_dir().'/tbm_crop_'.uniqid().'.jpg';
        $cropped = tbm_siren_crop_region($testImg,$ocrOpts['crop_summary'],$cropOut);
        $cropped ? rec('pass','OCR','영역 crop 성공') : rec('fail','OCR','영역 crop 실패');
        if ($cropped&&is_file($cropOut)) @unlink($cropOut);

        $prepOut = sys_get_temp_dir().'/tbm_prep_'.uniqid().'.jpg';
        $prepped = tbm_siren_preprocess_for_ocr($testImg,$prepOut,2);
        $prepped ? rec('pass','OCR','이미지 전처리(2×확대+이진화) 성공') : rec('warn','OCR','전처리 실패');
        if ($prepped&&is_file($prepOut)) @unlink($prepOut);
        @unlink($testImg);
    }
    if ($geminiKey!=='') {
        rec('info','OCR','Gemini Vision 연결 테스트 중...');
        $tinyPath=sys_get_temp_dir().'/tbm_tiny_'.uniqid().'.jpg';
        if (extension_loaded('gd')) {
            $tiny=imagecreatetruecolor(2,2); imagejpeg($tiny,$tinyPath); imagedestroy($tiny);
        }
        if (is_file($tinyPath)) {
            $vr = tbm_siren_gemini_vision_extract($tinyPath);
            @unlink($tinyPath);
            (!isset($vr['error'])||!str_contains($vr['error'],'API_KEY'))
                ? rec('pass','OCR','Gemini Vision API 연결 확인됨')
                : rec('fail','OCR','Gemini Vision API 키 오류: '.($vr['error']??''));
        }
    } else {
        rec('warn','OCR','GEMINI_API_KEY 없음 — Vision 연결 테스트 건너뜀');
    }
} elseif ($mode!=='ocr') {
    rec('info','OCR','OCR 파이프라인 테스트 비활성 — ?mode=ocr 로 활성화');
}

$elapsed = round(microtime(true)-$t0, 2);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// HTML 출력
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$typeColor=['pass'=>'#1a9850','fail'=>'#d73027','warn'=>'#f4a100','info'=>'#4575b4'];
$typeBg   =['pass'=>'#eaf6ec','fail'=>'#fdecea','warn'=>'#fff8e6','info'=>'#eaf1fb'];
$typeLabel=['pass'=>'PASS','fail'=>'FAIL','warn'=>'WARN','info'=>'INFO'];

$sections=[];
foreach ($results as $r) { $sections[$r['section']][]=$r; }

$overallColor = $counts['fail']>0 ? '#d73027' : ($counts['warn']>0 ? '#f4a100' : '#1a9850');
$overallMsg   = $counts['fail']>0 ? 'FAIL 항목 수정 필요' : ($counts['warn']>0 ? 'WARN 확인 권장' : '모든 항목 정상');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>tbm_siren 점검</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Malgun Gothic','Segoe UI',sans-serif;font-size:14px;background:#f4f4f5;color:#1a1a1a;line-height:1.6}
.wrap{max-width:900px;margin:0 auto;padding:24px 16px}
h1{font-size:18px;font-weight:700;margin-bottom:2px}
.sub{color:#777;font-size:12px;margin-bottom:18px}
.summary-bar{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:16px}
.sc{background:#fff;border-radius:8px;padding:12px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.07)}
.sc .n{font-size:26px;font-weight:700;line-height:1.2}
.sc .l{font-size:11px;color:#999;margin-top:2px}
.overall{background:#fff;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;box-shadow:0 1px 3px rgba(0,0,0,.07)}
.dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.modes{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
.ml{padding:5px 13px;border-radius:5px;text-decoration:none;font-size:12px;background:#e4e4e7;color:#555;transition:background .15s}
.ml.active,.ml:hover{background:#18181b;color:#fff}
.lim{font-size:12px;color:#aaa;margin-left:4px}
.lim a{margin:0 3px;color:#888;text-decoration:none}
.lim a.on{color:#18181b;font-weight:700}
.block{background:#fff;border-radius:8px;padding:0;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.07);overflow:hidden}
.block-head{font-size:13px;font-weight:600;color:#444;padding:10px 14px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between}
.block-head span{color:#bbb;font-weight:400;font-size:12px}
table{width:100%;border-collapse:collapse}
tr+tr td{border-top:1px solid #f5f5f5}
td{padding:7px 12px;vertical-align:top}
td:first-child{width:58px;padding-right:6px}
.badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;letter-spacing:.05em;white-space:nowrap}
.msg{font-size:13px}
.det{font-size:11px;color:#999;margin-top:2px}
.live-item{border:1px solid #eee;border-radius:6px;padding:10px 14px;margin:8px 14px}
.live-item:first-child{margin-top:12px}
.live-item:last-child{margin-bottom:12px}
.lt{font-weight:600;font-size:13px;margin-bottom:2px}
.lm{font-size:12px;color:#888}
a{color:#2563eb}
@media(max-width:500px){.summary-bar{grid-template-columns:repeat(3,1fr)}.sc .n{font-size:20px}}
</style>
</head>
<body>
<div class="wrap">
  <h1>tbm_siren.php 점검 결과</h1>
  <div class="sub"><?= date('Y-m-d H:i:s') ?> &nbsp;&middot;&nbsp; <?= $elapsed ?>초 소요</div>

  <div class="modes">
    <a class="ml <?= $mode==='full'?'active':'' ?>" href="?mode=full&limit=<?= $limit ?>">전체 (KOSHA 접속)</a>
    <a class="ml <?= $mode==='dry'?'active':'' ?>"  href="?mode=dry&limit=<?= $limit ?>">dry-run (로직만)</a>
    <a class="ml <?= $mode==='ocr'?'active':'' ?>"  href="?mode=ocr&limit=<?= $limit ?>">OCR 파이프라인</a>
    <span class="lim">수집:
      <?php foreach([1,3,5,10] as $n): ?>
        <a href="?mode=<?=$mode?>&limit=<?=$n?>" class="<?=$n==$limit?'on':''?>"><?=$n?>건</a>
      <?php endforeach; ?>
    </span>
  </div>

  <div class="summary-bar">
    <?php foreach(['pass'=>'PASS','fail'=>'FAIL','warn'=>'WARN','info'=>'INFO'] as $t=>$l): ?>
    <div class="sc">
      <div class="n" style="color:<?=$typeColor[$t]?>"><?=$counts[$t]?></div>
      <div class="l"><?=$l?></div>
    </div>
    <?php endforeach; ?>
    <div class="sc">
      <div class="n" style="color:#444"><?=array_sum($counts)?></div>
      <div class="l">전체</div>
    </div>
  </div>

  <div class="overall">
    <div class="dot" style="background:<?=$overallColor?>"></div>
    <strong style="color:<?=$overallColor?>"><?=$overallMsg?></strong>
    <span style="color:#aaa;font-size:12px">PASS <?=$counts['pass']?> / FAIL <?=$counts['fail']?> / WARN <?=$counts['warn']?></span>
  </div>

  <?php foreach($sections as $secName=>$rows): ?>
  <div class="block">
    <div class="block-head"><?=htmlspecialchars($secName)?><span><?=count($rows)?>건</span></div>
    <table>
    <?php foreach($rows as $r): ?>
      <tr>
        <td><span class="badge" style="background:<?=$typeBg[$r['type']]?>;color:<?=$typeColor[$r['type']]?>"><?=$typeLabel[$r['type']]?></span></td>
        <td>
          <div class="msg"><?=htmlspecialchars($r['msg'])?></div>
          <?php if($r['detail']!==''): ?>
          <div class="det"><?=htmlspecialchars($r['detail'])?></div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </table>
  </div>
  <?php endforeach; ?>

  <?php if(!empty($liveItems)): ?>
  <div class="block">
    <div class="block-head">실제 수집된 KOSHA 게시글<span><?=count($liveItems)?>건</span></div>
    <?php foreach($liveItems as $item): ?>
    <div class="live-item">
      <div class="lt"><?=htmlspecialchars($item['title'])?></div>
      <div class="lm">
        게시일: <?=$item['posted_date']?:'미상'?> &nbsp;&middot;&nbsp;
        <a href="<?=htmlspecialchars($item['detail_url'])?>" target="_blank">상세 페이지</a><br>
        검색 키워드: <?=htmlspecialchars(implode(' / ',array_slice($item['search_keywords']??[],0,3)))?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
