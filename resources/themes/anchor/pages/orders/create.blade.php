<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Reseller;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Expense;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

middleware('auth');
name('orders.create');

new class extends Component {
    // UI State & Form Data
    public string $channel = 'direct';
    public ?int $reseller_id = null;
    public string $customer_name = '';
    public string $shipping_provider = '';
    public string $tracking_number = '';
    public float $shipping_cost = 0;
    
    // Data Sources
    public Collection $resellers;
    public Collection $availableSkus;

    // Cart & Summary
    public array $cart = [];
    public float $subtotal = 0;
    public float $discount_amount = 0;
    public float $grand_total = 0;
    public int $total_weight = 0;
    public string $copyable_text = '';

    #[Livewire\Attributes\On('reseller-created')]
    public function handleResellerCreated(int $resellerId): void
    {
        $this->loadResellers();
        $this->reseller_id = $resellerId;
        $this->channel = 'reseller';
    }
    
    public function mount(): void
    {
        $this->loadResellers();
        $this->loadAvailableSkus();
    }
    
    private function loadResellers(): void
    {
        $this->resellers = Reseller::where('user_id', auth()->id())->orderBy('name')->get();
    }
    
    private function loadAvailableSkus(): void
    {
        $allVariants = ProductVariant::where('reseller', true)
            ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
            ->select('id', 'variant_sku', 'selling_price', 'weight')
            ->orderBy('variant_sku')
            ->get();
            
        $this->availableSkus = $allVariants->unique('variant_sku');
    }
    
    public function processCartAndCalculate(): void
    {
        $this->fillCartDetails();

        $this->subtotal = collect($this->cart)->sum(fn($item) => data_get($item, 'price', 0) * data_get($item, 'quantity', 0));
        $this->total_weight = collect($this->cart)->sum(fn($item) => data_get($item, 'weight', 0) * data_get($item, 'quantity', 0));
        
        $discountPercentage = 0;
        if ($this->channel === 'reseller' && $this->reseller_id) {
            $reseller = $this->resellers->find($this->reseller_id);
            if ($reseller) {
                $this->discount_amount = $this->subtotal * ($reseller->discount_percentage / 100);
                $discountPercentage = $reseller->discount_percentage;
            } else { $this->discount_amount = 0; }
        } else { $this->discount_amount = 0; }

        $this->grand_total = $this->subtotal - $this->discount_amount;
        
        $this->generateCopyableText($discountPercentage);
        
        Notification::make()->title('Perhitungan Selesai')->success()->body('Total pesanan telah diperbarui.')->send();
    }

    private function fillCartDetails(): void
    {
        $cartWithDetails = [];
        foreach ($this->cart as $variantId => $itemData) {
            $quantity = (int) data_get($itemData, 'quantity', 0);

            if ($quantity > 0) {
                $variant = $this->availableSkus->find($variantId);
                if ($variant) {
                    $currentStock = ProductVariant::find($variantId)->available_stock;
                    if ($quantity > $currentStock) {
                        $quantity = $currentStock;
                        Notification::make()->title('Stok Disesuaikan')->warning()->body("Stok untuk SKU {$variant->variant_sku} hanya tersisa {$currentStock} pcs.")->send();
                    }

                    $cartWithDetails[$variantId] = [
                        'id' => $variant->id,
                        'sku' => $variant->variant_sku,
                        'name' => $variant->variant_sku,
                        'price' => $variant->selling_price,
                        'weight' => $variant->weight ?? 0,
                        'quantity' => $quantity,
                    ];
                }
            }
        }
        $this->cart = $cartWithDetails;
    }
    
    protected function generateCopyableText(float $discountPercentage): void
    {
        $text = "_Bismillah_...\n\n";
        $text .= "*RO Produk:*\n";
        foreach ($this->cart as $item) {
            $quantity = data_get($item, 'quantity', 0);
            $name = data_get($item, 'name', 'Produk tidak dikenal');
            $price = data_get($item, 'price', 0);
            $text .= "{$quantity} x {$name} @Rp " . number_format($price, 0, ',', '.') . "\n";
        }
        $text .= "\n";
        $text .= "Subtotal: Rp " . number_format($this->subtotal, 0, ',', '.') . "\n";
        $text .= "Diskon [{$discountPercentage} %]: Rp " . number_format($this->discount_amount, 0, ',', '.') . "\n";
        $text .= "Ongkos Kirim: Rp " . number_format($this->shipping_cost, 0, ',', '.') . "\n";
        $totalTagihan = $this->grand_total + $this->shipping_cost;
        $text .= "*Total: Rp " . number_format($totalTagihan, 0, ',', '.') . "*\n\n";
        $ekspedisi = !empty($this->shipping_provider) ? $this->shipping_provider : '-';
        $text .= "Ekspedisi: {$ekspedisi}\n\n";
        $text .= "Mandiri 1640001337007\n";
        $text .= "BCA 4740432546\n";
        $text .= "atas nama Indah Nuraeni\n\n";
        $text .= "Harap mengirimkan foto bukti transfer 😊";
        $this->copyable_text = $text;
    }

public function saveOrder(): void
{
    $this->processCartAndCalculate();
    
    $this->validate([
        'channel' => 'required|in:direct,reseller',
        'reseller_id' => 'required_if:channel,reseller|nullable|exists:resellers,id',
        'customer_name' => 'required_if:channel,direct|nullable|string|max:255',
        'cart' => 'required|array|min:1',
        'shipping_cost' => 'nullable|numeric|min:0',
    ]);
    
    try {
        DB::transaction(function () {
            // PERBAIKAN UTAMA ADA DI BLOK INI
            $order = Order::create([
                'user_id' => auth()->id(),

                // 1. Secara eksplisit set 'channel' dari properti komponen
                'channel' => $this->channel, 

                // 2. Set 'reseller_id' jika channel adalah 'reseller'
                'reseller_id' => $this->channel === 'reseller' ? $this->reseller_id : null,
                
                // 3. Set 'customer_name' berdasarkan channel
                'customer_name' => $this->channel === 'direct' ? $this->customer_name : $this->resellers->find($this->reseller_id)?->name,
                
                // 4. Set 'order_date' dengan waktu saat ini
                'order_date' => now(), 

                // 5. Set 'status' menjadi 'completed' sebagai default untuk penjualan ini
                'order_status' => 'completed',
                
                'total_price' => $this->grand_total,
                'shipping_provider' => $this->shipping_provider,
                'tracking_number' => $this->tracking_number,
                'shipping_cost' => $this->shipping_cost,
                'is_stock_deducted' => true,
            ]);

            // dd($order);

            foreach ($this->cart as $item) {
                $order->items()->create([
                    'product_variant_id' => $item['id'],
                    'variant_sku' => $item['sku'],
                    'product_name' => $item['name'],
                    'price' => $item['price'],
                    'quantity' => $item['quantity'],
                    'subtotal' => $item['price'] * $item['quantity'],
                ]);

                $variant = ProductVariant::find($item['id']);
                if ($variant) {
                    StockMovement::create([
                        'user_id' => auth()->id(),
                        'product_variant_id' => $variant->id,
                        'order_id' => $order->id,
                        'type' => 'sale',
                        'quantity' => -$item['quantity'],
                        'notes' => 'Penjualan '.ucfirst($this->channel).' #'.$order->id,
                    ]);
                    $variant->updateWarehouseStock(); 
                }
            }

            if ($this->shipping_cost > 0) {
                Expense::create([
                    'user_id' => auth()->id(),
                    'category' => 'Biaya Pengiriman',
                    'description' => 'Ongkir Pesanan #'.$order->id.($order->customer_name ? ' a/n '.$order->customer_name : ''),
                    'amount' => $this->shipping_cost,
                    'transaction_date' => now(),
                ]);
            }
        });
        Notification::make()->title('Pesanan Berhasil Disimpan')->success()->send();
        $this->redirectRoute('dashboard', navigate: true);
    } catch (\Exception $e) {
         Notification::make()->title('Terjadi Kesalahan')->danger()->body($e->getMessage())->send();
    }
}
};
?>

<x-layouts.app>
    @livewire('resellers.create-modal')
    @volt('orders-create')
    <form wire:submit.prevent="saveOrder">
        <x-app.container>
            <x-app.heading title="Buat Pesanan Baru" description="Catat penjualan dari channel reseller atau penjualan langsung." />
            
            <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-8">
                {{-- Kolom Kiri & Tengah: Form Utama --}}
                <div class="lg:col-span-2 space-y-6">

                    {{-- Card Info Pelanggan & Pengiriman --}}
                    <div class="p-4 sm:p-6 bg-white dark:bg-gray-800 rounded-lg shadow">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">1. Informasi Pesanan</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <x-input-label value="Saluran Penjualan" />
                                <div class="mt-2 flex gap-4">
                                    <label class="flex items-center">
                                        <input type="radio" wire:model.live="channel" value="direct" class="form-radio-monochrome">
                                        <span class="ml-2 text-sm text-gray-600 dark:text-gray-300">Penjualan Langsung</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="radio" wire:model.live="channel" value="reseller" class="form-radio-monochrome">
                                        <span class="ml-2 text-sm text-gray-600 dark:text-gray-300">Reseller</span>
                                    </label>
                                </div>
                            </div>

                            @if($channel === 'direct')
                                <div>
                                    <x-input-label for="customer_name" value="Nama Pelanggan" />
                                    <x-text-input wire:model="customer_name" id="customer_name" class="block mt-1 w-full" type="text" />
                                    <x-input-error :messages="$errors->get('customer_name')" class="mt-2" />
                                </div>
                            @else
                                <div>
                                    <x-input-label for="reseller_id" value="Pilih Reseller" />
                                    <div class="flex items-center gap-2 mt-1">
                                        <x-select-input wire:model.live="reseller_id" id="reseller_id" class="block w-full">
                                            <option value="">-- Pilih Reseller --</option>
                                            @forelse($resellers as $reseller)
                                                <option value="{{ $reseller->id }}">{{ $reseller->name }} ({{ $reseller->discount_percentage }}%)</option>
                                            @empty
                                                <option value="" disabled>Belum ada reseller</option>
                                            @endforelse
                                        </x-select-input>
                                        <button type="button" wire:click="$dispatch('open-reseller-modal')" class="flex-shrink-0 px-3 py-2 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded-md text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                                            Baru
                                        </button>
                                    </div>
                                    <x-input-error :messages="$errors->get('reseller_id')" class="mt-2" />
                                </div>
                            @endif

                             <div>
                                <x-input-label for="shipping_provider" value="Jasa Kirim (Opsional)" />
                                <x-text-input wire:model="shipping_provider" id="shipping_provider" class="block mt-1 w-full" type="text" placeholder="e.g. JNE, Sicepat" />
                            </div>
                             <div>
                                <x-input-label for="tracking_number" value="No. Resi (Opsional)" />
                                <x-text-input wire:model="tracking_number" id="tracking_number" class="block mt-1 w-full" type="text" />
                            </div>
                        </div>
                    </div>

                    {{-- Card Keranjang --}}
                    <div class="p-4 sm:p-6 bg-white dark:bg-gray-800 rounded-lg shadow">
                         <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">2. Pilih Produk</h3>
                         <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-4 gap-4">
                            @foreach($availableSkus as $variant)
                                <div wire:key="sku-{{ $variant->id }}" class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 flex flex-col justify-between">
                                    <div>
                                        <p class="font-bold text-base text-gray-800 dark:text-gray-200 truncate" title="{{ $variant->variant_sku }}">{{ $variant->variant_sku }}</p>
                                        <p class="text-xs text-gray-500">Rp {{ number_format($variant->selling_price) }}</p>
                                    </div>
                                    <div class="mt-3">
                                        <x-text-input 
                                            type="number" 
                                            wire:model="cart.{{ $variant->id }}.quantity"
                                            class="w-full text-center" 
                                            placeholder="0"
                                            min="0"
                                        />
                                    </div>
                                </div>
                            @endforeach
                         </div>

                         <div class="mt-6 border-t border-gray-200 dark:border-gray-700 pt-4">
                             <button type="button" wire:click="processCartAndCalculate" class="w-full flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-black dark:bg-gray-200 dark:text-black hover:bg-gray-800 dark:hover:bg-gray-300">
                                 Hitung Total Pesanan
                                 <span wire:loading wire:target="processCartAndCalculate" class="ml-2">
                                     <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                       <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                       <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                     </svg>
                                 </span>
                             </button>
                         </div>
                    </div>
                </div>

                {{-- Kolom Kanan: Ringkasan & Format Pesan --}}
                <div class="lg:col-span-1">
                    <div class="sticky top-24 space-y-6">
                        {{-- Card Ringkasan --}}
                        <div class="p-4 sm:p-6 bg-white dark:bg-gray-800 rounded-lg shadow space-y-4">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white">3. Ringkasan</h3>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between text-gray-600 dark:text-gray-300"><span>Subtotal</span><span>Rp {{ number_format($subtotal) }}</span></div>
                                <div class="flex justify-between text-gray-600 dark:text-gray-300"><span>Diskon</span><span class="text-gray-800 dark:text-gray-200 font-medium">- Rp {{ number_format($discount_amount) }}</span></div>
                            </div>
                            
                             <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                                 <x-input-label for="shipping_cost" value="Biaya Ongkos Kirim" />
                                 <x-text-input wire:model="shipping_cost" id="shipping_cost" class="block mt-1 w-full" type="number" />
                             </div>
    
                            <div class="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-2">
                                 <div class="flex justify-between font-semibold text-base text-gray-900 dark:text-white">
                                    <span>Total Belanja</span>
                                    <span>Rp {{ number_format($grand_total) }}</span>
                                </div>
                                <div class="flex justify-between font-bold text-lg text-gray-900 dark:text-white">
                                    <span>Total Tagihan</span>
                                    <span>Rp {{ number_format($grand_total + $shipping_cost) }}</span>
                                </div>
                                <div class="flex justify-between text-sm font-medium text-blue-600 dark:text-blue-400 pt-1">
                                    <span>Estimasi Berat</span>
                                    <span>{{ number_format($total_weight) }} gram</span>
                                </div>
                            </div>
    
                            <button type="submit" class="w-full px-4 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-black dark:bg-gray-200 dark:text-black hover:bg-gray-800 dark:hover:bg-gray-300 disabled:opacity-50" @if(empty($cart)) disabled @endif>
                                Simpan Pesanan
                            </button>
                        </div>

                        {{-- Area Teks untuk Disalin --}}
                        @if(!empty($cart))
                            <div 
                                x-data="{ 
                                    showNotification: false,
                                    copyToClipboard() {
                                        navigator.clipboard.writeText(this.$refs.copytext.value);
                                        this.showNotification = true;
                                        setTimeout(() => this.showNotification = false, 2000);
                                    }
                                }"
                                class="p-4 sm:p-6 bg-white dark:bg-gray-800 rounded-lg shadow relative"
                            >
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Format Pesan</h3>
                                
                                <textarea x-ref="copytext" rows="15" readonly class="w-full text-sm border-gray-300 dark:border-gray-600 rounded-md bg-gray-50 dark:bg-gray-700/50 focus:ring-0 focus:border-gray-300 dark:focus:border-gray-600">{{ $copyable_text }}</textarea>

                                <button @click="copyToClipboard()" type="button" class="mt-4 w-full flex items-center justify-center px-4 py-2 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded-md text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                    Salin Teks
                                </button>

                                <div x-show="showNotification" 
                                     x-transition:enter="transition ease-out duration-300"
                                     x-transition:enter-start="opacity-0 transform scale-90"
                                     x-transition:enter-end="opacity-100 transform scale-100"
                                     x-transition:leave="transition ease-in duration-300"
                                     x-transition:leave-start="opacity-100 transform scale-100"
                                     x-transition:leave-end="opacity-0 transform scale-90"
                                     class="absolute top-0 right-0 mt-6 mr-6 bg-black text-white text-xs font-bold py-1 px-3 rounded-full"
                                     style="display: none;">
                                    Tersalin!
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </x-app.container>
    </form>
    @endvolt
</x-layouts.app>