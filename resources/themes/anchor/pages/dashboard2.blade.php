<?php

use function Laravel\Folio\{middleware, name};
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use Carbon\Carbon;

middleware(['auth']);
name('dashboard');

new class extends Component {
    // Properti untuk filter
    public int $year;
    public int $month;

    // Properti untuk kustomisasi widget
    public array $availableWidgets = [];
    public array $userWidgets = [];

    // Metode Lifecycle
    public function mount(): void
    {
        $this->year = now()->year;
        $this->month = now()->month;

        $this->availableWidgets = [
            'summary_cards' => ['title' => 'Kartu Ringkasan', 'description' => 'Tampilkan total pendapatan, pesanan, dan anomali.'],
            'sales_chart' => ['title' => 'Grafik Tren Pendapatan', 'description' => 'Visualisasi pendapatan harian dalam sebulan.'],
            'anomaly_chart' => ['title' => 'Grafik Tren Anomali', 'description' => 'Visualisasi kerugian/keuntungan ongkir harian.'],
            'top_products' => ['title' => 'Produk Terlaris', 'description' => 'Daftar produk dengan penjualan tertinggi.'],
        ];
        
        $user = auth()->user();
        $this->userWidgets = $user->dashboard_widgets ?? ['summary_cards', 'sales_chart', 'anomaly_chart', 'top_products'];
    }

    public function toggleWidget(string $widgetKey): void
    {
        if (($key = array_search($widgetKey, $this->userWidgets)) !== false) {
            unset($this->userWidgets[$key]);
        } else {
            $this->userWidgets[] = $widgetKey;
        }
        $this->userWidgets = array_values($this->userWidgets);
        auth()->user()->update(['dashboard_widgets' => $this->userWidgets]);
    }

    public function with(): array
    {
        $userId = auth()->id();
        $selectedDate = Carbon::create($this->year, $this->month, 1);

        if ($selectedDate->isCurrentMonth()) {
            $startDate = $selectedDate->copy()->startOfMonth();
            $endDate = now()->subDay()->endOfDay();
        } else {
            $startDate = $selectedDate->copy()->startOfMonth();
            $endDate = $selectedDate->copy()->endOfMonth();
        }

        $ordersInPeriodQuery = Order::query()
            ->where('orders.user_id', $userId)
            ->whereBetween('orders.created_at', [$startDate, $endDate]);

        $totalOrders = (clone $ordersInPeriodQuery)->count();

        $paymentDetailsQuery = (clone $ordersInPeriodQuery)
            ->join('order_payment_details', 'orders.id', '=', 'order_payment_details.order_id');
        
        $totalRevenue = (clone $paymentDetailsQuery)->sum('order_payment_details.total_income');
        $shippingAnomaly = (clone $paymentDetailsQuery)
            ->sum(DB::raw('order_payment_details.shipping_fee_paid_by_buyer + order_payment_details.shopee_shipping_subsidy + order_payment_details.shipping_fee_paid_to_logistic'));
        
        $salesData = (clone $paymentDetailsQuery)
            ->select(DB::raw('DATE(orders.created_at) as date'), DB::raw('SUM(order_payment_details.total_income) as total'))
            ->groupBy('date')->orderBy('date', 'asc')->get();
        
        $anomalyData = (clone $paymentDetailsQuery)
            ->select(DB::raw('DATE(orders.created_at) as date'), DB::raw('SUM(order_payment_details.shipping_fee_paid_by_buyer + order_payment_details.shopee_shipping_subsidy + order_payment_details.shipping_fee_paid_to_logistic) as total'))
            ->groupBy('date')->orderBy('date', 'asc')->get();
            
        $topProducts = (clone $ordersInPeriodQuery)
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->select('order_items.product_name', DB::raw('SUM(order_items.quantity) as total_sold'))
            ->groupBy('order_items.product_name')->orderBy('total_sold', 'desc')->limit(5)->get();

        return [
            'summary' => ['totalRevenue' => $totalRevenue, 'totalOrders' => $totalOrders, 'shippingAnomaly' => $shippingAnomaly, 'period' => $startDate->isoFormat('D MMM') . ' - ' . $endDate->isoFormat('D MMM YYYY')],
            'charts' => ['sales' => $this->prepareChartData($salesData, $startDate, $endDate, 'total'), 'anomaly' => $this->prepareChartData($anomalyData, $startDate, $endDate, 'total')],
            'topProducts' => $topProducts,
        ];
    }
    
    private function prepareChartData($data, Carbon $start, Carbon $end, string $valueColumn): array
    {
        $period = collect(Carbon::parse($start)->toPeriod($end));
        $dataset = collect($data)->keyBy(fn($item) => Carbon::parse($item->date)->format('Y-m-d'));
        
        $labels = []; $seriesData = [];
        foreach ($period as $date) {
            $dateString = $date->format('Y-m-d');
            $labels[] = $date->format('d M');
            $seriesData[] = $dataset[$dateString]->$valueColumn ?? 0;
        }
        return ['labels' => $labels, 'series' => $seriesData];
    }
}; ?>

<x-layouts.app>
    @volt('dashboard')
    <x-app.container>
        {{-- Header dan Kontrol Kustomisasi tidak berubah --}}
        <div class="md:flex md:items-center md:justify-between">
            <div class="min-w-0 flex-1">
                <h2 class="text-2xl font-bold leading-7 text-gray-900 dark:text-white sm:truncate sm:text-3xl sm:tracking-tight">Dashboard</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Ringkasan performa toko untuk periode {{ $summary['period'] }}.</p>
            </div>
            <div class="mt-4 flex md:ml-4 md:mt-0 items-center space-x-2">
                <div><select wire:model.live="year" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:bg-gray-800 dark:border-gray-600 dark:text-gray-200">@for ($y = now()->year; $y >= now()->year - 5; $y--) <option value="{{ $y }}">{{ $y }}</option> @endfor</select></div>
                <div><select wire:model.live="month" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:bg-gray-800 dark:border-gray-600 dark:text-gray-200">@foreach (range(1, 12) as $m) <option value="{{ $m }}">{{ Carbon::create(null, $m)->isoFormat('MMMM') }}</option> @endforeach</select></div>
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" type="button" class="inline-flex items-center gap-x-1.5 rounded-md bg-white dark:bg-gray-700 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600"><svg class="-ml-0.5 h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3.75a2 2 0 100 4 2 2 0 000-4zM10 8.75a2 2 0 100 4 2 2 0 000-4zM10 13.75a2 2 0 100 4 2 2 0 000-4z" /></svg>Kustomisasi</button>
                    <div x-show="open" @click.away="open = false" x-transition class="absolute right-0 z-10 mt-2 w-72 origin-top-right rounded-md bg-white dark:bg-gray-800 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none" style="display: none;">
                        <div class="py-1">@foreach($availableWidgets as $key => $widget)<div class="relative flex items-start px-4 py-2"><div class="flex h-6 items-center"><input id="widget-{{$key}}" wire:click="toggleWidget('{{ $key }}')" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:bg-gray-700 dark:border-gray-600" {{ in_array($key, $userWidgets) ? 'checked' : '' }}></div><div class="ml-3 text-sm leading-6"><label for="widget-{{$key}}" class="font-medium text-gray-900 dark:text-gray-100">{{ $widget['title'] }}</label><p class="text-gray-500 dark:text-gray-400">{{ $widget['description'] }}</p></div></div>@endforeach</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- GRID WIDGET --}}
        <div class="mt-8">
            <div wire:loading.flex class="w-full items-center justify-center py-16">
                <svg class="animate-spin h-8 w-8 text-indigo-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                <span class="ml-3 text-gray-600 dark:text-gray-300">Memuat data dashboard...</span>
            </div>
            <div wire:loading.remove>
                @if(in_array('summary_cards', $userWidgets))
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <x-app.stat-card title="Total Pendapatan Bersih"><x-slot:value>Rp {{ number_format($summary['totalRevenue'], 0, ',', '.') }}</x-slot:value></x-app.stat-card>
                    <x-app.stat-card title="Total Pesanan"><x-slot:value>{{ number_format($summary['totalOrders'], 0, ',', '.') }}</x-slot:value></x-app.stat-card>
                    <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700"><dt class="text-sm font-medium text-gray-500 dark:text-gray-400 truncate">Selisih Ongkir (Untung/Rugi)</dt><dd class="mt-1 text-2xl font-semibold tracking-tight {{ $summary['shippingAnomaly'] < 0 ? 'text-red-500' : 'text-green-500' }}">{{ $summary['shippingAnomaly'] > 0 ? '+' : '' }}Rp {{ number_format($summary['shippingAnomaly'], 0, ',', '.') }}</dd></div>
                </div>
                @endif
                <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
                    @if(in_array('sales_chart', $userWidgets))
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700" wire:key="sales-chart-widget">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">Tren Pendapatan</h3>
                        <div x-data="{
                                chart: null,
                                updateChart(data) {
                                    if (!this.chart || !data) return;
                                    this.chart.updateOptions({ xaxis: { categories: data.labels || [] } });
                                    this.chart.updateSeries([{ data: data.series || [] }]);
                                },
                                init() {
                                    const options = {
                                        series: [{ name: 'Pendapatan', data: [] }],
                                        chart: { id: 'sales-chart', height: 300, type: 'area', toolbar: { show: false }, zoom: { enabled: false } },
                                        xaxis: { categories: [], labels: { style: { colors: '#9ca3af' } } },
                                        yaxis: { labels: { style: { colors: '#9ca3af' }, formatter: (val) => 'Rp ' + new Intl.NumberFormat('id-ID').format(val) } },
                                        dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 },
                                        fill: { type: 'gradient', gradient: { opacityFrom: 0.6, opacityTo: 0.05 } },
                                        tooltip: { theme: 'dark', x: { format: 'dd MMM yyyy' } },
                                        grid: { borderColor: '#374151', strokeDashArray: 4 },
                                    };
                                    this.chart = new ApexCharts($refs.chart, options);
                                    this.chart.render();
                                    
                                    this.$nextTick(() => this.updateChart($wire.charts.sales));
                                    $watch('$wire.charts.sales', (newData) => this.updateChart(newData));
                                }
                            }" x-init="init()" wire:ignore>
                            <div x-ref="chart"></div>
                        </div>
                    </div>
                    @endif
                    @if(in_array('top_products', $userWidgets))
                    <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Produk Terlaris</h3>
                        <ul role="list" class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($topProducts as $product)
                            <li class="py-3 sm:py-4"><div class="flex items-center space-x-4"><div class="flex-shrink-0"><div class="h-8 w-8 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center"><svg class="h-5 w-5 text-indigo-600 dark:text-indigo-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" /></svg></div></div><div class="flex-1 min-w-0"><p class="text-sm font-medium text-gray-900 truncate dark:text-white">{{ $product->product_name }}</p></div><div class="inline-flex items-center text-base font-semibold text-gray-900 dark:text-white">{{ $product->total_sold }} <span class="text-xs text-gray-500 ml-1">terjual</span></div></div></li>
                            @empty
                            <li class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">Tidak ada data penjualan produk pada periode ini.</li>
                            @endforelse
                        </ul>
                    </div>
                    @endif
                    @if(in_array('anomaly_chart', $userWidgets))
                    <div class="lg:col-span-3 bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700" wire:key="anomaly-chart-widget">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">Tren Anomali Ongkir</h3>
                        <div x-data="{
                                chart: null,
                                updateChart(data) {
                                    if (!this.chart || !data) return;
                                    this.chart.updateOptions({ xaxis: { categories: data.labels || [] } });
                                    this.chart.updateSeries([{ data: data.series || [] }]);
                                },
                                init() {
                                    const options = {
                                        series: [{ name: 'Selisih Ongkir', data: [] }],
                                        chart: { id: 'anomaly-chart', height: 300, type: 'bar', toolbar: { show: false }, zoom: { enabled: false } },
                                        colors: [({ value }) => value < 0 ? '#ef4444' : '#22c55e'],
                                        xaxis: { categories: [], labels: { style: { colors: '#9ca3af' } } },
                                        yaxis: { labels: { style: { colors: '#9ca3af' }, formatter: (val) => 'Rp ' + new Intl.NumberFormat('id-ID').format(val) } },
                                        dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 },
                                        tooltip: { theme: 'dark', x: { format: 'dd MMM yyyy' } },
                                        grid: { borderColor: '#374151', strokeDashArray: 4 },
                                    };
                                    this.chart = new ApexCharts($refs.chart, options);
                                    this.chart.render();

                                    this.$nextTick(() => this.updateChart($wire.charts.anomaly));
                                    $watch('$wire.charts.anomaly', (newData) => this.updateChart(newData));
                                }
                            }" x-init="init()" wire:ignore>
                            <div x-ref="chart"></div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </x-app.container>
    @endvolt
</x-layouts.app>