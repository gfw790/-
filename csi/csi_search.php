// 파일 쓰기 테스트
file_put_contents(__DIR__.'/debug_test.txt', '테스트: ' . date('c'));
<?php
// CSI 사고사례 크롤러 (간단 버전)
header('Content-Type: application/json; charset=UTF-8');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($q === '') {
    echo json_encode(['items' => []]);
    exit;
}

// CSI 사고사례 검색 URL
$searchUrl = 'https://www.csi.go.kr/acd/acdCaseList.do?searchCondition=all&searchKeyword=' . urlencode($q);

// 사이트에서 HTML 가져오기 (cURL 사용)

function curl_get($url, &$curl_debug = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // SSL 인증서 무시(내부망/테스트용)
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; CSI-SearchBot/1.0)');
    $res = curl_exec($ch);
    $curl_debug = [
        'errno' => curl_errno($ch),
        'error' => curl_error($ch),
        'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'url' => $url
    ];
    curl_close($ch);
    return $res;
}

$curl_debug = null;
$html = curl_get($searchUrl, $curl_debug);
if ($html === false || trim($html) === '') {
    file_put_contents(__DIR__.'/debug_csi.html', $html);
    echo json_encode([
        'items' => [],
        'error' => '검색 실패(cURL)',
        'debug' => $curl_debug
    ]);
    exit;
}

// 사고사례 목록 파싱 (간단한 정규식 기반)
$items = [];
    if (preg_match_all('/<a href="javascript:goView\\((\\d+)\\)">(.+?)<\\/a>.*?<td class="text-left">(.*?)<\\/td>/s', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $caseId = $m[1];
            $title = strip_tags($m[2]);
            $summary = strip_tags($m[3]);
            $url = 'https://www.csi.go.kr/acd/acdCaseView.do?caseId=' . $caseId;

            // 상세 페이지 본문 크롤링
            $detail_debug = null;
            $detailHtml = curl_get($url, $detail_debug);

            $bodyText = '';
            $accidentNo = '';
            $accidentDate = '';
            $accidentPlace = '';
            $accidentSituation = '';
            if ($detailHtml !== false) {
                // 본문 내용 추출 (간단한 정규식, 실제 구조에 따라 조정 필요)
                if (preg_match('/<div class="case-contents">([\s\S]+?)<\/div>/i', $detailHtml, $dm)) {
                    $bodyText = strip_tags($dm[1]);
                    // 사고번호, 일시, 장소, 사고상황 추출
                    if (preg_match('/<th>사고번호<\/th>\s*<td>(.*?)<\/td>.*?<th>일시<\/th>\s*<td>(.*?)<\/td>.*?<th>장소<\/th>\s*<td>(.*?)<\/td>.*?<th>사고상황<\/th>\s*<td>(.*?)<\/td>/s', $dm[1], $info)) {
                        $accidentNo = trim(strip_tags($info[1]));
                        $accidentDate = trim(strip_tags($info[2]));
                        $accidentPlace = trim(strip_tags($info[3]));
                        $accidentSituation = trim(strip_tags($info[4]));
                    }
                }
            }

            // 키워드가 제목, 요약, 본문 중 하나라도 포함되면 결과에 포함
            $qLower = mb_strtolower($q, 'UTF-8');
            $found = false;
            foreach ([$title, $summary, $bodyText] as $field) {
                if (mb_stripos(mb_strtolower($field, 'UTF-8'), $qLower) !== false) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $items[] = [
                    'title' => $title,
                    'url' => $url,
                    'summary' => $summary,
                    'body' => $bodyText,
                    'accident_no' => $accidentNo,
                    'accident_date' => $accidentDate,
                    'accident_place' => $accidentPlace,
                    'accident_situation' => $accidentSituation
                ];
            }
        }
    }

echo json_encode(['items' => $items]);
