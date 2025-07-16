<?php
use function Laravel\Folio\{middleware, name};
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\ProductVariant;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

middleware('auth');
name('purchases.show');

new class extends Component {
    public $purchaseOrder;

    public function mount($purchaseOrder): void
    {
        $po = PurchaseOrder::where('user_id', auth()->id())
                           ->findOrFail($purchaseOrder);
        $po->load('items.productVariant');
        $this->purchaseOrder = $po;
    }

    public function receiveStock(): void
    {
        if ($this->purchaseOrder->status !== 'draft' && $this->purchaseOrder->status !== 'ordered') {
            Notification::make()->title('Aksi Tidak Valid')->warning()->body('PO ini sudah diterima atau dibatalkan.')->send();
            return;
        }

        DB::transaction(function () {
            foreach ($this->purchaseOrder->items as $item) {
                if (!$item->productVariant) continue;
                
                ProductVariant::where('variant_sku', $item->productVariant->variant_sku)
                              ->whereHas('product', fn($q) => $q->where('user_id', auth()->id()))
                              ->increment('warehouse_stock', $item->quantity);

                StockMovement::create([
                    'user_id' => auth()->id(),
                    'product_variant_id' => $item->product_variant_id,
                    'type' => 'purchase',
                    'quantity' => $item->quantity,
                    'notes' => 'Penerimaan barang dari PO #' . $this->purchaseOrder->po_number,
                ]);
            }
            
            $this->purchaseOrder->update([
                'status' => 'received',
                'received_at' => now(),
            ]);
        });

        Notification::make()->title('Barang Diterima')->success()->body('Stok telah berhasil ditambahkan ke gudang.')->send();
        $this->purchaseOrder->refresh();
    }

    // [TAMBAHAN] Metode untuk menghapus Purchase Order
    public function deletePO(): void
    {
        // Keamanan tambahan: Pastikan statusnya adalah 'draft'
        if ($this->purchaseOrder->status !== 'draft') {
            Notification::make()
                ->title('Aksi Tidak Diizinkan')
                ->warning()
                ->body('Hanya Purchase Order dengan status DRAFT yang dapat dihapus.')
                ->send();
            return;
        }

        $poNumber = $this->purchaseOrder->po_number;
        
        // Eloquent akan menghapus item terkait secara otomatis jika relasi onDelete('cascade') diatur di migrasi.
        $this->purchaseOrder->delete();

        Notification::make()
            ->title('Purchase Order Dihapus')
            ->success()
            ->body("PO #{$poNumber} telah berhasil dihapus.")
            ->send();

        // Redirect kembali ke halaman index setelah penghapusan berhasil
        $this->redirectRoute('purchases.index');
    }


    public function with(): array { return []; }
};
?>

<x-layouts.app>
    @volt('purchases-show')
        <div>
            <x-app.container>
                @if($purchaseOrder)
                    <div class="flex flex-col sm:flex-row justify-between items-start gap-4">
                        <div>
                            <x-app.heading :title="'Detail Purchase Order #' . $purchaseOrder->po_number" :border="false" />
                            <p class="mt-1 text-sm text-gray-500">Dibuat pada {{ $purchaseOrder->created_at?->format('d F Y, H:i') ?? '-' }}</p>
                        </div>
                        
                        {{-- [TAMBAHAN] Grup untuk tombol-tombol aksi --}}
                        <div class="flex items-center gap-2 self-start sm:self-center">
                            @if($purchaseOrder->status === 'draft')
                                {{-- [TAMBAHAN] Tombol Hapus dengan konfirmasi --}}
                                <button 
                                    wire:click="deletePO" 
                                    wire:confirm="Anda yakin ingin menghapus Purchase Order ini? Aksi ini tidak dapat dibatalkan."
                                    class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700"
                                >
                                    Hapus PO
                                </button>
                            @endif

                            @if($purchaseOrder->status === 'draft' || $purchaseOrder->status === 'ordered')
                                <button wire:click="receiveStock" wire:confirm="Anda yakin ingin menandai semua item di PO ini sebagai DITERIMA? Stok akan otomatis ditambahkan." class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700">
                                    Tandai Diterima
                                </button>
                            @endif
                        </div>
                    </div>
                    <hr class="my-6 dark:border-gray-700">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <x-stat-card title="Pemasok" :value="$purchaseOrder->supplier ?? 'N/A'" />
                        <x-stat-card title="Total Pembelian" :value="'Rp ' . number_format($purchaseOrder->total_amount, 0, ',', '.')" />
                        <x-stat-card title="Status">
                            <x-slot:value>
                                <x-filament::badge :color="match($purchaseOrder->status) {
                                    'draft' => 'gray', 'ordered' => 'warning',
                                    'received' => 'success', 'cancelled' => 'danger',
                                    default => 'gray'
                                }">
                                    {{ str_replace('_', ' ', $purchaseOrder->status) }}
                                </x-filament::badge>
                            </x-slot:value>
                        </x-stat-card>
                    </div>

                    <div class="mt-8">
                        <h3 class="font-semibold text-lg">Item Pembelian</h3>
                        <div class="mt-4 shadow border-b border-gray-200 dark:border-gray-700 sm:rounded-lg">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">SKU</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Jumlah</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Harga Beli</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium uppercase">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($purchaseOrder->items as $item)
                                        @if($item->productVariant)
                                            <tr>
                                                <td class="px-6 py-4">
                                                    <p class="font-mono font-bold">{{ $item->productVariant->variant_sku }}</p>
                                                    <p class="text-xs text-gray-500">{{ $item->productVariant->variant_name }}</p>
                                                </td>
                                                <td class="px-6 py-4">{{ $item->quantity }}</td>
                                                <td class="px-6 py-4">Rp {{ number_format($item->cost_price, 0, ',', '.') }}</td>
                                                <td class="px-6 py-4 text-right font-semibold">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @else
                    <div class="text-center py-12 text-gray-500">
                        <p>Purchase Order tidak ditemukan atau Anda tidak memiliki izin untuk melihatnya.</p>
                    </div>
                @endif
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>