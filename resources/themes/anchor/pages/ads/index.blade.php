<?php

use function Laravel\Folio\{middleware, name};
use App\Models\CampaignReport;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection as EloquentCollection;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Livewire\Attributes\Url; // <-- LANGKAH 1: Import atribut Url

middleware('auth');
name('ads.index');

new class extends Component {

    public array $groupedCampaigns = [];
    public $key;

    // --- PERBAIKAN: Tandai properti ini untuk dimasukkan ke dalam URL ---
    #[Url(as: 'bulan', keep: true)] // `as` untuk alias di URL, `keep` agar tidak hilang saat navigasi
    public $selectedMonth;
    
    public $availableMonths;

    public function mount(): void
    {
        $this->key = uniqid();
        $this->availableMonths = $this->getAvailableMonths();
        
        // Hanya set default jika $selectedMonth belum ada dari URL
        if (!$this->selectedMonth) {
            $this->selectedMonth = $this->availableMonths->keys()->last() ?? now()->format('Y-m');
        }
    }
    
// Tambahkan fungsi ini
public function updatedSelectedMonth()
{
    $this->key = uniqid(); // Ganti key setiap kali bulan berubah
}

    private function getAvailableMonths(): \Illuminate\Support\Collection
    {
        $currentYear = now()->year;
        return CampaignReport::where('user_id', auth()->id())
            ->whereYear('scrape_date', $currentYear)
            ->selectRaw("DATE_FORMAT(scrape_date, '%Y-%m') as month_value, DATE_FORMAT(scrape_date, '%M %Y') as month_name")
            ->distinct()
            ->orderBy('month_value', 'asc')
            ->get()
            ->pluck('month_name', 'month_value');
    }
    
    private function categorizeCampaign(object $campaign): string
    {
        $mode = $campaign->mode_bidding;
        $placement = $campaign->penempatan_iklan;

        if (str_starts_with($mode, 'GMV')) return 'gmv';
        if ($mode === 'Manual') {
            if ($placement === 'Semua') return 'manual_semua';
            if ($placement === 'Halaman Pencarian') return 'manual_pencarian';
            if ($placement === 'Halaman Rekomendasi') return 'manual_rekomendasi';
        }
        return 'lainnya';
    }

    public function with(): array
    {
        $userId = auth()->id();
        
        // --- PERBAIKAN: Validasi $selectedMonth untuk mencegah error ---
        // Jika karena suatu hal $selectedMonth menjadi tidak valid, gunakan bulan sekarang.
        try {
            $date = Carbon::parse($this->selectedMonth);
        } catch (\Exception $e) {
            $this->selectedMonth = now()->format('Y-m');
            $date = now();
        }
        $year = $date->year;
        $month = $date->month;


        $aggregatedCampaigns = CampaignReport::query()
            ->select(
                'campaign_id',
                DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(nama_produk ORDER BY scrape_date DESC), ",", 1) as nama_produk'),
                DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(modal ORDER BY scrape_date DESC), ",", 1) as modal'),
                DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(mode_bidding ORDER BY scrape_date DESC), ",", 1) as mode_bidding'),
                DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(penempatan_iklan ORDER BY scrape_date DESC), ",", 1) as penempatan_iklan'),
                DB::raw('SUM(CAST(REPLACE(REPLACE(REPLACE(biaya, "Rp", ""), ".", ""), "k", "00") AS UNSIGNED)) as total_biaya'),
                DB::raw('SUM(CAST(REPLACE(REPLACE(REPLACE(omzet_iklan, "Rp", ""), ".", ""), "k", "00") AS UNSIGNED)) as total_omzet'),
                DB::raw('SUM(produk_terjual) as total_produk_terjual')
            )
            ->where('user_id', $userId)
            ->whereYear('scrape_date', $year)
            ->whereMonth('scrape_date', $month)
            ->groupBy('campaign_id')
            ->get()
            ->map(function ($campaign) {
                $campaign->roas = $campaign->total_biaya > 0 ? $campaign->total_omzet / $campaign->total_biaya : 0;
                return $campaign;
            })
            ->sortByDesc('total_omzet');

        $groups = [
            'gmv' => new EloquentCollection(),
            'manual_pencarian' => new EloquentCollection(),
            'manual_rekomendasi' => new EloquentCollection(),
            'manual_semua' => new EloquentCollection(),
            'lainnya' => new EloquentCollection(),
        ];
        
        foreach ($aggregatedCampaigns as $campaign) {
            $category = $this->categorizeCampaign($campaign);
            $groups[$category]->push($campaign);
        }

        $this->groupedCampaigns = $groups;
        
        $counts = [
            'gmv' => $groups['gmv']->count(),
            'manual_pencarian' => $groups['manual_pencarian']->count(),
            'manual_rekomendasi' => $groups['manual_rekomendasi']->count(),
            'manual_semua' => $groups['manual_semua']->count(),
        ];

        return [
            'groupedCampaigns' => $this->groupedCampaigns,
            'counts' => $counts,
        ];
    }
}; ?>

{{-- Tidak ada perubahan di bagian Blade/HTML --}}
<x-layouts.app>
    @volt('ads-list')
    <div wire:key="{{ $key }}">
    <x-app.container>
       {{-- ... sisa kode HTML sama persis ... --}}
       <div class="flex flex-col md:flex-row md:items-center md:justify-between">
            <x-app.heading title="Manajemen Iklan" description="Analisis performa bulanan setiap kampanye." />
            <div class="mt-4 md:mt-0">
                <x-select-input wire:model.live="selectedMonth" class="text-sm w-full md:w-auto">
                    @if($availableMonths->isEmpty()) <option value="">Tidak ada data</option>
                    @else @foreach($availableMonths as $value => $name) <option value="{{ $value }}">{{ $name }}</option> @endforeach
                    @endif
                </x-select-input>
            </div>
        </div>
        
        <div x-data="{ 
                tab: 'gmv',
                tabs: [
                    { id: 'gmv', label: 'Otomatis (GMV)', count: @js($counts['gmv']) },
                    { id: 'manual_pencarian', label: 'Manual - Pencarian', count: @js($counts['manual_pencarian']) },
                    { id: 'manual_rekomendasi', label: 'Manual - Rekomendasi', count: @js($counts['manual_rekomendasi']) },
                    { id: 'manual_semua', label: 'Manual - Semua', count: @js($counts['manual_semua']) },
                ]
            }" 
             class="mt-6" wire:key="tab-container-{{ $selectedMonth }}">
            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav class="-mb-px flex space-x-4 overflow-x-auto" aria-label="Tabs">
                    <template x-for="item in tabs" :key="item.id">
                        <button @click="tab = item.id" :class="tab === item.id ? 'border-indigo-500 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:border-gray-600'" class="whitespace-nowrap flex py-3 px-1 border-b-2 font-medium text-sm transition-colors duration-150">
                            <span x-text="item.label"></span>
                            <span x-text="item.count" :class="tab === item.id ? 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/50 dark:text-indigo-300' : 'bg-gray-100 text-gray-900 dark:bg-gray-700 dark:text-gray-300'" class="ml-2 hidden rounded-full py-0.5 px-2 text-xs font-medium md:inline-block"></span>
                        </button>
                    </template>
                </nav>
            </div>

            <div class="mt-6">
                <template x-for="item in tabs" :key="item.id">
                    <div x-show="tab === item.id">
                        @foreach ($groupedCampaigns as $key => $campaigns)
                            <div x-show="tab === '{{ $key }}'" class="space-y-3">
                                @forelse ($campaigns as $campaign)
                                    @php
                                        $namaProdukParts = explode(' ', $campaign->nama_produk);
                                        $jumlahKata = count($namaProdukParts);
                                        $namaTampil = $campaign->nama_produk;
                                        if ($jumlahKata > 8) {
                                            $enamKataDepan = implode(' ', array_slice($namaProdukParts, 0, 6));
                                            $duaKataBelakang = implode(' ', array_slice($namaProdukParts, -2, 2));
                                            $namaTampil = $enamKataDepan . '... ' . $duaKataBelakang;
                                        }
                                    @endphp
                                    <a href="{{ route('ads.show', ['campaign_id' => $campaign->campaign_id]) }}" class="block bg-white dark:bg-gray-800 shadow-sm rounded-lg overflow-hidden hover:shadow-md transition-shadow duration-200">
                                        <div class="flex flex-col md:flex-row">
                                            <div class="p-4 flex-grow flex flex-col justify-between">
                                                <div>
                                                    <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm md:text-base">{{ $namaTampil }}</p>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">ID: {{ $campaign->campaign_id }}</p>
                                                </div>
                                                <div class="mt-2">
                                                    <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-700 dark:text-gray-300">
                                                        {{ str_replace("\n", ' ', $campaign->modal) }}
                                                    </span>
                                                </div>
                                            </div>

                                            <div class="bg-gray-50 dark:bg-gray-800/50 p-4 border-t md:border-t-0 md:border-l border-gray-200 grid grid-cols-2 md:grid-cols-2 gap-x-4 gap-y-3 text-sm md:w-80 flex-shrink-0">
                                                <div><p class="text-gray-500 dark:text-gray-400">Omzet Bulan Ini</p><p class="font-bold text-base text-green-600 dark:text-green-400">Rp {{ number_format($campaign->total_omzet, 0, ',', '.') }}</p></div>
                                                <div><p class="text-gray-500 dark:text-gray-400">ROAS Bulan Ini</p><p class="font-bold text-base text-blue-600 dark:text-blue-400">{{ number_format($campaign->roas, 2, ',', '.') }}</p></div>
                                                <div><p class="text-gray-500 dark:text-gray-400">Biaya Bulan Ini</p><p class="font-medium text-red-600 dark:text-red-400">Rp {{ number_format($campaign->total_biaya, 0, ',', '.') }}</p></div>
                                                <div><p class="text-gray-500 dark:text-gray-400">Terjual Bulan Ini</p><p class="font-bold text-base text-purple-600 dark:text-purple-400">{{ number_format($campaign->total_produk_terjual) }}</p></div>
                                            </div>
                                        </div>
                                    </a>
                                @empty
                                    <div class="text-center py-12 px-4 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
                                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" /></svg>
                                        <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">Tidak Ada Kampanye</h3>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Tidak ada data kampanye untuk bulan yang dipilih.</p>
                                    </div>
                                @endforelse
                            </div>
                        @endforeach
                    </div>
                </template>
            </div>
        </div>
    </x-app.container>
    </div>
    @endvolt
</x-layouts.app>