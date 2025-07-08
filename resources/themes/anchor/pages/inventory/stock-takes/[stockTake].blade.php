<?php

use function Laravel\Folio\{middleware, name};
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\ProductVariant;
use Livewire\Volt\Component;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

middleware('auth');
name('inventory.stock-takes.show');

new class extends Component {
    public $stockTake;
    public Collection $skus;
    public array $itemsData = [];
    public ?string $notes = null;

    // Menggunakan route model binding Laravel Folio yang lebih bersih.
    // Variabel $stockTake secara otomatis diinjeksi dari URL.
    public function mount(StockTake $stockTake): void
    {
        $this->stockTake = $stockTake;

        // Keamanan: Pastikan user hanya bisa mengakses data miliknya.
        if ($this->stockTake->user_id !== auth()->id()) {
            abort(403, 'Unauthorized access');
        }
        
        $this->notes = $this->stockTake->notes;

        // 1. Ambil daftar SKU unik yang dimiliki user. Ini adalah inti logika baru.
        // Kita mengambil data relevan dan menjadikan SKU sebagai key untuk akses mudah.
        $this->skus = ProductVariant::query()
            ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
            ->where(fn($q) => $q->where('variant_sku', '!=', '')->whereNotNull('variant_sku'))
            ->select('variant_sku', 'variant_name', 'warehouse_stock', 'id as first_variant_id')
            ->distinct('variant_sku')
            ->orderBy('variant_sku')
            ->get()
            ->keyBy('variant_sku');

        // 2. Ambil data opname yang sudah ada, digabungkan dengan varian untuk mendapatkan SKU-nya.
        $existingItems = StockTakeItem::where('stock_take_id', $this->stockTake->id)
            ->join('product_variants', 'stock_take_items.product_variant_id', '=', 'product_variants.id')
            ->pluck('counted_stock', 'product_variants.variant_sku');
    
        // 3. Inisialisasi data untuk form input, berdasarkan SKU.
        foreach($this->skus as $sku => $skuData) {
            $this->itemsData[$sku] = [
                'counted_stock' => $existingItems[$sku] ?? null,
            ];
        }
    }

    /**
     * Menyimpan hasil hitungan untuk satu SKU.
     * Dipicu saat input field kehilangan fokus (blur).
     */
    public function saveCount(string $sku, int $referenceVariantId): void
    {
        if ($this->stockTake->status === 'completed') return;

        $countedValue = $this->itemsData[$sku]['counted_stock'];

        // Jika input dikosongkan, hapus entri opname yang ada.
        if (!is_numeric($countedValue) || $countedValue === '') {
            StockTakeItem::where('stock_take_id', $this->stockTake->id)
                         ->where('product_variant_id', $referenceVariantId)
                         ->delete();
            return;
        }
        
        $variant = ProductVariant::find($referenceVariantId);
        if (!$variant) return;

        // Buat atau perbarui catatan opname untuk SKU ini.
        // Kita hanya perlu satu `product_variant_id` sebagai referensi untuk SKU tersebut.
        StockTakeItem::updateOrCreate(
            ['stock_take_id' => $this->stockTake->id, 'product_variant_id' => $referenceVariantId],
            ['system_stock' => $variant->warehouse_stock, 'counted_stock' => (int)$countedValue]
        );
    }

    /**
     * Menyelesaikan sesi stock opname, membuatnya read-only.
     */
    public function completeStockTake(): void
    {
        if ($this->stockTake->status === 'completed') return;
        
        $this->stockTake->update([
            'status' => 'completed',
            'notes' => $this->notes,
        ]);

        Notification::make()->title('Stock Opname Selesai')
            ->success()->body("Sesi pengecekan #{$this->stockTake->id} telah ditandai sebagai selesai.")->send();
    }

    /**
     * Menghitung selisih antara stok sistem dan fisik.
     * Helper ini digunakan langsung di dalam view.
     */
    public function getVariance(string $sku, int $systemStock): ?int
    {
        $counted = $this->itemsData[$sku]['counted_stock'];
        if (!is_numeric($counted) || $counted === '') return null;
        return (int)$counted - $systemStock;
    }
}; ?>

<x-layouts.app>
    @volt('inventory-stock-take-show')
        <div>
            <x-app.container>
                {{-- HEADER: Judul dan Tombol Aksi --}}
                <div class="md:flex md:items-center md:justify-between">
                    <div class="min-w-0 flex-1">
                         <x-app.heading 
                            title="Stock Opname #{{ $stockTake->id }}"
                            description="Bandingkan stok sistem dengan fisik di gudang. Hasilnya disimpan sebagai laporan." 
                            :border="false"
                        />
                    </div>
                    <div class="mt-4 flex md:mt-0 md:ml-4">
                        @if($stockTake->status === 'in_progress')
                            <button wire:click="completeStockTake" class="w-full md:w-auto flex-shrink-0 rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                Tandai Selesai
                            </button>
                        @else
                            <span class="inline-flex items-center rounded-lg bg-gray-200 dark:bg-gray-700 px-4 py-2 text-sm font-semibold text-gray-500 dark:text-gray-300">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                                Opname Selesai
                            </span>
                        @endif
                    </div>
                </div>
                <hr class="my-6 dark:border-gray-700">

                {{-- AREA CATATAN --}}
                 @if($stockTake->status === 'in_progress')
                    <div class="mb-6">
                        <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Catatan (Opsional)</label>
                        <textarea id="notes" wire:model.lazy="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:bg-gray-800 dark:border-gray-600" placeholder="Contoh: Pengecekan rak A-3"></textarea>
                    </div>
                @elseif($stockTake->notes)
                    <div class="mb-6 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Catatan:</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ $stockTake->notes }}</p>
                    </div>
                @endif
                

                {{-- DAFTAR ITEM SKU UNTUK DI-OPNAME --}}
                <div class="space-y-2">
                    @forelse($skus as $sku => $skuData)
                        @php 
                            $systemStock = $skuData->warehouse_stock;
                            $variance = $this->getVariance($sku, $systemStock);
                        @endphp
                        {{-- Layout Card untuk setiap item SKU --}}
                        <div wire:key="sku-{{ $sku }}" class="bg-white dark:bg-gray-800 p-3 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 flex flex-col md:flex-row md:items-center gap-3">
                            
                            {{-- Kolom 1: Info SKU (Fleksibel, memanjang) --}}
                            <div class="flex-1 min-w-0">
                                <p class="font-bold font-mono text-gray-900 dark:text-white truncate" title="{{ $sku }}">{{ $sku }}</p>
                                @if($variantName = $skuData->variant_name)
                                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $variantName }}">{{ $variantName }}</p>
                                @endif
                            </div>

                            {{-- Kolom 2: Grup Data Stok (Lebar tetap di desktop, menyebar di mobile) --}}
                            <div class="flex items-center justify-between md:justify-end gap-3 w-full md:w-auto">
                                {{-- Stok Sistem --}}
                                <div class="text-center w-20 flex-shrink-0">
                                    <p class="text-xs text-gray-500 font-semibold uppercase">Sistem</p>
                                    <p class="text-lg font-bold text-gray-900 dark:text-gray-200">{{ $systemStock }}</p>
                                </div>
                                
                                {{-- Panah pemisah, hanya terlihat di desktop --}}
                                <div class="text-gray-300 dark:text-gray-600 font-light hidden md:block">→</div>
                                
                                {{-- Stok Fisik (Input) --}}
                                <div class="text-center w-24 flex-shrink-0">
                                    <label for="fisik-{{$sku}}" class="text-xs text-blue-600 dark:text-blue-400 font-semibold uppercase">Fisik</label>
                                    <input type="number" 
                                           id="fisik-{{$sku}}"
                                           wire:model="itemsData.{{ $sku }}.counted_stock"
                                           wire:blur="saveCount('{{ $sku }}', {{ $skuData->first_variant_id }})"
                                           @if($stockTake->status === 'completed') disabled @endif
                                           class="mt-1 block w-full text-center rounded-lg border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/50 shadow-sm sm:text-lg font-bold disabled:bg-gray-200 dark:disabled:bg-gray-700 disabled:cursor-not-allowed focus:border-blue-500 focus:ring-blue-500"
                                           placeholder="-">
                                </div>

                                {{-- Selisih (Variance) --}}
                                <div class="text-center w-20 flex-shrink-0">
                                    <p class="text-xs text-gray-500 font-semibold uppercase">Selisih</p>
                                    <p @class([
                                        'text-lg font-bold',
                                        'text-gray-400' => is_null($variance),
                                        'text-green-600 dark:text-green-400' => !is_null($variance) && $variance > 0,
                                        'text-red-600 dark:text-red-400' => !is_null($variance) && $variance < 0,
                                        'text-gray-700 dark:text-gray-300' => !is_null($variance) && $variance == 0,
                                    ])>
                                        {{ is_null($variance) ? '-' : ($variance > 0 ? '+'.$variance : $variance) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-gray-500">
                                Tidak ada SKU yang terdaftar. Silakan tambahkan SKU di Master SKU terlebih dahulu.
                            </td>
                        </tr>
                    @endforelse
                </div>
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>