<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\SkuComposition;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

middleware('auth');
name('inventory.sku-master');

new class extends Component {
    use WithPagination;

    public string $search = '';
    public array $skuData = [];
    public array $productCategories = [];
    public ?string $editingSku = null;

    protected $listeners = ['compositionSaved' => 'updateSuggestedPriceForSku'];

    public function mount(): void
    {
        $this->productCategories = ProductCategory::where('user_id', auth()->id())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
    
    // ... (metode-metode lain seperti updatedSearch, updatingPage, dll tetap sama) ...
    
    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->cancelEditing();
    }

    public function updatingPage(): void
    {
        $this->cancelEditing();
    }

    public function updateSuggestedPriceForSku(string $sku): void
    {
        if (isset($this->skuData[$sku])) {
            $this->skuData[$sku]['suggested_cost_price'] = $this->calculateSuggestedPrice($sku);
            
            $componentSkus = SkuComposition::where('bundle_sku', $sku)->pluck('component_sku')->unique();
            $componentStocks = ProductVariant::whereIn('variant_sku', $componentSkus)
                ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
                ->pluck('warehouse_stock', 'variant_sku');

            $this->skuData[$sku]['warehouse_stock_raw'] = $this->calculateBundleStock($sku, $componentStocks);
        }
    }

    public function updatedSkuData($value, $key)
    {
        $parts = explode('.', $key);
        if (count($parts) === 2 && $parts[1] === 'sku_type' && $value === 'gabungan') {
            $sku = $parts[0];
            $this->dispatch('manage-composition', sku: $sku);
        }
    }
    
    private function calculateSuggestedPrice(string $bundleSku): float
    {
        $compositions = SkuComposition::where('bundle_sku', $bundleSku)->where('user_id', auth()->id())->get();
        if ($compositions->isEmpty()) {
            return 0;
        }

        $componentPrices = ProductVariant::whereIn('variant_sku', $compositions->pluck('component_sku')->unique())
            ->pluck('cost_price', 'variant_sku');

        $totalSuggestedCost = 0;
        foreach ($compositions as $component) {
            $price = $componentPrices->get($component->component_sku, 0);
            $totalSuggestedCost += $price * $component->quantity;
        }

        return $totalSuggestedCost;
    }
    
    private function calculateBundleStock(string $bundleSku, Collection $componentStocks): int
    {
        $compositions = SkuComposition::where('bundle_sku', $bundleSku)
                                      ->where('user_id', auth()->id())
                                      ->get();

        if ($compositions->isEmpty()) {
            return 0;
        }

        $maxPossibleSets = PHP_INT_MAX;

        foreach ($compositions as $component) {
            $requiredQty = $component->quantity;
            if ($requiredQty <= 0) continue;

            $availableStock = $componentStocks->get($component->component_sku, 0);
            
            $possibleSetsFromThisComponent = floor($availableStock / $requiredQty);

            if ($possibleSetsFromThisComponent < $maxPossibleSets) {
                $maxPossibleSets = $possibleSetsFromThisComponent;
            }
        }
        
        return $maxPossibleSets === PHP_INT_MAX ? 0 : (int)$maxPossibleSets;
    }
    
public function saveSku(string $sku): void
    {
        if (empty($sku) || !isset($this->skuData[$sku])) {
            $this->cancelEditing();
            return;
        }
        $currentData = $this->skuData[$sku];

        // Validasi, sekarang juga perlu 'representative_product_id'
        $rules = [
            'representative_product_id' => 'required|exists:products,id',
            'cost_price_raw'      => 'required|numeric|min:0',
            'selling_price_raw'   => 'required|numeric|min:0',
            'product_category_id' => 'nullable|exists:product_categories,id',
            'status'              => 'required|in:active,draft',
            'sku_type'            => 'required|in:mandiri,gabungan',
            'reseller'            => 'required|boolean', // <-- TAMBAHKAN VALIDASI INI
            'weight_raw'          => 'required|integer|min:0',
        ];

        if ($currentData['sku_type'] !== 'gabungan') {
            $rules['warehouse_stock_raw'] = 'required|integer|min:0';
        }

        $validated = validator($currentData, $rules)->validate();

        DB::transaction(function () use ($sku, $validated, $currentData) {
            // =======================================================
            // PERBAIKAN UTAMA: Update produk representatif secara terpisah
            // =======================================================
            Product::where('id', $validated['representative_product_id'])
                ->where('user_id', auth()->id()) // Keamanan ekstra
                ->update([
                        'status' => $validated['status'],
                        'product_category_id' => $validated['product_category_id'],
                ]);

            // Query untuk semua varian dengan SKU ini
            $variantsQuery = ProductVariant::where('variant_sku', $sku)
                                        ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()));
            
            // Logika untuk SKU Mandiri
            if ($currentData['sku_type'] !== 'gabungan') {
                $firstVariant = $variantsQuery->clone()->first();
                $originalStock = $firstVariant->warehouse_stock ?? 0;
                $newStock = $validated['warehouse_stock_raw'];
                $stockDifference = $newStock - $originalStock;
                
                // Lakukan MASS UPDATE HANYA untuk data level-SKU
                $variantsQuery->update([
                    'cost_price' => $validated['cost_price_raw'],
                    'selling_price' => $validated['selling_price_raw'],
                    'warehouse_stock' => $validated['warehouse_stock_raw'],
                    'weight' => $validated['weight_raw'],
                    'sku_type' => $validated['sku_type'],
                    'reseller' => $validated['reseller'],
                ]);
                
                if ($firstVariant) {
                    $this->handleStockAdjustment($firstVariant, $stockDifference, $sku);
                }
            } else {
                // Logika untuk SKU Gabungan (hanya update data non-stok level-SKU)
                $variantsQuery->update([
                    'cost_price' => $validated['cost_price_raw'],
                    'selling_price' => $validated['selling_price_raw'],
                    'weight' => $validated['weight_raw'],
                    'sku_type' => $validated['sku_type'],
                    'reseller' => $validated['reseller'],
                ]);
            }
        });
        
        // Update state di frontend (tidak berubah)
        $this->skuData[$sku]['cost_price_raw'] = $validated['cost_price_raw'];
        $this->skuData[$sku]['selling_price_raw'] = $validated['selling_price_raw'];
        $this->skuData[$sku]['weight_raw'] = $validated['weight_raw'];
        $this->skuData[$sku]['product_category_id'] = $validated['product_category_id'];
        $this->skuData[$sku]['status'] = $validated['status'];
        $this->skuData[$sku]['sku_type'] = $validated['sku_type'];
        $this->skuData[$sku]['reseller'] = $validated['reseller'];
        
        $this->cancelEditing();
        Notification::make()->title('Update Berhasil')->success()->body("Perubahan untuk SKU '{$sku}' telah berhasil disimpan.")->send();
    }

    public function editSku(string $sku): void { $this->editingSku = $sku; }
    public function cancelEditing(): void { $this->editingSku = null; }
    
    protected function handleStockAdjustment(ProductVariant $variant, int $stockDifference, string $sku): void {
        if ($stockDifference == 0) return;
        $userId = auth()->id();
        StockMovement::create([
            'user_id' => $userId, 'product_variant_id' => $variant->id, 'type' => 'adjustment',
            'quantity' => $stockDifference, 'notes' => 'Penyesuaian massal dari Master SKU: ' . $sku,
        ]);
        if ($variant->sku_type === 'gabungan') {
            $components = SkuComposition::where('bundle_sku', $sku)->where('user_id', $userId)->get();
            foreach ($components as $component) {
                $componentVariant = ProductVariant::where('variant_sku', $component->component_sku)
                    ->whereHas('product', fn($q) => $q->where('user_id', $userId))->first();
                if ($componentVariant) {
                    $componentStockChange = $component->quantity * $stockDifference * -1;
                    ProductVariant::where('variant_sku', $component->component_sku)
                                  ->whereHas('product', fn($q) => $q->where('user_id', $userId))
                                  ->increment('warehouse_stock', $componentStockChange);
                    StockMovement::create([
                        'user_id' => $userId, 'product_variant_id' => $componentVariant->id, 'type' => 'bundle_adjustment',
                        'quantity' => $componentStockChange, 'notes' => "Penyesuaian otomatis dari SKU gabungan '{$sku}'",
                    ]);
                }
            }
        }
    }

    private function getVariantsForSkus(array $skus): Collection
    {
        if (empty($skus)) {
            return collect();
        }
        
        return ProductVariant::whereIn('variant_sku', $skus)
            ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
            ->with('product:id,product_name,product_category_id,status')
            ->get()
            ->groupBy('variant_sku');
    }
    
    // private function getPaginatedSkus()
    // {
    //     return ProductVariant::query()
    //         ->join('products', 'product_variants.product_id', '=', 'products.id')
    //         ->leftJoin('product_categories', 'products.product_category_id', '=', 'product_categories.id')
    //         ->where('products.user_id', auth()->id())
    //         ->where(function($q) {
    //             $q->where('product_variants.variant_sku', '!=', '')->whereNotNull('product_variants.variant_sku');
    //         })
    //         ->when($this->search, function($q) {
    //             $q->where('product_variants.variant_sku', 'like', '%' . $this->search . '%')
    //               ->orWhere('products.product_name', 'like', '%' . $this->search . '%')
    //               ->orWhere('product_categories.name', 'like', '%' . $this->search . '%');
    //         })
    //         ->select('product_variants.variant_sku')
    //         ->distinct()
    //         ->orderByRaw('ISNULL(product_categories.name), product_categories.name ASC, product_variants.variant_sku ASC')
    //         ->paginate(25);
    // }

    private function getSummaryData(): array
    {
        $uniqueMandiriSkus = ProductVariant::query()
            ->where('sku_type', 'mandiri')
            ->where(function ($q) {
                $q->where('variant_sku', '!=', '')->whereNotNull('variant_sku');
            })
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->where('products.user_id', auth()->id())
            ->leftJoin('product_categories', 'products.product_category_id', '=', 'product_categories.id')
            ->selectRaw("
                product_variants.variant_sku,
                MAX(product_variants.warehouse_stock) as warehouse_stock,
                MAX(product_variants.cost_price) as cost_price,
                MAX(product_categories.name) as category_name
            ")
            ->groupBy('product_variants.variant_sku')
            ->get();

        $overallTotalStock = 0;
        $overallTotalValue = 0;

        $categorySummaries = $uniqueMandiriSkus
            ->groupBy('category_name')
            ->map(function ($skus, $categoryName) use (&$overallTotalStock, &$overallTotalValue) {
                $categoryStock = $skus->sum('warehouse_stock');
                $categoryValue = $skus->sum(fn($sku) => $sku->warehouse_stock * $sku->cost_price);

                $overallTotalStock += $categoryStock;
                $overallTotalValue += $categoryValue;
                
                return [
                    'name' => $categoryName ?: 'Tanpa Kategori',
                    'total_stock' => $categoryStock,
                    'total_value' => $categoryValue,
                ];
            })
            ->sortBy('name')
            ->values();

        return [
            'categories' => $categorySummaries,
            'overall' => [
                'total_stock' => $overallTotalStock,
                'total_value' => $overallTotalValue,
            ],
        ];
    }

    // PERUBAHAN: Fungsi baru untuk memformat data untuk chart
    private function getInitialChartData(array $summary): array
    {
        if (empty($summary['categories'])) {
            return ['series' => [], 'labels' => []];
        }

        // Ambil hanya data nilai modal dan nama kategori
        $series = $summary['categories']->pluck('total_value')->all();
        $labels = $summary['categories']->pluck('name')->all();

        return [
            'series' => $series,
            'labels' => $labels,
        ];
    }
    
    public function with(): array
    {
        // ====================================================================
        // LANGKAH 1: Ambil daftar SKU unik untuk paginasi (logika ini sudah benar)
        // ====================================================================
        $baseQuery = ProductVariant::query()
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->where('products.user_id', auth()->id())
            ->where(function ($q) {
                $q->where('product_variants.variant_sku', '!=', '')->whereNotNull('product_variants.variant_sku');
            });

        if ($this->search) {
            $baseQuery->where(function ($q) {
                $q->where('product_variants.variant_sku', 'like', '%' . $this->search . '%')
                  ->orWhere('products.product_name', 'like', '%' . $this->search . '%')
                  ->orWhereHas('product.productCategory', function ($q) {
                      $q->where('name', 'like', '%' . $this->search . '%');
                  });
            });
        }
        
        $allMatchingUniqueSkus = $baseQuery
            ->leftJoin('product_categories', 'products.product_category_id', '=', 'product_categories.id')
            ->select('product_variants.variant_sku')
            ->distinct()
            ->orderByRaw('ISNULL(product_categories.name), product_categories.name ASC, product_variants.variant_sku ASC')
            ->pluck('variant_sku');

        // ====================================================================
        // LANGKAH 2: Buat paginator manual (logika ini sudah benar)
        // ====================================================================
        $perPage = 25;
        $currentPage = $this->getPage();
        $skusOnCurrentPage = $allMatchingUniqueSkus->slice(($currentPage - 1) * $perPage, $perPage)->values();
        
        $paginator = new LengthAwarePaginator(
            $skusOnCurrentPage,
            $allMatchingUniqueSkus->count(),
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        // ====================================================================
        // LANGKAH 3: Ambil data & Siapkan data Tampilan
        // ====================================================================
        $variantsBySku = $this->getVariantsForSkus($skusOnCurrentPage->all());
        $summary = $this->getSummaryData();
        $initialChartData = $this->getInitialChartData($summary);

        $bundleSkus = $variantsBySku->filter(fn($v) => $v->first() && $v->first()->sku_type === 'gabungan')->keys();
        $componentStocks = collect();
        if ($bundleSkus->isNotEmpty()) {
            $allComponentSkus = SkuComposition::whereIn('bundle_sku', $bundleSkus)->where('user_id', auth()->id())->pluck('component_sku')->unique();
            $componentStocks = ProductVariant::whereIn('variant_sku', $allComponentSkus)->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))->pluck('warehouse_stock', 'variant_sku');
        }

        // ====================================================================
        // PERBAIKAN UTAMA: Bangun ulang data dengan pengecualian
        // ====================================================================
        $newSkuData = [];
        foreach ($skusOnCurrentPage as $sku) {
            // JIKA SKU INI ADALAH YANG SEDANG DIEDIT, JANGAN AMBIL DARI DATABASE.
            // CUKUP PERTAHANKAN STATE YANG SUDAH ADA DI MEMORI.
            if ($sku === $this->editingSku && isset($this->skuData[$sku])) {
                $newSkuData[$sku] = $this->skuData[$sku];
                continue; // Lanjut ke SKU berikutnya
            }

            // Untuk semua SKU lain yang tidak sedang diedit, bangun dari DB.
            $firstVariant = $variantsBySku->get($sku)?->first();

            if ($firstVariant && $firstVariant->product) {
                $firstProduct = $firstVariant->product;
                $newSkuData[$sku] = [
                    'representative_product_id' => $firstProduct->id,
                    'cost_price_raw' => $firstVariant->cost_price ?? 0,
                    'selling_price_raw' => $firstVariant->selling_price ?? 0,
                    'weight_raw' => $firstVariant->weight ?? 0,
                    'product_category_id' => $firstProduct->product_category_id,
                    'status' => $firstProduct->status,
                    'sku_type' => $firstVariant->sku_type,
                    'reseller' => $firstVariant->reseller, // <-- TAMBAHKAN INI
                    'suggested_cost_price' => null,
                    'warehouse_stock_raw' => 0,
                ];

                if ($firstVariant->sku_type === 'gabungan') {
                    $newSkuData[$sku]['suggested_cost_price'] = $this->calculateSuggestedPrice($sku);
                    $newSkuData[$sku]['warehouse_stock_raw'] = $this->calculateBundleStock($sku, $componentStocks);
                } else {
                    $newSkuData[$sku]['warehouse_stock_raw'] = $firstVariant->warehouse_stock ?? 0;
                }
            } else {
                \Illuminate\Support\Facades\Log::warning("SKU Master: Data tidak lengkap untuk SKU '{$sku}'.");
            }
        }
        
        // Ganti properti publik dengan data yang baru dan aman.
        $this->skuData = $newSkuData;

        // ====================================================================
        // LANGKAH 5: Kembalikan semua data ke view
        // ====================================================================
        return [
            'summary' => $summary,
            'initialChartData' => $initialChartData,
            'skus' => $paginator,
            'variantsBySku' => $variantsBySku,
        ];
    }};
?>

<x-layouts.app>
    @livewire('inventory.sku-composition-manager')

    @volt('inventory-sku-master')
        <div>
            <x-app.container>
                <x-app.heading 
                    title="Master SKU"
                    description="Kelola data SKU secara terpusat. Klik pada baris untuk mengedit."
                    :border="true" />

                {{-- =============================================== --}}
                {{--         PERUBAHAN: SEKSI SUMMARY + GRAFIK     --}}
                {{-- =============================================== --}}
                @if(!empty($summary['overall']['total_value']))
                <div class="mt-6">
                    <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Ringkasan Inventaris (SKU Mandiri)</h3>
                    
                    {{-- Grid untuk menampung summary cards dan chart --}}
                    <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-6">
                        
                        {{-- Kolom Kiri: Summary Cards (memakan 2/3 ruang di layar besar) --}}
                        <div class="lg:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-5">
                            {{-- Card Total Keseluruhan --}}
                            <div class="overflow-hidden rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700">
                                <div class="p-5">
                                    <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">Total Keseluruhan</dt>
                                    <dd class="mt-1 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">
                                        {{ number_format($summary['overall']['total_stock']) }}
                                        <span class="text-lg font-medium text-gray-500 dark:text-gray-400">pcs</span>
                                    </dd>
                                    <dd class="mt-1 text-sm font-medium text-green-600 dark:text-green-400">
                                        Rp {{ number_format($summary['overall']['total_value'], 0, ',', '.') }}
                                    </dd>
                                </div>
                            </div>

                            {{-- Card per Kategori --}}
                            @foreach ($summary['categories'] as $categorySummary)
                                <div class="overflow-hidden rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700">
                                    <div class="p-5">
                                        <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">{{ $categorySummary['name'] }}</dt>
                                        <dd class="mt-1 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">
                                            {{ number_format($categorySummary['total_stock']) }}
                                            <span class="text-lg font-medium text-gray-500 dark:text-gray-400">pcs</span>
                                        </dd>
                                        <dd class="mt-1 text-sm font-medium text-green-600 dark:text-green-400">
                                            Rp {{ number_format($categorySummary['total_value'], 0, ',', '.') }}
                                        </dd>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Kolom Kanan: Donut Chart --}}
                        <div class="lg:col-span-1 overflow-hidden rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-4"
                             x-data="{
                                chart: null,
                                init() {
                                    let initialData = @js($initialChartData);
                                    this.renderChart(initialData);
                                    
                                    // Listener untuk dark mode, jika ada
                                    window.addEventListener('theme-changed', (event) => {
                                        if (this.chart) {
                                            this.chart.updateOptions({ theme: { mode: event.detail.theme } });
                                        }
                                    });
                                },
                                renderChart(data) {
                                    if (this.chart) {
                                        this.chart.destroy();
                                    }
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
                                        colors: ['#4ade80', '#fb923c', '#60a5fa', '#f87171', '#9ca3af', '#a78bfa', '#facc15'],
                                        dataLabels: {
                                            enabled: true,
                                            formatter: (val) => val.toFixed(1) + '%',
                                            style: { fontSize: '11px', fontWeight: 'bold' },
                                            dropShadow: { enabled: true, top: 1, left: 1, blur: 1, color: '#000', opacity: 0.45 }
                                        },
                                        legend: { show: true, position: 'bottom', horizontalAlign: 'center', fontSize: '12px' },
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
                                                            label: 'Total Nilai Modal', 
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
                    </div>
                </div>
                @endif
                {{-- =============================================== --}}
                {{--           AKHIR SEKSI SUMMARY + GRAFIK          --}}
                {{-- =============================================== --}}

                <div class="mt-8 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
                    <input type="search" wire:model.live.debounce.300ms="search" class="block w-full md:w-1/3 rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 px-3 text-sm" placeholder="Cari SKU...">
                    <a href="{{ route('inventory.categories') }}" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700">
                        Kelola Kategori
                    </a>
                </div>

                {{-- Tabel data (tidak ada perubahan di bawah ini) --}}
                {{-- ... (sisa kode tabel Anda) ... --}}
                <div class="mt-4 flow-root">
                    <div class="-my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                        <div class="py-2 align-middle inline-block min-w-full sm:px-6 lg:px-8">
                            <table class="min-w-full">
                                <thead class="hidden md:table-header-group bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider w-1/3">SKU</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Harga Modal</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Stok Gudang</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Jenis SKU</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Kategori</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-900 md:divide-y md:divide-gray-200 md:dark:divide-gray-700">
                                    @forelse($skus as $skuItem)
                                        @php 
                                            $sku = $skuItem;
                                            $currentVariants = $variantsBySku[$sku] ?? collect();
                                        @endphp
                                        
                                        @if(isset($skuData[$sku]))
                                            @if ($editingSku === $sku)
                                                <tr wire:key="editor-{{ $sku }}">
                                                    {{-- Colspan diubah menjadi 7 --}}
                                                    <td colspan="7" class="p-2 md:p-0">
                                                        <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50 shadow-lg border border-indigo-300 dark:border-indigo-600">
                                                            <div class="mb-4 pb-4 border-b border-gray-200 dark:border-gray-700">
                                                                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Mengedit SKU: {{ $sku }}</h3>
                                                                @if($variantName = $currentVariants->first()?->variant_name)
                                                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $variantName }}</p>
                                                                @endif
                                                            </div>
                                                            {{-- =================================== --}}
                                                            {{--   PERUBAHAN: FORM MENJADI 4 KOLOM   --}}
                                                            {{-- =================================== --}}
                                                            <div class="grid grid-cols-1 md:grid-cols-4 gap-x-6 gap-y-4">
                                                                {{-- Kolom 1: Harga Jual & Harga Modal --}}
                                                                <div class="space-y-4">
                                                                    <div>
                                                                        <label for="selling_price-{{$sku}}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Harga Jual</label>
                                                                        <input type="number" id="selling_price-{{$sku}}" wire:model="skuData.{{$sku}}.selling_price_raw" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm sm:text-sm">
                                                                        @error("skuData.{$sku}.selling_price_raw") <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                                                    </div>
                                                                    <div>
                                                                        <label for="cost_price-{{$sku}}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Harga Modal</label>
                                                                        <input type="number" id="cost_price-{{$sku}}" wire:model="skuData.{{$sku}}.cost_price_raw" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm sm:text-sm">
                                                                        @if($skuData[$sku]['sku_type'] === 'gabungan' && isset($skuData[$sku]['suggested_cost_price']))
                                                                            <p class="text-xs text-green-600 dark:text-green-400 mt-1">
                                                                                Rekomendasi: <span class="font-semibold">Rp {{ number_format($skuData[$sku]['suggested_cost_price'], 0, ',', '.') }}</span>
                                                                            </p>
                                                                        @endif
                                                                        @error("skuData.{$sku}.cost_price_raw") <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                                                    </div>
                                                                </div>
                                                                
                                                                {{-- Kolom 2: Stok & Berat --}}
                                                                <div class="space-y-4">
                                                                    <div>
                                                                        <label for="stock-{{$sku}}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Stok Gudang</label>
                                                                        <input type="number" id="stock-{{$sku}}" wire:model="skuData.{{$sku}}.warehouse_stock_raw" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm sm:text-sm @if($skuData[$sku]['sku_type'] === 'gabungan') bg-gray-200 dark:bg-gray-700 cursor-not-allowed @endif" @if($skuData[$sku]['sku_type'] === 'gabungan') readonly @endif>
                                                                        @if($skuData[$sku]['sku_type'] === 'gabungan')
                                                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Stok dihitung otomatis.</p>
                                                                        @endif
                                                                        @error("skuData.{$sku}.warehouse_stock_raw") <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                                                    </div>
                                                                    <div>
                                                                        <label for="weight-{{$sku}}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Berat Produk (gram)</label>
                                                                        <input type="number" id="weight-{{$sku}}" wire:model="skuData.{{$sku}}.weight_raw" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm sm:text-sm">
                                                                        @error("skuData.{$sku}.weight_raw") <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                                                    </div>
                                                                </div>
                                                                
                                                                {{-- Kolom 3: Kategori & Jenis SKU --}}
                                                                <div class="space-y-4">
                                                                    <div>
                                                                        <label for="category-{{$sku}}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Kategori Produk</label>
                                                                        <select id="category-{{$sku}}" wire:model="skuData.{{$sku}}.product_category_id" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm sm:text-sm">
                                                                            <option value="">-- Tanpa Kategori --</option>
                                                                            @foreach($productCategories as $id => $name)
                                                                                <option value="{{ $id }}">{{ $name }}</option>
                                                                            @endforeach
                                                                        </select>
                                                                    </div>
                                                                    <div>
                                                                        <label for="sku_type-{{$sku}}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Jenis SKU</label>
                                                                        <div class="mt-1 flex items-center space-x-2">
                                                                            <select id="sku_type-{{$sku}}" wire:model.live="skuData.{{$sku}}.sku_type" class="block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm sm:text-sm">
                                                                                <option value="mandiri">Mandiri</option>
                                                                                <option value="gabungan">Gabungan</option>
                                                                            </select>
                                                                            @if($skuData[$sku]['sku_type'] === 'gabungan')
                                                                                <button type="button" wire:click="$dispatch('manage-composition', { sku: '{{ $sku }}' })" class="flex-shrink-0 inline-flex items-center justify-center p-2 border border-transparent rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700">
                                                                                    <x-heroicon-o-cog-6-tooth class="h-5 w-5"/>
                                                                                </button>
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                {{-- Kolom 4: Status, Reseller & Tombol Aksi --}}
                                                                <div class="flex flex-col justify-between">
                                                                    <div class="space-y-4">
                                                                        <div>
                                                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                                                                            <fieldset class="mt-2">
                                                                                <div class="flex items-center space-x-4">
                                                                                    <div class="flex items-center"><input id="status-active-{{$sku}}" wire:model="skuData.{{$sku}}.status" type="radio" value="active" class="h-4 w-4 text-indigo-600 border-gray-300"><label for="status-active-{{$sku}}" class="ml-2 block text-sm">Tampil</label></div>
                                                                                    <div class="flex items-center"><input id="status-draft-{{$sku}}" wire:model="skuData.{{$sku}}.status" type="radio" value="draft" class="h-4 w-4 text-indigo-600 border-gray-300"><label for="status-draft-{{$sku}}" class="ml-2 block text-sm">Sembunyi</label></div>
                                                                                </div>
                                                                            </fieldset>
                                                                        </div>
                                                                        <div>
                                                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tampil di Reseller</label>
                                                                            <fieldset class="mt-2">
                                                                                <div class="flex items-center space-x-4">
                                                                                    <div class="flex items-center"><input id="reseller-yes-{{$sku}}" wire:model="skuData.{{$sku}}.reseller" type="radio" value="1" class="h-4 w-4 text-indigo-600 border-gray-300"><label for="reseller-yes-{{$sku}}" class="ml-2 block text-sm">Ya</label></div>
                                                                                    <div class="flex items-center"><input id="reseller-no-{{$sku}}" wire:model="skuData.{{$sku}}.reseller" type="radio" value="0" class="h-4 w-4 text-indigo-600 border-gray-300"><label for="reseller-no-{{$sku}}" class="ml-2 block text-sm">Tidak</label></div>
                                                                                </div>
                                                                            </fieldset>
                                                                        </div>
                                                                    </div>
                                                                    <div class="flex items-end justify-end space-x-3 mt-4">
                                                                        <button type="button" wire:click="cancelEditing" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50">Batal</button>
                                                                        <button type="button" wire:click="saveSku('{{ $sku }}')" class="px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-black hover:bg-gray-800">Simpan</button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @else
                                                <tr wire:key="row-{{ $sku }}" wire:click="editSku('{{ $sku }}')" class="block md:table-row mb-4 md:mb-0 bg-white dark:bg-gray-900 rounded-lg shadow-md border border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                                    {{-- ... (sel SKU) ... --}}
                                                    <td class="block md:table-cell p-4 md:px-6 md:py-4 md:align-middle md:whitespace-nowrap">
                                                        <p class="font-bold text-gray-900 dark:text-white">{{ $sku }}</p>
                                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" title="{{ $currentVariants->pluck('product.product_name')->implode(', ') }}">Digunakan di {{ $currentVariants->count() }} produk</p>
                                                    </td>
                                                    {{-- ... (sel Harga Modal) ... --}}
                                                    <td class="block md:table-cell px-4 py-2 border-t md:p-0 md:border-0 md:px-6 md:py-4 md:align-middle">
                                                        <div class="flex justify-between items-center md:block"><span class="text-sm font-medium text-gray-500 md:hidden">Harga Modal</span><span class="text-sm">Rp {{ number_format($skuData[$sku]['cost_price_raw'] ?? 0, 0, ',', '.') }}</span>
                                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" title="">Jual Rp {{ number_format($skuData[$sku]['selling_price_raw'] ?? 0, 0, ',', '.') }}</p>
                                                        </div>
                                                    </td>
                                                    {{-- ... (sel Stok Gudang) ... --}}
                                                    <td class="block md:table-cell px-4 py-2 border-t md:p-0 md:border-0 md:px-6 md:py-4 md:align-middle">
                                                        <div class="flex justify-between items-center md:block"><span class="text-sm font-medium text-gray-500 md:hidden">Stok Gudang</span>
                                                            @php $stock = $skuData[$sku]['warehouse_stock_raw'] ?? 0; $stockClass = 'text-gray-800 dark:text-gray-200'; if ($stock <= 0) { $stockClass = 'text-red-600 dark:text-red-400 font-bold'; } elseif ($stock <= 10) { $stockClass = 'text-yellow-600 dark:text-yellow-400 font-semibold'; } @endphp
                                                            <span class="text-sm {{ $stockClass }}">{{ $stock }}</span>
                                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Berat {{ number_format($skuData[$sku]['weight_raw'] ?? 0) }} g</p>
                                                        </div>
                                                    </td>
                                                    {{-- ... (sel Jenis SKU) ... --}}
                                                    <td class="block md:table-cell px-4 py-2 border-t md:p-0 md:border-0 md:px-6 md:py-4 md:align-middle">
                                                        <div class="flex justify-between items-center md:block"><span class="text-sm font-medium text-gray-500 md:hidden">Jenis SKU</span>
                                                            @if($skuData[$sku]['sku_type'] === 'gabungan') <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Gabungan</span> @else <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Mandiri</span> @endif
                                                        </div>
                                                    </td>
                                                    {{-- ... (sel Kategori) ... --}}
                                                    <td class="block md:table-cell px-4 py-2 border-t md:p-0 md:border-0 md:px-6 md:py-4 md:align-middle">
                                                        <div class="flex justify-between items-center md:block"><span class="text-sm font-medium text-gray-500 md:hidden">Kategori</span><span class="text-sm text-gray-500">{{ $productCategories[$skuData[$sku]['product_category_id']] ?? '-' }}</span></div>
                                                    </td>
                                                </tr>
                                            @endif
                                        @endif
                                    @empty
                                        <tr>
                                            {{-- Colspan diubah menjadi 7 --}}
                                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                                {{ $this->search ? 'SKU tidak ditemukan.' : 'Tidak ada data SKU.' }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                @if($skus->hasPages())
                    <div class="mt-6">
                        {{ $skus->links() }}
                    </div>
                @endif
                
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>