<?php

use function Laravel\Folio\{middleware, name};
use App\Models\StockMovement;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Carbon\Carbon;

middleware('auth');
name('reports.daily-shipments');

new class extends Component {
    use WithPagination;

    public string $reportDate;

    public function mount(): void
    {
        // Inisialisasi tanggal laporan ke hari ini
        $this->reportDate = today()->toDateString();
    }

    public function with(): array
    {
        // Ambil data dari tabel StockMovement, yang merupakan sumber kebenaran.
        $shipments = StockMovement::where('user_id', auth()->id())
            ->where('type', 'sale') // Hanya ambil pergerakan tipe 'penjualan'
            ->whereDate('created_at', $this->reportDate)
            ->with(['productVariant.product', 'order:id,order_sn']) // Eager load untuk info lengkap
            ->latest() // Tampilkan yang terbaru di atas
            ->paginate(50); // Paginasi jika dalam sehari banyak pengiriman

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

                <div class="mt-4 flex flex-col">
                    <div class="-my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                        <div class="py-2 align-middle inline-block min-w-full sm:px-6 lg:px-8">
                            <div class="shadow overflow-hidden border-b border-gray-200 dark:border-gray-700 sm:rounded-lg">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Waktu</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Produk & Varian</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">SKU</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Jml.</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">No. Pesanan</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                        @forelse($shipments as $shipment)
                                            <tr wire:key="shipment-{{ $shipment->id }}">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                    {{ $shipment->created_at->format('H:i') }}
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <p class="font-semibold text-gray-900 dark:text-white">{{ $shipment->productVariant->product->product_name ?? 'N/A' }}</p>
                                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $shipment->productVariant->variant_name ?? 'N/A' }}</p>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-700 dark:text-gray-300">
                                                    {{ $shipment->productVariant->variant_sku ?? '-' }}
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-red-600 dark:text-red-400">
                                                    {{ $shipment->quantity }}
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-700 dark:text-gray-300">
                                                    {{ $shipment->order->order_sn ?? '-' }}
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-500">
                                                    Tidak ada barang keluar pada tanggal ini.
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
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