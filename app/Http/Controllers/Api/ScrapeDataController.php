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
        // Log seluruh data yang masuk untuk verifikasi awal
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
                        Log::info("UPDATING existing CampaignReport ID: {$report->id}. Deleting old details...");
                        $report->keywordPerformances()->delete();
                        $report->recommendationPerformances()->delete();
                        $report->gmvPerformanceDetails()->delete(); // Nama relasi tetap sama
                    }
                    
                    // Simpan performa kata kunci (mode Manual/GMV Max)
                    if (!empty($data['keywordPerformance'])) {
                        Log::info("Found " . count($data['keywordPerformance']) . " keyword performance items.");
                        foreach ($data['keywordPerformance'] as $kw) {
                            $report->keywordPerformances()->create($this->flattenMetrics($kw, false));
                        }
                    }

                    // Simpan performa rekomendasi (mode Manual/GMV Max)
                    if (!empty($data['recommendationPerformance'])) {
                        Log::info("Found " . count($data['recommendationPerformance']) . " recommendation performance items.");
                        foreach ($data['recommendationPerformance'] as $rec) {
                            $report->recommendationPerformances()->create($this->flattenMetrics($rec, true));
                        }
                    }

                    // (MODIFIKASI 1) Simpan detail performa GMV%
                    // Menggunakan key 'gmvPerformance' sesuai output JS yang baru
                    if (!empty($data['gmvPerformance'])) {
                        Log::info("Found " . count($data['gmvPerformance']) . " GMV performance items. Processing now...");

                        foreach ($data['gmvPerformance'] as $gmv) {
                            // Menggunakan fungsi flatten yang sudah direvisi
                            $flattenedGmvData = $this->flattenGmvMetrics($gmv);
                            
                            Log::info("Attempting to create gmvPerformanceDetail with data:", $flattenedGmvData);
                            
                            // Nama fungsi relasi di Model tetap 'gmvPerformanceDetails()'
                            $report->gmvPerformanceDetails()->create($flattenedGmvData);
                        }
                    } else {
                        // Log jika tidak ada data performa GMV%
                        Log::info("Key 'gmvPerformance' was not found or is empty for scrapeDate: {$scrapeDate}. This is normal for non-GMV% modes.");
                    }
                });
            } catch (Throwable $e) {
                Log::error('Failed to store scrape data for user ' . $user->id, [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
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

        $dates = DB::table('campaign_reports')
            ->where('user_id', $user->id)
            ->where('campaign_id', $campaign_id)
            ->orderBy('scrape_date', 'desc')
            ->pluck('scrape_date');

        $formattedDates = $dates->map(function ($date) {
            return Carbon::parse($date)->format('Y-m-d');
        });

        return response()->json($formattedDates);
    }

    private function getReportValues(array $data): array
    {
        $productInfo = $data['productInfo'] ?? [];
        $perfMetrics = $data['performanceMetrics'] ?? [];
        
        $roas_key = array_key_exists('efektivitas_iklan_(roas)', $perfMetrics) ? 'efektivitas_iklan_(roas)' : 'efektivitas_iklan';
        $cir_key = array_key_exists('cir_(acos)', $perfMetrics) ? 'cir_(acos)' : 'cir';

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
            'produk_terjual' => $perfMetrics['produk_terjual_di_iklan'] ?? $perfMetrics['produk_terjual'] ?? null,
            'omzet_iklan' => $perfMetrics['omzet_iklan'] ?? null,
            'efektivitas_iklan' => $perfMetrics[$roas_key] ?? null,
            'cir' => $perfMetrics[$cir_key] ?? null,
        ];
    }
    
    private function flattenMetrics(array $item, bool $isRecommendation = false): array
    {
        $base = [];
        if ($isRecommendation) {
            $base = [
                'penempatan' => $item['penempatan'] ?? null,
                'harga_bid' => $item['harga_bid'] ?? null,
                'disarankan' => $item['disarankan'] ?? null,
            ];
        } else {
            $base = [
                'kata_pencarian' => $item['kata_pencarian'] ?? null,
                'tipe_pencocokan' => $item['tipe_pencocokan'] ?? null,
                'per_klik' => $item['per_klik'] ?? null,
                'disarankan' => $item['disarankan'] ?? null,
            ];
        }
        
        $metricsMap = [
            'iklan_dilihat','jumlah_klik','persentase_klik','biaya_iklan','penjualan_dari_iklan',
            'konversi','produk_terjual','roas','acos','tingkat_konversi',
            'biaya_per_konversi','peringkat_rata_rata'
        ];

        foreach($metricsMap as $key) {
            $base[$key . '_value'] = $item[$key]['value'] ?? null;
            $base[$key . '_delta'] = $item[$key]['delta'] ?? null;
        }
        
        return $base;
    }

    /**
     * (MODIFIKASI 2) Fungsi ini disederhanakan untuk menangani struktur data GMV% yang datar.
     */
    private function flattenGmvMetrics(array $item): array
    {
        // Kunci di map ini HARUS sama persis dengan kunci yang dihasilkan 
        // oleh fungsi `extractGmvPerformance` di JavaScript Anda.
        $metricsMap = [
            'kata_pencarian',
            'penempatan_rekomendasi',
            'harga_bid',
            'iklan_dilihat',
            'jumlah_klik',
            'persentase_klik',
            'biaya_iklan',
            'penjualan_dari_iklan',
            'konversi',
            'produk_terjual',
            'roas',
            'persentase_biaya_iklan_acos', // Key yang spesifik dari JS
            'tingkat_konversi',
            'biaya_per_konversi',
            'konversi_langsung',
            'produk_terjual_langsung',
            'penjualan_dari_iklan_langsung',
            'roas_langsung',
            'acos_langsung',
            'tingkat_konversi_langsung',
            'biaya_per_konversi_langsung',
        ];

        $flattenedData = [];

        foreach ($metricsMap as $key) {
            // Langsung ambil nilai dari item, karena tidak ada lagi struktur 'value'/'delta'
            $flattenedData[$key] = $item[$key] ?? null;
        }

        return $flattenedData;
    }
}