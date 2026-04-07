<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ai = app(\App\Services\AiService::class);

$moyenneGenerale = 14.5;
$performancesMatieres = [
    ['matiere' => 'Maths', 'moyenne_trimestrielle' => 12.0],
    ['matiere' => 'Physique', 'moyenne_trimestrielle' => 17.5]
];

try {
    echo "Asking Gemini...\n";
    $result = $ai->analyzeStudentGrades($moyenneGenerale, $performancesMatieres, 2);
    echo "AI Response:\n" . $result . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
