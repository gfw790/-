<?php
/**
 * 안전관리자 업무일지 데이터를 risk_assessment DB에서 risk_server DB로 옮기는 스크립트입니다.
 * 실행 후 원본 테이블을 삭제합니다.
 *
 * 사용 방법: 브라우저 혹은 CLI에서 실행합니다.
 */

$host = 'localhost';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';
$sourceDb = 'risk_assessment';
$destDb = 'risk_server';
$tables = [
    'safety_manager_log',
    'safety_manager_log_detail',
];

/**
 * CREATE TABLE 문에서 외래키 제약 정의를 제거합니다.
 *
 * @param string $sql
 * @return string
 */
function removeForeignKeys(string $sql): string
{
    // 외래키 제약이 포함된 라인을 제거합니다.
    $lines = preg_split('/\r\n|\r|\n/', $sql);
    $filtered = [];

    foreach ($lines as $line) {
        $trim = trim($line);
        if (preg_match('/^(CONSTRAINT|FOREIGN KEY)/i', $trim)) {
            continue;
        }

        // references 절이 있는 라인도 제거
        if (preg_match('/REFERENCES\s+`.*`/i', $trim)) {
            continue;
        }

        $filtered[] = $line;
    }

    // 마지막 쉼표 처리
    for ($i = count($filtered) - 1; $i >= 0; $i--) {
        if (preg_match('/,$/', trim($filtered[$i])) && $i === count($filtered) - 1) {
            $filtered[$i] = rtrim($filtered[$i], ',');
            break;
        }
    }

    return implode("\n", $filtered);
}

try {
    $dsn = "mysql:host={$host};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // 대상 DB가 없으면 생성
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$destDb}` CHARACTER SET {$charset} COLLATE {$charset}_general_ci");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $copiedTables = [];

    foreach ($tables as $table) {
        echo "처리 중: {$table}<br>\n";

        // 소스 테이블이 존재하는지 확인
        $result = $pdo->query("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = '{$sourceDb}' AND table_name = '{$table}'")->fetch();
        if ((int)$result['cnt'] === 0) {
            echo "원본 테이블이 없습니다: {$sourceDb}.{$table}<br>\n";
            continue;
        }

        // 대상 테이블이 존재하지 않으면 안전하게 생성합니다.
        $destExists = (int)$pdo->query("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = '{$destDb}' AND table_name = '{$table}'")->fetch()['cnt'];
        if ($destExists === 0) {
            if ($table === 'safety_manager_log') {
                $pdo->exec("CREATE TABLE `{$destDb}`.`safety_manager_log` AS SELECT * FROM `{$sourceDb}`.`safety_manager_log` WHERE 0");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log` MODIFY COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log` MODIFY COLUMN `log_date` DATE");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log` MODIFY COLUMN `manager_name` VARCHAR(255)");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log` MODIFY COLUMN `site_name` VARCHAR(255)");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log` MODIFY COLUMN `work_location` VARCHAR(255)");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log` MODIFY COLUMN `weather` VARCHAR(255)");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log` MODIFY COLUMN `subject` VARCHAR(255)");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log` MODIFY COLUMN `summary` TEXT");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log` MODIFY COLUMN `remark` TEXT");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log` MODIFY COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            } elseif ($table === 'safety_manager_log_detail') {
                $pdo->exec("CREATE TABLE `{$destDb}`.`safety_manager_log_detail` AS SELECT * FROM `{$sourceDb}`.`safety_manager_log_detail` WHERE 0");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log_detail` MODIFY COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log_detail` MODIFY COLUMN `log_id` INT UNSIGNED NOT NULL");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log_detail` MODIFY COLUMN `item_no` INT UNSIGNED");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log_detail` MODIFY COLUMN `work_time` VARCHAR(100)");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log_detail` MODIFY COLUMN `activity` VARCHAR(255)");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log_detail` MODIFY COLUMN `description` TEXT");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log_detail` MODIFY COLUMN `status` VARCHAR(50)");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log_detail` MODIFY COLUMN `photo_1` VARCHAR(500)");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log_detail` MODIFY COLUMN `photo_2` VARCHAR(500)");
                $pdo->exec("ALTER TABLE `{$destDb}`.`safety_manager_log_detail` ADD INDEX (`log_id`)");
            } else {
                throw new RuntimeException("알 수 없는 테이블: {$table}");
            }
            echo "대상 테이블 생성 완료: {$destDb}.{$table}<br>\n";
        } else {
            echo "대상 테이블이 이미 존재합니다: {$destDb}.{$table} (생성 생략)<br>\n";
        }

        // 데이터 복사 (중복 PK는 무시)
        $pdo->exec("INSERT IGNORE INTO `{$destDb}`.`{$table}` SELECT * FROM `{$sourceDb}`.`{$table}`");
        echo "데이터 복사 완료: {$sourceDb}.{$table} -> {$destDb}.{$table}<br>\n";

        $copiedTables[] = $table;
    }

    // 원본 테이블 삭제는 detail 테이블부터 수행합니다.
    foreach (array_reverse($copiedTables) as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$sourceDb}`.`{$table}`");
        echo "원본 테이블 삭제 완료: {$sourceDb}.{$table}<br>\n";
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    echo '<strong>마이그레이션 완료</strong><br>\n';
    echo '<p>이제 safety_log 모듈은 risk_server DB를 사용하도록 설정됩니다.</p>';
} catch (Throwable $e) {
    echo '<h1>마이그레이션 중 오류가 발생했습니다.</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    exit(1);
}
