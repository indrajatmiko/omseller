<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Order;
use Livewire\Volt\Component;
use Illuminate\Support\Str;

middleware('auth');
name('sales.orders.show');

new class extends Component {
    public $order;

    public function mount(int $order): void
    {
        // Secara manual cari Order berdasarkan ID DAN pastikan milik user yang login.
        $order = Order::where('user_id', auth()->id())
                      ->with('items')
                      ->find($order);

        // Jika order tidak ditemukan atau bukan milik user, tampilkan halaman 404.
        if (!$order) {
            abort(404);
        }

        $this->order = $order;
    }
}; ?>

<x-layouts.app>
    @volt('sales-orders-show')
        <div>
            <x-app.container>
                {{-- === PERUBAHAN DI SINI: Header dibungkus Flexbox === --}}
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    {{-- Judul Halaman --}}
                    <div class="flex-1">
                        <x-app.heading title="Detail Pesanan" description="{{ $order->order_sn }}" :border="false" />
                    </div>
                    {{-- Tombol Kembali --}}
                    <div class="flex-shrink-0">
                        <a href="{{ url()->previous() }}" wire:navigate 
                           class="inline-flex items-center justify-center w-full md:w-auto rounded-lg bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M9.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 1.414L7.414 9H15a1 1 0 110 2H7.414l2.293 2.293a1 1 0 010 1.414z" clip-rule="evenodd" />
                            </svg>
                            Kembali
                        </a>
                    </div>
                </div>
                
                <div class="mt-6 bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="font-semibold text-gray-800 dark:text-gray-200">Informasi Umum</h3>
                            <dl class="mt-2 space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Status:</dt>
                                    <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ Str::title($order->order_status) }}</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Tanggal Pesan:</dt>
                                    <dd class="text-gray-700 dark:text-gray-300">{{ $order->created_at->translatedFormat('d F Y, H:i') }}</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Total Bayar:</dt>
                                    <dd class="text-gray-700 dark:text-gray-300">Rp {{ number_format($order->total_price, 0, ',', '.') }}</dd>
                                </div>
                                @if($order->order_status === 'cancelled')
                                    <div class="pt-2 border-t border-dashed border-gray-200 dark:border-gray-700">
                                        <dt class="text-red-500">Info Pembatalan:</dt>
                                        <dd class="text-red-400">Dibatalkan pada {{ $order->updated_at->translatedFormat('d M Y') }}</dd>
                                    </div>
                                @endif
                            </dl>
                        </div>
                        <div>
                             <h3 class="font-semibold text-gray-800 dark:text-gray-200">Item Dipesan</h3>
                             <ul class="mt-2 space-y-2 text-sm">
                                @forelse($order->items as $item)
                                    <li class="flex justify-between items-center bg-gray-50 dark:bg-gray-700/50 p-2 rounded-md">
                                        <span class="text-gray-700 dark:text-gray-300">{{ Str::words($item->product_name ?? 'N/A', 8) }}</span>
                                        <span class="font-mono bg-gray-200 dark:bg-gray-600 rounded px-2 py-1 text-xs font-bold">x{{ $item->quantity }}</span>
                                    </li>
                                @empty
                                    <li class="text-center text-gray-500 py-4">Tidak ada item dalam pesanan ini.</li>
                                @endforelse
                             </ul>
                        </div>
                    </div>
                </div>
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>