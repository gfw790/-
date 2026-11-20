<?php
declare(strict_types=1);

// Two Base64 photos plus form fields must fit the server's 40 MiB POST limit.
const ER_FILE_LIMIT = 14 * 1024 * 1024;
const ER_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function er_db_path(): string
{
    return __DIR__ . '/storage/emergency_response.sqlite';
}

function er_evidence_dir(): string
{
    return __DIR__ . '/storage/emergency_response_evidence';
}

function er_photo_dir(): string
{
    return __DIR__ . '/storage/emergency_response_photos';
}

function er_items(): array
{
    return [
        'scenarios' => ['2.9.1', '고위험작업 연계 비상대응 시나리오', ['procedure' => '비상대응 절차서', 'permit' => '고위험작업 허가서·위험성평가', 'other' => '기타 증빙']],
        'drills' => ['2.9.2', '반기 비상대응 훈련 결과서', ['drill_result' => '훈련 결과서·참석자 명단', 'feedback' => '개선조치 확인자료', 'other' => '기타 증빙']],
        'contacts' => ['2.9.3', '비상연락망 및 비상대피도', ['contact_list' => '비상연락망', 'evacuation_map' => '비상대피도', 'posting_photo' => '비상대피도 게시 사진', 'other' => '기타 증빙']],
        'reports' => ['2.9.4', '대표자 보고 및 종사자 공유 이력', ['report' => '대표자 보고자료', 'worker_share' => '종사자 공유자료·참석자 명단', 'other' => '기타 증빙']],
    ];
}

function er_record_fields(string $mode): array
{
    return match ($mode) {
        'scenarios' => [
            'scenario_title' => ['시나리오명', 'text'],
            'hazard_link' => ['연계 고위험작업', 'text'],
            'revision_date' => ['개정일', 'date'],
            'work_stop_rule' => ['작업중지 반영 내용', 'textarea'],
            'initial_action' => ['초동 대응조치', 'textarea'],
            'rescue_action' => ['구호·이송조치', 'textarea'],
            'secondary_prevention' => ['추가 피해방지조치', 'textarea'],
        ],
        'drills' => [
            'drill_date' => ['훈련일', 'date'],
            'half_label' => ['훈련 구분', ['상반기', '하반기', '수시']],
            'scenario_title' => ['훈련 시나리오', 'text'],
            'location' => ['훈련 장소', 'text'],
            'participants_count' => ['참여 인원(명)', 'number'],
            'leader_name' => ['훈련 주관자', 'text'],
            'drill_summary' => ['훈련 결과 요약', 'textarea'],
            'prev_feedback_applied' => ['전회 미흡사항 반영 내용', 'textarea'],
            'issues_found' => ['금회 도출 미흡사항', 'textarea'],
            'improvement_plan' => ['차기 반영 개선계획', 'textarea'],
        ],
        'contacts' => [
            'org_group' => ['소속 구분', ['사내 비상조직', '원청 한라시멘트', '유관기관·병원']],
            'role_title' => ['직책·역할', 'text'],
            'person_or_org' => ['담당자·기관', 'text'],
            'phone' => ['연락처', 'tel', false],
            'note' => ['비고', 'textarea', false],
        ],
        'reports' => [
            'report_date' => ['보고·공유일', 'date'],
            'half_label' => ['보고 구분', ['상반기', '하반기', '분기']],
            'report_title' => ['보고 제목', 'text'],
            'reported_to' => ['보고 대상', 'text'],
            'share_method' => ['공유 방법', ['TBM 전파', '안전보건교육', '게시판 공지']],
            'shared_workers_count' => ['공유 인원(명)', 'number'],
            'summary' => ['보고 및 공유 내용', 'textarea'],
        ],
    };
}

function er_photo_fields(string $mode): array
{
    return match ($mode) {
        'drills' => ['photo1' => '훈련 사진 1', 'photo2' => '훈련 사진 2'],
        'reports' => ['photo1' => '종사자 공유 사진'],
        default => [],
    };
}

function er_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
}

function er_year(mixed $value): int
{
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 9999]])
        ?: (int)substr(er_today(), 0, 4);
}

function er_input(array $data, string $key): string
{
    return is_string($data[$key] ?? null) ? trim($data[$key]) : '';
}

function er_escape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function er_make_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('비상대응 저장소를 만들 수 없습니다.');
    }
}

function er_open_db(string $dsn): PDO
{
    $db = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}

function er_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }
    er_make_dir(dirname(er_db_path()));
    $connection = er_open_db('sqlite:' . er_db_path());
    $connection->exec('PRAGMA journal_mode = WAL');
    if ((int)$connection->query('PRAGMA user_version')->fetchColumn() === 0) {
        er_initialize($connection);
    }
    return $db = $connection;
}

function er_initialize(PDO $db): void
{
    $db->beginTransaction();
    try {
        // Schema creation takes the write lock before the version is checked again.
        $db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS er_scenarios (
                id INTEGER PRIMARY KEY, sort_order INTEGER NOT NULL DEFAULT 0,
                hazard_link TEXT NOT NULL, scenario_title TEXT NOT NULL,
                work_stop_rule TEXT NOT NULL, initial_action TEXT NOT NULL,
                rescue_action TEXT NOT NULL, secondary_prevention TEXT NOT NULL,
                revision_date TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS er_drills (
                id INTEGER PRIMARY KEY, year INTEGER NOT NULL,
                half_label TEXT NOT NULL CHECK (half_label IN ('상반기','하반기','수시')),
                drill_date TEXT NOT NULL, scenario_title TEXT NOT NULL, location TEXT NOT NULL,
                participants_count INTEGER NOT NULL CHECK (participants_count >= 0), leader_name TEXT NOT NULL,
                drill_summary TEXT NOT NULL, prev_feedback_applied TEXT NOT NULL,
                issues_found TEXT NOT NULL, improvement_plan TEXT NOT NULL,
                photo1 TEXT NOT NULL DEFAULT '', photo2 TEXT NOT NULL DEFAULT '',
                created_by TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS er_contacts (
                id INTEGER PRIMARY KEY, sort_order INTEGER NOT NULL DEFAULT 0,
                org_group TEXT NOT NULL CHECK (org_group IN ('사내 비상조직','원청 한라시멘트','유관기관·병원')),
                role_title TEXT NOT NULL, person_or_org TEXT NOT NULL,
                phone TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '',
                active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)), updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS er_reports (
                id INTEGER PRIMARY KEY, year INTEGER NOT NULL, report_date TEXT NOT NULL,
                half_label TEXT NOT NULL CHECK (half_label IN ('상반기','하반기','분기')),
                report_title TEXT NOT NULL, reported_to TEXT NOT NULL DEFAULT '정연탁 대표이사',
                share_method TEXT NOT NULL CHECK (share_method IN ('TBM 전파','안전보건교육','게시판 공지')),
                shared_workers_count INTEGER NOT NULL CHECK (shared_workers_count >= 0),
                summary TEXT NOT NULL, photo1 TEXT NOT NULL DEFAULT '',
                created_by TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS er_evidence (
                id INTEGER PRIMARY KEY, year INTEGER NOT NULL,
                item_code TEXT NOT NULL CHECK (item_code IN ('2.9.1','2.9.2','2.9.3','2.9.4')),
                kind TEXT NOT NULL, original_name TEXT NOT NULL, stored_name TEXT NOT NULL UNIQUE,
                mime TEXT NOT NULL, size INTEGER NOT NULL CHECK (size > 0), note TEXT NOT NULL DEFAULT '',
                uploaded_by TEXT NOT NULL, uploaded_at TEXT NOT NULL, deleted_at TEXT
            );
            CREATE TABLE IF NOT EXISTS er_assessments (
                year INTEGER NOT NULL,
                item_code TEXT NOT NULL CHECK (item_code IN ('2.9.1','2.9.2','2.9.3','2.9.4')),
                multiplier REAL NOT NULL CHECK (multiplier IN (0, 0.5, 0.8, 0.9, 1)),
                note TEXT NOT NULL DEFAULT '', updated_by TEXT NOT NULL, updated_at TEXT NOT NULL,
                PRIMARY KEY (year, item_code)
            );
            CREATE INDEX IF NOT EXISTS er_drills_by_year ON er_drills (year, drill_date DESC, id DESC);
            CREATE INDEX IF NOT EXISTS er_reports_by_year ON er_reports (year, report_date DESC, id DESC);
            CREATE INDEX IF NOT EXISTS er_live_evidence ON er_evidence (year, item_code, id DESC) WHERE deleted_at IS NULL;
            SQL);
        if ((int)$db->query('PRAGMA user_version')->fetchColumn() === 0) {
            er_seed_defaults($db);
            $db->exec('PRAGMA user_version = 1');
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function er_seed_defaults(PDO $db): void
{
    $today = er_today();
    $now = date(DATE_ATOM);
    $scenarios = [
        ['전기 감전 · 분전반 점검 및 전동기 결선', '전기 감전 사고 비상대응 시나리오',
            '감전이 의심되면 전기 작업과 인접 공정을 즉시 중지하고 활선 구역에서 대피한다.',
            '119와 공사팀 현장책임자 및 한라시멘트 안전환경팀에 위치와 설비 정보를 전한다. 지정 전기 담당자가 전원 차단과 무전압 확인을 수행한다.',
            '무전압과 접근 안전을 확인하기 전까지 환자에게 접촉하지 않는다. 안전 확인 후 119 지시에 따라 응급처치하고 구급대에 인계한다.',
            '차단부 잠금·표지를 유지하고 출입을 통제한다. 감전 원인과 격리 조치 확인 후 작업허가를 다시 받는다.'],
        ['고소작업 추락 · 케이블 트레이 및 배관 지지대 정비', '고소작업 추락 사고 비상대응 시나리오',
            '추락 또는 안전대 매달림을 발견하면 고소작업과 하부 작업을 중지하고 낙하 위험 구역에서 대피한다.',
            '119와 현장책임자에게 작업 높이, 접근 경로, 매달림 여부를 알린다. 지상에서 구조대의 진입로를 확보한다.',
            '매달린 작업자의 구조는 현장 구조계획에 따라 훈련된 구조인력에 맡긴다. 추락 환자는 119 지시에 따라 보호하고 구급대에 인계한다.',
            '하부 통제선을 유지하고 발판·안전대·부착설비를 점검한다. 보완조치 확인과 작업허가 갱신 후 재개한다.'],
        ['화기·용접 화재 · 설비 지지대 용접 및 절단', '화기·용접 작업 중 화재 발생 비상대응 시나리오',
            '연기나 불꽃 확산이 확인되면 화기작업과 인접 작업을 중지하고 알린 뒤 지정 경로로 대피한다.',
            '119 및 한라시멘트 안전환경팀에 화재 위치와 가스용기 등 주변 위험원을 알린다. 집결지에서 인원을 확인한다.',
            '미확인 인원의 마지막 위치를 소방대에 전달하고 구조 및 이송은 소방·구급대에 인계한다.',
            '통제 구역에 재진입하지 않는다. 잔불 점검과 화기작업 허가 재검토를 마친 뒤 현장책임자가 재개 여부를 확인한다.'],
        ['밀폐공간 질식 · 집진설비 및 탱크 내부 정비', '밀폐공간 유해가스·산소결핍 질식 비상대응 시나리오',
            '가스 경보, 이상 증상 또는 연락 두절 시 작업을 중지한다. 감시인은 대피를 지시하고 추가 진입을 차단한다.',
            '감시인은 119와 현장책임자에게 출입구, 내부 인원, 측정값 및 유입 가능 물질을 전달한다.',
            '작업자는 구조를 위해 진입하지 않는다. 119 구조대 또는 장비를 갖춘 훈련된 구조팀에 구조를 맡기고 환자는 구급대에 인계한다.',
            '출입을 통제하고 유입원을 격리한다. 환기와 재측정, 작업허가 및 감시체계 재확인 후 재개를 검토한다.'],
    ];
    $insert = $db->prepare('INSERT INTO er_scenarios
        (sort_order, hazard_link, scenario_title, work_stop_rule, initial_action, rescue_action, secondary_prevention, revision_date, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($scenarios as $index => $scenario) {
        $insert->execute([$index + 1, ...$scenario, $today, $now, $now]);
    }

    $contacts = [
        ['사내 비상조직', '대표이사', '정연탁', '', '현장 확인 후 등록'],
        ['사내 비상조직', '안전관리책임자', '김남균', '', '현장 확인 후 등록'],
        ['원청 한라시멘트', '원청 비상상황 접수', '한라시멘트 안전환경팀', '', '사업장별 비상연락처 확인 후 등록'],
        ['유관기관·병원', '응급구조·화재', '119', '119', '긴급 신고'],
        ['유관기관·병원', '산업안전 상담', '고용노동부', '1350', '고객상담센터'],
        ['유관기관·병원', '안전보건 기술지원', '안전보건공단', '1644-4544', '대표전화'],
        ['유관기관·병원', '지정 응급의료기관', '인근 지정 응급의료기관', '', '현장별 기관명·연락처 확인 후 등록'],
    ];
    $insert = $db->prepare('INSERT INTO er_contacts (sort_order, org_group, role_title, person_or_org, phone, note, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($contacts as $index => $contact) {
        $insert->execute([$index + 1, ...$contact, $now]);
    }
}

function er_list_records(PDO $db, string $mode, int $year, bool $includeInactive = false): array
{
    if ($mode === 'scenarios' || $mode === 'contacts') {
        $where = $includeInactive ? '' : 'WHERE active = 1';
        return $db->query("SELECT * FROM er_$mode $where ORDER BY active DESC, sort_order, id")->fetchAll();
    }
    $sql = match ($mode) {
        'drills' => 'SELECT * FROM er_drills WHERE year = ? ORDER BY drill_date DESC, id DESC',
        'reports' => 'SELECT * FROM er_reports WHERE year = ? ORDER BY report_date DESC, id DESC',
    };
    $query = $db->prepare($sql);
    $query->execute([$year]);
    return $query->fetchAll();
}

function er_list_evidence(PDO $db, int $year): array
{
    $query = $db->prepare('SELECT * FROM er_evidence WHERE year = ? AND deleted_at IS NULL ORDER BY item_code, id DESC');
    $query->execute([$year]);
    return $query->fetchAll();
}

function er_file_path(string $name, bool $photo): ?string
{
    $extensions = $photo ? 'jpg|png|webp' : 'jpg|png|webp|pdf|docx|xlsx';
    if (preg_match('/^[a-f0-9]{40}\.(?:' . $extensions . ')$/D', $name) !== 1) {
        return null;
    }
    $path = ($photo ? er_photo_dir() : er_evidence_dir()) . '/' . $name;
    return is_file($path) && !is_link($path) && @filesize($path) > 0 ? $path : null;
}

function er_photo_mime(string $path): ?string
{
    $info = @getimagesize($path);
    $mime = $info['mime'] ?? '';
    return (ER_IMAGE_TYPES[$mime] ?? null) === pathinfo($path, PATHINFO_EXTENSION) ? $mime : null;
}

function er_selected_file(array $files, string $name): ?array
{
    $file = $files[$name] ?? null;
    return ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ? ($files[$name . '_camera'] ?? null) : $file;
}

function er_save_upload(?array $file, string $base64, bool $photo): ?array
{
    $dir = $photo ? er_photo_dir() : er_evidence_dir();
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $raw = null;
    if ($base64 !== '') {
        $parts = explode(',', $base64, 2);
        if (!isset($parts[1]) || !preg_match('~^data:image/(?:jpeg|png|webp);base64$~D', $parts[0])
            || strlen($parts[1]) > 4 * ceil(ER_FILE_LIMIT / 3)) {
            throw new InvalidArgumentException('붙여넣은 이미지는 JPG, PNG, WEBP 형식이며 14MB 이하여야 합니다.');
        }
        $raw = base64_decode($parts[1], true);
        if ($raw === false || $raw === '' || strlen($raw) > ER_FILE_LIMIT) {
            throw new InvalidArgumentException('붙여넣은 이미지 크기와 형식을 확인해 주세요.');
        }
        $size = strlen($raw);
        $mime = $finfo->buffer($raw);
        $info = @getimagesizefromstring($raw);
        $extension = ER_IMAGE_TYPES[$mime] ?? null;
        if ($extension === null || ($info['mime'] ?? '') !== $mime) {
            throw new InvalidArgumentException('붙여넣은 이미지 형식이 올바르지 않습니다.');
        }
        $original = '스크린샷_' . date('Ymd_His') . '.' . $extension;
    } else {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $source = er_input($file, 'tmp_name');
        $size = is_uploaded_file($source) ? filesize($source) : false;
        if ($error !== UPLOAD_ERR_OK || $size === false || $size < 1 || $size > ER_FILE_LIMIT) {
            throw new InvalidArgumentException('14MB 이하의 파일을 선택해 주세요.');
        }
        $original = mb_substr(basename(str_replace('\\', '/', er_input($file, 'name'))), 0, 180);
        $mime = $finfo->file($source);
        $extension = ER_IMAGE_TYPES[$mime] ?? null;
        if ($extension !== null) {
            $info = @getimagesize($source);
            if (($info['mime'] ?? '') !== $mime) {
                throw new InvalidArgumentException('이미지 파일 형식이 올바르지 않습니다.');
            }
        } elseif (!$photo) {
            $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            $officeTypes = [
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xl/workbook.xml'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'word/document.xml'],
            ];
            if (isset($officeTypes[$extension])) {
                [$officeMime, $entry] = $officeTypes[$extension];
                $zip = new ZipArchive();
                if (!in_array($mime, [$officeMime, 'application/zip'], true) || $zip->open($source, ZipArchive::RDONLY) !== true) {
                    throw new InvalidArgumentException('Office 문서 형식이 올바르지 않습니다.');
                }
                $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($entry) !== false;
                $zip->close();
                if (!$valid) {
                    throw new InvalidArgumentException('Office 문서 구성을 확인해 주세요.');
                }
                $mime = $officeMime;
            } elseif ($extension !== 'pdf' || $mime !== 'application/pdf') {
                throw new InvalidArgumentException('PDF, DOCX, XLSX, JPG, PNG, WEBP 파일을 첨부해 주세요.');
            }
        } else {
            throw new InvalidArgumentException('JPG, PNG, WEBP 사진을 첨부해 주세요.');
        }
    }

    er_make_dir($dir);
    $name = bin2hex(random_bytes(20)) . '.' . $extension;
    $path = $dir . '/' . $name;
    $saved = $raw !== null ? @file_put_contents($path, $raw) === $size : move_uploaded_file($source, $path);
    if (!$saved) {
        if (is_file($path)) {
            @unlink($path);
        }
        throw new RuntimeException('첨부 파일을 저장하지 못했습니다.');
    }
    return ['stored_name' => $name, 'original_name' => $original, 'mime' => $mime, 'size' => $size, 'path' => $path];
}

function er_upload_evidence(PDO $db, int $year, string $code, string $kind, ?array $file, string $base64, string $note, string $actor): void
{
    $kinds = array_column(array_values(er_items()), 2, 0);
    if (!isset($kinds[$code][$kind])) {
        throw new InvalidArgumentException('증빙 구분을 확인해 주세요.');
    }
    $attachment = er_save_upload($file, $base64, false);
    if ($attachment === null) {
        throw new InvalidArgumentException('증빙 파일을 선택하거나 스크린샷을 붙여넣어 주세요.');
    }
    try {
        $db->prepare('INSERT INTO er_evidence (year, item_code, kind, original_name, stored_name, mime, size, note, uploaded_by, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $year, $code, $kind, $attachment['original_name'], $attachment['stored_name'],
                $attachment['mime'], $attachment['size'], $note, $actor, date(DATE_ATOM),
            ]);
    } catch (Throwable $error) {
        @unlink($attachment['path']);
        throw $error;
    }
}

function er_save_record(PDO $db, string $mode, int $year, int $id, array $input, array $files, string $actor): void
{
    $fields = [];
    $dated = in_array($mode, ['drills', 'reports'], true);
    foreach (er_record_fields($mode) as $key => $spec) {
        [$label, $type] = $spec;
        $value = er_input($input, $key);
        if (($spec[2] ?? true) && $value === '') {
            throw new InvalidArgumentException($label . ' 항목을 입력해 주세요.');
        }
        if (is_array($type) && !in_array($value, $type, true)) {
            throw new InvalidArgumentException($label . ' 항목을 확인해 주세요.');
        }
        if ($type === 'date') {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if (!$date || $date->format('Y-m-d') !== $value || ($dated && (int)$date->format('Y') !== $year)) {
                throw new InvalidArgumentException($label . ' 날짜와 선택 연도를 확인해 주세요.');
            }
        }
        if ($type === 'number') {
            $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999999]]);
            if ($value === false) {
                throw new InvalidArgumentException($label . ' 항목에 0 이상의 정수를 입력해 주세요.');
            }
        }
        if (is_string($value) && mb_strlen($value) > ($type === 'textarea' ? 3000 : 180)) {
            throw new InvalidArgumentException($label . ' 항목이 너무 깁니다.');
        }
        $fields[$key] = $value;
    }
    if ($mode === 'drills' && $fields['half_label'] !== '수시') {
        $half = (int)substr($fields['drill_date'], 5, 2) <= 6 ? '상반기' : '하반기';
        if ($fields['half_label'] !== $half) {
            throw new InvalidArgumentException('훈련일에 해당하는 반기를 선택해 주세요.');
        }
    }

    if (!$dated) {
        $fields['sort_order'] = filter_var(er_input($input, 'sort_order'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 9999]]);
        if ($fields['sort_order'] === false) {
            throw new InvalidArgumentException('표시 순서는 0~9999 사이 정수로 입력해 주세요.');
        }
        $fields['active'] = er_input($input, 'active') === '1' ? 1 : 0;
    }
    $fields['updated_at'] = date(DATE_ATOM);
    $table = 'er_' . $mode;
    $newFiles = [];
    $oldFiles = [];
    try {
        foreach (array_keys(er_photo_fields($mode)) as $key) {
            $upload = er_save_upload(er_selected_file($files, $key), er_input($input, $key . '_base64'), true);
            if ($upload !== null) {
                $newFiles[] = $upload['path'];
                $fields[$key] = $upload['stored_name'];
            } elseif (er_input($input, 'remove_' . $key) === '1') {
                $fields[$key] = '';
            }
        }
        $db->beginTransaction();
        if ($id > 0) {
            $where = $dated ? 'id = ? AND year = ?' : 'id = ?';
            $keys = $dated ? [$id, $year] : [$id];
            $query = $db->prepare("SELECT * FROM $table WHERE $where");
            $query->execute($keys);
            $row = $query->fetch();
            $query->closeCursor();
            if (!$row) {
                throw new InvalidArgumentException('수정할 기록을 찾을 수 없습니다.');
            }
            foreach (array_keys(array_intersect_key(er_photo_fields($mode), $fields)) as $key) {
                $path = er_file_path($row[$key], true);
                if ($path !== null) {
                    $oldFiles[] = $path;
                }
            }
            $assignments = implode(', ', array_map(static fn(string $key): string => "$key = ?", array_keys($fields)));
            $db->prepare("UPDATE $table SET $assignments WHERE $where")->execute([...array_values($fields), ...$keys]);
        } else {
            if ($dated) {
                $fields += ['year' => $year, 'created_by' => $actor];
            }
            if ($mode !== 'contacts') {
                $fields['created_at'] = $fields['updated_at'];
            }
            $columns = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $db->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)")->execute(array_values($fields));
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($newFiles as $path) {
            @unlink($path);
        }
        throw $error;
    }
    foreach ($oldFiles as $path) {
        @unlink($path);
    }
}

function er_score_levels(): array
{
    return ['auto' => '자동 (유효 증빙 등록 시 100%)', '1' => '100%', '0.9' => '90%', '0.8' => '80%', '0.5' => '50%', '0' => '0%'];
}

function er_save_assessment(PDO $db, int $year, string $code, string $level, string $note, string $actor): void
{
    if (!array_key_exists($level, er_score_levels())) {
        throw new InvalidArgumentException('평가 수준을 확인해 주세요.');
    }
    if ($level === 'auto') {
        $db->prepare('DELETE FROM er_assessments WHERE year = ? AND item_code = ?')->execute([$year, $code]);
        return;
    }
    $db->prepare('INSERT INTO er_assessments (year, item_code, multiplier, note, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(year, item_code) DO UPDATE SET
        multiplier = excluded.multiplier, note = excluded.note, updated_by = excluded.updated_by, updated_at = excluded.updated_at')
        ->execute([$year, $code, $level, $note, $actor, date(DATE_ATOM)]);
}

function csm_er_score_summary(int $year, string $itemCode, float $points = 2.0): array
{
    if (!in_array($itemCode, array_column(er_items(), 0), true) || !is_file(er_db_path())) {
        return [];
    }
    try {
        $path = str_replace('\\', '/', er_db_path());
        $uri = str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $path);
        $db = er_open_db('sqlite:file:' . $uri . '?mode=ro');
        $db->exec('PRAGMA query_only = ON');
        $db->beginTransaction();
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;
        if (in_array('er_evidence', $tables, true)) {
            $query = $db->prepare('SELECT stored_name, size FROM er_evidence WHERE year = ? AND item_code = ? AND deleted_at IS NULL');
            $query->execute([$year, $itemCode]);
            foreach ($query as $row) {
                $file = er_file_path($row['stored_name'], false);
                if ($file !== null && @filesize($file) === (int)$row['size']) {
                    $count++;
                }
            }
        }
        $photoTable = match ($itemCode) {
            '2.9.2' => 'drills',
            '2.9.4' => 'reports',
            default => null,
        };
        if ($photoTable !== null && in_array('er_' . $photoTable, $tables, true)) {
            $columns = implode(', ', array_keys(er_photo_fields($photoTable)));
            $query = $db->prepare("SELECT $columns FROM er_$photoTable WHERE year = ?");
            $query->execute([$year]);
            foreach ($query as $row) {
                foreach ($row as $name) {
                    $file = er_file_path($name, true);
                    if ($file !== null && er_photo_mime($file) !== null) {
                        $count++;
                    }
                }
            }
        }
        $assessment = null;
        if (in_array('er_assessments', $tables, true)) {
            $query = $db->prepare('SELECT multiplier, note, updated_by, updated_at FROM er_assessments WHERE year = ? AND item_code = ?');
            $query->execute([$year, $itemCode]);
            $assessment = $query->fetch() ?: null;
        }
        if (!in_array('er_evidence', $tables, true) && $count === 0 && $assessment === null) {
            return [];
        }
        $multiplier = $count > 0 ? (float)($assessment['multiplier'] ?? 1.0) : 0.0;
        return [
            'earned' => round($points * $multiplier, 2),
            'evidence_count' => $count,
            'state' => $count > 0 ? 'assessed' : 'no_evidence',
            'multiplier' => $multiplier,
            'revision' => 0,
            'automatic' => $assessment === null,
            'assessment' => $assessment,
        ];
    } catch (PDOException $error) {
        error_log('csm_er_score_summary: ' . $error->getMessage());
        return [];
    }
}
