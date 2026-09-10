<?php
declare(strict_types=1);

function safety_cover_storage_path(): string
{
    return __DIR__ . '/cover_data.json';
}

function safety_cover_load_data(): array
{
    $defaults = [
        'document_number' => '',
        'established_date' => '',
        'revision_date' => '',
        'revision_count' => '',
        'control_type' => '관리본',
        'content' => '',
        'revisions' => [],
    ];
    $path = safety_cover_storage_path();
    if (!is_file($path)) {
        return $defaults;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
}

function safety_cover_save_data(array $data): void
{
    file_put_contents(
        safety_cover_storage_path(),
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}
