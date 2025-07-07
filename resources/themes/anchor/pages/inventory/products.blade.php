<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

middleware('auth');
name('inventory.products');

new class extends Component {
    public $products;
    public array $variantsData = [];

    // Menggunakan ID produk untuk feedback, bukan varian
    public ?int $justSavedProductId = null;

    public function mount(): void
    {
        $this->products = Product::where('user_id', auth()->id())
            ->with('variants')
            ->orderBy('product_name')
            ->get();
        
        foreach ($this->products as $product) {
            foreach ($product->variants as $variant) {
                $this->variantsData[$variant->id] = [
                    'cost_price' => $variant->cost_price,
                    'warehouse_stock' => $variant->warehouse_stock,
                ];
            }
        }
    }
    
    // PERUBAHAN: Method ini sekarang menyimpan semua varian untuk satu produk
    public function saveVariantsForProduct(int $productId): void
    {
        $this->justSavedProductId = null;

        $product = Product::with('variants')->find($productId);
        if (!$product || $product->user_id !== auth()->id()) {
            abort(403, 'Unauthorized action.');
        }

        // Kumpulkan aturan validasi untuk semua varian produk ini
        $rules = [];
        foreach ($product->variants as $variant) {
            $rules["variantsData.{$variant->id}.cost_price"] = 'nullable|numeric|min:0';
            $rules["variantsData.{$variant->id}.warehouse_stock"] = 'required|integer|min:0';
        }

        $this->validate($rules);
        
        DB::transaction(function () use ($product) {
            foreach ($product->variants as $variant) {
                $data = $this->variantsData[$variant->id];
                
                $originalStock = $variant->warehouse_stock;
                $newStock = (int) $data['warehouse_stock'];
                $stockDifference = $newStock - $originalStock;
                
                // Update data varian
                $variant->update([
                    'cost_price' => $data['cost_price'],
                    'warehouse_stock' => $newStock,
                ]);

                if ($stockDifference != 0) {
                    StockMovement::create([
                        'user_id' => auth()->id(),
                        'product_variant_id' => $variant->id,
                        'type' => 'adjustment',
                        'quantity' => $stockDifference,
                        'notes' => 'Penyesuaian stok manual oleh pengguna.',
                    ]);
                }
            }
        });

        $this->justSavedProductId = $productId;
    }
}; ?>

<x-layouts.app>
    @volt('inventory-products')
        <div>
            <x-app.container>
                <x-app.heading 
                    title="Manajemen Produk & Stok"
                    description="Atur harga modal dan stok awal untuk setiap varian produk Anda. Klik 'Simpan Perubahan' untuk menyimpan semua data produk."
                    :border="true" />

                <div class="mt-6 space-y-4">
                    @forelse($products as $product)
                        {{-- PERUBAHAN: Form sekarang membungkus seluruh produk --}}
                        <form wire:submit.prevent="saveVariantsForProduct({{ $product->id }})" 
                              wire:key="product-form-{{ $product->id }}"
                              class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                            
                            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                                    {{ $product->product_name }}
                                </h3>
                            </div>
                            
                            {{-- Container untuk semua varian --}}
                            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse($product->variants as $variant)
                                    <div wire:key="variant-{{ $variant->id }}" class="p-4 flex flex-col lg:flex-row lg:justify-between lg:items-center gap-4">
                                        
                                        {{-- Informasi Varian --}}
                                        <div class="flex-grow">
                                            {{-- PERBAIKAN: Batasi nama varian --}}
                                            <p class="font-semibold text-gray-800 dark:text-gray-200">{{ Str::words($variant->variant_name, 6, '...') ?: 'Varian Default' }}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">SKU: {{ $variant->variant_sku ?: '-' }}</p>
                                        </div>

                                        {{-- Grup Input --}}
                                        <div class="flex-shrink-0 flex flex-col md:flex-row md:items-end gap-3 w-full md:w-auto">
                                            
                                            {{-- Input Harga Modal --}}
                                            <div>
                                                <label for="cost_price_{{ $variant->id }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Harga Modal</label>
                                                <div class="relative mt-1">
                                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                        <span class="text-gray-500 sm:text-sm">Rp</span>
                                                    </div>
                                                    {{-- PERBAIKAN: Ukuran input diseragamkan --}}
                                                    <input type="number" step="any" id="cost_price_{{ $variant->id }}" 
                                                        wire:model="variantsData.{{ $variant->id }}.cost_price"
                                                        class="block w-full md:w-40 rounded-lg border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/50 shadow-sm focus:border-black dark:focus:border-white focus:ring-1 focus:ring-black dark:focus:ring-white pl-8 sm:text-sm"
                                                        placeholder="50000">
                                                </div>
                                            </div>

                                            {{-- Input Stok Gudang --}}
                                            <div>
                                                <label for="warehouse_stock_{{ $variant->id }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Stok Gudang</label>
                                                {{-- PERBAIKAN: Ukuran input diseragamkan dan SVG dihapus --}}
                                                <input type="number" id="warehouse_stock_{{ $variant->id }}" 
                                                    wire:model="variantsData.{{ $variant->id }}.warehouse_stock"
                                                    class="mt-1 block w-full md:w-40 rounded-lg border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/50 shadow-sm focus:border-black dark:focus:border-white focus:ring-1 focus:ring-black dark:focus:ring-white sm:text-sm"
                                                    placeholder="100">
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <p class="p-4 text-sm text-gray-500">Produk ini tidak memiliki varian.</p>
                                @endforelse
                            </div>

                            {{-- PERUBAHAN: Area Tombol Simpan Tunggal --}}
                            <div class="px-4 py-3 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-200 dark:border-gray-700 flex justify-end items-center gap-4">
                                @if($justSavedProductId === $product->id)
                                    <span wire:key="feedback-product-{{ $product->id }}" class="text-sm font-medium text-green-600 dark:text-green-400 transition-opacity duration-300">
                                        Perubahan tersimpan!
                                    </span>
                                @endif
                                <button type="submit"
                                        class="rounded-lg bg-black dark:bg-white px-4 py-2 text-sm font-semibold text-white dark:text-black hover:bg-gray-800 dark:hover:bg-gray-200 transition-colors">
                                    Simpan Perubahan
                                </button>
                            </div>

                        </form>
                    @empty
                        <div class="text-center text-gray-400 py-16 bg-white dark:bg-gray-800 rounded-lg border-dashed border-gray-300 dark:border-gray-700">
                            <p>Anda belum memiliki data produk.</p>
                            <p class="text-sm mt-1">Silakan sinkronisasikan data produk dari e-commerce terlebih dahulu.</p>
                        </div>
                    @endforelse
                </div>

            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>