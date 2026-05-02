<?php

use App\Http\Controllers\PaiementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/kkiapay/checkout', [PaiementController::class, 'kkiapayCheckout'])->name('kkiapay.checkout');
