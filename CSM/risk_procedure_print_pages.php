<?php
declare(strict_types=1);

/** Split editable procedure text into printable pages without breaking a table row. */
function csm_ra_document_print_pages(array $sections, array $data): array
{
    $blocks = [];
    foreach ($sections as $key => $section) {
        $text = trim((string)($data[$key] ?? ''));
        if ($text === '') continue;
        $title = (string)$section['title'];
        if (in_array($key, ['policy', 'article_1', 'article_3', 'article_4', 'article_5', 'article_6', 'article_7', 'article_8'], true) || str_starts_with($key, 'form_')) {
            $blocks[] = ['title' => $title, 'text' => $text, 'standalone' => $key === 'policy' || str_starts_with($key, 'form_'), 'cover' => $key === 'policy', 'form' => str_starts_with($key, 'form_')];
            continue;
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        $buffer = [];
        $part = 0;
        $flush = static function () use (&$blocks, &$buffer, &$part, $title): void {
            if (!$buffer) return;
            $blocks[] = ['title' => $title . ($part ? ' (계속)' : ''), 'text' => implode("\n", $buffer), 'standalone' => false];
            $buffer = [];
            $part++;
        };
        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') continue;
            if (str_starts_with($line, '|')) {
                $caption = $buffer && preg_match('/^<표\s*\d+>/u', (string)end($buffer)) ? array_pop($buffer) : null;
                $flush();
                $header = $line;
                $rows = [];
                while (isset($lines[$i + 1]) && str_starts_with(trim($lines[$i + 1]), '|')) $rows[] = trim($lines[++$i]);
                $isSpecial = $key === 'article_9' && $caption !== null
                    && preg_match('/<표\s*[4567]>/u', $caption);
                $chunkSize = $isSpecial ? count($rows) : ($key === 'article_4' ? 2 : ($key === 'article_8' ? 4 : 3));
                foreach (array_chunk($rows, max(1, $chunkSize)) as $chunk) {
                    $blocks[] = [
                        'title' => $title . ($part ? ' (계속)' : ''),
                        'text' => implode("\n", array_merge($caption !== null ? [$caption] : [], [$header], $chunk)),
                        'standalone' => true,
                    ];
                    $part++;
                }
                continue;
            }
            $length = mb_strlen(implode("\n", $buffer), 'UTF-8');
            if ($buffer && ($length + mb_strlen($line, 'UTF-8') > 350 || count($buffer) >= 12)) $flush();
            $buffer[] = $line;
        }
        $flush();
    }

    $pages = [];
    $current = [];
    $currentLength = 0;
    foreach ($blocks as $block) {
        $length = mb_strlen($block['text'], 'UTF-8');
        $joinsOrgChart = $block['title'] === ($sections['article_3']['title'] ?? '')
            && $current && end($current)['title'] === ($sections['article_2']['title'] ?? '')
            && $currentLength + $length <= 520;
        $joinsArticle5 = $block['title'] === ($sections['article_5']['title'] ?? '')
            && $current && end($current)['title'] === ($sections['article_4']['title'] ?? '');
        $joinsArticle7 = $block['title'] === ($sections['article_7']['title'] ?? '')
            && $current && end($current)['title'] === ($sections['article_6']['title'] ?? '');
        $joinsArticle8 = $block['title'] === ($sections['article_8']['title'] ?? '')
            && $current && end($current)['title'] === ($sections['article_7']['title'] ?? '');
        $joinsTable4 = $current && preg_match('/^<표\s*4>/u', $block['text'])
            && str_starts_with(end($current)['title'], $sections['article_9']['title'] ?? '');
        $joinsTable5 = $current && preg_match('/^<표\s*5>/u', $block['text'])
            && preg_match('/^<표\s*4>/u', end($current)['text']);
        $joinsTable6 = count($current) === 1 && preg_match('/^<표\s*6>/u', $block['text'])
            && preg_match('/^<표\s*5>/u', end($current)['text']);
        $joinsTable7 = $current && preg_match('/^<\x{D45C}\s*7>/u', $block['text'])
            && preg_match('/^<\x{D45C}\s*6>/u', end($current)['text']);
        $joinsPostTable7 = $current && !$block['standalone']
            && str_starts_with($block['title'], $sections['article_9']['title'] ?? '')
            && preg_match('/^<\x{D45C}\s*7>/u', end($current)['text']);
        $joinsArticles10to13 = $current
            && $current[0]['title'] === ($sections['article_10']['title'] ?? '')
            && ($block['title'] === ($sections['article_11']['title'] ?? '')
                || $block['title'] === ($sections['article_12']['title'] ?? '')
                || str_starts_with($block['title'], $sections['article_13']['title'] ?? ''));
        if ($current && !$joinsOrgChart && !$joinsArticle5 && !$joinsArticle7 && !$joinsArticle8 && !$joinsTable4 && !$joinsTable5 && !$joinsTable6 && !$joinsTable7 && !$joinsPostTable7 && !$joinsArticles10to13
            && ($block['standalone'] || $current[0]['standalone'] || count($current) >= 2 || $currentLength + $length > 520)) {
            $pages[] = ['sections' => $current];
            $current = [];
            $currentLength = 0;
        }
        $current[] = $block;
        $currentLength += $length;
    }
    if ($current) $pages[] = ['sections' => $current];
    foreach ($pages as &$page) {
        $merged = [];
        foreach ($page['sections'] as $section) {
            $baseTitle = preg_replace('/ \(계속\)$/u', '', $section['title']);
            $last = count($merged) - 1;
            if ($last >= 0 && preg_replace('/ \(계속\)$/u', '', $merged[$last]['title']) === $baseTitle) {
                $merged[$last]['text'] .= "\n" . $section['text'];
            } else {
                $merged[] = $section;
            }
        }
        $page['sections'] = $merged;
        $page['is_cover'] = (bool)($page['sections'][0]['cover'] ?? false);
        $page['is_form'] = (bool)($page['sections'][0]['form'] ?? false);
        $page['is_articles_1_3'] = count($page['sections']) === 3
            && $page['sections'][0]['title'] === ($sections['article_1']['title'] ?? '')
            && $page['sections'][2]['title'] === ($sections['article_3']['title'] ?? '');
        $page['is_overview'] = count($page['sections']) === 3
            && $page['sections'][0]['title'] === ($sections['article_6']['title'] ?? '')
            && $page['sections'][2]['title'] === ($sections['article_8']['title'] ?? '');
        $page['is_step_tables'] = count($page['sections']) === 1
            && str_contains($page['sections'][0]['text'], '<표 4>')
            && str_contains($page['sections'][0]['text'], '<표 5>');
    }
    unset($page);
    return $pages;
}
