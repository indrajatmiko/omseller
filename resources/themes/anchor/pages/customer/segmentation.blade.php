<?php

use function Laravel\Folio\{middleware, name};
use App\Models\BuyerProfile;
use App\Models\Order;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

middleware('auth');
name('customer.segmentation');

new class extends Component {
    use WithPagination;

    // UI & Interactivity Properties
    public string $sortBy = 'total_spend';
    public string $sortDir = 'desc';
    public string $filterSegment = 'all';

    // Modal Properties
    public bool $showDetailsModal = false;
    public ?object $selectedCustomer = null;
    public ?Collection $customerOrders = null;
    public ?Collection $frequentItems = null;

    public function updatedFilterSegment(): void { $this->resetPage(); }
    public function updatedSortBy(): void { $this->resetPage(); }
    public function updatedSortDir(): void { $this->resetPage(); }

    public function setSortBy(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }
    
private function getProcessedCustomerData(): Collection
{
    // Versi cache dinaikkan untuk memastikan data baru di-generate
    $cacheKey = 'customer_segmentation_data_v9_' . auth()->id(); 
    
    return Cache::remember($cacheKey, 3600, function () {
        // Query utama
        $profilesWithData = BuyerProfile::query()
            ->where('buyer_profiles.user_id', auth()->id())
            ->join('orders', function ($join) {
                $join->on('buyer_profiles.buyer_username', '=', 'orders.buyer_username')
                    ->where('orders.user_id', '=', auth()->id())
                    ->where(DB::raw('sha1(trim(orders.address_full))'), '=', DB::raw('buyer_profiles.address_identifier'));
            })
            // --- PERBAIKAN UTAMA DI SINI ---
            ->select(
                'buyer_profiles.id',
                'buyer_profiles.buyer_real_name',
                'buyer_profiles.buyer_username',      // PASTIKAN KOLOM INI ADA DI SELECT
                'buyer_profiles.address_identifier',
                DB::raw('MAX(orders.address_full) as address_full'),
                DB::raw('COUNT(orders.id) as frequency'),
                DB::raw('SUM(orders.total_price) as monetary'),
                DB::raw('MAX(orders.created_at) as recency_date')
            )
            ->groupBy(
                'buyer_profiles.id',
                'buyer_profiles.buyer_real_name',
                'buyer_profiles.buyer_username',      // PASTIKAN KOLOM INI ADA DI GROUP BY
                'buyer_profiles.address_identifier'
            )
            // --- AKHIR PERBAIKAN ---
            ->get();

        if ($profilesWithData->isEmpty()) return collect();

        // Query untuk siklus pembelian (tidak berubah)
        $customerOrderDates = Order::where('user_id', auth()->id())
            ->whereIn('buyer_username', $profilesWithData->pluck('buyer_username')->unique())
            ->select('buyer_username', DB::raw('sha1(trim(address_full)) as address_identifier'), 'created_at')
            ->orderBy('created_at', 'asc')
            ->get()->groupBy(fn($order) => $order->buyer_username . '|' . $order->address_identifier);
        
        // Helper siklus pembelian dengan abs() (tidak berubah)
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
        
        // Hitung batas kuartil
        // Untuk Recency, kita hitung selisih hari dari sekarang
        $recencyDays = $profilesWithData->pluck('recency_date')->map(fn($date) => Carbon::parse($date)->diffInDays(now()));
        $recencyBoundaries = $getQuartiles($recencyDays);
        $frequencyBoundaries = $getQuartiles($profilesWithData->pluck('frequency'));
        $monetaryBoundaries = $getQuartiles($profilesWithData->pluck('monetary'));

        // Proses mapping dengan logika skor 1-4
        return $profilesWithData->map(function ($profile) use ($recencyBoundaries, $frequencyBoundaries, $monetaryBoundaries, $customerOrderDates, $getPurchaseCycle) {

            // --- FUNGSI SKOR BARU (1-4) ---
            // Untuk Recency: semakin kecil nilainya (hari), semakin tinggi skornya
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
            
            // Panggil helper segmentasi BARU
            $segmentDetails = $this->getSegmentDetails($rScore, $fScore, $mScore);
            
            $customerKey = $profile->buyer_username . '|' . $profile->address_identifier;
            $orderDatesForCycle = $customerOrderDates->get($customerKey, collect())->pluck('created_at');
            $purchaseCycle = $getPurchaseCycle($orderDatesForCycle);
            
            return (object) [
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
}

    public function getDashboardStatsProperty()
    {
        $allSegments = $this->getProcessedCustomerData();
        if ($allSegments->isEmpty()) { return ['segmentDistribution' => [], 'totalCustomers' => 0, 'averageSpend' => 0, 'averageCycle' => 0]; }
        $totalCustomers = $allSegments->count();
        $averageSpend = $totalCustomers > 0 ? $allSegments->avg('total_spend') : 0;
        $averageCycle = $allSegments->whereNotNull('purchase_cycle_days')->avg('purchase_cycle_days');
        $segmentDistribution = $allSegments->groupBy('segment_label')->map(fn($group) => round(($group->count() / $totalCustomers) * 100, 1))->sortDesc()->toArray();
        return ['segmentDistribution' => $segmentDistribution, 'totalCustomers' => $totalCustomers, 'averageSpend' => $averageSpend, 'averageCycle' => $averageCycle];
    }

    public function getSegmentedCustomersProperty()
    {
        $allSegments = $this->getProcessedCustomerData();
        $filtered = $this->filterSegment !== 'all' ? $allSegments->filter(fn($s) => $s->segment_label === $this->filterSegment) : $allSegments;
        $sorted = $this->sortBy === 'last_order' ? $filtered->sortBy('last_order_raw', SORT_REGULAR, $this->sortDir === 'desc') : $filtered->sortBy($this->sortBy, SORT_REGULAR, $this->sortDir === 'desc');
        return new LengthAwarePaginator($sorted->forPage($this->getPage(), 50), $sorted->count(), 50, $this->getPage(), ['path' => request()->url(), 'query' => request()->query()]);
    }
    
    public function showCustomerDetails(int $buyerProfileId): void
    {
        $allSegments = $this->getProcessedCustomerData();
        $this->selectedCustomer = $allSegments->firstWhere('id', $buyerProfileId);
        if (!$this->selectedCustomer) return;

        $profile = BuyerProfile::find($buyerProfileId);
        $this->customerOrders = Order::where('user_id', auth()->id())->where('buyer_username', $profile->buyer_username)->where(DB::raw('sha1(trim(address_full))'), $profile->address_identifier)->orderBy('created_at', 'desc')->limit(10)->get();
        
        // --- PERBAIKAN QUERY PRODUK FAVORIT ---
        $this->frequentItems = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', auth()->id())
            ->where('orders.buyer_username', $profile->buyer_username)
            ->where(DB::raw('sha1(trim(orders.address_full))'), $profile->address_identifier)
            ->select(
                'orders.buyer_username', // TAMBAHKAN INI
                'order_items.product_name',
                'order_items.variant_sku', // TAMBAHKAN INI
                DB::raw('SUM(order_items.quantity) as total_quantity')
            )
            ->groupBy(
                'orders.buyer_username', // TAMBAHKAN INI
                'order_items.product_name',
                'order_items.variant_sku' // TAMBAHKAN INI
            )
            ->orderBy('total_quantity', 'desc')
            ->limit(5)
            ->get();
        $this->showDetailsModal = true;
    }

    public function closeModal(): void
    {
        $this->showDetailsModal = false;
        $this->reset('selectedCustomer', 'customerOrders', 'frequentItems');
    }

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
        $segment = 'Lainnya'; // Atau bisa juga 'Bronze', 'Tertidur', dll.
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
}; ?>

<style>
    /* Menambahkan warna custom baru. Anda bisa memindahkannya ke tailwind.config.js */
    .segment-emerald { --tw-bg-opacity: 1; background-color: rgb(16 185 129 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
    .segment-blue { --tw-bg-opacity: 1; background-color: rgb(59 130 246 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
    .segment-purple { --tw-bg-opacity: 1; background-color: rgb(139 92 246 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
    .segment-yellow { --tw-bg-opacity: 1; background-color: rgb(234 179 8 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
    .segment-teal { --tw-bg-opacity: 1; background-color: rgb(20 184 166 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
    .segment-lime { --tw-bg-opacity: 1; background-color: rgb(132 204 22 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
    .segment-orange { --tw-bg-opacity: 1; background-color: rgb(249 115 22 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
    .segment-red { --tw-bg-opacity: 1; background-color: rgb(239 68 68 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
    .segment-gray { --tw-bg-opacity: 1; background-color: rgb(107 114 128 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
</style>

<x-layouts.app>
    @volt('customer-segmentation')
        <div x-data="{ showModal: @entangle('showDetailsModal').live }">
            <x-app.container>
                <x-app.heading 
                    title="Segmentasi Pelanggan (RFM Lanjutan)"
                    description="Kelompokkan pelanggan berdasarkan riwayat pembelian untuk strategi pemasaran yang lebih efektif."
                    :border="true" />
                
                <!-- BAGIAN BARU: DASHBOARD RINGKASAN -->
                @if(!empty($this->dashboardStats) && $this->dashboardStats['totalCustomers'] > 0)
                <div class="mt-6">
                    <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-gray-100">Ringkasan Pelanggan</h3>
                    <div class="grid grid-cols-2 gap-5 mt-2 sm:grid-cols-4">
                        <div class="px-4 py-5 overflow-hidden bg-white rounded-lg shadow dark:bg-gray-800 sm:p-6">
                            <dt class="text-sm font-medium text-gray-500 truncate dark:text-gray-400">Total Pelanggan</dt>
                            <dd class="mt-1 text-3xl font-semibold text-gray-900 dark:text-white">{{ $this->dashboardStats['totalCustomers'] }}</dd>
                        </div>
                        <div class="px-4 py-5 overflow-hidden bg-white rounded-lg shadow dark:bg-gray-800 sm:p-6">
                            <dt class="text-sm font-medium text-gray-500 truncate dark:text-gray-400">Rata-rata Belanja</dt>
                            <dd class="mt-1 text-3xl font-semibold text-gray-900 dark:text-white">Rp {{ number_format($this->dashboardStats['averageSpend'], 0, ',', '.') }}</dd>
                        </div>
                        <div class="px-4 py-5 overflow-hidden bg-white rounded-lg shadow dark:bg-gray-800 sm:p-6">
                            <dt class="text-sm font-medium text-gray-500 truncate dark:text-gray-400">Siklus Beli Rata-rata</dt>
                            <dd class="mt-1 text-3xl font-semibold text-gray-900 dark:text-white">~{{ round($this->dashboardStats['averageCycle']) }} <span class="text-xl">hari</span></dd>
                        </div>
                        <div class="px-4 py-5 overflow-hidden bg-white rounded-lg shadow dark:bg-gray-800 sm:p-6">
                            <dt class="text-sm font-medium text-gray-500 truncate dark:text-gray-400">Distribusi Segmen</dt>
                            <dd class="mt-2 space-y-1">
                                @foreach($this->dashboardStats['segmentDistribution'] as $label => $percentage)
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $label }}</span>
                                        <span class="font-semibold text-gray-900 dark:text-white">{{ $percentage }}%</span>
                                    </div>
                                @endforeach
                            </dd>
                        </div>
                    </div>
                </div>
                @endif                
                <!-- Filter -->
                <div class="mt-8">
                    <label for="segment-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Filter berdasarkan Segmen:</label>
                        <select id="segment-filter" wire:model.live="filterSegment" class="mt-1 block w-full md:w-1/3 rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
        <option value="all">Semua Segmen</option>
        {{-- DAFTAR SEGMEN BARU SESUAI LOGIKA RFM YANG DISEDERHANAKAN --}}
        <option value="Juara">Juara</option>
        <option value="Pelanggan Setia">Pelanggan Setia</option>
        <option value="Potensial">Potensial</option>
        <option value="Pelanggan Baru">Pelanggan Baru</option>
        <option value="Butuh Perhatian">Butuh Perhatian</option>
        <option value="Lainnya">Lainnya</option>
    </select>
                </div>

                <!-- Tabel Segmentasi -->
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Pelanggan</th>
                                <th scope="col" wire:click="setSortBy('last_order')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer">Pesanan Terakhir</th>
                                <th scope="col" wire:click="setSortBy('frequency')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer">Total Pesanan</th>
                                <th scope="col" wire:click="setSortBy('total_spend')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer">Total Belanja</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Segmen</th>
                                <th scope="col" wire:click="setSortBy('purchase_cycle_days')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer">Siklus Beli</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($this->segmentedCustomers as $customer)
                                <tr wire:click="showCustomerDetails({{ $customer->id }})" class="hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $customer->name }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $customer->last_order }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 text-center">{{ $customer->frequency }}x</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">Rp {{ number_format($customer->total_spend, 0, ',', '.') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="px-3 py-1 text-xs font-semibold leading-5 rounded-full segment-{{ $customer->segment_color }}">
                                            {{ $customer->segment_label }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        @if($customer->purchase_cycle_days)
                                            ~{{ $customer->purchase_cycle_days }} hari
                                        @else
                                            <span class="italic text-gray-400">N/A</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">
                                        Tidak ada data pelanggan yang cocok dengan filter.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    
                    <!-- BAGIAN BARU: LINK PAGINASI -->
                    <div class="px-4 py-3 mt-4">
                        {{ $this->segmentedCustomers->links() }}
                    </div>
                </div>

            </x-app.container>

            <!-- Modal Detail Pelanggan -->
            @if($selectedCustomer)
            <div x-show="showModal" x-transition.opacity.duration.300ms class="fixed inset-0 z-50 overflow-y-auto bg-gray-500 bg-opacity-75" aria-labelledby="modal-title" role="dialog" aria-modal="true" @keydown.escape.window="showModal = false">
                <div class="flex items-end justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                    <div x-show="showModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" @click.away="showModal = false" class="relative inline-block w-full max-w-4xl px-4 pt-5 pb-4 overflow-hidden text-left align-bottom transition-all transform bg-white rounded-lg shadow-xl dark:bg-gray-800 sm:my-8 sm:align-middle sm:p-6">
                        <div class="absolute top-0 right-0 hidden pt-4 pr-4 sm:block">
                            <button @click="showModal = false" type="button" class="text-gray-400 bg-white rounded-md dark:bg-gray-800 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-900">
                                <span class="sr-only">Close</span>
                                <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                        <div>
                            <div class="sm:flex sm:items-start">
                                <div class="w-full">
                                    <div class="flex items-start justify-between">
                                        <div>
<h3 class="text-2xl font-bold leading-6 text-gray-900 dark:text-white" id="modal-title">{{ $selectedCustomer->name }}</h3>
<p class="text-sm font-mono text-gray-500 dark:text-gray-400">{{ $selectedCustomer->username }}</p>
<p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $selectedCustomer->address }}</p>
                                        </div>
                                        <span class="flex-shrink-0 px-3 py-1 ml-4 text-sm font-semibold leading-5 rounded-full segment-{{ $selectedCustomer->segment_color }}">{{ $selectedCustomer->segment_label }}</span>
                                    </div>
<div class="p-4 mt-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
    <h4 class="font-semibold text-gray-800 dark:text-gray-200">Analisis & Strategi</h4>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $selectedCustomer->segment_description }}</p>
    <p class="mt-2 text-sm font-medium text-indigo-600 dark:text-indigo-400"><span class="font-bold">Saran Aksi:</span> {{ $selectedCustomer->segment_action }}</p>

    <!-- BAGIAN BARU: PREDIKSI PEMBELIAN YANG CERDAS -->
@if($selectedCustomer->purchase_cycle_days && $selectedCustomer->purchase_cycle_days > 0)
@php
    $today = \Carbon\Carbon::today();
    $lastOrderDate = \Carbon\Carbon::parse($selectedCustomer->last_order_raw);
    $nextPurchaseDate = $lastOrderDate->copy()->addDays($selectedCustomer->purchase_cycle_days);
    $totalCycleDays = $selectedCustomer->purchase_cycle_days;
    $daysPassed = $lastOrderDate->diffInDays($today);
    
    $progressPercentage = ($daysPassed / $totalCycleDays) * 100;
    if ($progressPercentage > 100) {
        $progressPercentage = 100;
    }

    $statusText = '';
    $statusBarColor = 'bg-blue-500'; 
    $statusTextColor = 'text-blue-800 dark:text-blue-200';
    $statusIcon = '<svg class="w-5 h-5 mr-1.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-13a.75.75 0 00-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 000-1.5h-3.25V5z" clip-rule="evenodd" /></svg>';

    if ($nextPurchaseDate->isFuture()) {
        // PERBAIKAN DI SINI: Bulatkan jumlah hari
        $daysRemaining = (int) round($today->diffInDays($nextPurchaseDate, false));
        if ($daysRemaining <= 7) {
            $statusBarColor = 'bg-yellow-500';
            $statusTextColor = 'text-yellow-800 dark:text-yellow-200';
            $statusText = "Waktunya bersiap! Prediksi dalam ~{$daysRemaining} hari.";
        } else {
            $statusText = "Prediksi pembelian dalam ~{$daysRemaining} hari.";
        }
    } elseif ($nextPurchaseDate->isPast()) {
        // PERBAIKAN DI SINI: Bulatkan jumlah hari
        $daysOverdue = (int) round($today->diffInDays($nextPurchaseDate));
        $statusBarColor = 'bg-red-500';
        $statusTextColor = 'text-red-800 dark:text-red-200';
        $statusText = "TERLAMBAT! Seharusnya membeli ~{$daysOverdue} hari yang lalu.";
        $statusIcon = '<svg class="w-5 h-5 mr-1.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>';
    } else {
        $statusBarColor = 'bg-green-500';
        $statusTextColor = 'text-green-800 dark:text-green-200';
        $statusText = "PELUANG EMAS! Prediksi pembelian adalah HARI INI.";
        $statusIcon = '<svg class="w-5 h-5 mr-1.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>';
    }
@endphp

    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-600">
        <h5 class="font-semibold text-gray-800 dark:text-gray-200">Siklus & Prediksi Pembelian</h5>
        
        <!-- Timeline Visual -->
        <div class="mt-2 space-y-2">
            <div class="flex justify-between text-xs font-medium text-gray-500 dark:text-gray-400">
                <span>Terakhir Beli: {{ $lastOrderDate->format('d M Y') }}</span>
                <span>Prediksi: {{ $nextPurchaseDate->format('d M Y') }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                <div class="{{ $statusBarColor }} h-2.5 rounded-full" style="width: {{ $progressPercentage }}%"></div>
            </div>
            <div class="flex items-center text-sm font-semibold {{ $statusTextColor }}">
                {!! $statusIcon !!}
                <span>{{ $statusText }}</span>
            </div>
        </div>
    </div>
@endif
</div>
<div class="grid grid-cols-1 gap-6 mt-6 md:grid-cols-2">
    <div>
        <h4 class="text-lg font-medium text-gray-900 dark:text-white">Riwayat Pesanan Terbaru</h4>
        <div class="mt-2 overflow-y-auto max-h-64 border rounded-md dark:border-gray-600">
            <!-- Riwayat pesanan tidak berubah -->
            <ul class="divide-y divide-gray-200 dark:divide-gray-600">
                @forelse($customerOrders as $order)
                <li class="px-4 py-3"><div class="flex items-center justify-between"><p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">Order #{{ $order->id }}</p><p class="text-sm text-gray-500 dark:text-gray-400">{{ $order->created_at->format('d M Y') }}</p></div><p class="text-sm text-gray-600 dark:text-gray-300">Total: Rp {{ number_format($order->total_price, 0, ',', '.') }}</p></li>
                @empty
                <li class="px-4 py-3 text-sm text-gray-500">Tidak ada riwayat pesanan.</li>
                @endforelse
            </ul>
        </div>
    </div>
    
    <!-- GANTI SELURUH BLOK "PRODUK FAVORIT" INI -->
    <div>
        <h4 class="text-lg font-medium text-gray-900 dark:text-white">Produk Favorit</h4>
        <div class="mt-2 border rounded-md dark:border-gray-600">
            <ul class="divide-y divide-gray-200 dark:divide-gray-600">
                @forelse($frequentItems as $item)
                <li class="px-4 py-3">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-200" title="{{ $item->product_name }}">
                            {{ \Illuminate\Support\Str::words($item->product_name, 10, '...') }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 ml-2 flex-shrink-0">Dibeli {{ $item->total_quantity }}x</p>
                    </div>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                        SKU: <span class="font-mono">{{ $item->variant_sku ?: 'N/A' }}</span>
                    </p>
                </li>
                @empty
                <li class="px-4 py-3 text-sm text-gray-500">Belum ada data produk.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </div>
    @endvolt
</x-layouts.app>