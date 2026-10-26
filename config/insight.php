<?php

return [
    'api_key' => env('GEMINI_API_KEY'),
    'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
    'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    'timeout' => 20,
];
