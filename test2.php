<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$apiKey = env('GEMINI_API_KEY');
$response = Illuminate\Support\Facades\Http::withoutVerifying()->get("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}");
$data = $response->json();
if (isset($data['models'])) {
    foreach ($data['models'] as $model) {
        // Output model name
        echo $model['name'] . "\n";
    }
} else {
    echo "NO MODELS FOUND or ERROR: \n";
    print_r($data);
}
