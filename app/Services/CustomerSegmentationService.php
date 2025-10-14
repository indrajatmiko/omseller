<?php

namespace App\Services;

use App\Models\BuyerProfile;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CustomerSegmentationService
{
    private $userId;

    public function __construct()
    {
        // Gunakan auth()->id() hanya jika ada user yang login
        $this->userId = auth()->check() ? auth()->id() : null;
    }

    /**
     * Pastikan selalu mengembalikan Collection.
     */
    public function getSegmentedData(): Collection
    {
        // Jika tidak ada user (misal: dijalankan dari context non-API/web), kembalikan koleksi kosong.
        if (!$this->userId) {
            return collect();
        }

        $cacheKey = 'customer_segmentation_data_v01_quartile_' . $this->userId;
        
        // Cache::remember akan mengembalikan nilai dari closure.
        // Kita harus memastikan closure ini selalu mengembalikan Collection.
        $data = Cache::remember($cacheKey, 3600, function () {
            // Query utama
            $profilesWithData = BuyerProfile::query()
                ->where('buyer_profiles.user_id', $this->userId)
                ->join('orders', function ($join) {
                    $join->on('buyer_profiles.buyer_username', '=', 'orders.buyer_username')
                        ->where('orders.user_id', '=', $this->userId)
                        ->where(DB::raw('sha1(trim(orders.address_full))'), '=', DB::raw('buyer_profiles.address_identifier'));
                })
                ->select(
                    'buyer_profiles.id',
                    'buyer_profiles.buyer_real_name',
                    'buyer_profiles.buyer_username',
                    'buyer_profiles.address_identifier',
                    DB::raw('MAX(orders.address_full) as address_full'),
                    DB::raw('COUNT(orders.id) as frequency'),
                    DB::raw('SUM(orders.total_price) as monetary'),
                    DB::raw('MAX(orders.created_at) as recency_date')
                )
                ->groupBy(
                    'buyer_profiles.id',
                    'buyer_profiles.buyer_real_name',
                    'buyer_profiles.buyer_username',
                    'buyer_profiles.address_identifier'
                )
                ->get();

            // --- PERBAIKAN UTAMA DI SINI ---
            if ($profilesWithData->isEmpty()) {
                // Jika tidak ada data, kembalikan koleksi KOSONG, bukan null.
                return collect();
            }
            // --- AKHIR PERBAIKAN ---

            // Query untuk siklus pembelian
            $customerOrderDates = Order::where('user_id', $this->userId)
                ->whereIn('buyer_username', $profilesWithData->pluck('buyer_username')->unique())
                ->select('buyer_username', DB::raw('sha1(trim(address_full)) as address_identifier'), 'created_at')
                ->orderBy('created_at', 'asc')
                ->get()->groupBy(fn($order) => $order->buyer_username . '|' . $order->address_identifier);
            
            // ... (Sisa logika helper dan mapping sama persis seperti sebelumnya)
            $getPurchaseCycle = function (Collection $dates) {
                if ($dates->count() < 2) return null;
                $diffs = [];
                for ($i = 1; $i < $dates->count(); $i++) {
                    $diffs[] = abs(Carbon::parse($dates[$i])->diffInDays(Carbon::parse($dates[$i - 1]), false));
                }
                return round(collect($diffs)->avg());
            };
            
            // Helper quintiles (tidak berubah)
            $getQuartiles = function (Collection $collection) {
                $sorted = $collection->sort()->values();
                $count = $sorted->count();
                if ($count < 4) { 
                    $val = $sorted->get(intval($count / 2)) ?: 1;
                    return [$val, $val, $val]; 
                }
                return [
                    $sorted->get(intval($count * 0.25)), // Q1
                    $sorted->get(intval($count * 0.50)), // Q2 (Median)
                    $sorted->get(intval($count * 0.75)), // Q3
                ];
            };

            $recencyDays = $profilesWithData->pluck('recency_date')->map(fn($date) => Carbon::parse($date)->diffInDays(now()));
            $recencyBoundaries = $getQuartiles($recencyDays);
            $frequencyBoundaries = $getQuartiles($profilesWithData->pluck('frequency'));
            $monetaryBoundaries = $getQuartiles($profilesWithData->pluck('monetary'));

            return $profilesWithData->map(function ($profile) use ($recencyBoundaries, $frequencyBoundaries, $monetaryBoundaries, $customerOrderDates, $getPurchaseCycle) {
                // ... (Fungsi skor dan logika mapping) ...
                $getRecencyScore = function (int $value, array $boundaries): int {
                    if ($value <= $boundaries[0]) return 4; // Sangat baru
                    if ($value <= $boundaries[1]) return 3;
                    if ($value <= $boundaries[2]) return 2;
                    return 1; // Sudah lama
                };
                
                // Untuk Frequency & Monetary: semakin besar nilainya, semakin tinggi skornya
                $getScore = function (int|float $value, array $boundaries): int {
                    if ($value > $boundaries[2]) return 4; // Sangat tinggi
                    if ($value > $boundaries[1]) return 3;
                    if ($value > $boundaries[0]) return 2;
                    return 1; // Rendah
                };
                
                $rScore = $getRecencyScore(Carbon::parse($profile->recency_date)->diffInDays(now()), $recencyBoundaries);
                $fScore = $getScore($profile->frequency, $frequencyBoundaries);
                $mScore = $getScore($profile->monetary, $monetaryBoundaries);
                $segmentDetails = $this->getSegmentDetails($rScore, $fScore, $mScore);
                $customerKey = $profile->buyer_username . '|' . $profile->address_identifier;
                $orderDatesForCycle = $customerOrderDates->get($customerKey, collect())->pluck('created_at');
                $purchaseCycle = $getPurchaseCycle($orderDatesForCycle);
                
                return (object) [
                    // ... (semua properti objek)
                    'id' => $profile->id,
                    'name' => $profile->buyer_real_name,
                    'username' => $profile->buyer_username,
                    'address' => $profile->address_full,
                    'last_order' => Carbon::parse($profile->recency_date)->diffForHumans(),
                    'last_order_raw' => $profile->recency_date,
                    'frequency' => $profile->frequency,
                    'total_spend' => $profile->monetary,
                    'segment_label' => $segmentDetails['label'],
                    'segment_color' => $segmentDetails['color'],
                    'segment_description' => $segmentDetails['description'],
                    'segment_action' => $segmentDetails['action'],
                    'purchase_cycle_days' => $purchaseCycle,
                ];
            });
        });

        // Pastikan hasilnya adalah Collection sebelum dikembalikan
        return $data instanceof Collection ? $data : collect();
    }

    /**
     * Logika segmentasi (tidak ada perubahan).
     */
    private function getSegmentDetails(int $r, int $f, int $m): array
    {
        $segment = 'Bronze'; // Default segment
        
        if ($r === 4 && $f === 4 && $m === 4) {
            $segment = 'Juara';
        } elseif ($f === 4) {
            $segment = 'Pelanggan Setia';
        } elseif ($r === 4 && $f >= 2) {
            $segment = 'Potensial';
        } elseif ($r === 4 && $f === 1) {
            $segment = 'Pelanggan Baru';
        } elseif ($r <= 2 && $f >= 2) { // R=1 atau R=2
            $segment = 'Butuh Perhatian';
        } else {
            $segment = 'Tertidur'; // Atau bisa juga 'Bronze', 'Tertidur', dll.
        }
        
        // Definisikan deskripsi dan warna untuk setiap segmen
        $details = [
            'Juara' => [
                'color' => 'emerald',
                'description' => 'Pelanggan terbaik Anda di semua metrik. Baru saja membeli, sangat sering, dan belanja banyak.',
                'action' => 'Berikan reward eksklusif, program loyalitas, dan jadikan duta brand.'
            ],
            'Pelanggan Setia' => [
                'color' => 'blue',
                'description' => 'Pelanggan yang paling sering membeli. Mereka adalah tulang punggung bisnis Anda.',
                'action' => 'Tawarkan produk baru lebih awal (early access) dan program langganan.'
            ],
            'Potensial' => [
                'color' => 'yellow',
                'description' => 'Baru saja membeli dan sudah beberapa kali. Punya potensi besar menjadi pelanggan setia.',
                'action' => 'Bimbing mereka dengan tips produk dan tawarkan diskon untuk pembelian berikutnya.'
            ],
            'Pelanggan Baru' => [
                'color' => 'teal',
                'description' => 'Baru saja melakukan pembelian pertama. Kesan pertama sangat penting.',
                'action' => 'Pastikan pengalaman on-boarding mereka luar biasa. Kirim email selamat datang dan panduan produk.'
            ],
            'Butuh Perhatian' => [
                'color' => 'orange',
                'description' => 'Dulu sering membeli, tapi sudah lama tidak kembali. Berisiko hilang.',
                'action' => 'Kirim kampanye "Kami Merindukanmu" dengan insentif yang kuat untuk menarik mereka kembali.'
            ],
            'Lainnya' => [
                'color' => 'gray',
                'description' => 'Pelanggan dengan aktivitas belanja yang rendah atau tidak menentu.',
                'action' => 'Sertakan dalam newsletter umum, tidak perlu penanganan khusus saat ini.'
            ],
            'Bronze' => [ // Default fallback
                'color' => 'gray',
                'description' => 'Pelanggan dengan aktivitas belanja yang rendah.',
                'action' => 'Sertakan dalam newsletter umum.'
            ],
        ];

        return [
            'label' => $segment,
            'color' => $details[$segment]['color'],
            'description' => $details[$segment]['description'],
            'action' => $details[$segment]['action'],
        ];
    }
}