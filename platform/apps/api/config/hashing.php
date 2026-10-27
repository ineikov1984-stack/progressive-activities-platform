<?php

return [
    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', true),
    ],

    'argon' => [
        'memory' => env('ARGON_MEMORY', PASSWORD_ARGON2_DEFAULT_MEMORY_COST),
        'threads' => env('ARGON_THREADS', PASSWORD_ARGON2_DEFAULT_THREADS),
        'time' => env('ARGON_TIME', PASSWORD_ARGON2_DEFAULT_TIME_COST),
        'verify' => env('HASH_VERIFY', true),
    ],
];
