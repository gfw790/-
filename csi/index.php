<?php
// CSI 사고사례 검색 메인
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>CSI 사고사례 검색</title>
    <style>
        body { font-family: sans-serif; margin: 40px; }
        .search-box { margin-bottom: 20px; }
        .result { border: 1px solid #ccc; padding: 12px; margin-bottom: 10px; border-radius: 6px; }
        .result-title { font-weight: bold; font-size: 1.1em; }
        .result-link { font-size: 0.95em; color: #2563eb; }
        .result-summary { margin-top: 6px; color: #444; }
    </style>
</head>
<body>
    <h1>CSI 사고사례 검색</h1>
    <form class="search-box" method="get" action="">
        <input type="text" name="q" placeholder="키워드 또는 내용 입력" style="width:300px;" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
        <button type="submit">검색</button>
    </form>
    <div id="results">
    <?php
    $caseTable = '';
    $caseArr = [];
    if (!empty($_GET['q'])) {
        $q = trim($_GET['q']);
        $results = @file_get_contents('csi_search.php?q=' . urlencode($q));
        if ($results) {
            $data = json_decode($results, true);
            if (is_array($data) && isset($data['items'])) {
                // 사고사례 표 생성 및 배열 직렬화
                $caseTable .= "사고번호 | 일시 | 장소 | 사고상황\n";
                $caseTable .= "---|---|---|---\n";
                foreach ($data['items'] as $item) {
                    $caseNo = $item['accident_no'] ?? '-';
                    $caseDate = $item['accident_date'] ?? '-';
                    $casePlace = $item['accident_place'] ?? '-';
                    $caseSit = $item['accident_situation'] ?? '-';
                    $caseTable .= "$caseNo | $caseDate | $casePlace | $caseSit\n";
                    $caseArr[] = [
                        '사고번호' => $caseNo,
                        '일시' => $caseDate,
                        '장소' => $casePlace,
                        '사고상황' => $caseSit
                    ];
                    echo '<div class="result">';
                    echo '<div class="result-title">사고번호: ' . htmlspecialchars($caseNo) . '</div>';
                    echo '<div>일시: ' . htmlspecialchars($caseDate) . '</div>';
                    echo '<div>장소: ' . htmlspecialchars($casePlace) . '</div>';
                    echo '<div>사고상황: ' . htmlspecialchars($caseSit) . '</div>';
                    echo '<a class="result-link" href="' . htmlspecialchars($item['url']) . '" target="_blank">상세보기</a>';
                    echo '</div>';
                }
                // 질문 입력 UI
                echo '<form id="ask-form" style="margin-top:30px;">';
                echo '<input type="text" id="ask-q" name="ask-q" placeholder="사고사례에 대해 자연어로 질문하세요" style="width:350px;"> ';
                echo '<button type="submit">질문</button>';
                echo '<span id="ask-status" style="margin-left:10px;color:#888;"></span>';
                echo '</form>';
                echo '<div id="ask-answer" style="margin-top:18px;font-weight:500;color:#1e293b;"></div>';
                // 사고사례 표/배열을 JS에 전달
                echo '<script>window.csiCaseTable = ' . json_encode($caseTable) . "; window.csiCaseArr = " . json_encode($caseArr) . ";</script>";
            } else {
                echo '<div>검색 결과가 없습니다.</div>';
            }
        } else {
            echo '<div>검색 중 오류가 발생했습니다.</div>';
        }
    }
    ?>
    </div>
    <script>
    // 질문 폼 AJAX 처리
    const askForm = document.getElementById('ask-form');
    if (askForm) {
        askForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const q = document.getElementById('ask-q').value.trim();
            if (!q) return;
            document.getElementById('ask-status').textContent = 'AI 답변 생성 중...';
            document.getElementById('ask-answer').textContent = '';
            fetch('ask.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'q=' + encodeURIComponent(q) + '&cases=' + encodeURIComponent(window.csiCaseTable)
            })
            .then(r => r.json())
            .then(data => {
                document.getElementById('ask-status').textContent = '';
                if (data.answer) {
                    document.getElementById('ask-answer').textContent = data.answer;
                } else {
                    document.getElementById('ask-answer').textContent = data.error || '답변 생성 실패';
                }
            })
            .catch(() => {
                document.getElementById('ask-status').textContent = '';
                document.getElementById('ask-answer').textContent = '오류 발생';
            });
        });
    }
    </script>
</body>
</html>
