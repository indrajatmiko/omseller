<?php

use function Laravel\Folio\{middleware, name};
use App\Models\StockMovement;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Carbon\Carbon;
use Illuminate\Support\Str;

middleware('auth');
name('reports.daily-shipments');

new class extends Component {
    use WithPagination;

    public string $reportDate;

    public function mount(): void
    {
        $this->reportDate = today()->toDateString();
    }

    public function with(): array
    {
        $shipments = StockMovement::where('user_id', auth()->id())
            ->where('type', 'sale')
            ->whereDate('created_at', $this->reportDate)
            ->with(['productVariant' => function ($query) {
                // Pilih kolom spesifik untuk efisiensi
                $query->select('id', 'product_id', 'variant_sku', 'variant_name');
            }, 'productVariant.product:id,product_name', 'order:id,order_sn'])
            ->latest()
            ->paginate(50);

        return [
            'shipments' => $shipments
        ];
    }
}; ?>

<x-layouts.app>
    @volt('reports-daily-shipments')
        <div>
            <x-app.container>
                <x-app.heading 
                    title="Laporan Barang Keluar Harian"
                    description="Lihat daftar barang yang stoknya telah dikurangi (terkirim) berdasarkan tanggal yang dipilih."
                    :border="true" />
                
                <div class="mt-6 flex items-center gap-4">
                    <label for="report_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Pilih Tanggal:</label>
                    <input type="date" id="report_date"
                           wire:model.live="reportDate"
                           class="block rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 px-3 text-sm focus:border-black focus:ring-black">
                </div>

                {{-- PERBAIKAN TOTAL: Layout responsif untuk Mobile dan Desktop --}}

                {{-- Tampilan Desktop (Tabel) - Muncul di layar medium ke atas --}}
                <div class="mt-4 hidden md:block">
                    <div class="shadow overflow-hidden border-b border-gray-200 dark:border-gray-700 sm:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    {{-- PERBAIKAN: Kolom diubah --}}
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">SKU & Produk</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Jumlah Keluar</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">No. Pesanan</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse($shipments as $shipment)
                                    <tr wire:key="shipment-desktop-{{ $shipment->id }}">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <p class="font-bold font-mono text-gray-900 dark:text-white">{{ $shipment->productVariant->variant_sku ?? '-' }}</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400" title="{{ $shipment->productVariant->product->product_name ?? 'N/A' }}">
                                                {{ Str::words($shipment->productVariant->product->product_name ?? 'N/A', 6, '...') }}
                                            </p>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-lg font-bold text-red-600 dark:text-red-400">
                                            {{ $shipment->quantity }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-700 dark:text-gray-300">
                                            {{ $shipment->order->order_sn ?? '-' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="px-6 py-12 text-center text-sm text-gray-500">
                                            Tidak ada barang keluar pada tanggal ini.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Tampilan Mobile (Kartu) - Muncul di layar kecil, disembunyikan di medium ke atas --}}
                <div class="mt-4 space-y-3 md:hidden">
                    @forelse($shipments as $shipment)
                        <div wire:key="shipment-mobile-{{ $shipment->id }}" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="font-bold font-mono text-gray-900 dark:text-white">{{ $shipment->productVariant->variant_sku ?? '-' }}</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400" title="{{ $shipment->productVariant->product->product_name ?? 'N/A' }}">
                                        {{ Str::words($shipment->productVariant->product->product_name ?? 'N/A', 6, '...') }}
                                    </p>
                                </div>
                                <div class="text-right flex-shrink-0 ml-4">
                                    <p class="text-xs text-gray-500">Jumlah</p>
                                    <p class="text-xl font-bold text-red-600 dark:text-red-400">{{ $shipment->quantity }}</p>
                                </div>
                            </div>
                            <div class="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                <p class="text-xs text-gray-500 dark:text-gray-400">No. Pesanan: <span class="font-mono text-gray-700 dark:text-gray-300">{{ $shipment->order->order_sn ?? '-' }}</span></p>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-gray-500 py-12">
                            <p>Tidak ada barang keluar pada tanggal ini.</p>
                        </div>
                    @endforelse
                </div>

                @if($shipments->hasPages())
                    <div class="mt-6">
                        {{ $shipments->links() }}
                    </div>
                @endif

            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>