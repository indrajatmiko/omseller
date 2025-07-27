<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use Illuminate\Support\Facades\Route;
use Wave\Facades\Wave;
use App\Http\Controllers\PdfController; 

// Wave routes
Wave::routes();

// Route::middleware('auth')->group(function () {
    // ... rute-rute Anda yang lain
    
    Route::get('/orders/{order}/print-label', [PdfController::class, 'shippingLabel'])
        ->name('orders.print-label');
// });