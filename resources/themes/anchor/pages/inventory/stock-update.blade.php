<?php

use function Laravel\Folio\{middleware, name};
use App\Models\ProductVariant;
use App\Models\StockAdjustment; // PERUBAHAN: Import model baru
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Filament\Notifications\Notification;

middleware('auth');
name('inventory.stock-update');

new class extends Component {
    use WithPagination;

    public string $search = '';
    public array $skuData = []; 

    public function mount(): void
    {
        // Tidak perlu memuat data di mount, biarkan `with()` yang menangani
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }
    
    protected function initializeAdjustmentValues(): void
    {
        $paginatedSkus = $this->getPaginatedSkus();
        foreach ($paginatedSkus as $skuItem) {
            $sku = $skuItem->variant_sku;
            if (!isset($this->skuData[$sku]['adjustment'])) {
                $this->skuData[$sku]['adjustment'] = null;
                $this->skuData[$sku]['notes'] = ''; // Inisialisasi notes
            }
        }
    }

    public function saveAdjustment(string $sku): void
    {
        $adjustmentValue = (int) ($this->skuData[$sku]['adjustment'] ?? 0);
        // PERUBAHAN: Ambil nilai 'notes' dari input
        $notes = trim($this->skuData[$sku]['notes'] ?? '');
        
        if ($adjustmentValue === 0) {
            $this->skuData[$sku]['adjustment'] = null;
            return;
        }

        $validated = validator([
            'adjustment' => $adjustmentValue,
            'notes' => $notes, // Validasi notes
        ], [
            'adjustment' => 'required|integer',
            'notes' => 'nullable|string|max:255',
        ])->validate();
        
        $quantityAdjusted = $validated['adjustment'];
        // PERUBAHAN: Gunakan notes dari validasi, atau fallback ke default
        $finalNotes = !empty($validated['notes']) ? $validated['notes'] : 'Penyesuaian stok manual dari halaman Stok';

        DB::transaction(function () use ($sku, $quantityAdjusted, $finalNotes) {
            $variantsToUpdate = ProductVariant::where('variant_sku', $sku)
                ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
                ->get();
            
            if ($variantsToUpdate->isEmpty()) return;

            $firstVariant = $variantsToUpdate->first();
            $stockBefore = $firstVariant->warehouse_stock;
            $stockAfter = $stockBefore + $quantityAdjusted;

            // PERUBAHAN: Simpan 'finalNotes' ke database
            StockAdjustment::create([
                'user_id' => auth()->id(),
                'variant_sku' => $sku,
                'product_variant_id' => $firstVariant->id,
                'stock_before' => $stockBefore,
                'quantity_adjusted' => $quantityAdjusted,
                'stock_after' => $stockAfter,
                'notes' => $finalNotes,
            ]);

            ProductVariant::where('variant_sku', $sku)
                ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
                ->update(['warehouse_stock' => $stockAfter]);
        });
        
        // PERUBAHAN: Reset kedua input setelah berhasil
        $this->skuData[$sku]['adjustment'] = null;
        $this->skuData[$sku]['notes'] = '';
        
        Notification::make()->title('Stok Diperbarui')->success()->body("Stok untuk SKU '{$sku}' telah berhasil disesuaikan.")->send();
    }

    public function setFocus(string $sku): void
    {
        if ($this->skuData[$sku]['adjustment'] === '0') {
             $this->skuData[$sku]['adjustment'] = '';
        }
    }

    private function getPaginatedSkus() {
        return ProductVariant::query()
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('product_categories', 'products.product_category_id', '=', 'product_categories.id')
            ->where('products.user_id', auth()->id())
            ->where('products.status', 'active')
            ->where(function($q) {
                $q->where('product_variants.variant_sku', '!=', '')->whereNotNull('product_variants.variant_sku');
            })
            ->when($this->search, function($q) {
                $q->where('product_variants.variant_sku', 'like', '%' . $this->search . '%')
                  ->orWhere('products.product_name', 'like', '%' . $this->search . '%')
                  ->orWhere('product_categories.name', 'like', '%' . $this->search . '%');
            })
            ->select('product_variants.variant_sku', 'product_variants.warehouse_stock')
            ->distinct('product_variants.variant_sku')
            ->orderByRaw('ISNULL(product_categories.name), product_categories.name ASC, product_variants.variant_sku ASC')
            ->paginate(50);
    }
    
    private function getVariantsForSkus(array $skus) {
        return ProductVariant::whereIn('variant_sku', $skus)
            ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
            ->with('product:id,product_name')
            ->get()
            ->groupBy('variant_sku');
    }

    public function with(): array
    {
        $this->initializeAdjustmentValues();
        $paginatedSkus = $this->getPaginatedSkus();
        return [
            'skus' => $paginatedSkus,
            'variantsBySku' => $this->getVariantsForSkus($paginatedSkus->pluck('variant_sku')->all()),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('inventory-stock-update')
        <div>
            <x-app.container>
                <x-app.heading 
                    title="Penyesuaian Stok"
                    description="Ubah stok dengan mengisi kolom penyesuaian. Perubahan tersimpan otomatis saat fokus berpindah atau menekan Enter."
                    :border="true" />

                <div class="mt-6">
                    <input type="search" wire:model.live.debounce.300ms="search" class="block w-full md:w-1/3 rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 px-3 text-sm placeholder:text-gray-400" placeholder="Cari SKU atau Nama Produk...">
                </div>

                {{-- ====================================================== --}}
                {{-- PERUBAHAN UTAMA: DUA BLOK TAMPILAN BERBEDA --}}
                {{-- ====================================================== --}}

                {{-- 1. TAMPILAN DESKTOP (Terlihat di layar `lg` dan ke atas) --}}
                <div class="mt-4 flex-col hidden lg:flex">
                    <div class="-my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                        <div class="py-2 align-middle inline-block min-w-full sm:px-6 lg:px-8">
                            <div class="shadow overflow-hidden border-b border-gray-200 dark:border-gray-700 sm:rounded-lg">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider w-2/5">SKU</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Stok Saat Ini</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Tambah / Kurang</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Catatan</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                        @forelse($skus as $skuItem)
                                            @php 
                                                $sku = $skuItem->variant_sku; 
                                                $currentStock = $skuItem->warehouse_stock;
                                                $currentVariants = $variantsBySku[$sku] ?? collect();
                                                $stockColorClass = '';
                                                if ($currentStock <= 2) { $stockColorClass = 'text-red-600 dark:text-red-400 font-bold'; } 
                                                elseif ($currentStock <= 10) { $stockColorClass = 'text-yellow-600 dark:text-yellow-400 font-semibold'; }
                                            @endphp
                                            <tr wire:key="stock-row-desktop-{{ $sku }}">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div>
                                                        <p class="font-bold text-gray-900 dark:text-white">{{ $sku }}</p>
                                                        {{-- @if($variantName = $currentVariants->first()->variant_name)
                                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 truncate" title="{{ $variantName }}">{{ $variantName }}</p>
                                                        @endif --}}
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap align-middle">
                                                    <span class="text-lg {{ $stockColorClass }}">{{ number_format($currentStock) }}</span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap align-middle">
                                                    <input type="number"
                                                           wire:model="skuData.{{ $sku }}.adjustment"
                                                           wire:blur="saveAdjustment('{{ $sku }}')"
                                                           wire:keydown.enter="saveAdjustment('{{ $sku }}')"
                                                           class="block w-32 rounded-lg border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 shadow-sm sm:text-sm"
                                                           placeholder="0">
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap align-middle">
                                                    <input type="text"
                                                           wire:model="skuData.{{ $sku }}.notes"
                                                           wire:blur="saveAdjustment('{{ $sku }}')"
                                                           wire:keydown.enter="saveAdjustment('{{ $sku }}')"
                                                           class="block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 shadow-sm sm:text-sm"
                                                           placeholder="Opsional">
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="px-6 py-12 text-center text-gray-500">{{ $this->search ? 'SKU tidak ditemukan.' : 'Tidak ada data SKU.' }}</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 2. TAMPILAN MOBILE (Terlihat di bawah layar `lg`) --}}
                <div class="mt-4 grid grid-cols-1 gap-4 lg:hidden">
                    @forelse($skus as $skuItem)
                        @php 
                            $sku = $skuItem->variant_sku; 
                            $currentStock = $skuItem->warehouse_stock;
                            $currentVariants = $variantsBySku[$sku] ?? collect();
                            $stockColorClass = '';
                            if ($currentStock <= 2) { $stockColorClass = 'text-red-600 dark:text-red-400 font-bold'; } 
                            elseif ($currentStock <= 10) { $stockColorClass = 'text-yellow-600 dark:text-yellow-400 font-semibold'; }
                        @endphp
                        <div wire:key="stock-row-mobile-{{ $sku }}" class="bg-white dark:bg-gray-800/50 shadow overflow-hidden rounded-lg p-4 space-y-4">
                            {{-- Baris 1: Info SKU & Stok Saat Ini --}}
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="font-bold text-gray-900 dark:text-white">{{ $sku }}</p>
                                    {{-- @if($variantName = $currentVariants->first()->variant_name)
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 truncate" title="{{ $variantName }}">{{ $variantName }}</p>
                                    @endif --}}
                                </div>
                                <div class="text-right">
                                    <label class="text-xs font-medium text-gray-500">Stok Saat Ini</label>
                                    <p class="text-lg {{ $stockColorClass }}">{{ number_format($currentStock) }}</p>
                                </div>
                            </div>
                            
                            {{-- PERUBAHAN: Baris 2: Input Penyesuaian & Catatan (berdampingan) --}}
                            <div class="flex items-start space-x-3">
                                {{-- Input Penyesuaian (Lebih kecil) --}}
                                <div class="w-1/3">
                                    <label for="mobile_adjustment-{{$sku}}" class="block text-xs font-medium text-gray-500 mb-1">Tambah/Kurang</label>
                                    <input type="number" id="mobile_adjustment-{{$sku}}"
                                        wire:model="skuData.{{ $sku }}.adjustment"
                                        wire:blur="saveAdjustment('{{ $sku }}')"
                                        wire:keydown.enter="saveAdjustment('{{ $sku }}')"
                                        class="block w-16 rounded-lg border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 shadow-sm text-sm"
                                        placeholder="0">
                                </div>
                                {{-- Input Catatan (Lebih besar) --}}
                                <div class="flex-1">
                                    <label for="mobile_notes-{{$sku}}" class="block text-xs font-medium text-gray-500 mb-1">Catatan</label>
                                    <input type="text" id="mobile_notes-{{$sku}}"
                                        wire:model="skuData.{{ $sku }}.notes"
                                        wire:blur="saveAdjustment('{{ $sku }}')"
                                        wire:keydown.enter="saveAdjustment('{{ $sku }}')"
                                        class="block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 shadow-sm text-sm"
                                        placeholder="Opsional">
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="bg-white dark:bg-gray-800/50 shadow rounded-lg p-12 text-center text-gray-500">
                            {{ $this->search ? 'SKU tidak ditemukan.' : 'Tidak ada data SKU.' }}
                        </div>
                    @endforelse
                </div>
                
                {{-- Paginasi --}}
                @if($skus->hasPages())
                    <div class="mt-6">
                        {{ $skus->links() }}
                    </div>
                @endif
                
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>