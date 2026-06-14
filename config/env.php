<?php

$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$host = strtolower(preg_replace('/:\d+$/', '', $host));
$isLocal = PHP_SAPI === 'cli' || in_array($host, ['localhost', '127.0.0.1', '::1'], true);
$isInfinityFree = substr($host, -strlen('infinityfreeapp.com')) === 'infinityfreeapp.com'
    || substr($host, -strlen('rf.gd')) === 'rf.gd'
    || substr($host, -strlen('epizy.com')) === 'epizy.com'
    || strpos($host, 'infinityfree') !== false;

if ($isLocal && !$isInfinityFree) {
    return [
        'APP_DEBUG' => false,
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_NAME' => 'itmcd',
        'DB_USER' => 'root',
        'DB_PASS' => '',
    ];
}

return [
    'APP_DEBUG' => false,
    'DB_HOST' => 'sql302.infinityfree.com',
    'DB_PORT' => '3306',
    'DB_NAME' => 'if0_40978795_udbl_notes',
    'DB_USER' => 'if0_40978795',
    'DB_PASS' => 'JeaN2533',
];
