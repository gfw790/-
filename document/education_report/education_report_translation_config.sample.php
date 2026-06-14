<?php
declare(strict_types=1);

return [
    // Supported: google_basic, libretranslate
    'provider' => 'google_basic',

    // Only for libretranslate. Example: http://127.0.0.1:5000 or https://libretranslate.com
    'endpoint' => '',

    // Google Cloud Translation Basic v2 API key, or LibreTranslate API key.
    'api_key' => '',

    // Source language for education content.
    'source_language' => 'ko',

    // HTTP timeout in seconds.
    'request_timeout' => 15,
];
