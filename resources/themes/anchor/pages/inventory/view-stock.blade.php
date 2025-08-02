<?php

use function Laravel\Folio\{middleware, name};
use App\Models\ProductVariant;
use App\Models\OrderItem;
use App\Models\SkuComposition;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

middleware('auth');
name('inventory.view-stock');

new class extends Component {
    
    protected array $colorPalette = [
        'blue', 'green', 'amber', 'indigo', 'purple', 'pink', 'red', 'teal', 'cyan'
    ];

    private function calculateBundleStock(string $bundleSku, Collection $componentStocks): int
    {
        // Fungsi ini tidak berubah
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

    private function getSummaryData(): array
    {
        // Fungsi ini tidak berubah
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
    
    private function getAllSkusGroupedByCategory(Collection $allocatedStocksToday): Collection
    {
        // Fungsi ini tidak berubah, karena sudah menerima data alokasi final
        $allSkuDetails = ProductVariant::with('product.productCategory')
            ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
            ->where(fn($q) => $q->whereNotNull('variant_sku')->where('variant_sku', '!=', ''))
            ->get()
            ->keyBy('variant_sku');
        
        $bundleSkus = $allSkuDetails->where('sku_type', 'gabungan')->keys();
        $componentStocks = collect();
        if ($bundleSkus->isNotEmpty()) {
            $allComponentSkus = SkuComposition::whereIn('bundle_sku', $bundleSkus)
                ->where('user_id', auth()->id())->pluck('component_sku')->unique();
            $componentStocks = ProductVariant::whereIn('variant_sku', $allComponentSkus)
                 ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
                 ->pluck('warehouse_stock', 'variant_sku');
        }
        $bundleStockMap = [];
        foreach ($bundleSkus as $sku) {
            $bundleStockMap[$sku] = $this->calculateBundleStock($sku, $componentStocks);
        }

        $processedSkus = $allSkuDetails->map(function ($variant) use ($bundleStockMap, $allocatedStocksToday) {
            $sku = $variant->variant_sku;
            
            $stockAwal = $variant->sku_type === 'gabungan'
                ? ($bundleStockMap[$sku] ?? 0)
                : $variant->warehouse_stock;
            
            // Logika baru untuk mendapatkan alokasi komponen
            $alokasi = 0;
            if($variant->sku_type === 'mandiri') {
                $alokasi = $allocatedStocksToday->get($sku, 0);
            } else { // Jika gabungan, kita tetap tampilkan alokasi dari komponennya
                 $compositions = SkuComposition::where('bundle_sku', $sku)->get();
                 $possibleSets = [];
                 if ($compositions->isNotEmpty()) {
                     foreach ($compositions as $component) {
                        $componentAllocation = $allocatedStocksToday->get($component->component_sku, 0);
                        $possibleSets[] = floor($componentAllocation / $component->quantity);
                     }
                     $alokasi = min($possibleSets);
                 }
            }
            
            // Stok tersedia untuk SKU komponen dihitung seperti biasa
            $stock_tersedia = $stockAwal - ($variant->sku_type === 'mandiri' ? $alokasi : 0);
            if ($variant->sku_type === 'gabungan') {
                // Untuk bundle, stok tersedia dihitung dari komponennya juga
                $compositions = SkuComposition::where('bundle_sku', $sku)->get();
                $maxPossibleSets = PHP_INT_MAX;
                if ($compositions->isNotEmpty()) {
                     foreach($compositions as $component) {
                         $componentVariant = ProductVariant::where('variant_sku', $component->component_sku)
                            ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))->first();

                         if($componentVariant) {
                            $componentStockAwal = $componentVariant->warehouse_stock;
                            $componentAlokasi = $allocatedStocksToday->get($component->component_sku, 0);
                            $componentStockTersedia = $componentStockAwal - $componentAlokasi;
                            $possibleSetsFromThisComponent = floor($componentStockTersedia / $component->quantity);
                            $maxPossibleSets = min($maxPossibleSets, $possibleSetsFromThisComponent);
                         } else {
                             $maxPossibleSets = 0; // Jika salah satu komponen tidak ditemukan, tidak bisa buat bundle
                             break;
                         }
                     }
                     $stock_tersedia = ($maxPossibleSets === PHP_INT_MAX) ? 0 : $maxPossibleSets;
                } else {
                    $stock_tersedia = 0;
                }
            }


            return [
                'sku' => $sku,
                'stock_awal' => $stockAwal,
                'alokasi' => $alokasi,
                'stock_tersedia' => $stock_tersedia,
                'sku_type' => $variant->sku_type,
                'category_name' => $variant->product->productCategory->name ?? 'Tanpa Kategori',
            ];
        });

        return $processedSkus->groupBy('category_name')->sortBy(function ($items, $key) {
            if ($key === 'Tanpa Kategori') return 'zzz';
            return $key;
        });
    }

    // PERUBAHAN UTAMA ADA DI FUNGSI INI
    public function with(): array
    {
        // 1. Ambil semua item yang dipesan dengan status "Perlu Dikirim"
        // INI ADALAH QUERY BARU YANG ANDA BERIKAN
        $orderedItemsToday = OrderItem::whereNotNull('variant_sku')
            ->where('variant_sku', '!=', '')
            ->whereHas('order', function ($query) {
                $query->where('order_status', 'Perlu Dikirim');
                // Anda bisa menambahkan filter user di sini jika diperlukan, contoh:
                $query->where('user_id', auth()->id());
            })
            ->get();
            
        // 2. Siapkan array untuk menampung alokasi final (hanya untuk SKU komponen/mandiri)
        $finalAllocations = collect();

        if ($orderedItemsToday->isNotEmpty()) {
            $orderedSkus = $orderedItemsToday->pluck('variant_sku')->unique();

            // 3. Ambil tipe dan komposisi untuk semua SKU yang dipesan dalam beberapa query
            // (Logika ini tetap efisien dan tidak perlu diubah)
            $skuTypes = ProductVariant::whereIn('variant_sku', $orderedSkus)
                ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
                ->pluck('sku_type', 'variant_sku');

            $compositions = SkuComposition::whereIn('bundle_sku', $skuTypes->filter(fn($type) => $type === 'gabungan')->keys())
                ->where('user_id', auth()->id())
                ->get();

            // 4. Loop melalui item yang dipesan untuk menghitung alokasi
            // (Logika ini juga tidak perlu diubah)
            foreach ($orderedItemsToday as $item) {
                $sku = $item->variant_sku;
                $quantity = $item->quantity;
                $type = $skuTypes->get($sku);

                if ($type === 'mandiri') {
                    // Jika mandiri, langsung tambahkan ke alokasi final
                    $finalAllocations[$sku] = $finalAllocations->get($sku, 0) + $quantity;
                } elseif ($type === 'gabungan') {
                    // Jika gabungan, pecah menjadi komponennya
                    $bundleComponents = $compositions->where('bundle_sku', $sku);
                    foreach ($bundleComponents as $component) {
                        // Kebutuhan komponen = kuantitas komponen * jumlah bundle yg dipesan
                        $componentNeeded = $component->quantity * $quantity;
                        $finalAllocations[$component->component_sku] = $finalAllocations->get($component->component_sku, 0) + $componentNeeded;
                    }
                }
            }
        }
        
        $summary = $this->getSummaryData();
        
        // 5. Teruskan alokasi final yang sudah dihitung ke fungsi pemrosesan SKU
        $skusByCategory = $this->getAllSkusGroupedByCategory($finalAllocations);

        return [
            'summary' => $summary,
            'skusByCategory' => $skusByCategory,
            'categoryColorPalette' => $this->colorPalette,
        ];
    }
};
?>

<x-layouts.app>
    @volt('inventory-view-stock')
        <div x-data="{ showSummaryDetails: false }">
            <x-app.container>
                <x-app.heading 
                    title="Laporan Stok per SKU"
                    description="Ringkasan dan daftar stok untuk semua SKU berdasarkan kategori."
                    :border="true" />

                {{-- PERUBAHAN 1: Ringkasan Inventaris dengan Akordeon --}}
                <div class="mt-6">
                    <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Ringkasan Inventaris (SKU Mandiri)</h3>
                    <div class="mt-4 flow-root">
                        <div class="overflow-x-auto">
                            <div class="inline-block min-w-full align-middle">
                                <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 sm:rounded-lg">
                                    <table class="min-w-full">
                                        <thead class="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">Kategori</th>
                                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Total Stok</th>
                                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Total Nilai Modal</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white dark:bg-gray-900">
                                            {{-- Baris Total yang selalu terlihat --}}
                                            <tr class="font-bold border-b border-gray-300 dark:border-gray-700">
                                                <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm text-gray-900 dark:text-white sm:pl-6">
                                                    Total Keseluruhan
                                                    <button @click="showSummaryDetails = !showSummaryDetails" class="ml-2 text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-200 text-xs font-normal">
                                                        <span x-show="!showSummaryDetails">(Lihat Detail)</span>
                                                        <span x-show="showSummaryDetails">(Sembunyikan)</span>
                                                    </button>
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-800 dark:text-gray-200">{{ number_format($summary['overall']['total_stock']) }} pcs</td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-green-600 dark:text-green-400">Rp {{ number_format($summary['overall']['total_value'], 0, ',', '.') }}</td>
                                            </tr>
                                        </tbody>
                                        {{-- Baris Detail yang bisa disembunyikan --}}
                                        <tbody x-show="showSummaryDetails" x-collapse class="divide-y divide-gray-200 dark:divide-gray-700">
                                            @foreach ($summary['categories'] as $categorySummary)
                                                @php
                                                    $colorName = $categoryColorPalette[$loop->index % count($categoryColorPalette)];
                                                @endphp
                                                <tr>
                                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 dark:text-white sm:pl-6">
                                                        <div class="flex items-center">
                                                            <div @class(['h-2.5 w-2.5 rounded-full mr-3', 'bg-blue-500' => $colorName === 'blue', 'bg-green-500' => $colorName === 'green', 'bg-amber-500' => $colorName === 'amber', 'bg-indigo-500' => $colorName === 'indigo', 'bg-purple-500' => $colorName === 'purple', 'bg-pink-500' => $colorName === 'pink', 'bg-red-500' => $colorName === 'red', 'bg-teal-500' => $colorName === 'teal', 'bg-cyan-500' => $colorName === 'cyan', 'bg-gray-500' => !in_array($colorName, $categoryColorPalette)])></div>
                                                            {{ $categorySummary['name'] }}
                                                        </div>
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ number_format($categorySummary['total_stock']) }} pcs</td>
                                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">Rp {{ number_format($categorySummary['total_value'], 0, ',', '.') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- PERUBAHAN 2: Daftar Stok SKU dengan kolom baru --}}
                <div class="mt-8">
                        <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Daftar Stok SKU per Kategori</h3>
                        <div x-data="{ openCategory: @js($skusByCategory->keys()->first()) }" class="mt-4 space-y-3">
                            @forelse ($skusByCategory as $categoryName => $skus)
                                @php
                                    // 1. Filter koleksi untuk hanya mendapatkan SKU mandiri dan hitung jumlahnya
                                    $mandiriSkus = $skus->filter(fn($item) => $item['sku_type'] === 'mandiri');
                                    $mandiriSkuCount = $mandiriSkus->count();
                                    
                                    $colorName = $categoryColorPalette[$loop->index % count($categoryColorPalette)];
                                @endphp
                                
                                {{-- Hanya tampilkan blok kategori jika ada SKU mandiri di dalamnya --}}
                                @if ($mandiriSkuCount > 0)
                                    <div class="overflow-hidden rounded-lg bg-white dark:bg-gray-900 shadow border border-gray-200 dark:border-gray-700">
                                        <button @click="openCategory = openCategory === @js($categoryName) ? null : @js($categoryName)" 
                                                @class(['flex w-full items-center justify-between p-4 text-left border-l-4', 'border-blue-500' => $colorName === 'blue', 'border-green-500' => $colorName === 'green', 'border-amber-500' => $colorName === 'amber', 'border-indigo-500' => $colorName === 'indigo', 'border-purple-500' => $colorName === 'purple', 'border-pink-500' => $colorName === 'pink', 'border-red-500' => $colorName === 'red', 'border-teal-500' => $colorName === 'teal', 'border-cyan-500' => $colorName === 'cyan', 'border-gray-500' => !in_array($colorName, $categoryColorPalette)])>
                                            
                                            {{-- 2. Gunakan hitungan yang sudah difilter untuk judul --}}
                                            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $categoryName }} ({{ $mandiriSkuCount }} SKU)</span>
                                            
                                            <svg class="h-5 w-5 transform transition-transform text-gray-500" :class="{ 'rotate-180': openCategory === @js($categoryName) }" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.23 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                                        </button>
                                        
                                        <div x-show="openCategory === @js($categoryName)" x-collapse>
                                            <table class="min-w-full">
                                                <thead class="bg-gray-50 dark:bg-gray-800/50 text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">
                                                    <tr>
                                                        <th scope="col" class="py-2 pl-4 pr-3 text-left sm:pl-6 w-12">#</th>
                                                        <th scope="col" class="px-3 py-2 text-left">SKU</th>
                                                        <th scope="col" class="px-3 py-2 text-left">Stok Awal</th>
                                                        <th scope="col" class="px-3 py-2 text-left">Perlu Dikirim</th>
                                                        <th scope="col" class="px-3 py-2 text-left">Stok Tersedia</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {{-- 3. Loop melalui koleksi yang SUDAH DIFILTER --}}
                                                    @foreach ($mandiriSkus->sortBy('sku') as $skuItem)
                                                        <tr class="odd:bg-white even:bg-gray-50 dark:odd:bg-gray-800/90 dark:even:bg-gray-800/50 border-t border-gray-200 dark:border-gray-700">
                                                            <td class="whitespace-nowrap py-3 pl-4 pr-3 text-sm text-gray-500 dark:text-gray-400 sm:pl-6">{{ $loop->iteration }}</td>
                                                            <td class="whitespace-nowrap px-3 py-3 text-sm font-medium text-gray-800 dark:text-gray-200">{{ $skuItem['sku'] }}</td>
                                                            <td class="whitespace-nowrap px-3 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $skuItem['stock_awal'] }}</td>
                                                            <td class="whitespace-nowrap px-3 py-3 text-sm {{ $skuItem['alokasi'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">{{ $skuItem['alokasi'] }}</td>
                                                            <td class="whitespace-nowrap px-3 py-3 text-sm font-bold {{ $skuItem['stock_tersedia'] <= 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-500' }}">{{ $skuItem['stock_tersedia'] }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @endif
                            @empty
                            <div class="text-center py-12 text-gray-500">
                                Tidak ada data SKU untuk ditampilkan.
                            </div>
                        @endforelse
                    </div>
                </div>

            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>