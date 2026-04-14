<?php
$env = [];
foreach (file('a:/risk_server/project/tbm/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    list($k, $v) = explode('=', $line, 2) + ['', ''];
    $env[trim($k)] = trim($v);
}
$host = $env['TBM_DB_HOST'] ?? 'localhost';
$port = $env['TBM_DB_PORT'] ?? 3306;
$db = $env['TBM_DB_NAME'] ?? 'tbm_db';
$user = $env['TBM_DB_USER'] ?? 'root';
$pass = $env['TBM_DB_PASS'] ?? '';
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $stmt = $pdo->query('SHOW COLUMNS FROM tbm_documents');
    $cols = $stmt->fetchAll();
    echo json_encode($cols, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo 'ERROR: ' . $e->getMessage();
}
