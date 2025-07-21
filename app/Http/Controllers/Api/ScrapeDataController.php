<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampaignReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;
use Carbon\Carbon;

class ScrapeDataController extends Controller
{
    public function store(Request $request)
    {
        // //Log::info('--- Received Scrape Request ---', $request->all());
        $validator = Validator::make($request->all(), [
            'campaign_id' => 'required|integer',
            'aggregatedData' => 'required|array|min:1',
            'aggregatedData.*.scrapeDate' => 'required|date_format:Y-m-d',
            'aggregatedData.*.data' => 'required|array',
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed.', $validator->errors()->toArray());
            return response()->json(['message' => 'Data tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $user = Auth::user();
        $reportsCreated = 0;
        $reportsUpdated = 0;

        foreach ($validated['aggregatedData'] as $dailyData) {
            try {
                DB::transaction(function () use ($user, $validated, $dailyData, &$reportsCreated, &$reportsUpdated) {
                    $data = $dailyData['data'];
                    $scrapeDate = $dailyData['scrapeDate'];
                    ////Log::info("Processing data for scrapeDate: {$scrapeDate}");

                    $reportValues = $this->getReportValues($data);
                    $uniqueAttributes = [
                        'user_id' => (int) $user->id,
                        'campaign_id' => (int) $validated['campaign_id'],
                        'scrape_date' => Carbon::parse($scrapeDate)->startOfDay(),
                    ];
                    
                    $report = CampaignReport::updateOrCreate($uniqueAttributes, $reportValues);

                    if ($report->wasRecentlyCreated) {
                        $reportsCreated++;
                        ////Log::info("CREATED new CampaignReport ID: {$report->id}");
                    } else {
                        $reportsUpdated++;
                        ////Log::info("UPDATING existing CampaignReport ID: {$report->id}. Deleting all old details...");
                        $report->keywordPerformances()->delete();
                        $report->recommendationPerformances()->delete();
                        $report->gmvPerformanceDetails()->delete();
                    }
                    
                    // (MODIFIKASI KUNCI) Alur Mode Manual sekarang menggunakan fungsi baru
                    if (!empty($data['keywordPerformance'])) {
                        //Log::info("Processing Manual Mode: Found " . count($data['keywordPerformance']) . " keyword performance items.");
                        foreach ($data['keywordPerformance'] as $kw) {
                            // Panggil fungsi baru yang sesuai dengan struktur data flat
                            $report->keywordPerformances()->create($this->prepareKeywordPerformanceData($kw));
                        }
                    }
                    if (!empty($data['recommendationPerformance'])) {
                        //Log::info("Processing Manual Mode: Found " . count($data['recommendationPerformance']) . " recommendation performance items.");
                        // Anda mungkin perlu membuat `prepareRecommendationPerformanceData` jika strukturnya berbeda
                        // Untuk saat ini, asumsikan strukturnya sama
                        foreach ($data['recommendationPerformance'] as $rec) {
                            // INI YANG BENAR: Memanggil fungsi yang dirancang untuk recommendation
                            $report->recommendationPerformances()->create($this->prepareRecommendationPerformanceData($rec));
                        }
                    }

                    // Alur Mode GMV (tidak berubah)
                    if (!empty($data['gmvPerformance'])) {
                        //Log::info("Processing GMV Mode: Found " . count($data['gmvPerformance']) . " GMV performance items.");
                        foreach ($data['gmvPerformance'] as $gmv) {
                            $report->gmvPerformanceDetails()->create($gmv);
                        }
                    }
                });
            } catch (Throwable $e) {
                Log::error('Failed to store scrape data for user ' . $user->id, [ 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString() ]);
                return response()->json(['message' => 'Kesalahan internal server: ' . $e->getMessage()], 500);
            }
        }

        return response()->json([
            'message' => 'Data berhasil diproses.',
            'created' => $reportsCreated,
            'updated' => $reportsUpdated,
        ], 200);
    }

    public function getScrapedDates($campaign_id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([], 401); // Unauthorized
        }

        $dates = DB::table('campaign_reports')
            ->where('user_id', $user->id)
            ->where('campaign_id', $campaign_id)
            ->orderBy('scrape_date', 'desc')
            ->pluck('scrape_date');

        $formattedDates = $dates->map(fn ($date) => Carbon::parse($date)->format('Y-m-d'));
        
        return response()->json($formattedDates->values()->all());
    }

    private function getReportValues(array $data): array {
        $productInfo = $data['productInfo'] ?? [];
        $perfMetrics = $data['performanceMetrics'] ?? [];
        return [
            'date_range_text' => $productInfo['rentang_tanggal'] ?? null,
            'nama_produk' => $productInfo['nama_produk'] ?? null,
            'gambar_url' => $productInfo['gambar'] ?? null,
            'status_iklan' => $productInfo['status_iklan'] ?? null,
            'modal' => $productInfo['modal'] ?? null,
            'periode_iklan' => $productInfo['periode_iklan'] ?? null,
            'penempatan_iklan' => $productInfo['penempatan_iklan'] ?? null,
            'mode_bidding' => $productInfo['mode_bidding'] ?? null,
            'bidding_dinamis' => $productInfo['bidding_dinamis'] ?? null,
            'target_roas' => $productInfo['target_roas'] ?? null,
            'dilihat' => $perfMetrics['dilihat'] ?? null,
            'klik' => $perfMetrics['klik'] ?? null,
            'persentase_klik' => $perfMetrics['persentase_klik'] ?? null,
            'biaya' => $perfMetrics['biaya'] ?? null,
            'pesanan' => $perfMetrics['pesanan'] ?? null,
            'produk_terjual' => $perfMetrics['produk_terjual'] ?? null,
            'omzet_iklan' => $perfMetrics['omzet_iklan'] ?? null,
            'efektivitas_iklan' => $perfMetrics['efektivitas_iklan'] ?? null,
            'cir' => $perfMetrics['cir'] ?? null,
        ];
    }
    
    /**
     * (FUNGSI BARU) Mempersiapkan data untuk tabel keyword_performances dari payload yang datar.
     * Fungsi ini menggantikan `flattenManualMetrics`.
     */
    private function prepareKeywordPerformanceData(array $item): array
    {
        // Langsung petakan satu per satu dari data yang masuk ke kolom database.
        // Ini lebih aman dan cocok dengan struktur data scraper yang baru.
        return [
            'kata_pencarian'          => $item['kata_pencarian'] ?? null,
            'tipe_pencocokan'         => $item['tipe_pencocokan'] ?? null,
            'per_klik'                => $item['per_klik'] ?? null,
            'disarankan'              => $item['disarankan'] ?? null,
            'iklan_dilihat_value'     => $item['iklan_dilihat_value'] ?? null,
            'iklan_dilihat_delta'     => $item['iklan_dilihat_delta'] ?? null,
            'jumlah_klik_value'       => $item['jumlah_klik_value'] ?? null,
            'jumlah_klik_delta'       => $item['jumlah_klik_delta'] ?? null,
            'persentase_klik_value'   => $item['persentase_klik_value'] ?? null,
            'persentase_klik_delta'   => $item['persentase_klik_delta'] ?? null,
            'biaya_iklan_value'       => $item['biaya_iklan_value'] ?? null,
            'biaya_iklan_delta'       => $item['biaya_iklan_delta'] ?? null,
            'penjualan_dari_iklan_value' => $item['penjualan_dari_iklan_value'] ?? null,
            'penjualan_dari_iklan_delta' => $item['penjualan_dari_iklan_delta'] ?? null,
            'konversi_value'          => $item['konversi_value'] ?? null,
            'konversi_delta'          => $item['konversi_delta'] ?? null,
            'produk_terjual_value'    => $item['produk_terjual_value'] ?? null,
            'produk_terjual_delta'    => $item['produk_terjual_delta'] ?? null,
            'roas_value'              => $item['roas_value'] ?? null,
            'roas_delta'              => $item['roas_delta'] ?? null,
            'acos_value'              => $item['acos_value'] ?? null,
            'acos_delta'              => $item['acos_delta'] ?? null,
            'tingkat_konversi_value'  => $item['tingkat_konversi_value'] ?? null,
            'tingkat_konversi_delta'  => $item['tingkat_konversi_delta'] ?? null,
            'biaya_per_konversi_value'=> $item['biaya_per_konversi_value'] ?? null,
            'biaya_per_konversi_delta'=> $item['biaya_per_konversi_delta'] ?? null,
            'peringkat_rata_rata_value' => $item['peringkat_rata_rata_value'] ?? null,
            'peringkat_rata_rata_delta' => $item['peringkat_rata_rata_delta'] ?? null,
        ];
    }
    
    /**
     * (BARU) Mempersiapkan data untuk tabel recommendation_performances dari payload yang datar.
     */
    private function prepareRecommendationPerformanceData(array $item): array
    {
        // Petakan data yang masuk ke kolom database sesuai skema recommendation_performances.
        return [
            'penempatan'              => $item['penempatan'] ?? null,
            'harga_bid'               => $item['harga_bid'] ?? null,
            'disarankan'              => $item['disarankan'] ?? null,
            'iklan_dilihat_value'     => $item['iklan_dilihat_value'] ?? null,
            'iklan_dilihat_delta'     => $item['iklan_dilihat_delta'] ?? null,
            'jumlah_klik_value'       => $item['jumlah_klik_value'] ?? null,
            'jumlah_klik_delta'       => $item['jumlah_klik_delta'] ?? null,
            'persentase_klik_value'   => $item['persentase_klik_value'] ?? null,
            'persentase_klik_delta'   => $item['persentase_klik_delta'] ?? null,
            'biaya_iklan_value'       => $item['biaya_iklan_value'] ?? null,
            'biaya_iklan_delta'       => $item['biaya_iklan_delta'] ?? null,
            'penjualan_dari_iklan_value' => $item['penjualan_dari_iklan_value'] ?? null,
            'penjualan_dari_iklan_delta' => $item['penjualan_dari_iklan_delta'] ?? null,
            'konversi_value'          => $item['konversi_value'] ?? null,
            'konversi_delta'          => $item['konversi_delta'] ?? null,
            'produk_terjual_value'    => $item['produk_terjual_value'] ?? null,
            'produk_terjual_delta'    => $item['produk_terjual_delta'] ?? null,
            'roas_value'              => $item['roas_value'] ?? null,
            'roas_delta'              => $item['roas_delta'] ?? null,
            'acos_value'              => $item['acos_value'] ?? null,
            'acos_delta'              => $item['acos_delta'] ?? null,
            'tingkat_konversi_value'  => $item['tingkat_konversi_value'] ?? null,
            'tingkat_konversi_delta'  => $item['tingkat_konversi_delta'] ?? null,
            'biaya_per_konversi_value'=> $item['biaya_per_konversi_value'] ?? null,
            'biaya_per_konversi_delta'=> $item['biaya_per_konversi_delta'] ?? null,
            'peringkat_rata_rata_value' => $item['peringkat_rata_rata_value'] ?? null,
            'peringkat_rata_rata_delta' => $item['peringkat_rata_rata_delta'] ?? null,
        ];
    }
}