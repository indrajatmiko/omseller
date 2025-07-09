<?php

use function Laravel\Folio\{middleware, name};
use App\Models\StockMovement;
use Livewire\Volt\Component;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Carbon\Carbon;

middleware('auth');
name('reports.daily-shipments');

new class extends Component {
    public string $reportDate;
    public string $search = '';

    public function mount(): void
    {
        $this->reportDate = today()->toDateString();
    }

    // // Reset paginasi saat user mengetik di search box
    // public function updatingSearch(): void
    // {
    //     // Menggunakan resolver bawaan Volt/Livewire untuk mereset page
    //     $this->resetPage();
    // }

    // Helper untuk mengecek apakah tanggal yang dipilih adalah hari ini
    public function isToday(): bool
    {
        return Carbon::parse($this->reportDate)->isToday();
    }

    public function with(): array
    {
        // 1. FETCH CEPAT: Ambil data mentah dengan filter dan eager loading.
        $movements = StockMovement::query()
            ->where('user_id', auth()->id())
            ->where('type', 'sale')
            ->whereDate('created_at', $this->reportDate)
            // === Logika Pencarian yang Efisien ===
            ->when(trim($this->search) !== '', function ($query) {
                $searchTerm = trim($this->search);
                $query->where(function($q) use ($searchTerm) {
                    // Cari berdasarkan SKU di relasi productVariant
                    $q->whereHas('productVariant', function ($subQuery) use ($searchTerm) {
                        $subQuery->where('variant_sku', 'like', '%' . $searchTerm . '%');
                    })
                    // ATAU cari berdasarkan No. Pesanan di relasi order
                    ->orWhereHas('order', function ($subQuery) use ($searchTerm) {
                        $subQuery->where('order_sn', 'like', '%' . $searchTerm . '%');
                    });
                });
            })
            ->with([
                'productVariant:id,variant_sku,variant_name,warehouse_stock',
                'order:id,order_sn'
            ])
            ->get();

        // 2. AGREGRASI DI PHP: Gunakan kekuatan Laravel Collections.
        $groupedData = $movements
            ->groupBy('productVariant.variant_sku')
            ->map(function ($items, $sku) {
                if (is_null($sku)) return null;

                $firstItem = $items->first();
                return (object) [
                    'variant_sku' => $sku,
                    'variant_name' => $firstItem->productVariant->variant_name ?? 'N/A',
                    'warehouse_stock' => $firstItem->productVariant->warehouse_stock ?? 0,
                    'total_quantity_out' => $items->sum('quantity'),
                    // Ambil ID dan SN untuk membuat link
                    'orders' => $items->pluck('order')->unique('id')->map(fn($order) => [
                        'id' => $order->id,
                        'order_sn' => $order->order_sn,
                    ])->all(),
                ];
            })
            ->filter() // Hapus item null
            ->sortBy('variant_sku');

        // 3. PAGINASI MANUAL: Buat paginator dari koleksi yang sudah diolah.
        $perPage = 20;
        $currentPage = Paginator::resolveCurrentPage('page');
        $currentPageItems = $groupedData->slice(($currentPage - 1) * $perPage, $perPage);
        
        $paginatedItems = new LengthAwarePaginator(
            $currentPageItems,
            $groupedData->count(),
            $perPage,
            $currentPage,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page']
        );

        return [
            'groupedShipments' => $paginatedItems
        ];
    }
}; ?>

<x-layouts.app>
    @volt('reports-daily-shipments')
        <div>
            <x-app.container>
                <x-app.heading 
                    title="Laporan Barang Keluar Harian (Ringkasan per SKU)"
                    description="Lihat ringkasan barang yang dikirim berdasarkan SKU. Cari berdasarkan SKU atau No. Pesanan."
                    :border="true" />
                
                {{-- Input Filter --}}
                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="report_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Pilih Tanggal:</label>
                        <input type="date" id="report_date"
                               wire:model.live="reportDate"
                               max="{{ today()->toDateString() }}"
                               class="mt-1 block w-full md:w-auto rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 px-3 text-sm focus:border-black focus:ring-black">
                    </div>
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cari SKU / No. Pesanan:</label>
                        <input type="search" id="search"
                               wire:model.live.debounce.300ms="search"
                               placeholder="Ketik untuk mencari..."
                               class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 px-3 text-sm focus:border-black focus:ring-black">
                    </div>
                </div>

                {{-- Kontainer Hasil --}}
                <div class="mt-4">
                    {{-- Tampilan Desktop (Tabel) --}}
                    <div class="hidden md:block">
                        <div class="shadow overflow-hidden border-b border-gray-200 dark:border-gray-700 sm:rounded-lg">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider w-1/3">SKU & Produk</th>
                                        <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Keluar</th>
                                        @if($this->isToday())
                                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Stok Aktif</th>
                                        @endif
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Nomor Pesanan (...XXXX)</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                    @forelse($groupedShipments as $shipment)
                                        <tr wire:key="shipment-desktop-{{ $shipment->variant_sku }}">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <p class="font-bold font-mono text-gray-900 dark:text-white">{{ $shipment->variant_sku }}</p>
                                                <p class="text-sm text-gray-500 dark:text-gray-400" title="{{ $shipment->variant_name }}">{{ Str::words($shipment->variant_name, 6, '...') }}</p>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-center text-lg font-bold text-red-600 dark:text-red-400">{{ abs($shipment->total_quantity_out) }}</td>
                                            @if($this->isToday())
                                                <td class="px-6 py-4 whitespace-nowrap text-center text-lg font-bold text-gray-700 dark:text-gray-300">{{ $shipment->warehouse_stock }}</td>
                                            @endif
                                            <td class="px-6 py-4">
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach($shipment->orders as $order)
                                                        <a href="{{ route('sales.orders.show', ['order' => $order['id']]) }}" wire:navigate class="bg-gray-200 dark:bg-gray-700 text-xs font-mono px-2 py-1 rounded hover:bg-blue-200 dark:hover:bg-blue-800 transition-colors">{{ substr($order['order_sn'], -4) }}</a>
                                                    @endforeach
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ $this->isToday() ? 4 : 3 }}" class="px-6 py-12 text-center text-sm text-gray-500">
                                                @if(strlen($search) > 0)
                                                    Tidak ada hasil yang cocok dengan pencarian Anda.
                                                @else
                                                    Tidak ada barang keluar pada tanggal ini.
                                                @endif
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Tampilan Mobile (Kartu) --}}
                    <div class="space-y-3 md:hidden">
                        @forelse($groupedShipments as $shipment)
                            <div wire:key="shipment-mobile-{{ $shipment->variant_sku }}" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4">
                                <div>
                                    <p class="font-bold font-mono text-gray-900 dark:text-white">{{ $shipment->variant_sku }}</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400" title="{{ $shipment->variant_name }}">{{ Str::words($shipment->variant_name, 8, '...') }}</p>
                                </div>
                                
                                <div class="mt-3 flex justify-around gap-4 border-t border-b border-gray-200 dark:border-gray-700 py-3">
                                    <div class="text-center">
                                        <p class="text-xs text-red-600 dark:text-red-400 font-semibold uppercase">Keluar</p>
                                        <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ abs($shipment->total_quantity_out) }}</p>
                                    </div>
                                    @if($this->isToday())
                                    <div class="text-center">
                                        <p class="text-xs text-gray-500 font-semibold uppercase">Sisa Stok</p>
                                        <p class="text-2xl font-bold text-gray-700 dark:text-gray-300">{{ $shipment->warehouse_stock }}</p>
                                    </div>
                                    @endif
                                </div>
                                
                                <details class="mt-2 group">
                                    <summary class="cursor-pointer text-xs text-gray-500 dark:text-gray-400 list-none group-open:mb-2">
                                        Lihat Nomor Pesanan (...XXXX) →
                                    </summary>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($shipment->orders as $order)
                                            <a href="{{ route('sales.orders.show', ['order' => $order['id']]) }}" wire:navigate class="bg-gray-200 dark:bg-gray-700 text-xs font-mono px-2 py-1 rounded hover:bg-blue-200 dark:hover:bg-blue-800 transition-colors">{{ substr($order['order_sn'], -4) }}</a>
                                        @endforeach
                                    </div>
                                </details>
                            </div>
                        @empty
                            <div class="text-center text-gray-500 py-12">
                                @if(strlen($search) > 0)
                                    <p>Tidak ada hasil yang cocok dengan pencarian Anda.</p>
                                @else
                                    <p>Tidak ada barang keluar pada tanggal ini.</p>
                                @endif
                            </div>
                        @endforelse
                    </div>

                    {{-- Paginasi --}}
                    @if($groupedShipments->hasPages())
                        <div class="mt-6">
                            {{ $groupedShipments->links() }}
                        </div>
                    @endif
                </div>
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>