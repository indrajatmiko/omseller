<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Order;
use App\Models\AdTransaction;
use App\Models\Expense;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

middleware('auth');
name('reports.profit-loss');

new class extends Component {
    // Properti Filter
    public $selectedYear;
    public $selectedMonth;

    // Properti Kontrol UI & Data
    public $daysInMonth;
    public array $summaryToday = [];
    public array $summaryMonthToDate = [];
    public array $summaryPrevMonth = [];

    public function mount(): void
    {
        $this->selectedYear = now()->year;
        $this->selectedMonth = now()->month;
        $this->summaryToday = $this->getEmptySummary();
        $this->summaryMonthToDate = $this->getEmptySummary();
        $this->summaryPrevMonth = $this->getEmptySummary();
    }
    
    private function getUniqueOrderHistoryQuery($userId)
    {
        return DB::table('order_status_histories')->select('order_id', DB::raw('MIN(pickup_time) as first_pickup_time'))->where('status', 'Sudah Kirim')->whereNotNull('pickup_time')->groupBy('order_id');
    }

    private function generateReportData()
    {
        $userId = auth()->id();
        $startDate = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();
        
        $lastDayForTable = (now()->year == $this->selectedYear && now()->month == $this->selectedMonth) ? now()->subDay()->day : $startDate->daysInMonth;
        $this->daysInMonth = $lastDayForTable;

        $uniqueOshSubquery = $this->getUniqueOrderHistoryQuery($userId);

        $itemBasedData = DB::table('orders as o')->where('o.user_id', $userId)->joinSub($uniqueOshSubquery, 'unique_osh', 'o.id', '=', 'unique_osh.order_id')->join('order_items as oi', 'o.id', '=', 'oi.order_id')->leftJoin(DB::raw('(SELECT variant_sku, MIN(cost_price) as cost_price FROM product_variants WHERE variant_sku IS NOT NULL AND variant_sku != \'\' GROUP BY variant_sku) as unique_pv'), 'oi.variant_sku', '=', 'unique_pv.variant_sku')->whereBetween('unique_osh.first_pickup_time', [$startDate, $endDate])->select(DB::raw('DATE(unique_osh.first_pickup_time) as date'), DB::raw('SUM(oi.subtotal) as omset'), DB::raw('SUM(oi.quantity * unique_pv.cost_price) as total_cogs'))->groupBy('date')->get()->keyBy(fn($item) => Carbon::parse($item->date)->format('Y-m-d'));
        $orderBasedFees = DB::table('orders as o')->where('o.user_id', $userId)->joinSub($uniqueOshSubquery, 'unique_osh', 'o.id', '=', 'unique_osh.order_id')->join('order_payment_details as opd', 'o.id', '=', 'opd.order_id')->whereBetween('unique_osh.first_pickup_time', [$startDate, $endDate])->select(DB::raw('DATE(unique_osh.first_pickup_time) as date'), DB::raw('SUM(opd.admin_fee) as biaya_admin'), DB::raw('SUM(opd.service_fee) as biaya_service'), DB::raw('SUM(opd.ams_commission_fee) as komisi_ams'), DB::raw('SUM(opd.shop_voucher) as voucher_toko'))->groupBy('date')->get()->keyBy(fn($item) => Carbon::parse($item->date)->format('Y-m-d'));
        $adsData = AdTransaction::where('user_id', $userId)->whereBetween('transaction_date', [$startDate, $endDate])->where('amount', '<', 0)->select(DB::raw('transaction_date as date'), DB::raw('SUM(ABS(amount)) as biaya_iklan'))->groupBy('date')->get()->keyBy(fn($item) => Carbon::parse($item->date)->format('Y-m-d'));
        $expensesData = Expense::where('user_id', $userId)->whereBetween('transaction_date', [$startDate, $endDate])->select(DB::raw('transaction_date as date'), DB::raw('SUM(amount) as pengeluaran'))->groupBy('date')->get()->keyBy(fn($item) => Carbon::parse($item->date)->format('Y-m-d'));
        
        $report = [];
        for ($i = 1; $i <= $startDate->daysInMonth; $i++) {
            $currentDate = Carbon::create($this->selectedYear, $this->selectedMonth, $i)->format('Y-m-d');
            $omset = $itemBasedData[$currentDate]->omset ?? 0;
            $cogs = $itemBasedData[$currentDate]->total_cogs ?? 0;
            $laba_kotor = $omset - $cogs;
            $biaya_admin = abs($orderBasedFees[$currentDate]->biaya_admin ?? 0);
            $biaya_service = abs($orderBasedFees[$currentDate]->biaya_service ?? 0);
            $komisi_ams = abs($orderBasedFees[$currentDate]->komisi_ams ?? 0);
            $voucher_toko = abs($orderBasedFees[$currentDate]->voucher_toko ?? 0);
            $biaya_iklan = $adsData[$currentDate]->biaya_iklan ?? 0;
            $pengeluaran = $expensesData[$currentDate]->pengeluaran ?? 0;
            $total_biaya_operasional = $biaya_admin + $biaya_service + $komisi_ams + $voucher_toko + $biaya_iklan + $pengeluaran;
            $profit = $laba_kotor - $total_biaya_operasional;
            $report[$i] = ['day' => str_pad($i, 2, '0', STR_PAD_LEFT), 'omset' => $omset, 'laba_kotor' => $laba_kotor, 'biaya_admin' => $biaya_admin, 'biaya_service' => $biaya_service, 'komisi_ams' => $komisi_ams, 'voucher_toko' => $voucher_toko, 'biaya_iklan' => $biaya_iklan, 'pengeluaran' => $pengeluaran, 'profit' => $profit];
        }
        
        $prevMonthStartDate = $startDate->copy()->subMonthNoOverflow();
        $prevMonthEndDate = now()->copy()->subMonthNoOverflow()->endOfDay();
        $this->summaryPrevMonth = $this->getAggregatedSummary($userId, $prevMonthStartDate, $prevMonthEndDate);
        
        $todayStartDate = now()->startOfDay();
        $todayEndDate = now()->endOfDay();
        $this->summaryToday = $this->getAggregatedSummary($userId, $todayStartDate, $todayEndDate);
        
        $this->calculateMonthToDateSummary($report);

        // --- PERUBAHAN BARU: Hitung dan kirim data alokasi omset yang benar ---
        $omsetMtd = $this->summaryMonthToDate['omset'] ?? 0;
        $profitMtd = $this->summaryMonthToDate['profit'] ?? 0;

        // Kalkulasi komponen untuk grafik
        $cogs = $omsetMtd - ($this->summaryMonthToDate['laba_kotor'] ?? 0);
        $marketplaceFees = ($this->summaryMonthToDate['biaya_admin'] ?? 0) 
                        + ($this->summaryMonthToDate['biaya_service'] ?? 0) 
                        + ($this->summaryMonthToDate['komisi_ams'] ?? 0) 
                        + ($this->summaryMonthToDate['voucher_toko'] ?? 0);
        $adsFees = $this->summaryMonthToDate['biaya_iklan'] ?? 0;
        $otherExpenses = $this->summaryMonthToDate['pengeluaran'] ?? 0;

        // Profit tidak boleh negatif di grafik pai. Jika rugi, kita anggap profit 0.
        $displayProfit = max(0, $profitMtd);

        $chartData = [
            'labels' => ['Profit Bersih', 'COGS (HPP)', 'Biaya Marketplace', 'Biaya Iklan', 'Pengeluaran Umum'],
            'series' => [
                $displayProfit,
                $cogs,
                $marketplaceFees,
                $adsFees,
                $otherExpenses,
            ]
        ];

        // Kirim event dengan key yang jelas
        $this->dispatch('monthly-data-updated', ['monthlyRevenueAllocation' => $chartData]);

        return $report;
    }

    private function getAggregatedSummary($userId, $startDate, $endDate): array
    {
        $uniqueOshSubquery = $this->getUniqueOrderHistoryQuery($userId);
        $itemBased = DB::table('orders as o')->where('o.user_id', $userId)->joinSub($uniqueOshSubquery, 'unique_osh', 'o.id', '=', 'unique_osh.order_id')->join('order_items as oi', 'o.id', '=', 'oi.order_id')->leftJoin(DB::raw('(SELECT variant_sku, MIN(cost_price) as cost_price FROM product_variants WHERE variant_sku IS NOT NULL AND variant_sku != \'\' GROUP BY variant_sku) as unique_pv'), 'oi.variant_sku', '=', 'unique_pv.variant_sku')->whereBetween('unique_osh.first_pickup_time', [$startDate, $endDate])->selectRaw('SUM(oi.subtotal) as omset, SUM(oi.quantity * unique_pv.cost_price) as total_cogs')->first();
        $orderBased = DB::table('orders as o')->where('o.user_id', $userId)->joinSub($uniqueOshSubquery, 'unique_osh', 'o.id', '=', 'unique_osh.order_id')->join('order_payment_details as opd', 'o.id', '=', 'opd.order_id')->whereBetween('unique_osh.first_pickup_time', [$startDate, $endDate])->selectRaw('SUM(opd.admin_fee) as biaya_admin, SUM(opd.service_fee) as biaya_service, SUM(opd.ams_commission_fee) as komisi_ams, SUM(opd.shop_voucher) as voucher_toko')->first();
        $ads = AdTransaction::where('user_id', $userId)->whereBetween('transaction_date', [$startDate, $endDate])->where('amount', '<', 0)->sum('amount');
        $expenses = Expense::where('user_id', $userId)->whereBetween('transaction_date', [$startDate, $endDate])->sum('amount');
        $laba_kotor = ($itemBased->omset ?? 0) - ($itemBased->total_cogs ?? 0);
        $biaya_admin = abs($orderBased->biaya_admin ?? 0);
        $biaya_service = abs($orderBased->biaya_service ?? 0);
        $komisi_ams = abs($orderBased->komisi_ams ?? 0);
        $voucher_toko = abs($orderBased->voucher_toko ?? 0);
        $biaya_iklan = abs($ads ?? 0);
        $total_biaya = $biaya_admin + $biaya_service + $komisi_ams + $voucher_toko + $biaya_iklan + $expenses;
        return ['omset' => $itemBased->omset ?? 0, 'laba_kotor' => $laba_kotor, 'biaya_admin' => $biaya_admin, 'biaya_service' => $biaya_service, 'komisi_ams' => $komisi_ams, 'voucher_toko' => $voucher_toko, 'biaya_iklan' => $biaya_iklan, 'pengeluaran' => $expenses, 'profit' => $laba_kotor - $total_biaya];
    }
    
    private function calculateMonthToDateSummary(array $report): void
    {
        $yesterday = now()->subDay()->day;
        $monthToDate = $this->getEmptySummary();
        if (now()->format('Y-m') == Carbon::create($this->selectedYear, $this->selectedMonth)->format('Y-m')) {
            for ($i = 1; $i <= $yesterday; $i++) {
                if (isset($report[$i])) {
                    foreach ($report[$i] as $key => $value) {
                        if ($key !== 'day') $monthToDate[$key] += $value;
                    }
                }
            }
        }
        $this->summaryMonthToDate = $monthToDate;
    }

    private function getEmptySummary(): array
    {
        return ['omset' => 0, 'laba_kotor' => 0, 'biaya_admin' => 0, 'biaya_service' => 0, 'komisi_ams' => 0, 'voucher_toko' => 0, 'biaya_iklan' => 0, 'pengeluaran' => 0, 'profit' => 0];
    }
    
    public function with(): array
    {
        $reportData = $this->generateReportData(); // Fungsi ini sudah menghitung summary dan dispatch event

        $availableYears = Order::where('user_id', auth()->id())->join('order_status_histories', 'orders.id', '=', 'order_status_histories.order_id')->whereNotNull('order_status_histories.pickup_time')->where('order_status_histories.status', 'Sudah Kirim')->select(DB::raw('YEAR(order_status_histories.pickup_time) as year'))->distinct()->orderBy('year', 'desc')->get()->pluck('year');
        if ($availableYears->isEmpty()) { $availableYears = collect([now()->year]); }
        $availableMonths = collect(range(1, 12))->mapWithKeys(fn ($m) => [Carbon::create(null, $m)->month => Carbon::create(null, $m)->isoFormat('MMMM')]);

        // Siapkan data yang sama untuk render awal grafik
        $omsetMtd = $this->summaryMonthToDate['omset'] ?? 0;
        $profitMtd = $this->summaryMonthToDate['profit'] ?? 0;
        
        $cogs = $omsetMtd - ($this->summaryMonthToDate['laba_kotor'] ?? 0);
        $marketplaceFees = ($this->summaryMonthToDate['biaya_admin'] ?? 0) + ($this->summaryMonthToDate['biaya_service'] ?? 0) + ($this->summaryMonthToDate['komisi_ams'] ?? 0) + ($this->summaryMonthToDate['voucher_toko'] ?? 0);
        $adsFees = $this->summaryMonthToDate['biaya_iklan'] ?? 0;
        $otherExpenses = $this->summaryMonthToDate['pengeluaran'] ?? 0;
        $displayProfit = max(0, $profitMtd);

        $initialChartData = [
            'labels' => ['Profit Bersih', 'COGS (HPP)', 'Biaya Marketplace', 'Biaya Iklan', 'Pengeluaran Umum'],
            'series' => [$displayProfit, $cogs, $marketplaceFees, $adsFees, $otherExpenses]
        ];

        return [
            'reportData' => $reportData, 
            'availableYears' => $availableYears, 
            'availableMonths' => $availableMonths,
            'initialChartData' => $initialChartData // Tambahkan ini
        ];
    }
}; ?>

<x-layouts.app>
    @volt('reports-profit-loss')
    <x-app.container>
        <x-app.heading title="Laporan Laba Rugi" description="Analisis performa harian toko Anda dalam periode yang dipilih." />

        {{-- Filter --}}
        <div class="mt-6 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <x-select-input wire:model.live="selectedYear" class="text-sm">
                    @foreach($availableYears as $year)<option value="{{ $year }}">{{ $year }}</option>@endforeach
                </x-select-input>
                <x-select-input wire:model.live="selectedMonth" class="text-sm">
                    @foreach($availableMonths as $num => $name)<option value="{{ $num }}">{{ $name }}</option>@endforeach
                </x-select-input>
            </div>
        </div>

        {{-- ====================================================== --}}
        {{-- CARD RINGKASAN DENGAN WARNA BARU --}}
        {{-- ====================================================== --}}
        <div class="mt-6 grid grid-cols-1 lg:grid-cols-1 gap-6">
            {{-- Card Hari Ini --}}
            <div class="bg-white dark:bg-gray-800/50 shadow-sm rounded-lg p-6">
                <h4 class="font-semibold text-gray-900 dark:text-white">Ringkasan Hari Ini ({{ now()->isoFormat('D MMM YYYY') }})</h4>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    @foreach(array_keys($summaryToday) as $key)
                        @if($key !== 'day')
                            @php
                                $colorClass = match($key) {
                                    'profit' => $summaryToday[$key] < 0 ? 'text-red-500' : 'text-green-600 dark:text-green-400',
                                    'komisi_ams' => 'text-blue-600 dark:text-blue-400',
                                    'voucher_toko' => 'text-yellow-600 dark:text-yellow-400',
                                    'biaya_iklan' => 'text-red-600 dark:text-red-400',
                                    default => 'text-gray-900 dark:text-white',
                                };
                            @endphp
                            <div class="flex justify-between border-b border-gray-100 dark:border-gray-700/50 py-1">
                                <span class="text-gray-500 dark:text-gray-400">{{ Str::title(str_replace('_', ' ', $key)) }}</span>
                                <span class="font-medium {{ $colorClass }}">
                                    Rp {{ number_format($summaryToday[$key], 0, ',', '.') }}
                                </span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
            
            {{-- Card Bulan Ini s/d Kemarin dengan Perbandingan --}}
            {{-- PERUBAHAN: Card Bulan Ini s/d Kemarin dengan Grafik Donat --}}
            <div wire:key="month-to-date-summary" class="bg-white dark:bg-gray-800/50 shadow-sm rounded-lg p-6">
                <h4 class="font-semibold text-gray-900 dark:text-white">Awal Bulan s/d Kemarin</h4>
                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
                    {{-- Kolom Kiri: Grafik Donat --}}
                    <div x-data="{
                            chart: null,
                            // Definisikan handler di luar agar bisa diakses oleh cleanup
                            updateHandler(event) {
                                // event.detail.monthlyRevenueAllocation adalah payload yang kita kirim dari backend
                                // Livewire 3 membungkus payload dalam array, jadi kita ambil index 0
                                const payload = event.detail[0] || {}; 
                                if (this.$el && payload.monthlyRevenueAllocation) {
                                    this.renderChart(payload.monthlyRevenueAllocation);
                                }
                            },
                            init() {
                                // Render chart dengan data awal dari server yang sudah benar
                                let initialData = @js($initialChartData);
                                this.renderChart(initialData);

                                // Bind 'this' agar context di dalam handler benar
                                const boundUpdateHandler = this.updateHandler.bind(this);
                                
                                // Pasang listener
                                window.addEventListener('monthly-data-updated', boundUpdateHandler);

                                // Bersihkan listener saat komponen dihancurkan untuk mencegah memory leak
                                this.$watch('$isUnloaded', () => {
                                    window.removeEventListener('monthly-data-updated', boundUpdateHandler);
                                })
                            },
                            renderChart(data) {
                                if (this.chart) {
                                    this.chart.destroy();
                                }
                                // Hanya render jika ada data yang berarti
                                if (data && data.series && data.series.some(v => v > 0)) {
                                    this.chart = new ApexCharts(this.$refs.donut, this.getOptions(data));
                                    this.chart.render();
                                } else {
                                    this.$refs.donut.innerHTML = `<div class='flex items-center justify-center h-full text-gray-500' style='height: 380px;'>Tidak ada data untuk ditampilkan.</div>`;
                                }
                            },
                            getOptions(data) {
                                return {
                                    chart: { type: 'donut', height: 380 },
                                    series: data.series,
                                    labels: data.labels,
                                    // Warna yang lebih sesuai dengan kategori baru
                                    colors: ['#16a34a', '#6b7280', '#ee4d2d', '#b91c1c', '#9ca3af'],
                                    dataLabels: {
                                        enabled: true,
                                        formatter: (val) => val.toFixed(1) + '%',
                                        style: { fontSize: '11px', fontWeight: 'bold', colors: ['#fff'] },
                                        dropShadow: { enabled: true, top: 1, left: 1, blur: 1, color: '#000', opacity: 0.45 }
                                    },
                                    legend: { 
                                        show: true, position: 'bottom', horizontalAlign: 'center', 
                                        fontSize: '12px', itemMargin: { horizontal: 5, vertical: 2 },
                                    },
                                    plotOptions: { 
                                        pie: { 
                                            donut: { 
                                                labels: { 
                                                    show: true, 
                                                    value: {
                                                        show: true,
                                                        formatter: (val) => 'Rp ' + parseFloat(val).toLocaleString('id-ID')
                                                    },
                                                    total: { 
                                                        show: true, 
                                                        label: 'Total Omset', 
                                                        // Total dari semua irisan adalah Omset, jadi kita jumlahkan semua seri
                                                        formatter: (w) => 'Rp ' + w.globals.seriesTotals.reduce((a, b) => a + b, 0).toLocaleString('id-ID')
                                                    } 
                                                } 
                                            } 
                                        } 
                                    },
                                    tooltip: { y: { formatter: (val) => 'Rp ' + parseFloat(val).toLocaleString('id-ID') } },
                                    theme: { mode: localStorage.getItem('theme') || 'light' }
                                }
                            }
                        }">
                        <div x-ref="donut" wire:ignore></div>
                    </div>
                    {{-- Kolom Kanan: Detail Angka & Perbandingan --}}
                    <div class="space-y-2">
                    @foreach(array_keys($summaryMonthToDate) as $key)
                        @if($key !== 'day')
                            @php
                                $currentValue = $summaryMonthToDate[$key] ?? 0;
                                $prevValue = $summaryPrevMonth[$key] ?? 0;
                                $isPositiveGood = !in_array($key, ['biaya_admin', 'biaya_service', 'komisi_ams', 'voucher_toko', 'biaya_iklan', 'pengeluaran']);
                                $difference = $currentValue - $prevValue;

                                if ($difference > 0) { $changeIndicatorClass = $isPositiveGood ? 'text-green-600' : 'text-red-600'; $icon = '▲'; }
                                elseif ($difference < 0) { $changeIndicatorClass = $isPositiveGood ? 'text-red-600' : 'text-green-600'; $icon = '▼'; }
                                else { $changeIndicatorClass = 'text-gray-500'; $icon = ''; }

                                $valueColorClass = match($key) {
                                    'profit' => $currentValue < 0 ? 'text-red-500' : 'text-gray-900 dark:text-white',
                                    'komisi_ams' => 'text-blue-600 dark:text-blue-400',
                                    'voucher_toko' => 'text-yellow-600 dark:text-yellow-400',
                                    'biaya_iklan' => 'text-red-600 dark:text-red-400',
                                    default => 'text-gray-900 dark:text-white',
                                };
                            @endphp
                            <div class="py-1">
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ Str::title(str_replace('_', ' ', $key)) }}</div>
                                <div class="flex items-baseline justify-between">
                                    <span class="text-lg font-bold {{ $valueColorClass }}">
                                        Rp {{ number_format($currentValue, 0, ',', '.') }}
                                    </span>
                                    @if(now()->format('Y-m') == Carbon::create($selectedYear, $selectedMonth)->format('Y-m'))
                                        <span class="ml-2 text-xs font-semibold {{ $changeIndicatorClass }}">
                                            {{ $icon }} {{ number_format(abs($difference), 0, ',', '.') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ====================================================== --}}
        {{-- TABEL DAN CARD DENGAN WARNA BARU --}}
        {{-- ====================================================== --}}

        {{-- 1. Tampilan DESKTOP (md ke atas) --}}
        <div class="mt-8 flow-root hidden md:block">
            <div class="min-w-full">
                <div class="overflow-hidden shadow-sm ring-1 ring-black ring-opacity-5 sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-700">
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                            @for ($i = 1; $i <= $daysInMonth; $i++)
                                @php $data = $reportData[$i]; @endphp
                                <tr class="{{ $data['profit'] > 0 ? '' : ($data['profit'] < 0 ? 'bg-red-50 dark:bg-red-900/20' : '') }}">
                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                                        <div class="flex items-center">
                                            <div class="h-10 w-10 flex-shrink-0 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center"><span class="text-lg font-bold text-gray-800 dark:text-white">{{ $data['day'] }}</span></div>
                                            <div class="ml-4">
                                                <div class="font-bold text-base {{ $data['profit'] < 0 ? 'text-red-500' : 'text-green-600 dark:text-green-400' }}">Rp {{ number_format($data['profit'], 0, ',', '.') }}</div>
                                                <div class="text-gray-500 dark:text-gray-400 text-xs">Profit Bersih</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-4 text-sm text-gray-500">
                                        <div class="grid grid-cols-2 gap-x-4 gap-y-1">
                                            <span>Omset</span> <span class="text-right font-medium text-gray-900 dark:text-gray-200">Rp {{ number_format($data['omset'], 0, ',', '.') }}</span>
                                            <span class="font-semibold text-gray-700 dark:text-gray-300">Laba Kotor</span><span class="text-right font-semibold text-gray-700 dark:text-gray-300">Rp {{ number_format($data['laba_kotor'], 0, ',', '.') }}</span>
                                            <span>Biaya Admin</span> <span class="text-right font-medium text-gray-900 dark:text-gray-200">Rp {{ number_format($data['biaya_admin'], 0, ',', '.') }}</span>
                                            <span>Biaya Service</span> <span class="text-right font-medium text-gray-900 dark:text-gray-200">Rp {{ number_format($data['biaya_service'], 0, ',', '.') }}</span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                        <div class="grid grid-cols-2 gap-x-4 gap-y-1">
                                            <span class="text-blue-600 dark:text-blue-400">Komisi AMS</span> <span class="text-right font-medium text-blue-600 dark:text-blue-400">Rp {{ number_format($data['komisi_ams'], 0, ',', '.') }}</span>
                                            <span class="text-yellow-600 dark:text-yellow-400">Voucher Toko</span> <span class="text-right font-medium text-yellow-600 dark:text-yellow-400">Rp {{ number_format($data['voucher_toko'], 0, ',', '.') }}</span>
                                            <span class="text-red-600 dark:text-red-400">Biaya Iklan</span> <span class="text-right font-medium text-red-600 dark:text-red-400">Rp {{ number_format($data['biaya_iklan'], 0, ',', '.') }}</span>
                                            <span>Pengeluaran</span> <span class="text-right font-medium text-gray-900 dark:text-gray-200">Rp {{ number_format($data['pengeluaran'], 0, ',', '.') }}</span>
                                        </div>
                                    </td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- 2. Tampilan MOBILE (di bawah md) --}}
        <div class="mt-6 space-y-4 md:hidden">
            @for ($i = 1; $i <= $daysInMonth; $i++)
                @php $data = $reportData[$i]; @endphp
                <div class="bg-white dark:bg-gray-800/50 shadow-sm rounded-lg overflow-hidden">
                    <div class="px-4 py-3 flex justify-between items-center {{ $data['profit'] > 0 ? '' : ($data['profit'] < 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-gray-50 dark:bg-gray-800') }}">
                        <div class="flex items-center">
                            <div class="h-8 w-8 flex-shrink-0 bg-white dark:bg-gray-700 rounded-full flex items-center justify-center shadow">
                                <span class="font-bold text-gray-800 dark:text-white">{{ $data['day'] }}</span>
                            </div>
                            <div class="ml-3">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Profit Bersih</p>
                                <p class="font-bold text-base {{ $data['profit'] < 0 ? 'text-red-500' : 'text-green-600 dark:text-green-400' }}">
                                    Rp {{ number_format($data['profit'], 0, ',', '.') }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="px-4 py-4 space-y-2 text-sm border-t border-gray-200 dark:border-gray-700/50">
                        <div class="flex justify-between font-medium">
                            <span class="text-gray-600 dark:text-gray-300">Laba Kotor</span>
                            <span class="text-gray-900 dark:text-white">Rp {{ number_format($data['laba_kotor'], 0, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500 dark:text-gray-400">Omset</span>
                            <span class="text-gray-700 dark:text-gray-300">Rp {{ number_format($data['omset'], 0, ',', '.') }}</span>
                        </div>
                        <hr class="border-t border-dashed border-gray-200 dark:border-gray-600 my-2">
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500 dark:text-gray-400">Biaya Admin & Service</span>
                            <span class="text-gray-700 dark:text-gray-300">Rp {{ number_format($data['biaya_admin'] + $data['biaya_service'], 0, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-blue-600 dark:text-blue-400">Komisi AMS</span>
                            <span class="font-medium text-blue-600 dark:text-blue-400">Rp {{ number_format($data['komisi_ams'], 0, ',', '.') }}</span>
                        </div>
                         <div class="flex justify-between text-xs">
                            <span class="text-yellow-600 dark:text-yellow-400">Voucher Toko</span>
                            <span class="font-medium text-yellow-600 dark:text-yellow-400">Rp {{ number_format($data['voucher_toko'], 0, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-red-600 dark:text-red-400">Biaya Iklan</span>
                            <span class="font-medium text-red-600 dark:text-red-400">Rp {{ number_format($data['biaya_iklan'], 0, ',', '.') }}</span>
                        </div>
                         <div class="flex justify-between text-xs">
                            <span class="text-gray-500 dark:text-gray-400">Pengeluaran</span>
                            <span class="text-gray-700 dark:text-gray-300">Rp {{ number_format($data['pengeluaran'], 0, ',', '.') }}</span>
                        </div>
                    </div>
                </div>
            @endfor
        </div>

    </x-app.container>
    @endvolt
</x-layouts.app>