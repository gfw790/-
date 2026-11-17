<?php
declare(strict_types=1);
require_once __DIR__ . '/risk_procedure_store.php';

function csm_ra_document_schema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS csm_ra_procedure_documents (
        year INTEGER PRIMARY KEY, revision INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT \'draft\',
        data_json TEXT NOT NULL, updated_by TEXT NOT NULL, updated_at TEXT NOT NULL, issued_at TEXT
    ); CREATE TABLE IF NOT EXISTS csm_ra_procedure_document_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT, year INTEGER NOT NULL, revision INTEGER NOT NULL,
        status TEXT NOT NULL, data_json TEXT NOT NULL, saved_by TEXT NOT NULL, saved_at TEXT NOT NULL
    ); CREATE INDEX IF NOT EXISTS csm_ra_procedure_document_history_year ON csm_ra_procedure_document_history(year,revision)');
}

function csm_ra_document_sections(): array
{
    static $sections = null;
    if ($sections === null) {
        $source = json_decode((string)file_get_contents(__DIR__ . '/risk_procedure_company_defaults.json'), true, 512, JSON_THROW_ON_ERROR);
        $sections = $source['sections'];
    }
    return $sections;
}

function csm_ra_document_defaults(int $year): array
{
    $data = ['company' => '주식회사 현대기전', 'site' => '전 사업장',
        'document_name' => '위험성평가 실시규정', 'version' => '8',
        'enacted_date' => '2015-07-01', 'effective_date' => '2024-08-27',
        'writer' => '정주랑', 'reviewer' => '진종철', 'approver' => '정연탁', 'revision_note' => ''];
    foreach (csm_ra_document_sections() as $key => $section) $data[$key] = $section['default'];
    return $data;
}

function csm_ra_document_history(PDO $db, int $year): array
{
    $query = $db->prepare('SELECT * FROM csm_ra_procedure_document_history WHERE year=? ORDER BY revision DESC');
    $query->execute([$year]);
    $rows = $query->fetchAll();
    foreach ($rows as &$row) $row['data'] = json_decode((string)$row['data_json'], true, 512, JSON_THROW_ON_ERROR);
    return $rows;
}

function csm_ra_document_revision(PDO $db, int $year, int $revision): ?array
{
    $query = $db->prepare('SELECT * FROM csm_ra_procedure_document_history WHERE year=? AND revision=?');
    $query->execute([$year, $revision]);
    $row = $query->fetch();
    if (!$row) return null;
    $row['data'] = json_decode((string)$row['data_json'], true, 512, JSON_THROW_ON_ERROR);
    return $row;
}

function csm_ra_document(PDO $db, int $year): ?array
{
    $query = $db->prepare('SELECT * FROM csm_ra_procedure_documents WHERE year=?');
    $query->execute([$year]);
    $row = $query->fetch();
    if (!$row) return null;
    $row['data'] = json_decode((string)$row['data_json'], true, 512, JSON_THROW_ON_ERROR);
    return $row;
}

function csm_ra_document_issued(PDO $db, int $year): ?array
{
    $query = $db->prepare("SELECT * FROM csm_ra_procedure_document_history WHERE year=? AND status='issued' ORDER BY revision DESC LIMIT 1");
    $query->execute([$year]);
    $row = $query->fetch();
    if (!$row) return null;
    $row['data'] = json_decode((string)$row['data_json'], true, 512, JSON_THROW_ON_ERROR);
    return $row;
}

function csm_ra_document_save(PDO $db, int $year, array $input, int $revision, string $status, string $editor): void
{
    if (!in_array($status, ['draft', 'issued'], true)) throw new InvalidArgumentException('저장 상태를 확인해 주세요.');
    $defaults = csm_ra_document_defaults($year);
    $data = [];
    foreach ($defaults as $key => $value) {
        $raw = $input[$key] ?? '';
        if (!is_string($raw)) throw new InvalidArgumentException('문서 입력값을 확인해 주세요.');
        $data[$key] = trim($raw);
        if (mb_strlen($data[$key], 'UTF-8') > (isset(csm_ra_document_sections()[$key]) ? 8000 : 500)) {
            throw new InvalidArgumentException('입력 내용이 너무 깁니다.');
        }
    }
    if ($data['company'] === '' || $data['document_name'] === '' || $data['version'] === '') {
        throw new InvalidArgumentException('회사명, 문서명, 개정번호를 입력해 주세요.');
    }
    foreach (['enacted_date' => '제정일', 'effective_date' => '시행일'] as $field => $label) {
        if ($data[$field] !== '' && (!DateTimeImmutable::createFromFormat('!Y-m-d', $data[$field])
            || DateTimeImmutable::createFromFormat('!Y-m-d', $data[$field])->format('Y-m-d') !== $data[$field])) {
            throw new InvalidArgumentException($label . '을 확인해 주세요.');
        }
    }
    if ($status === 'issued') {
        if ($data['effective_date'] === '' || $data['approver'] === '') throw new InvalidArgumentException('발행하려면 시행일과 승인자를 입력해 주세요.');
        foreach (csm_ra_document_sections() as $key => $section) {
            if ($data[$key] === '') throw new InvalidArgumentException($section['title'] . ' 내용을 입력해 주세요.');
        }
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $now = date('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        $existing = csm_ra_document($db, $year);
        $currentRevision = $existing ? (int)$existing['revision'] : 0;
        if ($currentRevision !== $revision) throw new InvalidArgumentException('다른 사용자가 문서를 수정했습니다. 새로고침 후 다시 확인해 주세요.');
        $next = $revision + 1;
        $issuedAt = $status === 'issued' ? $now : ($existing['issued_at'] ?? null);
        $query = $db->prepare('INSERT INTO csm_ra_procedure_documents(year,revision,status,data_json,updated_by,updated_at,issued_at) VALUES(?,?,?,?,?,?,?)
            ON CONFLICT(year) DO UPDATE SET revision=excluded.revision,status=excluded.status,data_json=excluded.data_json,
            updated_by=excluded.updated_by,updated_at=excluded.updated_at,issued_at=excluded.issued_at');
        $query->execute([$year, $next, $status, $json, $editor, $now, $issuedAt]);
        $history = $db->prepare('INSERT INTO csm_ra_procedure_document_history(year,revision,status,data_json,saved_by,saved_at) VALUES(?,?,?,?,?,?)');
        $history->execute([$year, $next, $status, $json, $editor, $now]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}
