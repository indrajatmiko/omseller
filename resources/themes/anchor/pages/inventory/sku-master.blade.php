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

    protected function loadSkuDataForCurrentPage(): void
    {
        $paginatedSkus = $this->getPaginatedSkus();
        $variantsBySku = $this->getVariantsForSkus($paginatedSkus->pluck('variant_sku')->all());
        
        $this->skuData = [];

        foreach ($paginatedSkus as $skuItem) {
            $sku = $skuItem->variant_sku;
            $firstVariant = $variantsBySku[$sku]->first() ?? null;
            $firstProduct = $firstVariant->product ?? null;

            if ($firstVariant && $firstProduct) {
                $this->skuData[$sku] = [
                    'cost_price_raw'      => $firstVariant->cost_price ?? 0,
                    'warehouse_stock_raw' => $firstVariant->warehouse_stock ?? 0,
                    'product_category_id' => $firstProduct->product_category_id,
                    'status'              => $firstProduct->status,
                    'sku_type'            => $firstVariant->sku_type,
                    'suggested_cost_price' => null,
                ];

                if ($firstVariant->sku_type === 'gabungan') {
                    $this->skuData[$sku]['suggested_cost_price'] = $this->calculateSuggestedPrice($sku);
                }
            }
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
    
    public function saveSku(string $sku): void
    {
        if (empty($sku) || !isset($this->skuData[$sku])) {
            $this->cancelEditing();
            return;
        }
        $currentData = $this->skuData[$sku];
        $validated = validator($currentData, [
            'cost_price_raw'      => 'required|numeric|min:0',
            'warehouse_stock_raw' => 'required|integer|min:0',
            'product_category_id' => 'nullable|exists:product_categories,id',
            'status'              => 'required|in:active,draft',
            'sku_type'            => 'required|in:mandiri,gabungan',
        ])->validate();
        DB::transaction(function () use ($sku, $validated) {
            $variantsToUpdate = ProductVariant::where('variant_sku', $sku)->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))->get();
            if ($variantsToUpdate->isEmpty()) return;
            $originalStock = $variantsToUpdate->first()->warehouse_stock ?? 0;
            $newStock = $validated['warehouse_stock_raw'];
            $stockDifference = $newStock - $originalStock;
            Product::whereIn('id', $variantsToUpdate->pluck('product_id')->unique())->update(['status' => $validated['status'],'product_category_id' => $validated['product_category_id'],]);
            foreach ($variantsToUpdate as $variant) {
                $variant->update(['cost_price' => $validated['cost_price_raw'],'warehouse_stock' => $validated['warehouse_stock_raw'],'sku_type' => $validated['sku_type'],]);
            }
            $this->handleStockAdjustment($variantsToUpdate->first(), $stockDifference, $sku);
        });
        $this->skuData[$sku]['cost_price_raw'] = $validated['cost_price_raw'];
        $this->skuData[$sku]['warehouse_stock_raw'] = $validated['warehouse_stock_raw'];
        $this->skuData[$sku]['product_category_id'] = $validated['product_category_id'];
        $this->skuData[$sku]['status'] = $validated['status'];
        $this->skuData[$sku]['sku_type'] = $validated['sku_type'];
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

    private function getVariantsForSkus(array $skus) {
        return ProductVariant::whereIn('variant_sku', $skus)
            ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
            ->with('product:id,product_name,product_category_id,status')
            ->get()->groupBy('variant_sku');
    }
    
    private function getPaginatedSkus()
    {
        return ProductVariant::query()
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('product_categories', 'products.product_category_id', '=', 'product_categories.id')
            ->where('products.user_id', auth()->id())
            ->where(function($q) {
                $q->where('product_variants.variant_sku', '!=', '')->whereNotNull('product_variants.variant_sku');
            })
            ->when($this->search, function($q) {
                $q->where('product_variants.variant_sku', 'like', '%' . $this->search . '%')
                  ->orWhere('products.product_name', 'like', '%' . $this->search . '%')
                  ->orWhere('product_categories.name', 'like', '%' . $this->search . '%');
            })
            ->select('product_variants.variant_sku')
            ->distinct()
            ->orderByRaw('ISNULL(product_categories.name), product_categories.name ASC, product_variants.variant_sku ASC')
            // PERUBAHAN 4: Mengubah paginasi dari 50 menjadi 25
            ->paginate(25);
    }
    
    public function with(): array
    {
        $editingData = null;
        if ($this->editingSku && isset($this->skuData[$this->editingSku])) {
            $editingData = $this->skuData[$this->editingSku];
        }

        // Muat data untuk halaman ini. Ini akan menimpa $this->skuData.
        $paginatedSkus = $this->getPaginatedSkus();
        $variantsBySku = $this->getVariantsForSkus($paginatedSkus->pluck('variant_sku')->all());
        
        $this->skuData = [];

        foreach ($paginatedSkus as $skuItem) {
            $sku = $skuItem->variant_sku;
            $firstVariant = $variantsBySku[$sku]->first() ?? null;
            $firstProduct = $firstVariant->product ?? null;

            if ($firstVariant && $firstProduct) {
                $this->skuData[$sku] = [
                    'cost_price_raw'      => $firstVariant->cost_price ?? 0,
                    'warehouse_stock_raw' => $firstVariant->warehouse_stock ?? 0,
                    'product_category_id' => $firstProduct->product_category_id,
                    'status'              => $firstProduct->status,
                    'sku_type'            => $firstVariant->sku_type,
                    'suggested_cost_price' => null,
                ];

                if ($firstVariant->sku_type === 'gabungan') {
                    $this->skuData[$sku]['suggested_cost_price'] = $this->calculateSuggestedPrice($sku);
                }
            }
        }

        if ($this->editingSku && $editingData) {
            $this->skuData[$this->editingSku] = $editingData;
        }
        
        return [
            'skus' => $paginatedSkus,
            'variantsBySku' => $variantsBySku,
        ];
    }
};
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

                <div class="mt-6 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
                    <input type="search" wire:model.live.debounce.300ms="search" class="block w-full md:w-1/3 rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 px-3 text-sm" placeholder="Cari SKU...">
                    <a href="{{ route('inventory.categories') }}" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700">
                        Kelola Kategori
                    </a>
                </div>

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
                                        <th scope="col" class="relative px-6 py-3"><span class="sr-only">Edit</span></th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-900 md:divide-y md:divide-gray-200 md:dark:divide-gray-700">
                                    @forelse($skus as $skuItem)
                                        @php 
                                            $sku = $skuItem->variant_sku; 
                                            $currentVariants = $variantsBySku[$sku] ?? collect();
                                        @endphp
                                        
                                        @if(isset($skuData[$sku]))
                                            @if ($editingSku === $sku)
                                                <tr wire:key="editor-{{ $sku }}">
                                                    <td colspan="6" class="p-2 md:p-0">
                                                        <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50 shadow-lg border border-indigo-300 dark:border-indigo-600">
                                                            <div class="mb-4 pb-4 border-b border-gray-200 dark:border-gray-700">
                                                                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Mengedit SKU: {{ $sku }}</h3>
                                                                @if($variantName = $currentVariants->first()->variant_name)
                                                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $variantName }}</p>
                                                                @endif
                                                            </div>
                                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4">
                                                                {{-- Form fields... --}}
                                                                <div class="space-y-4">
                                                                    <div>
                                                                        <label for="cost_price-{{$sku}}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Harga Modal</label>
                                                                        <input type="number" id="cost_price-{{$sku}}" wire:model="skuData.{{$sku}}.cost_price_raw" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm sm:text-sm">
                                                                        @if($skuData[$sku]['sku_type'] === 'gabungan' && isset($skuData[$sku]['suggested_cost_price']))
                                                                            <p class="text-xs text-green-600 dark:text-green-400 mt-1">
                                                                                Rekomendasi harga: <span class="font-semibold">Rp {{ number_format($skuData[$sku]['suggested_cost_price'], 0, ',', '.') }}</span>
                                                                            </p>
                                                                        @endif
                                                                        @error("skuData.{$sku}.cost_price_raw") <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                                                    </div>
                                                                    <div>
                                                                        <label for="stock-{{$sku}}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Stok Gudang</label>
                                                                        <input type="number" id="stock-{{$sku}}" wire:model="skuData.{{$sku}}.warehouse_stock_raw" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm sm:text-sm">
                                                                        @error("skuData.{$sku}.warehouse_stock_raw") <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                                                    </div>
                                                                </div>
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
                                                                        <label for="status-{{$sku}}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                                                                        <select id="status-{{$sku}}" wire:model="skuData.{{$sku}}.status" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm sm:text-sm">
                                                                            <option value="active">Tampil</option>
                                                                            <option value="draft">Sembunyi</option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="space-y-4">
                                                                    <div>
                                                                        <label for="sku_type-{{$sku}}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Jenis SKU</label>
                                                                        <select id="sku_type-{{$sku}}" wire:model.live="skuData.{{$sku}}.sku_type" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm sm:text-sm">
                                                                            <option value="mandiri">Mandiri</option>
                                                                            <option value="gabungan">Gabungan</option>
                                                                        </select>
                                                                    </div>
                                                                    <div class="flex items-end justify-end space-x-3">
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
                                                    {{-- SKU (Card Header on Mobile) --}}
                                                    <td class="block md:table-cell p-4 md:px-6 md:py-4 md:align-middle md:whitespace-nowrap">
                                                        <div class="flex justify-between items-start">
                                                            <div>
                                                                <p class="font-bold text-gray-900 dark:text-white">{{ $sku }}</p>
                                                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" title="{{ $currentVariants->pluck('product.product_name')->implode(', ') }}">
                                                                    Digunakan di {{ $currentVariants->count() }} produk
                                                                </p>
                                                            </div>
                                                            <div class="md:hidden">
                                                                <span class="inline-flex items-center px-3 py-1 border border-gray-300 dark:border-gray-600 text-xs font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">Edit</span>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    {{-- Other data with labels for mobile --}}
                                                    <td class="block md:table-cell px-4 py-2 border-t border-gray-200 dark:border-gray-700 md:p-0 md:border-0 md:px-6 md:py-4 md:align-middle">
                                                        <div class="flex justify-between items-center md:block">
                                                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400 md:hidden">Harga Modal</span>
                                                            <span class="text-sm text-gray-800 dark:text-gray-200">Rp {{ number_format($skuData[$sku]['cost_price_raw'] ?? 0, 0, ',', '.') }}</span>
                                                        </div>
                                                    </td>
                                                    <td class="block md:table-cell px-4 py-2 border-t border-gray-200 dark:border-gray-700 md:p-0 md:border-0 md:px-6 md:py-4 md:align-middle">
                                                        <div class="flex justify-between items-center md:block">
                                                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400 md:hidden">Stok Gudang</span>
                                                            @php
                                                                $stock = $skuData[$sku]['warehouse_stock_raw'] ?? 0;
                                                                $stockClass = 'text-gray-800 dark:text-gray-200';
                                                                if ($stock <= 0) { $stockClass = 'text-red-600 dark:text-red-400 font-bold'; } 
                                                                elseif ($stock <= 10) { $stockClass = 'text-yellow-600 dark:text-yellow-400 font-semibold'; }
                                                            @endphp
                                                            <span class="text-sm {{ $stockClass }}">{{ $stock }}</span>
                                                        </div>
                                                    </td>
                                                    <td class="block md:table-cell px-4 py-2 border-t border-gray-200 dark:border-gray-700 md:p-0 md:border-0 md:px-6 md:py-4 md:align-middle">
                                                        <div class="flex justify-between items-center md:block">
                                                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400 md:hidden">Jenis SKU</span>
                                                            @if($skuData[$sku]['sku_type'] === 'gabungan')
                                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-800 dark:text-blue-100">Gabungan</span>
                                                            @else
                                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100">Mandiri</span>
                                                            @endif
                                                        </div>
                                                    </td>
                                                    <td class="block md:table-cell px-4 py-2 border-t border-gray-200 dark:border-gray-700 md:p-0 md:border-0 md:px-6 md:py-4 md:align-middle">
                                                        <div class="flex justify-between items-center md:block">
                                                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400 md:hidden">Kategori</span>
                                                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $productCategories[$skuData[$sku]['product_category_id']] ?? '-' }}</span>
                                                        </div>
                                                    </td>
                                                    {{-- Edit Button (Desktop Only) --}}
                                                    <td class="hidden md:table-cell p-4 md:px-6 md:py-4 md:whitespace-nowrap md:text-right md:align-middle">
                                                        <button type="button" class="inline-flex items-center px-3 py-1 border border-gray-300 dark:border-gray-600 text-xs font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                            Edit
                                                        </button>
                                                    </td>
                                                </tr>
                                            @endif
                                        @endif
                                    @empty
                                        <tr>
                                            <td colspan="6" class="px-6 py-12 text-center text-gray-500">
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