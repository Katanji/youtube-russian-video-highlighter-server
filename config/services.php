<?php

return [
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'youtube' => [
        'api_key_1' => env('YOUTUBE_API_KEY_1'),
        'api_key_2' => env('YOUTUBE_API_KEY_2'),
        'api_key_3' => env('YOUTUBE_API_KEY_3'),
        'api_key_4' => env('YOUTUBE_API_KEY_4'),
        'api_key_5' => env('YOUTUBE_API_KEY_5'),
        'api_key_6' => env('YOUTUBE_API_KEY_6'),
        'api_key_7' => env('YOUTUBE_API_KEY_7'),
        'api_key_8' => env('YOUTUBE_API_KEY_8'),
        'api_key_9' => env('YOUTUBE_API_KEY_9'),
        'api_key_10' => env('YOUTUBE_API_KEY_10'),
        'api_key_11' => env('YOUTUBE_API_KEY_11'),
        'api_key_12' => env('YOUTUBE_API_KEY_12'),
        'api_key_13' => env('YOUTUBE_API_KEY_13'),
        'api_key_14' => env('YOUTUBE_API_KEY_14'),
        'api_key_15' => env('YOUTUBE_API_KEY_15'),
        'api_key_16' => env('YOUTUBE_API_KEY_16'),
        'api_key_17' => env('YOUTUBE_API_KEY_17'),
        'api_key_18' => env('YOUTUBE_API_KEY_18'),
        'api_key_19' => env('YOUTUBE_API_KEY_19'),
        'api_key_20' => env('YOUTUBE_API_KEY_20'),
        'api_key_21' => env('YOUTUBE_API_KEY_21'),
        'api_key_22' => env('YOUTUBE_API_KEY_22'),
        'api_key_23' => env('YOUTUBE_API_KEY_23'),
        'api_key_24' => env('YOUTUBE_API_KEY_24'),
    ],
];
