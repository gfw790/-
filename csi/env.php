<?php
// .env 파일에서 환경변수 읽기 (간단 버전)
function env_get($key, $default = null) {
    static $env = null;
    if ($env === null) {
        $env = [];
        $envPath = __DIR__ . '/.env';
        if (is_file($envPath)) {
            foreach (file($envPath) as $line) {
                if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($line), $m)) {
                    $env[$m[1]] = $m[2];
                }
            }
        }
    }
    return $env[$key] ?? $default;
}
