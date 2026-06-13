<?php
declare(strict_types=1);

return [
    // Supported for now: libretranslate
    'provider' => 'libretranslate',

    // Example: http://127.0.0.1:5000 or https://libretranslate.com
    'endpoint' => '',

    // Optional for self-hosted LibreTranslate. Required by some hosted instances.
    'api_key' => '',

    // Source language for education content.
    'source_language' => 'ko',

    // HTTP timeout in seconds.
    'request_timeout' => 15,
];
