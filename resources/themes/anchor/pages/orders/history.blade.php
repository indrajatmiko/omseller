<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Order;
use App\Models\StockMovement;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Carbon\Carbon;

middleware('auth');
name('orders.history');

new class extends Component {
    use WithPagination;

    public $selectedYear;
    public $selectedMonth;
    public $availableYears;
    // PERBAIKAN 1: Nama variabel diperbaiki
    public $availableMonths; 

    public ?int $expandedOrderId = null;

    public function mount(): void
    {
        $this->selectedYear = now()->year;
        $this->selectedMonth = now()->month;

        $this->availableYears = Order::where('user_id', auth()->id())
            ->whereIn('channel', ['direct', 'reseller'])
            ->whereNotNull('order_date')
            ->select(DB::raw('YEAR(order_date) as year'))
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year');

        if ($this->availableYears->isEmpty()) {
            $this->availableYears = collect([now()->year]);
        }
        
        $this->availableMonths = collect(range(1, 12))
            ->mapWithKeys(fn ($m) => [
                $m => Carbon::create(null, $m)->isoFormat('MMMM')
            ]);
    }
    
    // ... (sisa method PHP tidak ada perubahan, sudah benar)
    // toggleDetails(), cancelOrder(), with()
    public function toggleDetails(int $orderId): void
    {
        if ($this->expandedOrderId === $orderId) {
            $this->expandedOrderId = null;
        } else {
            $this->expandedOrderId = $orderId;
        }
    }
    
    public function cancelOrder(int $orderId): void
    {
        $order = Order::with('items.productVariant')
            ->where('user_id', auth()->id())
            ->where('id', $orderId)
            ->first();

        if (!$order) {
            Notification::make()->title('Gagal')->danger()->body('Pesanan tidak ditemukan.')->send();
            return;
        }

        if ($order->order_status === 'cancelled') {
            Notification::make()->title('Info')->warning()->body('Pesanan ini sudah dibatalkan.')->send();
            return;
        }

        try {
            DB::transaction(function () use ($order) {
                $order->order_status = 'cancelled';
                $order->save();

                foreach ($order->items as $item) {
                    $variant = $item->productVariant;
                    if ($variant) {
                        StockMovement::create([
                            'user_id' => auth()->id(),
                            'product_variant_id' => $variant->id,
                            'order_id' => $order->id,
                            'type' => 'cancellation',
                            'quantity' => $item->quantity,
                            'notes' => 'Pembatalan Pesanan #' . $order->id,
                        ]);
                        $variant->updateWarehouseStock();
                    }
                }
            });
            
            Notification::make()->title('Berhasil')->success()->body('Pesanan telah dibatalkan dan stok telah dikembalikan.')->send();
            $this->dispatch('$refresh');

        } catch (\Exception $e) {
            Notification::make()->title('Terjadi Kesalahan')->danger()->body($e->getMessage())->send();
        }
    }

    public function with(): array
    {
        $orders = Order::where('user_id', auth()->id())
            ->whereIn('channel', ['direct', 'reseller'])
            ->whereYear('order_date', $this->selectedYear)
            ->whereMonth('order_date', $this->selectedMonth)
            ->with('reseller:id,name,is_dropship', 'items') 
            ->orderBy('order_date', 'desc')
            ->paginate(15);
            
        return [
            'orders' => $orders,
        ];
    }
}; ?>

<x-layouts.app>
    @volt('orders-history')
    <x-app.container>
        <div>
        @livewire('print-label-modal')
        <div class="md:flex md:items-center md:justify-between">
            <div class="min-w-0 flex-1">
                <x-app.heading 
                    title="Riwayat Pesanan Manual"
                    description="Lihat dan kelola pesanan dari channel penjualan langsung dan reseller. Klik baris untuk detail."
                    :border="false"
                />
            </div>
            <div class="mt-4 flex md:mt-0 md:ml-4">
                <a href="{{ route('orders.create') }}" class="w-full md:w-auto flex-shrink-0 rounded-lg bg-black dark:bg-white px-4 py-2 text-sm font-semibold text-white dark:text-black hover:bg-gray-800 dark:hover:bg-gray-200 transition-colors">
                        Buat Pesanan Baru
                </a>
            </div>
        </div>
        {{-- Filter Section --}}
        <div class="mt-6 flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="flex items-center gap-2">
                <x-select-input wire:model.live="selectedYear" id="year_filter" class="mt-1">
                    @foreach($availableYears as $year)
                        <option value="{{ $year }}">{{ $year }}</option>
                    @endforeach
                </x-select-input>
                <x-select-input wire:model.live="selectedMonth" id="month_filter" class="mt-1">
                     @foreach($availableMonths as $num => $name)
                        <option value="{{ $num }}">{{ $name }}</option>
                    @endforeach
                </x-select-input>
            </div>
        </div>

        {{-- PERBAIKAN 2: Tampilan Tabel/Card yang Responsif --}}
        <div class="mt-8 flow-root">
            <div class="-my-2">
                <div class="py-2 align-middle inline-block min-w-full">
                    <div class="space-y-4">
                        {{-- Header Tabel (Hanya untuk Desktop) --}}
                        <div class="hidden md:grid md:grid-cols-5 gap-4 px-6 py-3 bg-gray-50 dark:bg-gray-800 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                            <div class="text-center">Pesanan</div>
                            <div class="text-center">Pelanggan</div>
                            <div class="text-right">Total</div>
                            <div class="text-center">Status</div>
                            <div></div>
                        </div>

                        {{-- Daftar Pesanan --}}
                        @forelse($orders as $order)
                            <div wire:key="order-{{ $order->id }}" 
                                 @class([
                                    'bg-white dark:bg-gray-800/50 shadow-sm rounded-lg border border-gray-200 dark:border-gray-700',
                                    'ring-2 ring-indigo-500' => $expandedOrderId === $order->id, // Highlight saat terbuka
                                    'opacity-70' => $order->order_status === 'cancelled',
                                 ])
                            >
                                {{-- Baris Utama (bisa diklik) --}}
                                <div class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50 rounded-t-lg"
                                     wire:click="toggleDetails({{ $order->id }})"
                                >
                                    {{-- Layout Grid untuk Desktop & Mobile --}}
                                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 px-4 py-4 sm:px-6">
                                        {{-- Kolom 1 : Info Pesanan --}}
                                        <div class="space-y-1">
                                            <div class="flex items-center gap-4">
                                                <span class="font-bold text-gray-900 dark:text-white">#{{ $order->id }}</span>
                                                @if($order->channel === 'reseller')
                                                    <span class="badge-blue">Reseller</span>
                                                @else
                                                     <span class="badge-green">Langsung</span>
                                                @endif
                                                @if($order->reseller?->is_dropship == '1')
                                                    <span class="badge-red">Dropship</span>
                                                @endif
                                            </div>
                                            <p class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($order->order_date)->isoFormat('D MMM YYYY, HH:mm') }}</p>
                                        </div>

                                        {{-- Kolom 2: Pelanggan --}}
                                        <div class="text-center sm:text-center">
                                            <p class="font-semibold text-gray-900 dark:text-white">{{ $order->customer_name }}</p>
                                        </div>
                                        
                                        {{-- Kolom 3: Total --}}
                                        <div class="text-left sm:text-right">
                                            <p class="text-xs text-gray-500 md:hidden">Total</p>
                                            <p class="font-semibold text-gray-900 dark:text-white">Rp {{ number_format($order->total_price, 0, ',', '.') }}</p>
                                            <p class="text-sm text-gray-500">{{ $order->items->sum('quantity') }} item</p>
                                        </div>

                                        {{-- Kolom 4: Status --}}
                                        <div class="text-left sm:text-center">
                                            <p class="text-xs text-gray-500 md:hidden">Status</p>
                                            @if($order->order_status === 'completed')
                                                <span class="badge-green">Selesai</span>
                                            @elseif($order->order_status === 'cancelled')
                                                <span class="badge-red">Dibatalkan</span>
                                            @else
                                                <span class="badge-yellow">{{ Str::title($order->order_status) }}</span>
                                            @endif
                                        </div>
                                        
                                        {{-- Kolom 5: Aksi --}}
                                        <div class="flex items-center justify-end" @click.stop>
                                            {{-- PERUBAHAN: Tombol Cetak di sini --}}
                                            <button 
                                                wire:click="$dispatch('open-print-modal', { orderId: {{ $order->id }}, dropship: {{ (int) $order->reseller?->is_dropship ?? 0 }} })"
                                                type="button" 
                                                class="rounded-lg bg-black dark:bg-white px-4 py-2 text-sm font-semibold text-white dark:text-black hover:bg-gray-800 dark:hover:bg-gray-200 transition-colors"> {{-- Ganti class jika perlu --}}
                                                Cetak Label
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                {{-- Area Detail (Expandable) --}}
                                @if($expandedOrderId === $order->id)
                                <div class="border-t border-gray-200 dark:border-gray-700">
                                    <div class="p-4 bg-gray-50/50 dark:bg-gray-800/20 flow-root">
                                        <div class="overflow-x-auto">
                                            <table class="min-w-full text-sm">
                                                <thead>
                                                    <tr>
                                                        <th class="py-2 text-left font-medium text-gray-500">SKU</th>
                                                        <th class="py-2 text-center font-medium text-gray-500">Jumlah</th>
                                                        <th class="py-2 text-right font-medium text-gray-500">Harga Satuan</th>
                                                        <th class="py-2 text-right font-medium text-gray-500">Subtotal</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                                    @foreach($order->items as $item)
                                                    <tr>
                                                        <td class="py-2 text-left text-gray-800 dark:text-gray-200">{{ $item->variant_sku }}</td>
                                                        <td class="py-2 text-center text-gray-600 dark:text-gray-300">{{ $item->quantity }}</td>
                                                        <td class="py-2 text-right text-gray-600 dark:text-gray-300">Rp {{ number_format($item->price, 0, ',', '.') }}</td>
                                                        <td class="py-2 text-right text-gray-800 dark:text-gray-200 font-medium">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                                                    </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="mt-4 text-right">
                                            @if($order->order_status === 'completed')
                                                <button 
                                                    wire:click="cancelOrder({{ $order->id }})"
                                                    wire:confirm="Anda yakin ingin membatalkan pesanan ini? Stok akan dikembalikan."
                                                    type="button" 
                                                    class="px-3 py-1 border border-red-300 dark:border-red-600 text-sm font-medium rounded-md text-red-700 dark:text-red-200 bg-white dark:bg-red-700 hover:bg-red-50 dark:hover:bg-red-600 transition-colors">
                                                    Batalkan Pesanan Ini
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                @endif
                            </div>
                        @empty
                            <div class="text-center py-16 text-gray-500">
                                <p>Tidak ada data pesanan untuk periode yang dipilih.</p>
                            </div>
                        @endforelse

                        @if($orders->hasPages())
                           <div class="mt-6">
                               {{ $orders->links() }}
                           </div>
                       @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    </x-app.container>
    @endvolt
</x-layouts.app>