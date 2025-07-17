<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Order;
use App\Models\StockMovement;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

middleware('auth');
name('reports.cancelled-orders');

new class extends Component {
    use WithPagination;

    public string $reportDate;
    public string $searchOrderSn = '';
    public ?Order $orderToRestock = null;
    public string $feedbackMessage = '';
    public string $startDate;
    public string $endDate;

    public function updatedSearchOrderSn($value): void
    {
        $this->searchOrderSn = strtoupper($value);
    }

    public function mount(): void
    {
        // Inisialisasi rentang tanggal ke hari ini
        $this->startDate = today()->toDateString();
        $this->endDate = today()->toDateString();
    }

    public function updatedStartDate(): void
    {
        $this->resetPage();
    }
    public function updatedEndDate(): void
    {
        $this->resetPage();
    }

    public function updatedReportDate(): void
    {
        $this->resetPage();
    }

    public function findOrder(): void
    {
        $this->reset('orderToRestock', 'feedbackMessage');

        $validated = $this->validate([
            'searchOrderSn' => 'required|string|min:5',
        ]);

        $order = Order::query()
            ->where('user_id', auth()->id())
            ->where('order_sn', trim($validated['searchOrderSn']))
            ->where('order_status', 'Dibatalkan')
            ->whereNotNull('tracking_number')
            // PERBAIKAN DI SINI: 'orderItems' -> 'items'
            ->with('items.productVariant')
            ->first();

        if (!$order) {
            $this->feedbackMessage = 'Pesanan tidak ditemukan atau tidak memenuhi syarat (Status bukan "Dibatalkan" / Belum ada No. Resi).';
            return;
        }

        $this->orderToRestock = $order;

        if ($order->is_stock_restored) {
             $this->feedbackMessage = 'INFO: Stok untuk pesanan ini sudah pernah dikembalikan sebelumnya.';
        }
    }

    public function restoreStock(): void
    {
        if (!$this->orderToRestock || $this->orderToRestock->is_stock_restored) {
            $this->feedbackMessage = 'Aksi tidak dapat dilakukan.';
            return;
        }

        try {
            DB::transaction(function () {
                // PERBAIKAN DI SINI: 'orderItems' -> 'items'
                foreach ($this->orderToRestock->items as $item) {
                    $variant = $item->productVariant;

                    $variant->increment('warehouse_stock', $item->quantity);

                    StockMovement::create([
                        'user_id' => auth()->id(),
                        'product_variant_id' => $variant->id,
                        'order_id' => $this->orderToRestock->id,
                        'quantity' => $item->quantity,
                        'type' => 'restock_cancelled',
                        'description' => 'Pengembalian stok dari pesanan dibatalkan: ' . $this->orderToRestock->order_sn,
                    ]);
                }

                $this->orderToRestock->is_stock_restored = true;
                $this->orderToRestock->save();
            });

            $this->feedbackMessage = 'SUKSES: Stok untuk pesanan ' . $this->orderToRestock->order_sn . ' telah dikembalikan.';
            $this->orderToRestock->refresh();

        } catch (\Exception $e) {
            $this->feedbackMessage = 'ERROR: Terjadi kesalahan saat mengembalikan stok. ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $cancelledOrders = Order::query()
            ->where('user_id', auth()->id())
            ->where('order_status', 'Dibatalkan')
            // GANTI BAGIAN INI: dari whereDate menjadi whereBetween
            ->whereBetween('updated_at', [
                Carbon::parse($this->startDate)->startOfDay(),
                Carbon::parse($this->endDate)->endOfDay(),
            ])
            ->with('items.productVariant:id,variant_sku,variant_name')
            ->orderBy('updated_at', 'desc')
            ->paginate(10);

        return [
            'cancelledOrders' => $cancelledOrders,
        ];
    }
}; ?>

<x-layouts.app>
    @volt('reports-cancelled-orders')
        <div class="space-y-10">
            {{-- BAGIAN 1: FORM PENGEMBALIAN STOK --}}
            <x-app.container>
                <x-app.heading 
                    title="Form Pengembalian Stok (Pesanan Batal)"
                    description="Cari pesanan yang dibatalkan & memiliki No. Resi untuk mengembalikan stoknya ke gudang." 
                    :border="true" />
                
                <form wire:submit="findOrder" class="mt-6 max-w-xl">
                    <label for="search_order_sn" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Masukkan No. Pesanan Lengkap:</label>
                    <div class="mt-1 flex items-center gap-2">
                        <input type="text" id="search_order_sn"
                            wire:model.live.debounce.300ms="searchOrderSn"
                            placeholder="Contoh: 231120ABCD123E"
                            required
                            class="uppercase block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 px-3 text-sm focus:border-black focus:ring-black">
                        <x-primary-button type="submit" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="findOrder">Cari Pesanan</span>
                            <span wire:loading wire:target="findOrder">Mencari...</span>
                        </x-primary-button>
                    </div>
                </form>

                <div class="mt-4">
                    @if ($feedbackMessage)
                        <div class="rounded-md p-3 text-sm mb-4 {{ str_contains($feedbackMessage, 'SUKSES') ? 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' : 'bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200' }}">
                            {{ $feedbackMessage }}
                        </div>
                    @endif

                    @if ($orderToRestock)
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                            <div class="flex justify-between items-start">
                                <div>
                                    <h4 class="font-bold text-lg text-gray-800 dark:text-gray-200">Detail Pesanan</h4>
                                    <p class="font-mono text-gray-600 dark:text-gray-400">{{ $orderToRestock->order_sn }}</p>
                                </div>
                                @if ($orderToRestock->is_stock_restored)
                                    <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                        Stok Sudah Dikembalikan
                                    </span>
                                @else
                                     <span class="inline-flex items-center rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-medium text-orange-800 dark:bg-orange-900 dark:text-orange-200">
                                        Stok Belum Dikembalikan
                                    </span>
                                @endif
                            </div>
                            <div class="mt-4 border-t border-gray-200 dark:border-gray-700 pt-4">
                                <p class="text-sm font-medium mb-2">Item dalam pesanan:</p>
                                <ul class="space-y-2">
                                    {{-- PERBAIKAN DI SINI: 'orderItems' -> 'items' --}}
                                    @foreach ($orderToRestock->items as $item)
                                        <li class="flex justify-between items-center text-sm">
                                            <div>
                                                <p class="font-mono font-semibold text-gray-800 dark:text-gray-200">{{ $item->productVariant->variant_sku }}</p>
                                                <p class="text-gray-500">{{ $item->productVariant->variant_name }}</p>
                                            </div>
                                            <p class="font-bold text-gray-800 dark:text-gray-200">x {{ $item->quantity }}</p>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                            
                            @if (!$orderToRestock->is_stock_restored)
                                <div class="mt-6 border-t border-gray-200 dark:border-gray-700 pt-4 text-right">
                                     <x-primary-button
                                        wire:click="restoreStock"
                                        wire:loading.attr="disabled"
                                        wire:confirm="Anda yakin ingin mengembalikan stok untuk pesanan ini? Aksi ini tidak dapat dibatalkan.">
                                        <span wire:loading.remove wire:target="restoreStock">Kembalikan Stok ke Gudang</span>
                                        <span wire:loading wire:target="restoreStock">Memproses...</span>
                                    </x-primary-button>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </x-app.container>

            {{-- BAGIAN 2: LAPORAN HARIAN --}}
            <x-app.container>
                <x-app.heading 
                    title="Laporan Pesanan Dibatalkan" 
                    description="Daftar pesanan yang dibatalkan pada tanggal yang dipilih." 
                    :border="true" />

                <div class="mt-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-xl">
                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tanggal Mulai:</label>
                            <input type="date" id="start_date"
                                wire:model.live="startDate"
                                max="{{ today()->toDateString() }}"
                                class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 px-3 text-sm focus:border-black focus:ring-black">
                        </div>
                        <div>
                            <label for="end_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tanggal Selesai:</label>
                            <input type="date" id="end_date"
                                wire:model.live="endDate"
                                max="{{ today()->toDateString() }}"
                                class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 px-3 text-sm focus:border-black focus:ring-black">
                        </div>
                    </div>
                </div>

                <div class="mt-4 flow-root">
                     <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                        <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                            <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 sm:rounded-lg">
                                <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-100 sm:pl-6">No. Pesanan</th>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-gray-100">Produk</th>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-gray-100">Alasan Batal</th>
                                            <th scope="col" class="px-3 py-3.5 text-center text-sm font-semibold text-gray-900 dark:text-gray-100">Tgl. Dibatalkan</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                                        @forelse ($cancelledOrders as $order)
                                            <tr wire:key="order-{{ $order->id }}">
                                                <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 dark:text-gray-100 sm:pl-6">{{ $order->order_sn }}</td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                                    <ul class="list-disc list-inside">
                                                        {{-- PERBAIKAN DI SINI: 'orderItems' -> 'items' --}}
                                                        @foreach($order->items as $item)
                                                            <li>{{ $item->productVariant->variant_sku }} (x{{$item->quantity}})</li>
                                                        @endforeach
                                                    </ul>
                                                </td>
                                                <td class="px-3 py-4 text-sm text-gray-500 max-w-xs truncate" title="{{ $order->status_description }}">
                                                    {{ $order->status_description }}
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-center text-gray-500">{{ $order->updated_at->isoFormat('D MMM YYYY, HH:mm') }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="px-6 py-12 text-center text-sm text-gray-500">
                                                    Tidak ada pesanan yang dibatalkan pada tanggal ini.
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                @if($cancelledOrders->hasPages())
                    <div class="mt-6">
                        {{ $cancelledOrders->links() }}
                    </div>
                @endif
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>