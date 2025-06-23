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
        $validator = Validator::make($request->all(), [
            'campaign_id' => 'required|integer',
            'aggregatedData' => 'required|array|min:1',
            'aggregatedData.*.scrapeDate' => 'required|date_format:Y-m-d',
            'aggregatedData.*.data' => 'required|array',
        ]);

        if ($validator->fails()) {
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
                    
                    $reportValues = $this->getReportValues($data);
                    
                    $uniqueAttributes = [
                        'user_id' => (int) $user->id,
                        'campaign_id' => (int) $validated['campaign_id'],
                        'scrape_date' => Carbon::parse($dailyData['scrapeDate'])->startOfDay(),
                    ];
                    
                    $report = CampaignReport::updateOrCreate($uniqueAttributes, $reportValues);

                    if ($report->wasRecentlyCreated) {
                        $reportsCreated++;
                    } else {
                        $reportsUpdated++;
                        $report->keywordPerformances()->delete();
                        $report->recommendationPerformances()->delete();
                    }
                    
                    if (!empty($data['keywordPerformance'])) {
                        foreach ($data['keywordPerformance'] as $kw) {
                            // PERBAIKAN: Tambahkan argumen kedua 'false'
                            $report->keywordPerformances()->create($this->flattenMetrics($kw, false));
                        }
                    }

                    if (!empty($data['recommendationPerformance'])) {
                        foreach ($data['recommendationPerformance'] as $rec) {
                            $report->recommendationPerformances()->create($this->flattenMetrics($rec, true));
                        }
                    }
                });
            } catch (Throwable $e) {
                Log::error('Failed to store scrape data for user ' . $user->id, [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
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

    /**
     * Mengambil daftar tanggal yang sudah tersimpan untuk kampanye tertentu.
     * @param int $campaign_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getScrapedDates($campaign_id)
    {
        $user = Auth::user();

        $dates = DB::table('campaign_reports')
            ->where('user_id', $user->id)
            ->where('campaign_id', $campaign_id)
            ->orderBy('scrape_date', 'desc')
            ->pluck('scrape_date'); // Mengambil hanya kolom scrape_date

        // Format tanggal menjadi Y-m-d untuk konsistensi
        $formattedDates = $dates->map(function ($date) {
            return Carbon::parse($date)->format('Y-m-d');
        });

        return response()->json($formattedDates);
    }

    private function getReportValues(array $data): array
    {
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
            'produk_terjual' => $perfMetrics['produk_terjual_di_iklan'] ?? $perfMetrics['produk_terjual'] ?? null,
            'omzet_iklan' => $perfMetrics['omzet_iklan'] ?? null,
            'efektivitas_iklan' => $perfMetrics['efektivitas_iklan_(roas)'] ?? null,
            'cir' => $perfMetrics['cir_(acos)'] ?? null,
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
            'iklan_dilihat' => 'iklan_dilihat',
            'jumlah_klik' => 'jumlah_klik',
            'persentase_klik' => 'persentase_klik',
            'biaya_iklan' => 'biaya_iklan',
            'penjualan_dari_iklan' => 'penjualan_dari_iklan',
            'konversi' => 'konversi',
            'produk_terjual' => 'produk_terjual',
            'roas' => 'roas',
            'acos' => 'acos',
            'tingkat_konversi' => 'tingkat_konversi',
            'biaya_per_konversi' => 'biaya_per_konversi',
            'peringkat_rata_rata' => 'peringkat_rata_rata'
        ];

        foreach($metricsMap as $key => $db_prefix) {
            $base[$db_prefix . '_value'] = $item[$key]['value'] ?? null;
            $base[$db_prefix . '_delta'] = $item[$key]['delta'] ?? null;
        }
        
        return $base;
    }
}