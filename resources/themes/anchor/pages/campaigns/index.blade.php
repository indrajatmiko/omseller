<?php

use function Laravel\Folio\{middleware, name};
use App\Models\CampaignReport;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Collection;

middleware('auth');
name('reports.campaigns');

new class extends Component {
    // Properti untuk filter
    public $selectedYear;
    public $selectedMonth;

    // Properti untuk mengontrol tampilan (daftar vs detail)
    public $selectedCampaignId = null;
    public ?CampaignReport $selectedCampaignInfo = null;

    // Properti untuk menyimpan data yang akan ditampilkan
    public Collection $campaigns;
    public array $dailyPerformance = [];
    public array $monthlySummary = [];

    /**
     * Inisialisasi komponen saat pertama kali dimuat.
     */
    public function mount(): void
    {
        $this->selectedYear = now()->year;
        $this->selectedMonth = now()->month;
        $this->campaigns = collect();
    }

    /**
     * Dipanggil saat user mengklik kartu kampanye untuk melihat detail.
     */
    public function showDetail($campaignId): void
    {
        $this->selectedCampaignId = $campaignId;
        // Ambil info kampanye terbaru untuk ditampilkan di header
        $this->selectedCampaignInfo = CampaignReport::where('user_id', auth()->id())
            ->where('campaign_id', $campaignId)
            ->latest('scrape_date')
            ->firstOrFail();
        
        $this->generateDetailData();
    }

    /**
     * Kembali ke tampilan daftar kampanye.
     */
    public function backToList(): void
    {
        $this->selectedCampaignId = null;
        $this->selectedCampaignInfo = null;
        $this->dailyPerformance = [];
        $this->monthlySummary = [];
    }

    /**
     * Hook yang dijalankan saat properti diperbarui.
     * Jika filter tahun/bulan diubah, kembali ke daftar.
     */
    public function updated($property): void
    {
        if (in_array($property, ['selectedYear', 'selectedMonth'])) {
            $this->backToList();
        }
    }

    /**
     * Logika utama untuk mengambil dan memproses data detail kampanye.
     */
    private function generateDetailData(): void
    {
        if (!$this->selectedCampaignId) {
            return;
        }

        $userId = auth()->id();
        $startDate = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();
        
        // 1. Ambil semua laporan harian untuk campaign_id ini pada bulan terpilih
        $reportsInMonth = CampaignReport::where('user_id', $userId)
            ->where('campaign_id', $this->selectedCampaignId)
            ->whereBetween('scrape_date', [$startDate, $endDate])
            ->with(['gmvPerformanceDetails', 'keywordPerformances', 'recommendationPerformances'])
            ->orderBy('scrape_date')
            ->get();
        
        if ($reportsInMonth->isEmpty()) {
            $this->dailyPerformance = [];
            $this->monthlySummary = ['total_biaya' => 0, 'total_omzet' => 0, 'total_dilihat' => 0, 'total_klik' => 0, 'total_terjual' => 0];
            return;
        }
        
        // 2. Tentukan sumber data detail berdasarkan mode bidding dan penempatan
        $allPerformances = collect();
        $mode = $this->selectedCampaignInfo->mode_bidding;
        $placement = $this->selectedCampaignInfo->penempatan_iklan;

        if (str_contains($mode, 'GMV')) {
            $allPerformances = $reportsInMonth->flatMap->gmvPerformanceDetails;
        } elseif ($mode === 'Manual') {
            $keywords = $reportsInMonth->flatMap->keywordPerformances;
            $recommendations = $reportsInMonth->flatMap->recommendationPerformances;
            
            if ($placement === 'Semua') $allPerformances = $keywords->merge($recommendations);
            elseif ($placement === 'Halaman Pencarian') $allPerformances = $keywords;
            elseif ($placement === 'Halaman Rekomendasi') $allPerformances = $recommendations;
        }
        
        // 3. Kelompokkan performa berdasarkan tanggal dan hitung total harian
        $dailyData = $allPerformances->groupBy(function($detail) use ($reportsInMonth) {
                $parentReport = $reportsInMonth->firstWhere('id', $detail->campaign_report_id);
                return $parentReport ? $parentReport->scrape_date->format('Y-m-d') : null;
            })
            ->filter()
            ->map(function ($dayGroup) {
                return [
                    'dilihat' => $dayGroup->sum('iklan_dilihat_value'),
                    'klik' => $dayGroup->sum('jumlah_klik_value'),
                    'biaya' => $dayGroup->sum('clean_biaya'),
                    'omzet' => $dayGroup->sum('clean_omzet'),
                    'terjual' => $dayGroup->sum('produk_terjual_value'),
                ];
            })->sortBy(fn($val, $key) => $key); // Sort by date string 'Y-m-d'

        // 4. Simpan hasil ke properti komponen
        $this->dailyPerformance = $dailyData->all();
        $this->monthlySummary = [
            'total_biaya' => $dailyData->sum('biaya'),
            'total_omzet' => $dailyData->sum('omzet'),
            'total_dilihat' => $dailyData->sum('dilihat'),
            'total_klik' => $dailyData->sum('klik'),
            'total_terjual' => $dailyData->sum('terjual'),
        ];
    }
    
    /**
     * Menyediakan data ke view.
     */
    public function with(): array
    {
        $userId = auth()->id();
        
        // Jika dalam mode daftar, ambil data daftar kampanye
        if (!$this->selectedCampaignId) {
            $startDate = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
            $endDate = $startDate->copy()->endOfMonth();

            $this->campaigns = CampaignReport::where('user_id', $userId)
                ->whereBetween('scrape_date', [$startDate, $endDate])
                ->get()
                ->groupBy('campaign_id')
                ->map(fn ($group) => $group->sortByDesc('scrape_date')->first()); // Ambil yang terbaru dari tiap grup
        }

        // Siapkan data filter (Tahun & Bulan)
        $availableYears = CampaignReport::where('user_id', $userId)
            ->select(DB::raw('YEAR(scrape_date) as year'))->distinct()->orderBy('year', 'desc')->pluck('year');
        if ($availableYears->isEmpty()) { $availableYears = collect([now()->year]); }
        $availableMonths = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(null, $m)->isoFormat('MMMM')]);

        return [
            'availableYears' => $availableYears,
            'availableMonths' => $availableMonths,
        ];
    }
}; ?>

<x-layouts.app>
    @volt('reports.campaigns')
    <x-app.container>
        {{-- BAGIAN HEADER: JUDUL, FILTER, TOMBOL KEMBALI --}}
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
        
        {{-- TAMPILAN A: DAFTAR KAMPANYE --}}
        @if(!$selectedCampaignId)
        <div class="mt-8" wire:loading.remove>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @forelse($campaigns as $campaign)
                    <div wire:click="showDetail('{{ $campaign->campaign_id }}')" class="bg-white dark:bg-gray-800/50 shadow-sm rounded-lg p-5 cursor-pointer hover:ring-2 hover:ring-blue-500 transition-all">
                        <p class="font-bold text-gray-900 dark:text-white truncate" title="{{ $campaign->nama_produk }}">{{ $campaign->nama_produk }}</p>
                        <div class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500 dark:text-gray-400">Mode Bidding</span><span class="font-medium text-gray-800 dark:text-gray-200">{{ $campaign->mode_bidding }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500 dark:text-gray-400">Status</span><span class="font-medium {{ $campaign->status_iklan === 'Berjalan' ? 'text-green-600' : 'text-gray-600' }}">{{ $campaign->status_iklan }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500 dark:text-gray-400">Periode</span><span class="font-medium text-gray-800 dark:text-gray-200">{{ $campaign->periode_iklan }}</span></div>
                        </div>
                    </div>
                @empty
                    <div class="md:col-span-2 lg:col-span-3 mt-8 text-center py-12 bg-white dark:bg-gray-800/50 rounded-lg shadow-sm">
                        <p class="text-gray-500 dark:text-gray-400">Tidak ada data kampanye untuk periode yang dipilih.</p>
                    </div>
                @endforelse
            </div>
        </div>
        @endif

        {{-- TAMPILAN B: DETAIL KAMPANYE --}}
        @if($selectedCampaignId)
        <div wire:loading.remove>
            {{-- Kartu Ringkasan Bulanan --}}
            <div class="mt-6 bg-white dark:bg-gray-800/50 shadow-sm rounded-lg p-6">
                <h4 class="font-semibold text-gray-900 dark:text-white">Ringkasan Bulan {{ Carbon::create(null, $selectedMonth)->isoFormat('MMMM') }} {{ $selectedYear }}</h4>
                <div class="mt-4 grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-4">
                    @php
                        $summaryRoas = ($monthlySummary['total_biaya'] > 0) ? $monthlySummary['total_omzet'] / $monthlySummary['total_biaya'] : 0;
                        $summaryCtr = ($monthlySummary['total_dilihat'] > 0) ? ($monthlySummary['total_klik'] / $monthlySummary['total_dilihat']) * 100 : 0;
                    @endphp
                    <div><div class="text-xs text-gray-500 dark:text-gray-400">Total Omzet Iklan</div><div class="text-lg font-bold text-gray-900 dark:text-white">Rp {{ number_format($monthlySummary['total_omzet'], 0, ',', '.') }}</div></div>
                    <div><div class="text-xs text-gray-500 dark:text-gray-400">Total Biaya Iklan</div><div class="text-lg font-bold text-red-600 dark:text-red-400">Rp {{ number_format($monthlySummary['total_biaya'], 0, ',', '.') }}</div></div>
                    <div><div class="text-xs text-gray-500 dark:text-gray-400">ROAS</div><div class="text-lg font-bold {{ $summaryRoas >= 1 ? 'text-green-600 dark:text-green-400' : 'text-red-500' }}">{{ number_format($summaryRoas, 2, ',', '.') }}</div></div>
                    <div class="pt-2 border-t border-gray-200 dark:border-gray-700/50"><div class="text-xs text-gray-500 dark:text-gray-400">Total Klik</div><div class="text-base font-medium text-gray-900 dark:text-white">{{ number_format($monthlySummary['total_klik'], 0, ',', '.') }}</div></div>
                    <div class="pt-2 border-t border-gray-200 dark:border-gray-700/50"><div class="text-xs text-gray-500 dark:text-gray-400">Total Produk Terjual</div><div class="text-base font-medium text-gray-900 dark:text-white">{{ number_format($monthlySummary['total_terjual'], 0, ',', '.') }}</div></div>
                    <div class="pt-2 border-t border-gray-200 dark:border-gray-700/50"><div class="text-xs text-gray-500 dark:text-gray-400">CTR</div><div class="text-base font-medium text-gray-900 dark:text-white">{{ number_format($summaryCtr, 2, ',', '.') }}%</div></div>
                </div>
            </div>

            {{-- Tabel Rincian Harian --}}
            <div class="mt-8 flow-root">
                <div class="overflow-x-auto">
                    <div class="inline-block min-w-full align-middle">
                        <div class="overflow-hidden shadow-sm ring-1 ring-black ring-opacity-5 sm:rounded-lg">
                            <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800"><tr class="text-left text-sm font-semibold text-gray-900 dark:text-white"><th scope="col" class="py-3.5 pl-4 pr-3 sm:pl-6">Tanggal</th><th scope="col" class="px-3 py-3.5">Omzet</th><th scope="col" class="px-3 py-3.5">Biaya</th><th scope="col" class="px-3 py-3.5">ROAS</th><th scope="col" class="px-3 py-3.5">Klik</th><th scope="col" class="px-3 py-3.5">Produk Terjual</th></tr></thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                                    @forelse ($dailyPerformance as $date => $data)
                                        @php $roas = ($data['biaya'] > 0) ? $data['omzet'] / $data['biaya'] : 0; @endphp
                                        <tr>
                                            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 dark:text-white sm:pl-6">{{ Carbon::parse($date)->isoFormat('dddd, D MMM') }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-800 dark:text-gray-200">Rp {{ number_format($data['omzet'], 0, ',', '.') }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-red-600 dark:text-red-400">Rp {{ number_format($data['biaya'], 0, ',', '.') }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm font-bold {{ $roas >= 1 ? 'text-green-600' : 'text-red-500' }}">{{ number_format($roas, 2, ',', '.') }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ number_format($data['klik'], 0, ',', '.') }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ number_format($data['terjual'], 0, ',', '.') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">Tidak ada data performa harian yang ditemukan untuk bulan ini.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif
    </x-app.container>
    @endvolt
</x-layouts.app>