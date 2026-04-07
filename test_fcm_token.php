<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tuteur = \App\Models\Tuteur::first();
echo "Tuteur first: " . ($tuteur ? "found" : "not found") . "\n";
if ($tuteur) {
    if (array_key_exists('fcm_token', $tuteur->getAttributes())) {
        echo "fcm_token exists! Value: " . $tuteur->fcm_token . "\n";
    } else {
        echo "fcm_token DOES NOT exist on Tuteur table.\n";
    }
}
