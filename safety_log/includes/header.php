<?php
if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!isset($pageTitle)) {
    $pageTitle = '안전관리자 업무일지';
}
if (!isset($extraHead)) {
    $extraHead = '';
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-VnY9Xl60G7eusM0ZyEJ+X8LwKUQ/yqPn2rGHXeFQ0WlQg5KL6N37pP3cT7QeFk0I" crossorigin="anonymous">
    <?= $extraHead ?>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="container py-4">
