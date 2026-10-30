<?php

return [
    // The platform's name in emails ("<organization> чрез <platform>") until the owner decides on it
    // (docs/process/WORKBOARD.md, questions); changed in .env without code.
    'name' => env('PLATFORM_NAME', 'Progressive Activities'),

    // The web app (links in emails); APP_URL is the API.
    'web_url' => rtrim((string) env('WEB_URL', 'http://localhost:3000'), '/'),
];
