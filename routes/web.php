<?php

use App\Http\Controllers\PaiementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/storage/{path}', function ($path) {
    // If the file actually exists in the public directory (symlink is working),
    // the web server (Nginx/Apache) will usually serve it directly without hitting Laravel.
    // However, if the symlink is missing or the web server routes it here, we serve it manually.
    $storagePath = storage_path("app/public/{$path}");
    if (file_exists($storagePath)) {
        return response()->file($storagePath);
    }
    abort(404);
})->where('path', '.*');

Route::get('/kkiapay/checkout', [PaiementController::class, 'kkiapayCheckout'])->name('kkiapay.checkout');
