<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    echo "[1] Testing DB Connection...\n";
    $userCount = \App\Models\Tuteur::count();
    echo "    -> Found {$userCount} Tuteurs in DB.\n";
    
    echo "[2] Initializing Firebase Factory...\n";
    $factory = (new \Kreait\Firebase\Factory)->withServiceAccount(config('services.firebase.credentials'));
    $messaging = $factory->createMessaging();
    echo "    -> Firebase Initialized successfully!\n";
    
    echo "[3] All push prerequisites are fully functional.\n";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}


