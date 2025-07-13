<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Order;
use App\Models\AdTransaction;
use App\Models\Expense;
use App\Models\StockMovement;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Collection;

middleware('auth');
name('reports.quarterly-overview');

new class extends Component {
    // Properti Filter
    public $selectedYear;
    public $selectedQuarter;

    // Properti untuk menampung data laporan
    public array $quarterlyProfitLoss = [];
    public array $costBreakdown = [];
    public array $inventoryHealth = [];
    public Collection $topPerformingProducts;
    public Collection $bottomPerformingProducts;
    public array $costBreakdownChartData = [];
    
    // Properti untuk Grafik
    public array $profitLossChartData = [];

    public function mount(): void
    {
        $this->selectedYear = now()->year;
        $this->selectedQuarter = now()->quarter;
        $this->topPerformingProducts = collect();
        $this->bottomPerformingProducts = collect();
    }
    
    private function getPeriodDates(): array
    {
        $quarter = (int) $this->selectedQuarter;
        $year = (int) $this->selectedYear;
        $startMonth = ($quarter - 1) * 3 + 1;
        $startDate = Carbon::create($year, $startMonth, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfQuarter();
        return [$startDate, $endDate];
    }
    
    // --- METODE YANG HILANG SEKARANG ADA DI SINI ---
    private function getAggregatedSummary($userId, $startDate, $endDate): array
    {
        $uniqueOshSubquery = DB::table('order_status_histories')->select('order_id', DB::raw('MIN(pickup_time) as first_pickup_time'))->where('status', 'Sudah Kirim')->whereNotNull('pickup_time')->groupBy('order_id');

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

        return ['omset' => $itemBased->omset ?? 0, 'laba_kotor' => $laba_kotor, 'biaya_admin' => $biaya_admin, 'biaya_service' => $biaya_service, 'komisi_ams' => $komisi_ams, 'voucher_toko' => $voucher_toko, 'biaya_iklan' => $biaya_iklan, 'pengeluaran' => $expenses, 'profit_bersih' => $laba_kotor - $total_biaya, 'total_cogs' => $itemBased->total_cogs ?? 0, 'total_marketplace_fees' => $biaya_admin + $biaya_service + $komisi_ams + $voucher_toko];
    }
    
    // --- SEMUA METODE LAINNYA JUGA LENGKAP DI SINI ---
    
    private function getProfitLossSummary($summary): array
    {
        $omset = $summary['omset'] ?? 0;
        $laba_kotor = $summary['laba_kotor'] ?? 0;
        $profit_bersih = $summary['profit_bersih'] ?? 0;
        return [
            'omset' => $omset, 'laba_kotor' => $laba_kotor, 'profit_bersih' => $profit_bersih,
            'margin_laba_kotor' => $omset > 0 ? ($laba_kotor / $omset) * 100 : 0,
            'margin_profit_bersih' => $omset > 0 ? ($profit_bersih / $omset) * 100 : 0,
        ];
    }
    
    private function getCostBreakdown($summary): array
    {
        return [
            'COGS' => $summary['total_cogs'] ?? 0,
            'Biaya Marketplace' => $summary['total_marketplace_fees'] ?? 0,
            'Biaya Iklan' => $summary['biaya_iklan'] ?? 0,
            'Pengeluaran Umum' => $summary['pengeluaran'] ?? 0,
        ];
    }
    
    private function getInventoryHealthSummary($userId, $startDate, $endDate, $cogs): array
    {
        $startStockValue = DB::table('stock_movements as sm')->join('product_variants as pv', 'sm.product_variant_id', '=', 'pv.id')->where('sm.user_id', $userId)->where('sm.created_at', '<', $startDate)->select(DB::raw('SUM(sm.quantity * pv.cost_price) as total_value'))->first()->total_value ?? 0;
        $endStockValue = DB::table('stock_movements as sm')->join('product_variants as pv', 'sm.product_variant_id', '=', 'pv.id')->where('sm.user_id', $userId)->where('sm.created_at', '<=', $endDate)->select(DB::raw('SUM(sm.quantity * pv.cost_price) as total_value'))->first()->total_value ?? 0;
        $purchases = DB::table('stock_movements as sm')->join('product_variants as pv', 'sm.product_variant_id', '=', 'pv.id')->where('sm.user_id', $userId)->where('sm.type', 'PEMBELIAN_STOK')->whereBetween('sm.created_at', [$startDate, $endDate])->sum(DB::raw('sm.quantity * pv.cost_price'));
        $avgStockValue = ($startStockValue + $endStockValue) / 2;
        
        return [
            'nilai_stok_awal' => $startStockValue, 'nilai_stok_akhir' => $endStockValue, 'total_pembelian' => $purchases,
            'inventory_turnover' => $avgStockValue > 0 ? round($cogs / $avgStockValue, 2) : 0,
        ];
    }
    
    private function getProductPerformance($userId, $startDate, $endDate): array
    {
        $uniqueOshSubquery = DB::table('order_status_histories')->select('order_id', DB::raw('MIN(pickup_time) as first_pickup_time'))->where('status', 'Sudah Kirim')->whereNotNull('pickup_time')->groupBy('order_id');
        $productsData = DB::table('products as p')->where('p.user_id', $userId)->join('product_variants as pv', 'p.id', '=', 'pv.product_id')->join('order_items as oi', 'pv.variant_sku', '=', 'oi.variant_sku')->join('orders as o', 'oi.order_id', '=', 'o.id')->joinSub($uniqueOshSubquery, 'unique_osh', 'o.id', '=', 'unique_osh.order_id')->whereBetween('unique_osh.first_pickup_time', [$startDate, $endDate])->select('p.id as product_id', 'p.product_name', 'p.image_url', DB::raw('SUM(oi.quantity) as total_terjual'), DB::raw('SUM(oi.subtotal) as total_omset'), DB::raw('SUM(oi.subtotal) - SUM(oi.quantity * pv.cost_price) as total_laba_kotor'))->groupBy('p.id', 'p.product_name', 'p.image_url')->get();
        return ['top' => $productsData->sortByDesc('total_laba_kotor')->take(5), 'bottom' => $productsData->sortBy('total_laba_kotor')->take(5)];
    }

    private function prepareChartsData($userId, $startDate)
    {
        $monthlyData = collect(range(0, 2))->map(function ($monthOffset) use ($userId, $startDate) {
            $monthStart = $startDate->copy()->addMonths($monthOffset)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $summary = $this->getAggregatedSummary($userId, $monthStart, $monthEnd);
            return ['month' => $monthStart->isoFormat('MMM'), 'omset' => $summary['omset'], 'laba_kotor' => $summary['laba_kotor'], 'profit_bersih' => $summary['profit_bersih']];
        });

        $this->profitLossChartData = [
            'labels' => $monthlyData->pluck('month')->all(),
            'series' => [
                ['name' => 'Omset', 'data' => $monthlyData->pluck('omset')->all()],
                ['name' => 'Laba Kotor', 'data' => $monthlyData->pluck('laba_kotor')->all()],
                ['name' => 'Profit Bersih', 'data' => $monthlyData->pluck('profit_bersih')->all()],
            ],
        ];
    }

    public function with(): array
    {
        $userId = auth()->id();
        [$startDate, $endDate] = $this->getPeriodDates();

        // Hitung ringkasan utama kuartal ini SEKALI saja
        $mainSummary = $this->getAggregatedSummary($userId, $startDate, $endDate);

        // Bagikan hasil ke setiap widget
        $this->quarterlyProfitLoss = $this->getProfitLossSummary($mainSummary);
        $this->costBreakdown = $this->getCostBreakdown($mainSummary);
        $this->inventoryHealth = $this->getInventoryHealthSummary($userId, $startDate, $endDate, $mainSummary['total_cogs']);

        $productPerformance = $this->getProductPerformance($userId, $startDate, $endDate);
        $this->topPerformingProducts = $productPerformance['top'];
        $this->bottomPerformingProducts = $productPerformance['bottom'];

        // Siapkan data untuk grafik
        $this->prepareChartsData($userId, $startDate);
        $this->costBreakdownChartData = [
            'labels' => array_keys($this->costBreakdown),
            'series' => array_map('floatval', array_values($this->costBreakdown)),
        ];
        $availableYears = Order::where('user_id', auth()->id())
            ->join('order_status_histories', 'orders.id', '=', 'order_status_histories.order_id')
            ->whereNotNull('order_status_histories.pickup_time')->where('order_status_histories.status', 'Sudah Kirim')
            ->select(DB::raw('YEAR(order_status_histories.pickup_time) as year'))
            ->distinct()->orderBy('year', 'desc')->get()->pluck('year');
        
        return ['availableYears' => $availableYears];
    }
};
?>

<x-layouts.app>
    @volt('reports-quarterly-overview')
    <x-app.container>
        <x-app.heading title="Tinjauan Laporan Kuartalan" description="Lihat performa bisnis Anda dalam periode 3 bulanan." />

        {{-- Filter --}}
        <div class="mt-6 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <x-select-input wire:model.live="selectedYear" class="text-sm">
                    @foreach($availableYears as $year)<option value="{{ $year }}">{{ $year }}</option>@endforeach
                </x-select-input>
                <x-select-input wire:model.live="selectedQuarter" class="text-sm">
                    <option value="1">Kuartal 1 (Jan - Mar)</option>
                    <option value="2">Kuartal 2 (Apr - Jun)</option>
                    <option value="3">Kuartal 3 (Jul - Sep)</option>
                    <option value="4">Kuartal 4 (Okt - Des)</option>
                </x-select-input>
            </div>
        </div>
        
        {{-- PERUBAHAN 1: Struktur Grid Baru --}}
        <div class="mt-8 space-y-8">
            {{-- Bagian Atas: Card Ringkasan & Biaya --}}
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-8">
                {{-- Kolom Kiri Lebih Besar (3/5) --}}
                <div class="lg:col-span-3 space-y-8">
                    {{-- 1. Widget: Ringkasan Laba Rugi dengan Grafik Modern --}}
                    <div class="bg-white dark:bg-gray-800/50 shadow-sm rounded-lg p-6"
                        x-data="{
                            chartData: @entangle('profitLossChartData'),
                            init() {
                                let chart = new ApexCharts(this.$refs.chart, this.getOptions());
                                chart.render();
                                this.$watch('chartData', () => {
                                    chart.updateOptions(this.getOptions());
                                });
                                window.addEventListener('theme-changed', () => {
                                    chart.updateOptions({ theme: { mode: localStorage.getItem('theme') } });
                                });
                            },
                            getOptions() {
                                return {
                                    chart: { 
                                        type: 'area', 
                                        height: 250,
                                        toolbar: { show: false },
                                    },
                                    series: this.chartData.series,
                                    
                                    xaxis: {
                                        categories: this.chartData.labels,
                                        labels: {
                                            style: {
                                                colors: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280',
                                                fontSize: '12px',
                                            },
                                        },
                                        axisBorder: { show: false },
                                        axisTicks: { show: false },
                                    },
                                    yaxis: {
                                        labels: {
                                            style: {
                                                colors: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280',
                                                fontSize: '12px',
                                            },
                                            formatter: function (value) {
                                                if (value >= 1000000) {
                                                    return (value / 1000000).toFixed(1).replace('.0', '') + ' Jt';
                                                }
                                                if (value >= 1000) {
                                                    return (value / 1000).toFixed(1).replace('.0', '') + ' Rb';
                                                }
                                                return value;
                                            }
                                        }
                                    },
                                    grid: {
                                        show: true,
                                        borderColor: document.documentElement.classList.contains('dark') ? '#374151' : '#e5e7eb',
                                        strokeDashArray: 4,
                                        yaxis: {
                                            lines: {
                                                show: true
                                            }
                                        },
                                        xaxis: {
                                            lines: {
                                                show: false
                                            }
                                        }
                                    },

                                    stroke: { curve: 'smooth', width: 2 },
                                    fill: { type: 'gradient', gradient: { opacityFrom: 0.6, opacityTo: 0.05, } },
                                    colors: ['#dc2626', '#1d4ed8', '#16a34a'], // Omset, Laba Kotor, Profit Bersih
                                    dataLabels: { enabled: false },
                                    tooltip: { 
                                        y: { formatter: (val) => 'Rp ' + val.toLocaleString('id-ID') },
                                        theme: localStorage.getItem('theme') || 'light'
                                    },
                                    theme: { mode: localStorage.getItem('theme') || 'light' }
                                }
                            }
                        }">
                        <h3 class="font-semibold text-lg text-gray-900 dark:text-white">Tren Laba Rugi Kuartalan</h3>
                        {{-- Elemen untuk menampung grafik --}}
                        <div class="mt-2 -mx-4" x-ref="chart"></div>
                        <div class="mt-4 space-y-3 border-t border-gray-200 dark:border-gray-700 pt-4">
                            @foreach($quarterlyProfitLoss as $key => $value)
                                <div class="flex justify-between items-baseline text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">{{ Str::title(str_replace('_', ' ', $key)) }}</span>
                                    <span class="font-semibold text-gray-900 dark:text-white">
                                        @if(Str::contains($key, 'margin')) {{ number_format($value, 2) }}%
                                        @else Rp {{ number_format($value, 0, ',', '.') }} @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Kolom Kanan Lebih Kecil (2/5) --}}
                <div class="lg:col-span-2 space-y-8">
                     {{-- 2. Widget: Rincian Biaya dengan Grafik Donat Modern --}}
<div class="bg-white dark:bg-gray-800/50 shadow-sm rounded-lg p-6"
     x-data="{
        chart: null,
        chartData: @entangle('costBreakdownChartData'),
        init() {
            this.renderChart();
            
            this.$watch('chartData', () => {
                this.renderChart();
            });

            window.addEventListener('theme-changed', () => {
                if (this.chart) {
                    this.chart.updateOptions({ theme: { mode: localStorage.getItem('theme') } });
                }
            });
        },
        renderChart() {
            if (this.chart) {
                this.chart.destroy();
            }
            if (this.chartData && this.chartData.series && this.chartData.series.length > 0) {
                 this.chart = new ApexCharts(this.$refs.donut, this.getOptions());
                 this.chart.render();
            }
        },
        getOptions() {
            return {
                chart: { type: 'donut', height: 250 },
                series: this.chartData.series,
                labels: this.chartData.labels,
                colors: ['#22c55e', '#3b82f6', '#ef4444', '#6b7280'],
                plotOptions: {
                    pie: {
                        donut: {
                            labels: {
                                show: true,
                                value: {
                                    show: true,
                                    fontSize: '22px',
                                    fontWeight: 'bold',
                                    formatter: function (val) {
                                        return 'Rp ' + parseFloat(val).toLocaleString('id-ID');
                                    }
                                },
                                total: {
                                    show: true,
                                    label: 'Total Biaya',
                                    formatter: (w) => {
                                        const total = w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                                        return 'Rp ' + total.toLocaleString('id-ID');
                                    }
                                }
                            }
                        }
                    }
                },
                legend: {
                    position: 'bottom',
                    horizontalAlign: 'center',
                    formatter: function(seriesName, opts) {
                        return [seriesName, ' - Rp ', opts.w.globals.series[opts.seriesIndex].toLocaleString('id-ID')];
                    }
                },
                tooltip: { 
                    y: { formatter: (val) => 'Rp ' + val.toLocaleString('id-ID'), title: { formatter: (seriesName) => seriesName + ':' } },
                    fillSeriesColor: false
                },
                theme: { mode: localStorage.getItem('theme') || 'light' }
            }
        }
     }">
    <h3 class="font-semibold text-lg text-gray-900 dark:text-white">Proporsi Biaya</h3>
    <div class="mt-4" x-ref="donut"></div>
</div>
                </div>
            </div>

            {{-- Bagian Bawah: Analisis Inventaris & Produk (Lebar Penuh) --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                 {{-- 3. Widget: Analisis Kesehatan Inventaris --}}
                <div class="bg-white dark:bg-gray-800/50 shadow-sm rounded-lg p-6">
                    <h3 class="font-semibold text-lg text-gray-900 dark:text-white">Kesehatan Inventaris</h3>
                    <div class="mt-4 space-y-3">
                       @foreach($inventoryHealth as $key => $value)
                        <div class="flex justify-between items-baseline">
                            <span class="text-gray-500 dark:text-gray-400">{{ Str::title(str_replace('_', ' ', $key)) }}</span>
                            <span class="font-semibold text-gray-900 dark:text-white">
                                @if(Str::contains($key, 'turnover')) {{ $value }} kali
                                @else Rp {{ number_format($value, 0, ',', '.') }} @endif
                            </span>
                        </div>
                        @endforeach
                    </div>
                </div>
                
                 {{-- 4a. Widget Baru: Dead Stock Report --}}
                 {{-- Placeholder untuk Ide #3 nanti --}}
            </div>
            
            {{-- Widget Analisis Produk (Lebar Penuh) --}}
            <div class="bg-white dark:bg-gray-800/50 shadow-sm rounded-lg p-6">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-8 gap-y-6">
                    <div>
                        <h3 class="font-semibold text-lg text-gray-900 dark:text-white">Produk Paling Menguntungkan</h3>
                        <ul role="list" class="mt-4 space-y-4">
                            @forelse($topPerformingProducts as $product)
                                <li class="flex items-center gap-4">
                                    <img class="h-10 w-10 rounded-md object-cover flex-shrink-0" src="{{ $product->image_url }}" alt="">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ $product->product_name }}</p>
                                        <div class="w-full bg-gray-200 rounded-full h-1.5 dark:bg-gray-700 mt-1">
                                            <div class="bg-green-500 h-1.5 rounded-full" style="width: {{ $topPerformingProducts->max('total_laba_kotor') > 0 ? ($product->total_laba_kotor / $topPerformingProducts->max('total_laba_kotor') * 100) : 0 }}%"></div>
                                        </div>
                                    </div>
                                    <div class="text-sm font-semibold text-green-600">
                                        Rp {{ number_format($product->total_laba_kotor, 0, ',', '.') }}
                                    </div>
                                </li>
                            @empty
                                <p class="py-4 text-sm text-gray-500">Tidak ada data penjualan pada periode ini.</p>
                            @endforelse
                        </ul>
                    </div>
                     <div>
                        <h3 class="font-semibold text-lg text-gray-900 dark:text-white">Produk Paling Tidak Menguntungkan</h3>
                        <ul role="list" class="mt-4 space-y-4">
                            @forelse($bottomPerformingProducts as $product)
                                <li class="flex items-center gap-4">
                                    <img class="h-10 w-10 rounded-md object-cover flex-shrink-0" src="{{ $product->image_url }}" alt="">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ $product->product_name }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Terjual {{ $product->total_terjual }} unit</p>
                                    </div>
                                    <div class="text-sm font-semibold {{ $product->total_laba_kotor < 0 ? 'text-red-600' : 'text-gray-700' }}">
                                        Rp {{ number_format($product->total_laba_kotor, 0, ',', '.') }}
                                    </div>
                                </li>
                            @empty
                                <p class="py-4 text-sm text-gray-500">Tidak ada data penjualan pada periode ini.</p>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </x-app.container>
    @endvolt
</x-layouts.app>