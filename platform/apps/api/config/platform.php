<?php

return [
    // The platform's name in emails ("<organization> чрез <platform>") until the owner decides on it
    // (docs/process/WORKBOARD.md, questions); changed in .env without code.
    'name' => env('PLATFORM_NAME', 'Progressive Activities'),

    // The web app (links in emails); APP_URL is the API.
    'web_url' => rtrim((string) env('WEB_URL', 'http://localhost:3000'), '/'),

    // How long a change of the schedule waits for the groups another change holds before it answers 409, in milliseconds
    // (docs/product/schedule-changes.md, т. 4.1а). The tests set it short.
    'attendance_lock_timeout_ms' => (int) env('ATTENDANCE_LOCK_TIMEOUT_MS', 5000),
    'schedule_lock_timeout_ms' => (int) env('SCHEDULE_LOCK_TIMEOUT_MS', 5000),
];
