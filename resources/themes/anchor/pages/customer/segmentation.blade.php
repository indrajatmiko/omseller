<?php

use function Laravel\Folio\{middleware, name};
use App\Models\BuyerProfile;
use Livewire\Volt\Component;
use Illuminate\Support\Collection;
use Carbon\Carbon;

middleware('auth');
name('customer.segmentation');

new class extends Component {
    public Collection $segments;
    public string $sortBy = 'total_spend';
    public string $sortDir = 'desc';
    public string $filterSegment = 'all';

    public function mount(): void
    {
        $this->loadSegmentationData();
    }

    public function setSortBy(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

public function loadSegmentationData(): void
{
    // 1. Bangun query agregasi secara manual untuk kontrol penuh
    $profilesWithData = BuyerProfile::query()
        ->where('buyer_profiles.user_id', auth()->id())
        // Gunakan JOIN untuk menghubungkan ke tabel orders
        ->join('orders', function ($join) {
            $join->on('buyer_profiles.buyer_username', '=', 'orders.buyer_username')
                 ->where('orders.user_id', '=', auth()->id())
                 ->where(DB::raw('sha1(trim(orders.address_full))'), '=', DB::raw('buyer_profiles.address_identifier'));
        })
        // Pilih kolom yang kita butuhkan dan hitung agregatnya
        ->select(
            'buyer_profiles.buyer_real_name',
            DB::raw('COUNT(orders.id) as frequency'),
            DB::raw('SUM(orders.total_price) as monetary'),
            DB::raw('MAX(orders.created_at) as recency')
        )
        // Kelompokkan hasilnya untuk setiap pelanggan unik
        ->groupBy('buyer_profiles.id', 'buyer_profiles.buyer_real_name')
        ->get();

    if ($profilesWithData->isEmpty()) {
        $this->segments = collect();
        return;
    }

    // --- Sisa logika tidak berubah, karena sekarang datanya sudah benar ---
    
    // 2. Helper manual untuk menghitung Kuantil
    $getQuantiles = function (Collection $collection) {
        $sorted = $collection->sort()->values();
        $count = $sorted->count();
        if ($count < 4) {
            $value = $sorted->last() ?: 1;
            return [$value, $value, $value];
        }
        return [
            $sorted->get(intval($count * 0.25)),
            $sorted->get(intval($count * 0.50)),
            $sorted->get(intval($count * 0.75)),
        ];
    };
    
    $recencyBoundaries = $getQuantiles($profilesWithData->pluck('recency')->map(fn($date) => Carbon::parse($date)->timestamp));
    $frequencyBoundaries = $getQuantiles($profilesWithData->pluck('frequency'));
    $monetaryBoundaries = $getQuantiles($profilesWithData->pluck('monetary'));

    // 3. Map setiap profil untuk memberikan skor dan segmen
    $this->segments = $profilesWithData->map(function ($profile) use ($recencyBoundaries, $frequencyBoundaries, $monetaryBoundaries) {
        $getScore = function ($value, $boundaries) {
            if ($value >= $boundaries[2]) return 5;
            if ($value >= $boundaries[1]) return 4;
            if ($value >= $boundaries[0]) return 3;
            if ($value > 0) return 2;
            return 1;
        };
        
        $rScore = $getScore(Carbon::parse($profile->recency)->timestamp, $recencyBoundaries);
        $fScore = $getScore($profile->frequency, $frequencyBoundaries);
        $mScore = $getScore($profile->monetary, $monetaryBoundaries);

        $rfmScore = (string)$rScore . (string)$fScore;
        $segmentLabel = 'Bronze';
        $segmentColor = 'bronze';

        if (preg_match('/^[4-5][4-5]$/', $rfmScore)) {
            $segmentLabel = 'Juara'; $segmentColor = 'emerald';
        } elseif (preg_match('/^[2-5][3-5]$/', $rfmScore)) {
            $segmentLabel = 'Pelanggan Setia'; $segmentColor = 'blue';
        } elseif (preg_match('/^[3-5][1-2]$/', $rfmScore)) {
            $segmentLabel = 'Potensial'; $segmentColor = 'yellow';
        } elseif (preg_match('/^[1-2][3-5]$/', $rfmScore)) {
            $segmentLabel = 'Butuh Perhatian'; $segmentColor = 'orange';
        } else {
            $segmentLabel = 'Tertidur'; $segmentColor = 'gray';
        }
        
        return (object) [
            'name' => $profile->buyer_real_name,
            'last_order' => Carbon::parse($profile->recency)->diffForHumans(),
            'frequency' => $profile->frequency,
            'total_spend' => $profile->monetary,
            'segment_label' => $segmentLabel,
            'segment_color' => $segmentColor,
        ];
    });
}
    
    // Gunakan computed property untuk menangani sorting dan filtering
    public function getSegmentedCustomersProperty()
    {
        $sorted = $this->segments->sortBy($this->sortBy, SORT_REGULAR, $this->sortDir === 'desc');
        
        if ($this->filterSegment !== 'all') {
            return $sorted->filter(fn($s) => $s->segment_label === $this->filterSegment);
        }

        return $sorted;
    }
}; ?>

<style>
    /* Menambahkan warna custom untuk segmen, bisa juga dimasukkan ke tailwind.config.js */
    .segment-emerald { --tw-bg-opacity: 1; background-color: rgb(16 185 129 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
    .segment-blue { --tw-bg-opacity: 1; background-color: rgb(59 130 246 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
    .segment-yellow { --tw-bg-opacity: 1; background-color: rgb(234 179 8 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
    .segment-orange { --tw-bg-opacity: 1; background-color: rgb(249 115 22 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
    .segment-gray { --tw-bg-opacity: 1; background-color: rgb(107 114 128 / var(--tw-bg-opacity)); --tw-text-opacity: 1; color: rgb(255 255 255 / var(--tw-text-opacity)); }
</style>

<x-layouts.app>
    @volt('customer-segmentation')
        <div>
            <x-app.container>
                <x-app.heading 
                    title="Segmentasi Pelanggan (RFM)"
                    description="Kelompokkan pelanggan berdasarkan riwayat pembelian untuk strategi pemasaran yang lebih efektif."
                    :border="true" />
                
                <!-- Filter -->
                <div class="mt-6">
                    <label for="segment-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Filter berdasarkan Segmen:</label>
                    <select id="segment-filter" wire:model.live="filterSegment" class="mt-1 block w-full md:w-1/3 rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                        <option value="all">Semua Segmen</option>
                        <option value="Juara">Juara</option>
                        <option value="Pelanggan Setia">Pelanggan Setia</option>
                        <option value="Potensial">Potensial</option>
                        <option value="Perlu Perhatian">Perlu Perhatian</option>
                        <option value="Tertidur">Tertidur</option>
                    </select>
                </div>

                <!-- Tabel Segmentasi -->
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Pelanggan</th>
                                <th scope="col" wire:click="setSortBy('last_order')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer">Pesanan Terakhir (Recency)</th>
                                <th scope="col" wire:click="setSortBy('frequency')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer">Total Pesanan (Frequency)</th>
                                <th scope="col" wire:click="setSortBy('total_spend')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer">Total Belanja (Monetary)</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Segmen</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($this->segmentedCustomers as $customer)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">{{ $customer->name }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $customer->last_order }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $customer->frequency }}x</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">Rp {{ number_format($customer->total_spend, 0, ',', '.') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="px-3 py-1 text-xs font-semibold leading-5 rounded-full segment-{{ $customer->segment_color }}">
                                            {{ $customer->segment_label }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-500">
                                        Tidak ada data pelanggan yang cocok dengan filter.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>