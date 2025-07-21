<?php

use function Laravel\Folio\{middleware, name};
use App\Models\CampaignReport;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Livewire\Attributes\Url;

middleware('auth');
name('ads.show');

new class extends Component {
    
    public ?CampaignReport $campaignReport = null; 

    #[Url(as: 'bulan', keep: true)]
    public $selectedMonth;
    public $availableMonths;

    // --- PERUBAHAN: dailyData sekarang akan berisi hasil join --
    public Collection $dailyData;
    public array $summary = [];

    public function mount(string $campaign_id): void
    {
        $this->campaignReport = CampaignReport::where('user_id', auth()->id())
            ->where('campaign_id', $campaign_id)
            ->latest('scrape_date')
            ->firstOrFail();

        $this->availableMonths = $this->getAvailableMonths();
        
        if (!$this->selectedMonth) {
            $this->selectedMonth = $this->availableMonths->keys()->last() ?? now()->format('Y-m');
        }
        
        $this->dailyData = new Collection();
        $this->summary = $this->getEmptySummary();
    }

    private function getAvailableMonths(): \Illuminate\Support\Collection
    {
        if (!$this->campaignReport) return new Collection();

        return CampaignReport::where('user_id', auth()->id())
            ->where('campaign_id', $this->campaignReport->campaign_id)
            ->selectRaw("DATE_FORMAT(scrape_date, '%Y-%m') as month_value, DATE_FORMAT(scrape_date, '%M %Y') as month_name")
            ->distinct()
            ->orderBy('month_value', 'asc')
            ->get()
            ->pluck('month_name', 'month_value');
    }
    
    public function with(): array
    {
        if ($this->campaignReport) {
            $this->loadData();
        }
        return [];
    }
    
    // --- PERUBAHAN UTAMA: Logika LEFT JOIN ---
    public function loadData(): void
    {
        try {
            $date = Carbon::parse($this->selectedMonth);
        } catch (\Exception $e) {
            $this->selectedMonth = now()->format('Y-m');
            $date = now();
        }
        $year = $date->year;
        $month = $date->month;

        // Tentukan tabel detail mana yang akan di-JOIN
        $detailTable = $this->getDetailTableName();
        if (!$detailTable) {
            $this->dailyData = new Collection();
            $this->summary = $this->getEmptySummary();
            return;
        }

        // Query Builder dengan LEFT JOIN
        $this->dailyData = DB::table('campaign_reports as cr')
            ->leftJoin("$detailTable as dt", 'cr.id', '=', 'dt.campaign_report_id')
            ->where('cr.user_id', auth()->id())
            ->where('cr.campaign_id', $this->campaignReport->campaign_id)
            ->whereYear('cr.scrape_date', $year)
            ->whereMonth('cr.scrape_date', $month)
            ->select(
                'cr.scrape_date',
                // Ambil kolom dari tabel detail, gunakan alias agar tidak konflik
                'dt.id as detail_id',
                'dt.kata_pencarian',
                // 'dt.penempatan', // Untuk recommendation
                // 'dt.penempatan_rekomendasi', // Untuk gmv
                'dt.biaya_iklan_value',
                'dt.penjualan_dari_iklan_value',
                'dt.penjualan_dari_iklan_langsung_value', // Khusus GMV
                'dt.roas_value',
                'dt.roas_langsung_value', // Khusus GMV
                'dt.produk_terjual_value',
                'dt.produk_terjual_langsung_value' // Khusus GMV
            )
            ->orderBy('cr.scrape_date', 'asc')
            ->get();
        
        $this->calculateSummary();
    }

    private function getDetailTableName(): ?string
    {
        $mode = $this->campaignReport->mode_bidding;
        // Penempatan iklan tidak relevan untuk GMV
        // $placement = $this->campaignReport->penempatan_iklan;

        if (str_starts_with($mode, 'GMV')) return 'gmv_performance_details';
        // if ($mode === 'Manual') ... (logika lain bisa ditambahkan di sini)
        
        return null; // Return null jika tidak ada tabel yang cocok
    }

    private function calculateSummary(): void
    {
        // Kalkulasi dilakukan pada collection hasil join
        $totalBiaya = 0;
        $totalOmzet = 0;
        $totalTerjual = 0;

        foreach($this->dailyData as $item) {
            // Kita perlu parsing manual karena tidak menggunakan model Eloquent & accessor
            $totalBiaya += $this->parseValue($item->biaya_iklan_value);
            $totalOmzet += $this->parseValue($item->penjualan_dari_iklan_value);
            $totalOmzet += $this->parseValue($item->penjualan_dari_iklan_langsung_value); // Khusus GMV
            $totalTerjual += (int)($item->produk_terjual_value ?? 0);
            $totalTerjual += (int)($item->produk_terjual_langsung_value ?? 0); // Khusus GMV
        }
        
        $this->summary = [
            'biaya' => $totalBiaya,
            'omzet' => $totalOmzet,
            'produk_terjual' => $totalTerjual,
            'roas' => ($totalBiaya > 0) ? $totalOmzet / $totalBiaya : 0,
        ];
    }
    
    // Helper function untuk parsing nilai, dipindah ke sini
    private function parseValue($value): float
    {
        if (is_null($value)) return 0;
        $cleanedValue = str_replace(['Rp', '.', ' ', ','], '', $value);
        if (str_ends_with(strtolower($cleanedValue), 'k')) {
            return (float) rtrim(strtolower($cleanedValue), 'k') * 1000;
        }
        return (float) $cleanedValue;
    }

    private function getEmptySummary(): array
    {
        return ['biaya' => 0, 'omzet' => 0, 'produk_terjual' => 0, 'roas' => 0];
    }
}; ?>

<x-layouts.app>
    @volt('campaign-detail')
    <x-app.container>
        @if($campaignReport)
            {{-- Header dan Ringkasan tidak berubah --}}
            {{-- ... --}}

            {{-- --- PERUBAHAN UTAMA DI TABEL INI --- --}}
            <div class="flow-root">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">Detail Harian</h2>
                <div class="overflow-x-auto">
                    <div class="inline-block min-w-full align-middle">
                        <div class="overflow-hidden shadow-sm ring-1 ring-black ring-opacity-5 rounded-lg">
                            <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">Tanggal</th>
                                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Detail</th>
                                        <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Omzet</th>
                                        <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Biaya</th>
                                        <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">ROAS</th>
                                        <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Terjual</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                                    @forelse ($dailyData as $item)
                                        <tr>
                                            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 dark:text-white sm:pl-6">
                                                {{ Carbon\Carbon::parse($item->scrape_date)->isoFormat('D MMM YYYY') }}
                                            </td>
                                            
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                                {{-- Jika tidak ada detail (detail_id null), tampilkan pesan --}}
                                                @if(is_null($item->detail_id))
                                                    <span class="italic text-gray-400">Tidak ada data detail</span>
                                                @else
                                                    {{-- Tampilkan detail yang relevan, contoh untuk GMV --}}
                                                    {{ $item->kata_pencarian }} / {{ $item->penempatan_rekomendasi }}
                                                @endif
                                            </td>

                                            @php
                                                // Kalkulasi per baris
                                                $omzet_harian = $this->parseValue($item->penjualan_dari_iklan_value) + $this->parseValue($item->penjualan_dari_iklan_langsung_value);
                                                $biaya_harian = $this->parseValue($item->biaya_iklan_value);
                                                $roas_harian = $biaya_harian > 0 ? $omzet_harian / $biaya_harian : 0;
                                                $terjual_harian = (int)($item->produk_terjual_value ?? 0) + (int)($item->produk_terjual_langsung_value ?? 0);
                                            @endphp

                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-right">Rp {{ number_format($omzet_harian, 0, ',', '.') }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-right">Rp {{ number_format($biaya_harian, 0, ',', '.') }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-right">{{ number_format($roas_harian, 2, ',', '.') }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-right font-medium">{{ number_format($terjual_harian) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">Tidak ada laporan harian untuk bulan ini.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <p>Kampanye tidak ditemukan.</p>
        @endif
    </x-app.container>
    @endvolt
</x-layouts.app>