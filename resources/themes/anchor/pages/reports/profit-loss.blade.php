<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Order;
use App\Models\OrderItem;
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

    private function getBaseQuery($userId)
    {
        return Order::query()
            ->from('orders as o')
            ->where('o.user_id', $userId)
            ->join(DB::raw('(SELECT order_id, MIN(pickup_time) as first_pickup_time 
                             FROM order_status_histories 
                             WHERE status = \'Sudah Kirim\' AND pickup_time IS NOT NULL 
                             GROUP BY order_id) as unique_osh'), 
                  'o.id', '=', 'unique_osh.order_id')
            ->join('order_items as oi', 'o.id', '=', 'oi.order_id')
            ->leftJoin(DB::raw('(SELECT variant_sku, MIN(cost_price) as cost_price
                                FROM product_variants
                                WHERE variant_sku IS NOT NULL AND variant_sku != \'\'
                                GROUP BY variant_sku) as unique_pv'),
                      'oi.variant_sku', '=', 'unique_pv.variant_sku')
            ->join('order_payment_details as opd', 'o.id', '=', 'opd.order_id');
    }

    private function generateReportData()
    {
        $userId = auth()->id();
        $startDate = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();
        
        $lastDayForTable = (now()->year == $this->selectedYear && now()->month == $this->selectedMonth)
            ? now()->subDay()->day : $startDate->daysInMonth;
        
        $this->daysInMonth = $lastDayForTable;

        $prevMonthStartDate = $startDate->copy()->subMonthNoOverflow();
        $prevMonthEndDate = now()->copy()->subMonthNoOverflow()->endOfDay();
        $this->summaryPrevMonth = $this->getAggregatedSummary($userId, $prevMonthStartDate, $prevMonthEndDate);

        $salesData = $this->getBaseQuery($userId)
            ->whereBetween('unique_osh.first_pickup_time', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(unique_osh.first_pickup_time) as date'),
                DB::raw('SUM(oi.subtotal) as omset'),
                DB::raw('SUM(oi.quantity * unique_pv.cost_price) as total_cogs'),
                DB::raw('SUM(opd.admin_fee) as biaya_admin'),
                DB::raw('SUM(opd.service_fee) as biaya_service'),
                DB::raw('SUM(opd.ams_commission_fee) as komisi_ams'),
                DB::raw('SUM(opd.shop_voucher) as voucher_toko')
            )
            ->groupBy('date')
            ->get()->keyBy(fn($item) => Carbon::parse($item->date)->format('Y-m-d'));

        $adsData = AdTransaction::where('user_id', $userId)->whereBetween('transaction_date', [$startDate, $endDate])
            ->where('amount', '<', 0)->select(DB::raw('transaction_date as date'), DB::raw('SUM(ABS(amount)) as biaya_iklan'))
            ->groupBy('date')->get()->keyBy(fn($item) => Carbon::parse($item->date)->format('Y-m-d'));

        $expensesData = Expense::where('user_id', $userId)->whereBetween('transaction_date', [$startDate, $endDate])
            ->select(DB::raw('transaction_date as date'), DB::raw('SUM(amount) as pengeluaran'))
            ->groupBy('date')->get()->keyBy(fn($item) => Carbon::parse($item->date)->format('Y-m-d'));
        
        $report = [];
        for ($i = 1; $i <= $startDate->daysInMonth; $i++) {
            $currentDate = Carbon::create($this->selectedYear, $this->selectedMonth, $i)->format('Y-m-d');
            
            $omset = $salesData[$currentDate]->omset ?? 0;
            $cogs = $salesData[$currentDate]->total_cogs ?? 0;
            $laba_kotor = $omset - $cogs;
            
            $biaya_admin = abs($salesData[$currentDate]->biaya_admin ?? 0);
            $biaya_service = abs($salesData[$currentDate]->biaya_service ?? 0);
            $komisi_ams = abs($salesData[$currentDate]->komisi_ams ?? 0);
            $voucher_toko = abs($salesData[$currentDate]->voucher_toko ?? 0);
            $biaya_iklan = $adsData[$currentDate]->biaya_iklan ?? 0;
            $pengeluaran = $expensesData[$currentDate]->pengeluaran ?? 0;

            $total_biaya_operasional = $biaya_admin + $biaya_service + $komisi_ams + $voucher_toko + $biaya_iklan + $pengeluaran;
            $profit = $laba_kotor - $total_biaya_operasional;

            $report[$i] = ['day' => str_pad($i, 2, '0', STR_PAD_LEFT), 'omset' => $omset, 'laba_kotor' => $laba_kotor, 'biaya_admin' => $biaya_admin, 'biaya_service' => $biaya_service, 'komisi_ams' => $komisi_ams, 'voucher_toko' => $voucher_toko, 'biaya_iklan' => $biaya_iklan, 'pengeluaran' => $pengeluaran, 'profit' => $profit];
        }
        
        $this->calculateSummaries($report); // Panggilan ini sekarang akan berfungsi
        return $report;
    }

    private function getAggregatedSummary($userId, $startDate, $endDate): array
    {
        $sales = $this->getBaseQuery($userId)
            ->whereBetween('unique_osh.first_pickup_time', [$startDate, $endDate])
            ->selectRaw('SUM(oi.subtotal) as omset, SUM(oi.quantity * unique_pv.cost_price) as total_cogs, SUM(opd.admin_fee) as biaya_admin, SUM(opd.service_fee) as biaya_service, SUM(opd.ams_commission_fee) as komisi_ams, SUM(opd.shop_voucher) as voucher_toko')->first();

        $ads = AdTransaction::where('user_id', $userId)->whereBetween('transaction_date', [$startDate, $endDate])->where('amount', '<', 0)->sum('amount');
        $expenses = Expense::where('user_id', $userId)->whereBetween('transaction_date', [$startDate, $endDate])->sum('amount');
        
        $laba_kotor = ($sales->omset ?? 0) - ($sales->total_cogs ?? 0);
        $biaya_admin = abs($sales->biaya_admin ?? 0);
        $biaya_service = abs($sales->biaya_service ?? 0);
        $komisi_ams = abs($sales->komisi_ams ?? 0);
        $voucher_toko = abs($sales->voucher_toko ?? 0);
        $biaya_iklan = abs($ads ?? 0);
        
        $total_biaya = $biaya_admin + $biaya_service + $komisi_ams + $voucher_toko + $biaya_iklan + $expenses;

        return ['omset' => $sales->omset ?? 0, 'laba_kotor' => $laba_kotor, 'biaya_admin' => $biaya_admin, 'biaya_service' => $biaya_service, 'komisi_ams' => $komisi_ams, 'voucher_toko' => $voucher_toko, 'biaya_iklan' => $biaya_iklan, 'pengeluaran' => $expenses, 'profit' => $laba_kotor - $total_biaya];
    }
    
    // --- METODE YANG HILANG SEKARANG DITAMBAHKAN ---
    private function calculateSummaries(array $report): void
    {
        $today = now()->day;
        $yesterday = now()->subDay()->day;
        
        $this->summaryToday = (now()->year == $this->selectedYear && now()->month == $this->selectedMonth && isset($report[$today])) 
            ? $report[$today] 
            : $this->getEmptySummary();
        
        $monthToDate = $this->getEmptySummary();
        if (now()->format('Y-m') == Carbon::create($this->selectedYear, $this->selectedMonth)->format('Y-m')) {
            for ($i = 1; $i <= $yesterday; $i++) {
                if (isset($report[$i])) {
                    foreach ($report[$i] as $key => $value) {
                        if ($key !== 'day') {
                            $monthToDate[$key] += $value;
                        }
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
        $availableYears = Order::where('user_id', auth()->id())
            ->join('order_status_histories', 'orders.id', '=', 'order_status_histories.order_id')
            ->whereNotNull('order_status_histories.pickup_time')->where('order_status_histories.status', 'Sudah Kirim')
            ->select(DB::raw('YEAR(order_status_histories.pickup_time) as year'))
            ->distinct()->orderBy('year', 'desc')->get()->pluck('year');
        
        $availableMonths = collect(range(1, 12))->mapWithKeys(fn ($m) => [Carbon::create(null, $m)->month => Carbon::create(null, $m)->isoFormat('MMMM')]);
        
        return [
            'reportData' => $this->generateReportData(),
            'availableYears' => $availableYears,
            'availableMonths' => $availableMonths,
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

        {{-- Card Ringkasan --}}
        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Card Hari Ini --}}
            <div class="bg-white dark:bg-gray-800/50 shadow-sm rounded-lg p-6">
                <h4 class="font-semibold text-gray-900 dark:text-white">Ringkasan Hari Ini ({{ now()->isoFormat('D MMM YYYY') }})</h4>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    @foreach(array_keys($summaryToday) as $key)
                        @if($key !== 'day')
                        <div class="flex justify-between border-b border-gray-100 dark:border-gray-700/50 py-1">
                            <span class="text-gray-500 dark:text-gray-400">{{ Str::title(str_replace('_', ' ', $key)) }}</span>
                            <span class="font-medium {{ $key == 'profit' && $summaryToday[$key] < 0 ? 'text-red-500' : 'text-gray-900 dark:text-white' }}">
                                Rp {{ number_format($summaryToday[$key], 0, ',', '.') }}
                            </span>
                        </div>
                        @endif
                    @endforeach
                </div>
            </div>
            
            {{-- Card Bulan Ini s/d Kemarin dengan Perbandingan --}}
            <div class="bg-white dark:bg-gray-800/50 shadow-sm rounded-lg p-6">
                <h4 class="font-semibold text-gray-900 dark:text-white">Awal Bulan s/d Kemarin</h4>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
                    @foreach(array_keys($summaryMonthToDate) as $key)
                        @if($key !== 'day')
                            @php
                                $currentValue = $summaryMonthToDate[$key] ?? 0;
                                $prevValue = $summaryPrevMonth[$key] ?? 0;
                                $isPositiveGood = !in_array($key, ['biaya_admin', 'biaya_service', 'komisi_ams', 'voucher_toko', 'biaya_iklan', 'pengeluaran']);
                                $difference = $currentValue - $prevValue;

                                if ($difference > 0) { $changeClass = $isPositiveGood ? 'text-green-600' : 'text-red-600'; $icon = '▲'; }
                                elseif ($difference < 0) { $changeClass = $isPositiveGood ? 'text-red-600' : 'text-green-600'; $icon = '▼'; }
                                else { $changeClass = 'text-gray-500'; $icon = ''; }
                            @endphp
                            <div class="py-1">
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ Str::title(str_replace('_', ' ', $key)) }}</div>
                                <div class="flex items-baseline justify-between">
                                    <span class="text-lg font-bold {{ $key == 'profit' && $currentValue < 0 ? 'text-red-500' : 'text-gray-900 dark:text-white' }}">
                                        Rp {{ number_format($currentValue, 0, ',', '.') }}
                                    </span>
                                    @if(now()->format('Y-m') == Carbon::create($selectedYear, $selectedMonth)->format('Y-m'))
                                        <span class="ml-2 text-xs font-semibold {{ $changeClass }}">
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

        {{-- Tabel Laporan Harian --}}
        <div class="mt-8 flow-root">
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
                                            <span>Komisi AMS</span> <span class="text-right font-medium text-gray-900 dark:text-gray-200">Rp {{ number_format($data['komisi_ams'], 0, ',', '.') }}</span>
                                            <span>Voucher Toko</span> <span class="text-right font-medium text-gray-900 dark:text-gray-200">Rp {{ number_format($data['voucher_toko'], 0, ',', '.') }}</span>
                                            <span>Biaya Iklan</span> <span class="text-right font-medium text-gray-900 dark:text-gray-200">Rp {{ number_format($data['biaya_iklan'], 0, ',', '.') }}</span>
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

    </x-app.container>
    @endvolt
</x-layouts.app>