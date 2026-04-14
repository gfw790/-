<?php
declare(strict_types=1);

// ============================================================
// tbm_db.php  —  DB 연결 + 공통 DB 함수
// XAMPP MariaDB 기준
// ============================================================

// ── .env 로드 (tbm_news.php 등에서 이미 로드했을 수 있으므로 중복 방지) ──
if (!function_exists('tbm_db_load_env')) {
    function tbm_db_load_env(): void
    {
        static $loaded = false;
        if ($loaded) return;

        $envFile = __DIR__ . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                [$k, $v] = explode('=', $line, 2) + ['', ''];
                $k = trim($k);
                $v = trim($v);
                $v = preg_replace('/^([\'\"])(.*)\1$/', '$2', $v);
                if (!getenv($k)) {
                    putenv($k . '=' . $v);
                    $_ENV[$k] = $v;
                }
            }
        }
        $loaded = true;
    }
}
tbm_db_load_env();

// ── 연결 설정 (.env 우선, 없으면 기본값) ──────────────────────
define('TBM_DB_HOST',    getenv('TBM_DB_HOST')    ?: 'localhost');
define('TBM_DB_PORT',    (int)(getenv('TBM_DB_PORT') ?: 3306));
define('TBM_DB_NAME',    getenv('TBM_DB_NAME')    ?: 'tbm_db');
define('TBM_DB_USER',    getenv('TBM_DB_USER')    ?: 'root');
define('TBM_DB_PASS',    getenv('TBM_DB_PASS')    ?: '');
define('TBM_DB_CHARSET', getenv('TBM_DB_CHARSET') ?: 'utf8mb4');

// ── PDO 싱글턴 ───────────────────────────────────────────────
function tbm_db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        TBM_DB_HOST,
        TBM_DB_PORT,
        TBM_DB_NAME,
        TBM_DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, TBM_DB_USER, TBM_DB_PASS, $options);
        tbm_db_ensure_document_team_column($pdo);
    } catch (PDOException $e) {
        // 운영 환경에서는 로그만 남기고 사용자에게 상세 오류를 노출하지 않는다.
        error_log('[TBM DB] 연결 실패: ' . $e->getMessage());
        throw new RuntimeException('DB 연결에 실패했습니다. 관리자에게 문의하세요.');
    }

    return $pdo;
}

function tbm_db_ensure_document_team_column(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM tbm_documents LIKE 'team'");
        $stmt->execute();
        if ($stmt->fetch() === false) {
            $pdo->exec("ALTER TABLE tbm_documents ADD COLUMN team VARCHAR(50) NULL AFTER doc_date");
        }
    } catch (Throwable $e) {
        // team 컬럼 추가 권한이 없거나 테이블이 없는 경우에도 기존 동작 유지
    }
}


// ============================================================
// [영업일 판별]
// 토·일·공휴일을 제외한 날짜가 영업일이다.
// ============================================================

/**
 * 주어진 날짜가 영업일인지 반환한다.
 */
function tbm_is_business_day(string $date): bool
{
    $pdo = tbm_db();

    // 날짜 유효성 확인
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return false;
    }

    // 토요일(6), 일요일(0) 제외
    $dow = (int)$dt->format('w');
    if ($dow === 0 || $dow === 6) {
        return false;
    }

    // 공휴일 테이블 확인
    $stmt = $pdo->prepare('SELECT 1 FROM tbm_holidays WHERE holiday_date = ? LIMIT 1');
    $stmt->execute([$date]);

    return $stmt->fetchColumn() === false; // 공휴일 없으면 true
}

/**
 * 오늘(또는 지정 기준일)로부터 가장 가까운 다음 영업일을 반환한다.
 * $from : 기준일 (Y-m-d), null이면 오늘
 */
function tbm_next_business_day(?string $from = null): string
{
    $dt = new DateTime($from ?? 'today');
    $dt->modify('+1 day');

    for ($i = 0; $i < 30; $i++) {
        if (tbm_is_business_day($dt->format('Y-m-d'))) {
            return $dt->format('Y-m-d');
        }
        $dt->modify('+1 day');
    }

    throw new RuntimeException('영업일을 찾을 수 없습니다 (30일 이내)');
}


// ============================================================
// [인명부]
// ============================================================

/**
 * 활성 인명부를 sort_order 순으로 최대 8명 반환한다.
 * 반환: [['id'=>int, 'name'=>string, 'position'=>string], ...]
 */
function tbm_get_active_members(): array
{
    $pdo  = tbm_db();
    $stmt = $pdo->query(
        'SELECT id, name, position FROM tbm_members
          WHERE is_active = 1
          ORDER BY sort_order ASC, id ASC
          LIMIT 8'
    );
    return $stmt->fetchAll();
}

/**
 * 인명부에서 이름만 추출한 배열을 반환한다. (tbm_functions.php names 배열 호환)
 */
function tbm_get_member_names(): array
{
    $members = tbm_get_active_members();
    $names   = array_column($members, 'name');

    // 8자리 고정 (빈 문자열로 패딩)
    while (count($names) < 8) {
        $names[] = '';
    }

    return array_slice($names, 0, 8);
}


// ============================================================
// [강사]
// ============================================================

/**
 * 활성 강사 1명 반환 (없으면 기본값)
 * 반환: ['name'=>string, 'position'=>string]
 */
function tbm_get_active_instructor(): array
{
    $pdo  = tbm_db();
    $stmt = $pdo->query(
        'SELECT name, position FROM tbm_instructors
          WHERE is_active = 1
          ORDER BY id ASC
          LIMIT 1'
    );
    $row = $stmt->fetch();

    return $row ?: ['name' => '김남균', 'position' => '과장'];
}


// ============================================================
// [콘텐츠 풀 - 중대재해 전파 내용]
// ============================================================

/**
 * 미사용 콘텐츠 중 가장 오래된 항목 1건을 반환한다.
 * 없으면 null 반환.
 */
function tbm_get_unused_content(): ?array
{
    $pdo  = tbm_db();
    $stmt = $pdo->query(
        'SELECT * FROM tbm_accident_content
          WHERE is_used = 0
          ORDER BY created_at ASC
          LIMIT 1'
    );
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * 콘텐츠를 "사용됨"으로 표시한다.
 */
function tbm_mark_content_used(int $contentId): void
{
    $pdo  = tbm_db();
    $stmt = $pdo->prepare('UPDATE tbm_accident_content SET is_used = 1 WHERE id = ?');
    $stmt->execute([$contentId]);
}

/**
 * 새 콘텐츠를 풀에 저장한다.
 * $data 배열 키: accident_date, accident_title, edu_title, body_text,
 *               image_file, source_url, quiz_1, quiz_2, quiz_3, ai_generated
 * 반환: 삽입된 ID
 */
function tbm_insert_content(array $data): int
{
    $pdo  = tbm_db();
    $stmt = $pdo->prepare(
        'INSERT INTO tbm_accident_content
            (accident_date, accident_title, edu_title, body_text,
             image_file, source_url, quiz_1, quiz_2, quiz_3, ai_generated)
         VALUES
            (:accident_date, :accident_title, :edu_title, :body_text,
             :image_file, :source_url, :quiz_1, :quiz_2, :quiz_3, :ai_generated)'
    );
    $stmt->execute([
        ':accident_date'   => $data['accident_date']  ?? null,
        ':accident_title'  => $data['accident_title'] ?? '',
        ':edu_title'       => $data['edu_title']      ?? '',
        ':body_text'       => $data['body_text']      ?? '',
        ':image_file'      => $data['image_file']     ?? null,
        ':source_url'      => $data['source_url']     ?? null,
        ':quiz_1'          => $data['quiz_1']         ?? '',
        ':quiz_2'          => $data['quiz_2']         ?? '',
        ':quiz_3'          => $data['quiz_3']         ?? '',
        ':ai_generated'    => $data['ai_generated']   ?? 1,
    ]);

    return (int)$pdo->lastInsertId();
}


// ============================================================
// [일지 문서]
// ============================================================

/**
 * 해당 날짜의 일지가 이미 존재하는지 확인한다.
 */
function tbm_document_exists(string $date): bool
{
    $pdo  = tbm_db();
    $stmt = $pdo->prepare('SELECT 1 FROM tbm_documents WHERE doc_date = ? LIMIT 1');
    $stmt->execute([$date]);

    return $stmt->fetchColumn() !== false;
}

/**
 * 해당 날짜의 일지를 반환한다. 없으면 null.
 */
function tbm_get_document(string $date): ?array
{
    $pdo  = tbm_db();
    $stmt = $pdo->prepare(
        'SELECT d.*, c.edu_title, c.body_text, c.source_url, c.image_file,
                c.quiz_1, c.quiz_2, c.quiz_3,
                i.name AS instructor_name, i.position AS instructor_position
           FROM tbm_documents d
      LEFT JOIN tbm_accident_content c ON d.content_id = c.id
      LEFT JOIN tbm_instructors      i ON d.instructor_id = i.id
          WHERE d.doc_date = ?
          LIMIT 1'
    );
    $stmt->execute([$date]);
    $row = $stmt->fetch();

    if ($row && (
        empty($row['content_id']) ||
        (
            trim((string)($row['edu_title'] ?? '')) === '' &&
            trim((string)($row['body_text'] ?? '')) === '' &&
            trim((string)($row['quiz_1'] ?? '')) === '' &&
            trim((string)($row['quiz_2'] ?? '')) === '' &&
            trim((string)($row['quiz_3'] ?? '')) === ''
        )
    )) {
        $fallbackStmt = $pdo->prepare(
            'SELECT *
               FROM tbm_accident_content
              WHERE DATE(created_at) = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $fallbackStmt->execute([$date]);
        $fallback = $fallbackStmt->fetch();

        if ($fallback) {
            $linkStmt = $pdo->prepare('UPDATE tbm_documents SET content_id = :content_id, updated_at = NOW() WHERE id = :id');
            $linkStmt->execute([
                ':content_id' => (int)$fallback['id'],
                ':id'         => (int)$row['id'],
            ]);

            $row['content_id']  = (int)$fallback['id'];
            $row['edu_title']   = $fallback['edu_title'] ?? '';
            $row['body_text']   = $fallback['body_text'] ?? '';
            $row['source_url']  = $fallback['source_url'] ?? '';
            $row['image_file']  = $fallback['image_file'] ?? '';
            $row['quiz_1']      = $fallback['quiz_1'] ?? '';
            $row['quiz_2']      = $fallback['quiz_2'] ?? '';
            $row['quiz_3']      = $fallback['quiz_3'] ?? '';
        }
    }

    return $row ?: null;
}

/**
 * 일지를 신규 생성(pending 상태)한다.
 * 반환: 삽입된 doc_id
 */
function tbm_create_document(string $date, int $instructorId, ?int $contentId, ?string $team = null): int
{
    $pdo  = tbm_db();
    $stmt = $pdo->prepare(
        'INSERT INTO tbm_documents
            (doc_date, team, is_business_day, instructor_id, content_id,
             risk_checks, risk_rows, generation_status)
         VALUES
            (:doc_date, :team, :is_biz, :instructor_id, :content_id,
             :risk_checks, :risk_rows, "pending")'
    );

    $emptyRiskRows = array_fill(0, 10, [
        'work' => '', 'hazard' => '', 'control' => '',
        'freq' => '', 'strength' => '', 'risk' => '',
    ]);

    $stmt->execute([
        ':doc_date'      => $date,
        ':team'          => $team,
        ':is_biz'        => 1,
        ':instructor_id' => $instructorId,
        ':content_id'    => $contentId,
        ':risk_checks'   => json_encode([], JSON_UNESCAPED_UNICODE),
        ':risk_rows'     => json_encode($emptyRiskRows, JSON_UNESCAPED_UNICODE),
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * 일지의 생성 결과를 업데이트한다.
 */
function tbm_update_document_result(int $docId, string $filename, string $status, ?string $error = null): void
{
    $pdo  = tbm_db();
    $stmt = $pdo->prepare(
        'UPDATE tbm_documents
            SET output_filename   = :filename,
                generation_status = :status,
                error_message     = :error,
                generated_at      = NOW()
          WHERE id = :id'
    );
    $stmt->execute([
        ':filename' => $filename,
        ':status'   => $status,
        ':error'    => $error,
        ':id'       => $docId,
    ]);
}

/**
 * 일지에 참석 인원을 연결한다.
 * $members : [['id'=>int], ...] 또는 tbm_get_active_members() 반환값
 */
function tbm_link_document_members(int $docId, array $members): void
{
    $pdo  = tbm_db();
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO tbm_document_members (doc_id, member_id, slot_order)
         VALUES (:doc_id, :member_id, :slot_order)'
    );

    foreach ($members as $order => $member) {
        $stmt->execute([
            ':doc_id'     => $docId,
            ':member_id'  => (int)$member['id'],
            ':slot_order' => $order + 1,
        ]);
    }
}


// ============================================================
// [생성 로그]
// ============================================================

/**
 * 생성 로그를 기록한다.
 */
function tbm_log(
    ?int   $docId,
    string $triggerType,
    string $status,
    string $message,
    ?int   $durationMs = null
): void {
    try {
        $pdo  = tbm_db();
        $stmt = $pdo->prepare(
            'INSERT INTO tbm_generation_log (doc_id, trigger_type, status, message, duration_ms)
             VALUES (:doc_id, :trigger, :status, :msg, :ms)'
        );
        $stmt->execute([
            ':doc_id'  => $docId,
            ':trigger' => $triggerType,
            ':status'  => $status,
            ':msg'     => $message,
            ':ms'      => $durationMs,
        ]);
    } catch (Throwable $e) {
        // 로그 실패는 무시 (메인 흐름을 방해하지 않는다)
        error_log('[TBM LOG] 로그 저장 실패: ' . $e->getMessage());
    }
}
