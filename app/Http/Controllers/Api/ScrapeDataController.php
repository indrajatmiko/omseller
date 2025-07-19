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
        Log::info('--- Received Scrape Request ---', $request->all());
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
                    Log::info("Processing data for scrapeDate: {$scrapeDate}");

                    $reportValues = $this->getReportValues($data);
                    $uniqueAttributes = [
                        'user_id' => (int) $user->id,
                        'campaign_id' => (int) $validated['campaign_id'],
                        'scrape_date' => Carbon::parse($scrapeDate)->startOfDay(),
                    ];
                    
                    $report = CampaignReport::updateOrCreate($uniqueAttributes, $reportValues);

                    if ($report->wasRecentlyCreated) {
                        $reportsCreated++;
                        Log::info("CREATED new CampaignReport ID: {$report->id}");
                    } else {
                        $reportsUpdated++;
                        Log::info("UPDATING existing CampaignReport ID: {$report->id}. Deleting all old details...");
                        $report->keywordPerformances()->delete();
                        $report->recommendationPerformances()->delete();
                        $report->gmvPerformanceDetails()->delete(); // Bersihkan semua jenis detail untuk kesederhanaan
                    }
                    
                    // Alur Mode Manual
                    if (!empty($data['keywordPerformance'])) {
                        Log::info("Processing Manual Mode: Found " . count($data['keywordPerformance']) . " keyword performance items.");
                        foreach ($data['keywordPerformance'] as $kw) {
                            $report->keywordPerformances()->create($this->flattenManualMetrics($kw, false));
                        }
                    }
                    if (!empty($data['recommendationPerformance'])) {
                        Log::info("Processing Manual Mode: Found " . count($data['recommendationPerformance']) . " recommendation performance items.");
                        foreach ($data['recommendationPerformance'] as $rec) {
                            $report->recommendationPerformances()->create($this->flattenManualMetrics($rec, true));
                        }
                    }

                    // (BARU) Alur Mode GMV
                    if (!empty($data['gmvPerformance'])) {
                        Log::info("Processing GMV Mode: Found " . count($data['gmvPerformance']) . " GMV performance items.");
                        foreach ($data['gmvPerformance'] as $gmv) {
                            // Langsung gunakan data karena sudah datar (flat)
                            $report->gmvPerformanceDetails()->create($gmv);
                        }
                    }
                });
            } catch (Throwable $e) {
                // ... (Error handling tetap sama)
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

    public function getScrapedDates($campaign_id) { /* ...Fungsi ini tetap sama... */ }

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
            'mode_bidding' => $productInfo['mode_bidding'] ?? null, // Ini akan terisi sekarang
            'bidding_dinamis' => $productInfo['bidding_dinamis'] ?? null,
            'target_roas' => $productInfo['target_roas'] ?? null, // Ini akan terisi sekarang
            'dilihat' => $perfMetrics['dilihat'] ?? null, // Cocokkan dengan payload JSON
            'klik' => $perfMetrics['klik'] ?? null, // Cocokkan dengan payload JSON
            'persentase_klik' => $perfMetrics['persentase_klik'] ?? null, // Cocokkan dengan payload JSON
            'biaya' => $perfMetrics['biaya'] ?? null, // Cocokkan dengan payload JSON
            'pesanan' => $perfMetrics['pesanan'] ?? null,
            'produk_terjual' => $perfMetrics['produk_terjual'] ?? null, // Cocokkan dengan payload JSON
            'omzet_iklan' => $perfMetrics['omzet_iklan'] ?? null, // Cocokkan dengan payload JSON
            'efektivitas_iklan' => $perfMetrics['efektivitas_iklan'] ?? null, // Cocokkan dengan payload JSON
            'cir' => $perfMetrics['cir'] ?? null,
        ];
    }
    
    /**
     * (Diganti namanya menjadi flattenManualMetrics untuk kejelasan)
     * Meratakan struktur data untuk mode Manual.
     */
    private function flattenManualMetrics(array $item, bool $isRecommendation = false): array {
        $base = [];
        if ($isRecommendation) {
            $base = ['penempatan' => $item['penempatan'] ?? null, 'harga_bid' => $item['harga_bid'] ?? null, 'disarankan' => $item['disarankan'] ?? null];
        } else {
            $base = ['kata_pencarian' => $item['kata_pencarian'] ?? null, 'tipe_pencocokan' => $item['tipe_pencocokan'] ?? null, 'per_klik' => $item['per_klik'] ?? null, 'disarankan' => $item['disarankan'] ?? null];
        }
        $metricsMap = ['iklan_dilihat','jumlah_klik','persentase_klik','biaya_iklan','penjualan_dari_iklan','konversi','produk_terjual','roas','acos','tingkat_konversi','biaya_per_konversi','peringkat_rata_rata'];
        foreach($metricsMap as $key) {
            $base[$key . '_value'] = $item[$key]['value'] ?? null;
            $base[$key . '_delta'] = $item[$key]['delta'] ?? null;
        }
        return $base;
    }
}