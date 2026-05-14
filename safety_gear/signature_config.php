<?php
declare(strict_types=1);

return [
    'provider' => 'pass',
    'enabled' => false,
    'simulation' => true,
    'service_name' => '안전보호구 수령 서명',
    'pass' => [
        'client_id' => '',
        'client_secret' => '',
        'site_url' => '',
        'request_url' => '',
        'callback_url' => '/safety_gear/external_signature_callback.php',
    ],
];
