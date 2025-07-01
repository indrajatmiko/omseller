<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


// Route::middleware('auth:api')->get('/user', function (Request $request) {
//     return auth()->user();
// });

Route::middleware('auth:api')->group(function () {
    Route::get('/user', function (Request $request) {
        // 'auth:api' akan memastikan $request->user() terisi
        return $request->user(); 
    });
    Route::post('/scrape-data', [App\Http\Controllers\Api\ScrapeDataController::class, 'store']);
    Route::get('/scraped-dates/{campaign_id}', [App\Http\Controllers\Api\ScrapeDataController::class, 'getScrapedDates']);

    // (Baru) Rute Scraper Produk
    Route::post('/products/scrape', [App\Http\Controllers\Api\ProductScrapeController::class, 'store']);
    Route::get('/products/stats', [App\Http\Controllers\Api\ProductScrapeController::class, 'getStats']);

    // (BARU) Rute Scraper Pesanan
    Route::post('/orders/scrape', [App\Http\Controllers\Api\OrderScrapeController::class, 'store']);
    Route::get('/orders/pending-details', [App\Http\Controllers\Api\OrderScrapeController::class, 'getPendingDetails']);

    Route::post('/ad-transactions', [App\Http\Controllers\Api\AdTransactionController::class, 'store']);
    Route::get('/ad-transactions/latest-date', [App\Http\Controllers\Api\AdTransactionController::class, 'getLatestTransactionDate']);
});

Wave::api();

// Posts Example API Route
Route::group(['middleware' => 'auth:api'], function () {
    Route::get('/posts', '\App\Http\Controllers\Api\ApiController@posts');
});