<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Product;
use App\Models\CampaignReport;
use App\Models\GmvPerformanceDetail;
use App\Models\KeywordPerformance;
use App\Models\RecommendationPerformance;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Collection;

middleware('auth');
name('reports.campaigns');

new class extends Component {
    // Properti filter
    public $selectedYear;
    public $selectedMonth;

    // Properti kontrol UI
    public $selectedCampaignId = null;
    public ?CampaignReport $selectedCampaignInfo = null;
    public ?string $activeTab = null;

    // Properti data
    public array $campaignsByGroup = [];
    public array $dailyPerformance = [];
    public array $monthlySummary = [];
    public array $costInfo = [];

    public function mount(): void
    {
        $this->selectedYear = now()->year;
        $this->selectedMonth = now()->month;
    }

    public function showDetail($campaignId): void
    {
        $this->selectedCampaignId = $campaignId;
        $this->selectedCampaignInfo = CampaignReport::where('user_id', auth()->id())
            ->where('campaign_id', $campaignId)
            ->latest('scrape_date')
            ->firstOrFail();
        
        $this->generateDetailData();
    }

    public function backToList(): void
    {
        $this->selectedCampaignId = null;
        $this->selectedCampaignInfo = null;
        $this->dailyPerformance = [];
        $this->monthlySummary = [];
        $this->campaignsByGroup = [];
        $this->activeTab = null;
    }

    public function updated($property): void
    {
        if (in_array($property, ['selectedYear', 'selectedMonth'])) {
            $this->backToList();
        }
    }

private function generateDetailData(): void
    {
        if (!$this->selectedCampaignId) return;

        $userId = auth()->id();
        $startDate = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();
        
        // [REVISI TOTAL] Logika baru untuk menentukan HPP berdasarkan jumlah varian
        $costPrice = 0;
        // Inisialisasi info HPP dengan nilai default
        $this->costInfo = [
            'sku_type' => 'Tidak Diketahui',
            'display_price' => 0,
            'note' => 'HPP (Harga Pokok Penjualan) tidak dapat ditentukan karena produk tidak ditemukan di database Anda.'
        ];

        $shopeeProductId = $this->selectedCampaignInfo->gambar_url; 
        if ($shopeeProductId) {
            $product = \App\Models\Product::where('shopee_product_id', $shopeeProductId)
                              ->where('user_id', $userId)
                              ->with('variants') // Eager load variants untuk efisiensi
                              ->first();
            
            if ($product && $product->variants->isNotEmpty()) {
                // Ambil varian yang memiliki HPP > 0 untuk dihitung
                $variantsWithCost = $product->variants->where('cost_price', '>', 0);
                $variantCount = $product->variants->count();

                if ($variantCount === 1) {
                    // KASUS 1: Produk Tunggal (hanya punya 1 varian)
                    $costPrice = $variantsWithCost->first()->cost_price ?? 0;
                    $this->costInfo = [
                        'sku_type' => 'Tunggal',
                        'display_price' => $costPrice,
                        'note' => 'Estimasi Profit dihitung menggunakan harga modal tetap dari satu-satunya SKU produk ini.'
                    ];
                } else {
                    // KASUS 2: Produk Kombinasi (punya > 1 varian)
                    $costPrice = $variantsWithCost->avg('cost_price') ?? 0;
                    $this->costInfo = [
                        'sku_type' => 'Kombinasi',
                        'display_price' => $costPrice,
                        'note' => 'Produk ini memiliki beberapa varian. Estimasi Profit dihitung menggunakan <strong> modal rata-rata</strong> dari semua varian yang memiliki HPP.'
                    ];
                }

                // Jika setelah semua pengecekan, tidak ada HPP yang bisa ditemukan
                if ($variantsWithCost->isEmpty()) {
                    $this->costInfo['note'] = 'HPP tidak dapat ditentukan karena tidak ada varian untuk produk ini yang memiliki harga modal di database.';
                }
            }
        }

        // --- Logika selanjutnya menggunakan $costPrice yang sudah ditentukan ---
        $reportsInMonth = CampaignReport::where('user_id', $userId)->where('campaign_id', $this->selectedCampaignId)->whereBetween('scrape_date', [$startDate, $endDate])->with(['gmvPerformanceDetails', 'keywordPerformances', 'recommendationPerformances'])->orderBy('scrape_date')->get();
        if ($reportsInMonth->isEmpty()) { $this->dailyPerformance = []; $this->monthlySummary = ['total_profit' => 0, 'total_biaya' => 0, 'total_omzet' => 0, 'total_dilihat' => 0, 'total_klik' => 0, 'total_terjual' => 0]; return; }
        $allPerformances = collect();
        $mode = $this->selectedCampaignInfo->mode_bidding;
        $placement = $this->selectedCampaignInfo->penempatan_iklan;
        if (str_contains($mode, 'GMV')) { $allPerformances = $reportsInMonth->flatMap->gmvPerformanceDetails; } elseif ($mode === 'Manual') { $keywords = $reportsInMonth->flatMap->keywordPerformances; $recommendations = $reportsInMonth->flatMap->recommendationPerformances; if ($placement === 'Semua') $allPerformances = $keywords->merge($recommendations); elseif ($placement === 'Halaman Pencarian') $allPerformances = $keywords; elseif ($placement === 'Halaman Rekomendasi') $allPerformances = $recommendations; }
        $dailyData = $allPerformances->groupBy(fn($detail) => CampaignReport::find($detail->campaign_report_id)->scrape_date->format('Y-m-d'))->map(function ($dayGroup, $date) use ($reportsInMonth, $costPrice) { $parentReport = $reportsInMonth->firstWhere('scrape_date', Carbon::parse($date)); $produkTerjual = $dayGroup->sum('clean_terjual'); $biayaIklan = $dayGroup->sum('clean_biaya'); $omzet = $dayGroup->sum('clean_omzet'); $totalCogs = $produkTerjual * $costPrice; $profit = $omzet - ($totalCogs + $biayaIklan); return ['modal' => $parentReport ? $parentReport->clean_modal_display : 'Rp 0', 'modal_numeric' => $parentReport ? $parentReport->modal_numeric_value : 0, 'profit' => $profit, 'dilihat' => $dayGroup->sum('clean_dilihat'), 'klik' => $dayGroup->sum('clean_klik'), 'biaya' => $biayaIklan, 'omzet' => $omzet, 'terjual' => $produkTerjual,]; })->sortBy(fn($val, $key) => $key);
        $processedDailyData = []; $values = $dailyData->values()->all(); $dates = $dailyData->keys()->all(); for ($i = 0; $i < count($values); $i++) { $currentModal = $values[$i]['modal_numeric']; $values[$i]['modal_changed'] = ($i > 0 && $currentModal !== $values[$i - 1]['modal_numeric']); }
        $this->dailyPerformance = count($dates) > 0 ? array_combine($dates, $values) : [];
        $this->monthlySummary = ['total_profit' => $dailyData->sum('profit'), 'total_biaya' => $dailyData->sum('biaya'), 'total_omzet' => $dailyData->sum('omzet'), 'total_dilihat' => $dailyData->sum('dilihat'), 'total_klik' => $dailyData->sum('klik'), 'total_terjual' => $dailyData->sum('terjual'),];
    }

    public function with(): array
    {
        $userId = auth()->id();
        
        if (!$this->selectedCampaignId) {
            $startDate = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
            $endDate = $startDate->copy()->endOfMonth();
            
            // [REVISI] Logika pengambilan data untuk tampilan daftar dengan KPI
            $reportIds = CampaignReport::where('user_id', $userId)
                ->whereBetween('scrape_date', [$startDate, $endDate])
                ->pluck('id');

            // Ambil semua data performa yang relevan dalam satu kali jalan
            $gmvData = GmvPerformanceDetail::whereIn('campaign_report_id', $reportIds)->get();
            $keywordData = KeywordPerformance::whereIn('campaign_report_id', $reportIds)->get();
            $recoData = RecommendationPerformance::whereIn('campaign_report_id', $reportIds)->get();
            $allPerformanceDetails = $gmvData->concat($keywordData)->concat($recoData);

            $performanceByReportId = $allPerformanceDetails->groupBy('campaign_report_id')->map(function ($details) {
                return [
                    'biaya' => $details->sum('clean_biaya'),
                    'omzet' => $details->sum('clean_omzet'),
                ];
            });

            // Ambil data kampanye dan gabungkan dengan KPI yang sudah dihitung
            $campaignsWithKpi = CampaignReport::whereIn('id', $reportIds)
                ->get()
                ->map(function ($report) use ($performanceByReportId) {
                    $performance = $performanceByReportId->get($report->id, ['biaya' => 0, 'omzet' => 0]);
                    $report->biaya = $performance['biaya'];
                    $report->omzet = $performance['omzet'];
                    return $report;
                })
                ->groupBy('campaign_id')
                ->map(function ($group) {
                    $latest = $group->sortByDesc('scrape_date')->first();
                    $totalBiaya = $group->sum('biaya');
                    $totalOmzet = $group->sum('omzet');
                    return [
                        'info' => $latest,
                        'total_biaya' => $totalBiaya,
                        'total_omzet' => $totalOmzet,
                        'roas' => $totalBiaya > 0 ? $totalOmzet / $totalBiaya : 0,
                    ];
                });

            // Kelompokkan hasil akhir untuk tab
            $this->campaignsByGroup = $campaignsWithKpi->groupBy(function ($campaign) {
                $mode = $campaign['info']->mode_bidding;
                if (str_starts_with($mode, 'GMV')) return 'GMV';
                if ($mode === 'Manual') return 'Manual - ' . $campaign['info']->penempatan_iklan;
                return 'Lainnya';
            })->sortKeys()->all();

            // Set tab aktif
            if (!$this->activeTab && !empty($this->campaignsByGroup)) {
                $this->activeTab = array_key_first($this->campaignsByGroup);
            }
        }

        $availableYears = CampaignReport::where('user_id', $userId)->select(DB::raw('YEAR(scrape_date) as year'))->distinct()->orderBy('year', 'desc')->pluck('year');
        if ($availableYears->isEmpty()) { $availableYears = collect([now()->year]); }
        $availableMonths = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(null, $m)->isoFormat('MMMM')]);

        return ['availableYears' => $availableYears, 'availableMonths' => $availableMonths];
    }
}; ?>

<x-layouts.app>
    @volt('reports.campaigns')
    <x-app.container>
        {{-- BAGIAN HEADER --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                @if(!$selectedCampaignInfo)
                    <x-app.heading title="Laporan Iklan" description="Analisis performa kampanye iklan Anda." />
                @else
                    <div class="flex items-center gap-4">
                        <button wire:click="backToList" class="text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors" aria-label="Kembali ke daftar kampanye">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12" /></svg>
                        </button>
                        <x-app.heading title="{{ $selectedCampaignInfo->nama_produk }}" description="Detail Performa: {{ $selectedCampaignInfo->mode_bidding }}" />
                    </div>
                @endif
            </div>
            <div class="mt-4 sm:mt-0 flex items-center gap-2">
                <x-select-input wire:model.live="selectedYear" class="text-sm">
                    @foreach($availableYears as $year)<option value="{{ $year }}">{{ $year }}</option>@endforeach
                </x-select-input>
                <x-select-input wire:model.live="selectedMonth" class="text-sm">
                    @foreach($availableMonths as $num => $name)<option value="{{ $num }}">{{ $name }}</option>@endforeach
                </x-select-input>
            </div>
        </div>
        
        <div wire:loading.delay.long class="mt-4 w-full text-center text-gray-500">
            <p>Memuat data, mohon tunggu...</p>
        </div>
        
        {{-- [REVISI] TAMPILAN A: DAFTAR KAMPANYE BERDASARKAN GRUP BARU --}}
        @if(!$selectedCampaignId)
        <div class="mt-6" wire:loading.remove>
            @if(empty($campaignsByGroup))
                <div class="mt-8 text-center py-12 bg-white dark:bg-gray-800/50 rounded-lg shadow-sm">
                    <p class="text-gray-500 dark:text-gray-400">Tidak ada data kampanye untuk periode yang dipilih.</p>
                </div>
            @else
                <div x-data="{ activeTab: @entangle('activeTab').live }">
                    {{-- Navigasi Tab --}}
                    <div class="border-b border-gray-200 dark:border-gray-700">
                        <nav class="-mb-px flex space-x-6" aria-label="Tabs">
                            @foreach(array_keys($campaignsByGroup) as $groupName)
                                <button
                                    @click="activeTab = '{{ $groupName }}'"
                                    :class="activeTab === '{{ $groupName }}' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:border-gray-600'"
                                    class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors"
                                >
                                    {{ $groupName }}
                                </button>
                            @endforeach
                        </nav>
                    </div>

                    {{-- Konten Tab --}}
                    <div class="mt-5">
                        @foreach($campaignsByGroup as $groupName => $campaignsInGroup)
                            <div x-show="activeTab === '{{ $groupName }}'" x-cloak>
                                <div class="bg-white dark:bg-gray-800/50 shadow-sm rounded-lg">
                                    {{-- Header Daftar --}}
                                    <div class="px-6 py-3 border-b border-gray-200 dark:border-gray-700 hidden md:grid md:grid-cols-[3fr,1fr,1fr,1fr] gap-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">
                                        <span>Nama Kampanye</span>
                                        <span class="text-right">Biaya</span>
                                        <span class="text-right">Omzet</span>
                                        <span class="text-right">ROAS</span>
                                    </div>
                                    {{-- Isi Daftar --}}
                                    <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                                        @foreach($campaignsInGroup as $campaign)
                                            <li wire:click="showDetail('{{ $campaign['info']->campaign_id }}')" class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                                                <div class="px-6 py-4 grid grid-cols-2 md:grid-cols-[3fr,1fr,1fr,1fr] gap-4 items-center">
                                                    {{-- Nama --}}
                                                    <div class="font-medium text-gray-900 dark:text-white truncate" title="{{ $campaign['info']->nama_produk }}">
                                                        {{ $campaign['info']->nama_produk }}
                                                    </div>
                                                    {{-- KPIs --}}
                                                    <div class="text-right text-sm text-gray-500 dark:text-gray-300 md:col-start-2">
                                                        <span class="md:hidden">Biaya: </span>{{ formatRupiahShort($campaign['total_biaya']) }}
                                                    </div>
                                                    <div class="text-right text-sm text-gray-500 dark:text-gray-300">
                                                        <span class="md:hidden">Omzet: </span>{{ formatRupiahShort($campaign['total_omzet']) }}
                                                    </div>
                                                    <div class="text-right font-bold {{ $campaign['roas'] >= 1 ? 'text-green-600' : 'text-red-500' }}">
                                                        <span class="md:hidden font-medium text-gray-500 dark:text-gray-300">ROAS: </span>{{ number_format($campaign['roas'], 2, ',', '.') }}
                                                    </div>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
        @endif

        {{-- TAMPILAN B: DETAIL KAMPANYE (Tidak ada perubahan di sini) --}}
        @if($selectedCampaignId)
        <div wire:loading.remove>
            {{-- Kartu Ringkasan Bulanan --}}
            <div class="mt-6 bg-white dark:bg-gray-800/50 shadow-sm rounded-lg p-6">
                <h4 class="font-semibold text-gray-900 dark:text-white">Ringkasan Bulan {{ Carbon::create(null, $selectedMonth)->isoFormat('MMMM') }} {{ $selectedYear }}</h4>
                <div class="mt-4 grid grid-cols-2 md:grid-cols-3 gap-x-8 gap-y-5">
                    @php
                        $summaryRoas = ($monthlySummary['total_biaya'] > 0) ? $monthlySummary['total_omzet'] / $monthlySummary['total_biaya'] : 0;
                        $summaryCtr = ($monthlySummary['total_dilihat'] > 0) ? ($monthlySummary['total_klik'] / $monthlySummary['total_dilihat']) * 100 : 0;
                        $totalProfit = $monthlySummary['total_profit'] ?? 0;
                    @endphp
                    
                    {{-- Baris 1: Omzet, Biaya, Profit --}}
                    <div><div class="text-xs text-gray-500 dark:text-gray-400">Total Omzet Iklan</div><div class="text-lg font-bold text-gray-900 dark:text-white">Rp {{ number_format($monthlySummary['total_omzet'], 0, ',', '.') }}</div></div>
                    <div><div class="text-xs text-gray-500 dark:text-gray-400">Total Biaya Iklan</div><div class="text-lg font-bold text-red-600 dark:text-red-400">Rp {{ number_format($monthlySummary['total_biaya'], 0, ',', '.') }}</div></div>
                    <div><div class="text-xs text-gray-500 dark:text-gray-400">Estimasi Profit</div><div class="text-lg font-bold {{ $totalProfit >= 0 ? 'text-green-600' : 'text-red-500' }}">Rp {{ number_format($totalProfit, 0, ',', '.') }}</div></div>
                    
                    {{-- Baris 2: Target ROAS, ROAS, CTR --}}
                    <div class="pt-3 border-t border-gray-200 dark:border-gray-700/50"><div class="text-xs text-gray-500 dark:text-gray-400">Target ROAS</div><div class="text-base font-medium text-gray-900 dark:text-white">{{ $selectedCampaignInfo->target_roas ?? 'N/A' }}</div></div>
                    <div class="pt-3 border-t border-gray-200 dark:border-gray-700/50"><div class="text-xs text-gray-500 dark:text-gray-400">ROAS Aktual</div><div class="text-base font-bold {{ $summaryRoas >= ($selectedCampaignInfo->target_roas ?? 0) ? 'text-green-600' : 'text-red-500' }}">{{ number_format($summaryRoas, 2, ',', '.') }}</div></div>
                    <div class="pt-3 border-t border-gray-200 dark:border-gray-700/50"><div class="text-xs text-gray-500 dark:text-gray-400">CTR</div><div class="text-base font-medium text-gray-900 dark:text-white">{{ number_format($summaryCtr, 2, ',', '.') }}%</div></div>
                    
                    {{-- Baris 3: Klik, Produk Terjual --}}
                    <div class="pt-3 border-t border-gray-200 dark:border-gray-700/50"><div class="text-xs text-gray-500 dark:text-gray-400">Total Klik</div><div class="text-base font-medium text-gray-900 dark:text-white">{{ number_format($monthlySummary['total_klik'], 0, ',', '.') }}</div></div>
                    <div class="pt-3 border-t border-gray-200 dark:border-gray-700/50"><div class="text-xs text-gray-500 dark:text-gray-400">Total Produk Terjual</div><div class="text-base font-medium text-gray-900 dark:text-white">{{ number_format($monthlySummary['total_terjual'], 0, ',', '.') }}</div></div>
                </div>
            </div>
            {{-- [PENAMBAHAN BARU] Kartu Informasi HPP --}}
            @if(!empty($costInfo))
            <div class="mt-6 bg-blue-50 dark:bg-gray-800 border border-blue-200 dark:border-gray-700 rounded-lg p-5">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0">
                        <svg class="h-6 w-6 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                        </svg>
                    </div>
                    <div class="flex-grow">
                        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <div class="flex items-baseline">
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-300 mr-2">Tipe SKU:</span>
                                <span class="font-semibold text-gray-900 dark:text-white">{{ $costInfo['sku_type'] }}</span>
                            </div>
                            <div class="flex items-baseline">
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-300 mr-2">Harga Modal (HPP):</span>
                                <span class="font-semibold text-gray-900 dark:text-white">Rp {{ number_format($costInfo['display_price'], 0, ',', '.') }}</span>
                            </div>
                        </div>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            {!! $costInfo['note'] !!}
                        </p>
                    </div>
                </div>
            </div>
            @endif
            {{-- Tabel Rincian Harian --}}
            <div class="mt-8 flow-root">
                <div class="overflow-x-auto"><div class="inline-block min-w-full align-middle"><div class="overflow-hidden shadow-sm ring-1 ring-black ring-opacity-5 sm:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr class="text-left text-sm font-semibold text-gray-900 dark:text-white">
                                        <th scope="col" class="py-3.5 pl-4 pr-3 sm:pl-6">Tanggal</th>
                                        <th scope="col" class="px-3 py-3.5">Modal Harian</th>
                                        <th scope="col" class="px-3 py-3.5">Biaya</th>
                                        <th scope="col" class="px-3 py-3.5">Profit</th>
                                        <th scope="col" class="px-3 py-3.5">Omzet</th>
                                        <th scope="col" class="px-3 py-3.5">ROAS</th>
                                        <th scope="col" class="px-3 py-3.5">Klik</th>
                                        <th scope="col" class="px-3 py-3.5">Produk Terjual</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                                    @forelse ($dailyPerformance as $date => $data)
                                        @php $roas = ($data['biaya'] > 0) ? $data['omzet'] / $data['biaya'] : 0; @endphp
                                        <tr @class(['transition-colors', 'bg-blue-50 dark:bg-blue-900/40' => $data['modal_changed']])>
                                            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 dark:text-white sm:pl-6">{{ Carbon::parse($date)->format('d') }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $data['modal'] }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-red-600 dark:text-red-400">Rp {{ number_format($data['biaya'], 0, ',', '.') }}</td>
                                            <td @class([
                                                'whitespace-nowrap px-3 py-4 text-sm font-bold',
                                                'text-green-600' => $data['profit'] >= 0,
                                                'text-red-500' => $data['profit'] < 0,
                                            ])>
                                                Rp {{ number_format($data['profit'], 0, ',', '.') }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-800 dark:text-gray-200">Rp {{ number_format($data['omzet'], 0, ',', '.') }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm font-bold {{ $roas >= 1 ? 'text-green-600' : 'text-red-500' }}">{{ number_format($roas, 2, ',', '.') }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ number_format($data['klik'], 0, ',', '.') }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ number_format($data['terjual'], 0, ',', '.') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500">Tidak ada data performa harian yang ditemukan untuk bulan ini.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div></div></div>
            </div>
        </div>
        @endif
    </x-app.container>
    @endvolt
</x-layouts.app>