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

    private function generateReportData()
    {
        $userId = auth()->id();
        $startDate = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();
        
        $lastDayForTable = (now()->year == $this->selectedYear && now()->month == $this->selectedMonth)
            ? now()->subDay()->day
            : $startDate->daysInMonth;
        
        $this->daysInMonth = $lastDayForTable;

        // Tentukan periode bulan sebelumnya untuk perbandingan
        $prevMonthStartDate = $startDate->copy()->subMonthNoOverflow();
        $prevMonthEndDate = now()->copy()->subMonthNoOverflow()->endOfDay();

        // Query agregat untuk bulan sebelumnya (untuk card perbandingan)
        $this->summaryPrevMonth = $this->getAggregatedSummary($userId, $prevMonthStartDate, $prevMonthEndDate);

        // Query per hari untuk bulan ini (untuk tabel)
        $salesData = Order::where('orders.user_id', $userId)
            ->join('order_payment_details', 'orders.id', '=', 'order_payment_details.order_id')
            ->join('order_status_histories', 'orders.id', '=', 'order_status_histories.order_id')
            ->whereNotNull('order_status_histories.pickup_time')
            ->where('order_status_histories.status', 'Sudah Kirim')
            ->whereBetween('order_status_histories.pickup_time', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(order_status_histories.pickup_time) as date'),
                DB::raw('SUM(order_payment_details.product_subtotal) as omset'),
                DB::raw('SUM(order_payment_details.admin_fee) as biaya_admin'),
                DB::raw('SUM(order_payment_details.service_fee) as biaya_service'),
                DB::raw('SUM(order_payment_details.ams_commission_fee) as komisi_ams'),
                DB::raw('SUM(order_payment_details.shop_voucher) as voucher_toko')
            )
            ->groupBy('date')
            ->get()->keyBy(fn($item) => Carbon::parse($item->date)->format('Y-m-d'));

        $cogsData = OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('product_variants', 'order_items.variant_sku', '=', 'product_variants.variant_sku')
            ->join('order_status_histories', 'orders.id', '=', 'order_status_histories.order_id')
            ->where('orders.user_id', $userId)
            ->whereNotNull('order_status_histories.pickup_time')
            ->where('order_status_histories.status', 'Sudah Kirim')
            ->whereBetween('order_status_histories.pickup_time', [$startDate, $endDate])
            ->select(DB::raw('DATE(order_status_histories.pickup_time) as date'), DB::raw('SUM(order_items.quantity * product_variants.cost_price) as total_cogs'))
            ->groupBy('date')
            ->get()->keyBy(fn($item) => Carbon::parse($item->date)->format('Y-m-d'));

        $adsData = AdTransaction::where('user_id', $userId)->whereBetween('transaction_date', [$startDate, $endDate])
            ->where('amount', '<', 0)->select(DB::raw('transaction_date as date'), DB::raw('SUM(amount) * -1 as biaya_iklan'))
            ->groupBy('date')->get()->keyBy(fn($item) => Carbon::parse($item->date)->format('Y-m-d'));

        $expensesData = Expense::where('user_id', $userId)->whereBetween('transaction_date', [$startDate, $endDate])
            ->select(DB::raw('transaction_date as date'), DB::raw('SUM(amount) as pengeluaran'))
            ->groupBy('date')->get()->keyBy(fn($item) => Carbon::parse($item->date)->format('Y-m-d'));
        
        // Gabungkan semua data per hari
        $report = [];
        for ($i = 1; $i <= $startDate->daysInMonth; $i++) {
            $currentDate = Carbon::create($this->selectedYear, $this->selectedMonth, $i)->format('Y-m-d');
            
            $omset = $salesData[$currentDate]->omset ?? 0;
            $cogs = $cogsData[$currentDate]->total_cogs ?? 0;
            $laba_kotor = $omset - $cogs;
            $biaya_admin = $salesData[$currentDate]->biaya_admin ?? 0;
            $biaya_service = $salesData[$currentDate]->biaya_service ?? 0;
            $komisi_ams = $salesData[$currentDate]->komisi_ams ?? 0;
            $voucher_toko = $salesData[$currentDate]->voucher_toko ?? 0;
            $biaya_iklan = $adsData[$currentDate]->biaya_iklan ?? 0;
            $pengeluaran = $expensesData[$currentDate]->pengeluaran ?? 0;
            $total_biaya_operasional = $biaya_admin + $biaya_service + $komisi_ams + $voucher_toko + $biaya_iklan + $pengeluaran;
            $profit = $laba_kotor - $total_biaya_operasional;

            $report[$i] = [
                'day' => str_pad($i, 2, '0', STR_PAD_LEFT),
                'omset' => $omset,
                'laba_kotor' => $laba_kotor,
                'biaya_admin' => $biaya_admin,
                'biaya_service' => $biaya_service,
                'komisi_ams' => $komisi_ams,
                'voucher_toko' => $voucher_toko,
                'biaya_iklan' => $biaya_iklan,
                'pengeluaran' => $pengeluaran,
                'profit' => $profit,
            ];
        }
        
        $this->calculateSummaries($report);
        return $report;
    }

    private function getAggregatedSummary($userId, $startDate, $endDate): array
    {
        $sales = Order::where('orders.user_id', $userId)
            ->join('order_payment_details', 'orders.id', '=', 'order_payment_details.order_id')
            ->join('order_status_histories', 'orders.id', '=', 'order_status_histories.order_id')
            ->whereNotNull('order_status_histories.pickup_time')->where('order_status_histories.status', 'Sudah Kirim')
            ->whereBetween('order_status_histories.pickup_time', [$startDate, $endDate])
            ->selectRaw('SUM(order_payment_details.product_subtotal) as omset, SUM(order_payment_details.admin_fee) as biaya_admin, SUM(order_payment_details.service_fee) as biaya_service, SUM(order_payment_details.ams_commission_fee) as komisi_ams, SUM(order_payment_details.shop_voucher) as voucher_toko')->first();

        $cogs = OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('product_variants', 'order_items.variant_sku', '=', 'product_variants.variant_sku')
            ->join('order_status_histories', 'orders.id', '=', 'order_status_histories.order_id')
            ->where('orders.user_id', $userId)->whereNotNull('order_status_histories.pickup_time')->where('order_status_histories.status', 'Sudah Kirim')
            ->whereBetween('order_status_histories.pickup_time', [$startDate, $endDate])->sum(DB::raw('order_items.quantity * product_variants.cost_price'));

        $ads = AdTransaction::where('user_id', $userId)->whereBetween('transaction_date', [$startDate, $endDate])->where('amount', '<', 0)->sum('amount') * -1;
        $expenses = Expense::where('user_id', $userId)->whereBetween('transaction_date', [$startDate, $endDate])->sum('amount');
        
        $laba_kotor = ($sales->omset ?? 0) - ($cogs ?? 0);
        $total_biaya = ($sales->biaya_admin ?? 0) + ($sales->biaya_service ?? 0) + ($sales->komisi_ams ?? 0) + ($sales->voucher_toko ?? 0) + $ads + $expenses;

        return ['omset' => $sales->omset ?? 0, 'laba_kotor' => $laba_kotor, 'biaya_admin' => $sales->biaya_admin ?? 0, 'biaya_service' => $sales->biaya_service ?? 0, 'komisi_ams' => $sales->komisi_ams ?? 0, 'voucher_toko' => $sales->voucher_toko ?? 0, 'biaya_iklan' => $ads, 'pengeluaran' => $expenses, 'profit' => $laba_kotor - $total_biaya];
    }
    
    private function calculateSummaries(array $report): void
    {
        $today = now()->day;
        $yesterday = now()->subDay()->day;
        
        $this->summaryToday = (now()->year == $this->selectedYear && now()->month == $this->selectedMonth && isset($report[$today])) ? $report[$today] : $this->getEmptySummary();
        
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
                                        <span class="ml-2 text-md font-semibold {{ $changeClass }}">
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