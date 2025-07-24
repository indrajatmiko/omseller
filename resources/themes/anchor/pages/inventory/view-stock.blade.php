<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\SkuComposition;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

// Catatan: WithPagination tidak lagi digunakan karena tampilan baru menampilkan semua data.
middleware('auth');
name('inventory.view-stock');

new class extends Component {
    // Properti yang tidak lagi digunakan oleh tampilan baru dapat dihapus atau diabaikan.
    // public string $search = '';
    // public ?string $editingSku = null;
    protected array $colorPalette = [
        'blue', 'green', 'amber', 'indigo', 'purple', 'pink', 'red', 'teal', 'cyan'
    ];

    // Logika perhitungan tetap dibutuhkan
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

    private function getSummaryData(): array
    {
        $uniqueMandiriSkus = ProductVariant::query()
            ->where('sku_type', 'mandiri')
            ->where(function ($q) {
                $q->where('variant_sku', '!=', '')->whereNotNull('variant_sku')->where('sku_type', '!=', 'gabungan');
            })
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->where('products.user_id', auth()->id())->where('products.status', 'active')
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
    
    private function getAllSkusGroupedByCategory(): Collection
    {
        $allSkuDetails = ProductVariant::with('product.productCategory')
            ->whereHas('product', fn($q) =>
                $q->where('user_id', auth()->id())
                ->where('status', 'active') // <-- LETAKKAN DI SINI
            )
            ->where(fn($q) => $q->whereNotNull('variant_sku')->where('variant_sku', '!=', '')->where('sku_type', '!=', 'gabungan'))
            ->get()
            ->keyBy('variant_sku');

        $bundleSkus = $allSkuDetails->where('sku_type', 'gabungan')->keys();
        $componentStocks = collect();
        if ($bundleSkus->isNotEmpty()) {
            $allComponentSkus = SkuComposition::whereIn('bundle_sku', $bundleSkus)
                ->where('user_id', auth()->id())
                ->pluck('component_sku')
                ->unique();
            $componentStocks = ProductVariant::whereIn('variant_sku', $allComponentSkus)
                 ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
                 ->pluck('warehouse_stock', 'variant_sku');
        }
        $bundleStockMap = [];
        foreach ($bundleSkus as $sku) {
            $bundleStockMap[$sku] = $this->calculateBundleStock($sku, $componentStocks);
        }

        $processedSkus = $allSkuDetails->map(function ($variant) use ($bundleStockMap) {
            return [
                'sku' => $variant->variant_sku,
                'stock' => $variant->sku_type === 'gabungan'
                    ? ($bundleStockMap[$variant->variant_sku] ?? 0)
                    : $variant->warehouse_stock,
                'category_name' => $variant->product->productCategory->name ?? 'Tanpa Kategori',
            ];
        });

        return $processedSkus->groupBy('category_name')->sortBy(function ($items, $key) {
            if ($key === 'Tanpa Kategori') return 'zzz';
            return $key;
        });
    }

    public function with(): array
    {
        $summary = $this->getSummaryData();
        $skusByCategory = $this->getAllSkusGroupedByCategory();

        return [
            'summary' => $summary,
            'skusByCategory' => $skusByCategory,
            'categoryColorPalette' => $this->colorPalette,
        ];
    }
};
?>

<x-layouts.app>
    {{-- PERUBAHAN: Nama @volt disesuaikan dengan nama route baru agar lebih konsisten --}}
    @volt('inventory-view-stock')
        <div>
            <x-app.container>
                <x-app.heading 
                    title="Laporan Stok per SKU"
                    description="Ringkasan dan daftar stok untuk semua SKU berdasarkan kategori."
                    :border="true" />

                {{-- 1. Card Ringkasan Inventaris --}}
                <div class="mt-6">
                    <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Ringkasan Inventaris (SKU Mandiri)</h3>
                    <div class="mt-4 flow-root">
                        <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                            <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                                <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 sm:rounded-lg">
                                    <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">Kategori</th>
                                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Total Stok</th>
                                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Total Nilai Modal</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                                            <tr class="font-bold bg-gray-50 dark:bg-gray-800/50">
                                                <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm text-gray-900 dark:text-white sm:pl-6">Total Keseluruhan</td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-800 dark:text-gray-200">{{ number_format($summary['overall']['total_stock']) }} pcs</td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-green-600 dark:text-green-400">Rp {{ number_format($summary['overall']['total_value'], 0, ',', '.') }}</td>
                                            </tr>
                                            @foreach ($summary['categories'] as $categorySummary)
                                                @php
                                                    $colorName = $categoryColorPalette[$loop->index % count($categoryColorPalette)];
                                                @endphp
                                                <tr>
                                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 dark:text-white sm:pl-6">
                                                        <div class="flex items-center">
                                                            {{-- PERBAIKAN: Gunakan @switch untuk memastikan Tailwind mendeteksi kelas --}}
                                                            <div @class([
                                                                'h-2.5 w-2.5 rounded-full mr-3',
                                                                'bg-blue-500' => $colorName === 'blue',
                                                                'bg-green-500' => $colorName === 'green',
                                                                'bg-amber-500' => $colorName === 'amber',
                                                                'bg-indigo-500' => $colorName === 'indigo',
                                                                'bg-purple-500' => $colorName === 'purple',
                                                                'bg-pink-500' => $colorName === 'pink',
                                                                'bg-red-500' => $colorName === 'red',
                                                                'bg-teal-500' => $colorName === 'teal',
                                                                'bg-cyan-500' => $colorName === 'cyan',
                                                                'bg-gray-500' => !in_array($colorName, $categoryColorPalette),
                                                            ])></div>
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

                {{-- 2. Card List SKU (Accordion) --}}
                <div class="mt-8">
                    <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Daftar Stok SKU per Kategori</h3>
                    <div x-data="{ openCategory: @js($skusByCategory->keys()->first()) }" class="mt-4 space-y-3">
                        @forelse ($skusByCategory as $categoryName => $skus)
                            @php
                                $colorName = $categoryColorPalette[$loop->index % count($categoryColorPalette)];
                            @endphp
                            <div class="overflow-hidden rounded-lg bg-white dark:bg-gray-900 shadow border border-gray-200 dark:border-gray-700">
                                <button @click="openCategory = openCategory === @js($categoryName) ? null : @js($categoryName)" 
                                        @class([
                                            'flex w-full items-center justify-between p-4 text-left border-l-4',
                                            'border-blue-500' => $colorName === 'blue',
                                            'border-green-500' => $colorName === 'green',
                                            'border-amber-500' => $colorName === 'amber',
                                            'border-indigo-500' => $colorName === 'indigo',
                                            'border-purple-500' => $colorName === 'purple',
                                            'border-pink-500' => $colorName === 'pink',
                                            'border-red-500' => $colorName === 'red',
                                            'border-teal-500' => $colorName === 'teal',
                                            'border-cyan-500' => $colorName === 'cyan',
                                            'border-gray-500' => !in_array($colorName, $categoryColorPalette),
                                        ])>
                                    <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $categoryName }} ({{ count($skus) }} SKU)</span>
                                    <svg class="h-5 w-5 transform transition-transform text-gray-500" :class="{ 'rotate-180': openCategory === @js($categoryName) }" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.23 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                                
                                <div x-show="openCategory === @js($categoryName)" x-collapse>
                                    <table class="min-w-full">
                                        <thead class="bg-gray-50 dark:bg-gray-800/50">
                                            <tr>
                                                <th scope="col" class="py-2 pl-4 pr-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 sm:pl-6 w-16">#</th>
                                                <th scope="col" class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-300">SKU</th>
                                                <th scope="col" class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-300">Stok</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($skus->sortBy('sku') as $skuItem)
                                                {{-- PERBAIKAN: Zebra-striping diterapkan di sini --}}
                                                <tr class="odd:bg-white even:bg-gray-50 dark:odd:bg-gray-800/90 dark:even:bg-gray-800/50 border-t border-gray-200 dark:border-gray-700">
                                                    <td class="whitespace-nowrap py-3 pl-4 pr-3 text-sm text-gray-500 dark:text-gray-400 sm:pl-6">{{ $loop->iteration }}</td>
                                                    <td class="whitespace-nowrap px-3 py-3 text-sm font-medium text-gray-800 dark:text-gray-200">{{ $skuItem['sku'] }}</td>
                                                    <td class="whitespace-nowrap px-3 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $skuItem['stock'] }} pcs</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
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