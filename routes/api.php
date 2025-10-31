<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\UnidentifiedOrderController;
use App\Http\Controllers\Api\V1\CustomerDetailController;
use App\Http\Controllers\Api\V1\SegmentationController;
use App\Http\Controllers\Api\V1\MarginCalculatorController;


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

    // Versi 1 API - Flutter App
    Route::group(['prefix' => 'v1', 'namespace' => 'App\Http\Controllers\Api\V1', 'as' => 'api.v1.'], function () {
        Route::get('orders/unidentified', [UnidentifiedOrderController::class, 'index'])->name('orders.unidentified.index');
        Route::post('orders/{order}/buyer-profile', [UnidentifiedOrderController::class, 'storeProfile'])->name('orders.buyer-profile.store');

        Route::get('customers/search', [CustomerDetailController::class, 'search'])->name('customers.search');
        Route::get('customers/details/{identifier}', [CustomerDetailController::class, 'show'])->name('customers.details.show');

        /**
         * Mengambil statistik ringkasan untuk dasbor segmentasi.
         * GET /api/v1/segmentation/dashboard-stats
         */
        Route::get('segmentation/dashboard-stats', [SegmentationController::class, 'dashboardStats'])->name('segmentation.dashboard');

        /**
         * Mengambil daftar pelanggan yang sudah disegmentasi.
         * Mendukung filter, sorting, dan paginasi.
         * GET /api/v1/segmentation/customers?filter_segment=...&sort_by=...&sort_dir=...&page=...
         */
        Route::get('segmentation/customers', [SegmentationController::class, 'index'])->name('segmentation.customers.index');
        
        /**
         * Mengambil detail spesifik seorang pelanggan (termasuk riwayat pesanan dan produk favorit).
         * GET /api/v1/segmentation/customers/{buyerProfile}
         */
        Route::get('segmentation/customers/{buyerProfile}', [SegmentationController::class, 'show'])->name('segmentation.customers.show');    
        // Endpoint untuk mendapatkan data yang dibutuhkan kalkulator
        Route::get('admin-fees', [MarginCalculatorController::class, 'getAdminFees'])->name('admin-fees');
        Route::get('program-fees', [MarginCalculatorController::class, 'getProgramFees'])->name('program-fees');
        Route::get('category-details', [MarginCalculatorController::class, 'getCategoryDetails'])->name('category-details');

        // Endpoint untuk melakukan perhitungan
        Route::post('calculate/margin', [MarginCalculatorController::class, 'calculate'])->name('calculate.margin');

    }); // End group auth:api

});

Wave::api();

// Posts Example API Route
Route::group(['middleware' => 'auth:api'], function () {
    Route::get('/posts', '\App\Http\Controllers\Api\ApiController@posts');
});