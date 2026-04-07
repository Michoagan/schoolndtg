<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$apiKey = env('GEMINI_API_KEY');
$response = Illuminate\Support\Facades\Http::withoutVerifying()->get("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}");
echo $response->body();
