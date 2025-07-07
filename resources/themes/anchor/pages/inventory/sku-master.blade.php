<?php

use function Laravel\Folio\{middleware, name};
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Filament\Notifications\Notification; // <-- BARU: Import Notifikasi Filament

middleware('auth');
name('inventory.sku-master');

new class extends Component {
    use WithPagination;

    public string $search = '';
    public array $skuData = [];
    // HAPUS: public ?string $justSavedSku = null;
    public string $focusedInput = '';

    public function mount(): void
    {
        $this->loadSkuDataForCurrentPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }
    
    // Fungsi untuk memuat data dan memformatnya untuk tampilan
    protected function loadSkuDataForCurrentPage(): void
    {
        $paginatedSkus = $this->getPaginatedSkus();
        $variantsBySku = $this->getVariantsForSkus($paginatedSkus->pluck('variant_sku')->all());

        foreach ($paginatedSkus as $skuItem) {
            $sku = $skuItem->variant_sku;
            $firstVariant = $variantsBySku[$sku]->first() ?? null;

            if ($firstVariant) {
                $this->skuData[$sku] = [
                    'cost_price' => number_format($firstVariant->cost_price ?? 0, 0, ',', '.'),
                    'warehouse_stock' => number_format($firstVariant->warehouse_stock ?? 0, 0, ',', '.'),
                ];
            }
        }
    }

    // Fungsi ini dipanggil saat input kehilangan fokus (blur)
    public function saveSku(string $sku): void
    {
        if (empty($sku)) return;
        
        $costPriceRaw = (float) str_replace('.', '', $this->skuData[$sku]['cost_price'] ?? '0');
        $warehouseStockRaw = (int) str_replace('.', '', $this->skuData[$sku]['warehouse_stock'] ?? '0');

        $validated = validator([
            'cost_price' => $costPriceRaw,
            'warehouse_stock' => $warehouseStockRaw,
        ], [
            'cost_price' => 'nullable|numeric|min:0',
            'warehouse_stock' => 'required|integer|min:0',
        ])->validate();
        
        DB::transaction(function () use ($sku, $validated) {
            $variantsToUpdate = ProductVariant::where('variant_sku', $sku)
                ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
                ->get();

            $originalStock = $variantsToUpdate->first()->warehouse_stock ?? 0;
            $newStock = $validated['warehouse_stock'];
            $stockDifference = $newStock - $originalStock;
            
            foreach ($variantsToUpdate as $variant) {
                $variant->update([
                    'cost_price' => $validated['cost_price'],
                    'warehouse_stock' => $newStock,
                ]);

                if ($stockDifference != 0) {
                    StockMovement::create([
                        'user_id' => auth()->id(),
                        'product_variant_id' => $variant->id,
                        'type' => 'adjustment',
                        'quantity' => $stockDifference,
                        'notes' => 'Penyesuaian massal dari Master SKU: ' . $sku,
                    ]);
                }
            }
        });
        
        // Format ulang input setelah disimpan, untuk konsistensi
        $this->skuData[$sku]['cost_price'] = number_format($costPriceRaw, 0, ',', '.');
        $this->skuData[$sku]['warehouse_stock'] = number_format($warehouseStockRaw, 0, ',', '.');
        
        // PERUBAHAN: Kirim notifikasi Filament
        Notification::make()
            ->title('Update Berhasil')
            ->success()
            ->body("Perubahan untuk SKU '{$sku}' telah berhasil disimpan.")
            ->send();
    }

    public function setFocus(string $sku, string $field): void
    {
        $this->focusedInput = "{$sku}-{$field}";
        if (isset($this->skuData[$sku][$field])) {
            $this->skuData[$sku][$field] = str_replace('.', '', $this->skuData[$sku][$field]);
        }
    }

    private function getPaginatedSkus()
    {
        return ProductVariant::query()
            ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
            ->where(fn($q) => $q->where('variant_sku', '!=', '')->whereNotNull('variant_sku'))
            ->when($this->search, fn($q) => $q->where('variant_sku', 'like', '%' . $this->search . '%'))
            ->select('variant_sku')
            ->distinct()
            ->orderBy('variant_sku')
            ->paginate(50);
    }
    
    // Fungsi helper untuk mengambil data varian secara efisien
    private function getVariantsForSkus(array $skus)
    {
        return ProductVariant::whereIn('variant_sku', $skus)
            ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
            ->with('product:id,product_name')
            ->get()
            ->groupBy('variant_sku');
    }

    public function with(): array
    {
        $paginatedSkus = $this->getPaginatedSkus();
        $this->loadSkuDataForCurrentPage();

        return [
            'skus' => $paginatedSkus,
            'variantsBySku' => $this->getVariantsForSkus($paginatedSkus->pluck('variant_sku')->all()),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('inventory-sku-master')
        <div>
            <x-app.container>
                <x-app.heading 
                    title="Master SKU"
                    description="Kelola harga modal dan stok untuk setiap SKU unik secara terpusat. Perubahan akan tersimpan otomatis saat Anda beralih dari input."
                    :border="true" />

                <div class="mt-6">
                    <input type="search" wire:model.live.debounce.300ms="search" class="block w-full md:w-1/3 rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 px-3 text-sm placeholder:text-gray-400 focus:border-black dark:focus:border-white focus:ring-1 focus:ring-black dark:focus:ring-white" placeholder="Cari SKU...">
                </div>

                <div class="mt-4 flex flex-col">
                    <div class="-my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                        <div class="py-2 align-middle inline-block min-w-full sm:px-6 lg:px-8">
                            <div class="shadow overflow-hidden border-b border-gray-200 dark:border-gray-700 sm:rounded-lg">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider w-1/3">SKU</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Harga Modal</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Stok Gudang</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                        @forelse($skus as $skuItem)
                                            @php 
                                                $sku = $skuItem->variant_sku; 
                                                $currentVariants = $variantsBySku[$sku] ?? collect();
                                            @endphp
                                            <tr wire:key="sku-row-{{ $sku }}">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div>
                                                        <p class="font-bold text-gray-900 dark:text-white">{{ $sku }}</p>
                                                        {{-- PERUBAHAN: Tampilkan nama varian --}}
                                                        @if($variantName = $currentVariants->first()->variant_name)
                                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" title="{{ $variantName }}">
                                                                {{ Str::words($variantName, 6, '...') }}
                                                            </p>
                                                        @endif
                                                        {{-- "Digunakan di" sekarang di bawah nama varian --}}
                                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" title="{{ $currentVariants->pluck('product.product_name')->implode(', ') }}">
                                                            Digunakan di {{ $currentVariants->count() }} produk
                                                        </p>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap align-top">
                                                    <input type="text"
                                                           wire:model="skuData.{{ $sku }}.cost_price"
                                                           wire:focus="setFocus('{{ $sku }}', 'cost_price')"
                                                           wire:blur="saveSku('{{ $sku }}')"
                                                           class="block w-32 rounded-lg border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 shadow-sm sm:text-sm"
                                                           placeholder="0">
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap align-top">
                                                    <input type="text"
                                                           wire:model="skuData.{{ $sku }}.warehouse_stock"
                                                           wire:focus="setFocus('{{ $sku }}', 'warehouse_stock')"
                                                           wire:blur="saveSku('{{ $sku }}')"
                                                           class="block w-32 rounded-lg border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 shadow-sm sm:text-sm"
                                                           placeholder="0">
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="3" class="px-6 py-12 text-center text-gray-500">
                                                    {{ $this->search ? 'SKU tidak ditemukan.' : 'Tidak ada data SKU.' }}
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
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