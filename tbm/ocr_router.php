<?php
declare(strict_types=1);

function tbm_ocr_normalize_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    return trim($text);
}

function tbm_ocr_score_text(string $text): int
{
    $score = 0;
    $len = mb_strlen(trim($text), 'UTF-8');

    if ($len >= 80) $score += 3;
    elseif ($len >= 40) $score += 2;
    elseif ($len >= 15) $score += 1;

    foreach (['사고', '예방', '대책', '재해', '업종', '발생', '개요'] as $kw) {
        if (mb_strpos($text, $kw, 0, 'UTF-8') !== false) {
            $score += 1;
        }
    }

    return $score;
}

function tbm_ocr_run_tesseract(string $imagePath, string $tesseractPath = 'A:\\Tesseract-OCR\\tesseract.exe'): array
{
    $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tbm_ocr_' . uniqid();
    $cmd = '"' . $tesseractPath . '" ' . escapeshellarg($imagePath) . ' ' . escapeshellarg($tmpBase) . ' -l kor+eng 2>&1';
    exec($cmd, $output, $code);

    $txtPath = $tmpBase . '.txt';
    $text = is_file($txtPath) ? (string)file_get_contents($txtPath) : '';
    if (is_file($txtPath)) @unlink($txtPath);

    return [
        'engine' => 'tesseract',
        'ok' => $code === 0 || trim($text) !== '',
        'text' => tbm_ocr_normalize_text($text),
        'raw_output' => implode("\n", $output),
        'score' => tbm_ocr_score_text($text),
    ];
}

function tbm_ocr_run_easyocr(string $imagePath, string $pythonPath = 'python', ?string $scriptPath = null): array
{
    $scriptPath ??= __DIR__ . '/ocr_easy.py';
    if (!is_file($scriptPath)) {
        return [
            'engine' => 'easyocr',
            'ok' => false,
            'text' => '',
            'raw_output' => 'ocr_easy.py not found',
            'score' => 0,
        ];
    }

    $cmd = '"' . $pythonPath . '" ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($imagePath) . ' 2>&1';
    exec($cmd, $output, $code);
    $raw = implode("\n", $output);
    $json = json_decode($raw, true);

    if (!is_array($json) || empty($json['ok'])) {
        return [
            'engine' => 'easyocr',
            'ok' => false,
            'text' => '',
            'raw_output' => $raw,
            'score' => 0,
        ];
    }

    $text = tbm_ocr_normalize_text((string)($json['text'] ?? ''));
    return [
        'engine' => 'easyocr',
        'ok' => true,
        'text' => $text,
        'raw_output' => $raw,
        'score' => tbm_ocr_score_text($text),
    ];
}

function tbm_ocr_read_image(string $imagePath, array $options = []): array
{
    $tesseractPath = $options['tesseract_path'] ?? 'tesseract';
    $pythonPath = $options['python_path'] ?? 'python';
    $easyocrScript = $options['easyocr_script'] ?? (__DIR__ . '/ocr_easy.py');
    $minScore = (int)($options['min_score_for_tesseract'] ?? 4);

    $first = tbm_ocr_run_tesseract($imagePath, $tesseractPath);
    if ($first['ok'] && $first['score'] >= $minScore) {
        return $first;
    }

    $second = tbm_ocr_run_easyocr($imagePath, $pythonPath, $easyocrScript);
    if ($second['ok'] && $second['score'] >= $first['score']) {
        return $second;
    }

    return $first['score'] >= $second['score'] ? $first : $second;
}
