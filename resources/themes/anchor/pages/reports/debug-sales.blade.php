<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Order;
use Livewire\Volt\Component;
// use Livewire\WithPagination; // Pagination tidak lagi digunakan
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

middleware('auth');
name('reports.debug-sales');

new class extends Component {
    public function with(): array
    {
        $year = 2025;
        $month = 7;
        $startDate = \Carbon\Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();

        // --- QUERY PALING DEFensif UNTUK MENGHINDARI SEMUA DUPLIKASI ---
        $salesDetailsQuery = Order::query()
            ->from('orders as o')
            ->where('o.user_id', auth()->id())
            
            // 1. Join dengan subquery unik untuk status history
            ->join(DB::raw('(SELECT order_id, MIN(pickup_time) as first_pickup_time 
                             FROM order_status_histories 
                             WHERE status = \'Sudah Kirim\' AND pickup_time IS NOT NULL 
                             GROUP BY order_id) as unique_osh'), 
                  'o.id', '=', 'unique_osh.order_id')
            
            ->join('order_items as oi', 'o.id', '=', 'oi.order_id')

            // 2. Join dengan subquery unik untuk product variants
            ->leftJoin(DB::raw('(SELECT variant_sku, MIN(cost_price) as cost_price
                                FROM product_variants
                                WHERE variant_sku IS NOT NULL AND variant_sku != \'\'
                                GROUP BY variant_sku) as unique_pv'),
                      'oi.variant_sku', '=', 'unique_pv.variant_sku')
            
            ->whereBetween('unique_osh.first_pickup_time', [$startDate, $endDate])
            
            ->select(
                'o.order_sn',
                'unique_osh.first_pickup_time as pickup_time',
                'oi.variant_sku',
                'oi.quantity',
                'oi.subtotal as total_beli',
                // Ambil cost_price dari subquery unik product variants
                DB::raw('oi.quantity * unique_pv.cost_price AS total_harga_modal'),
                DB::raw('(oi.subtotal) - (oi.quantity * unique_pv.cost_price) AS profit_item')
            )
            ->orderBy('unique_osh.first_pickup_time', 'asc')
            ->orderBy('o.order_sn', 'asc');

        $groupedSales = $salesDetailsQuery
            ->get()
            ->groupBy(function($item) {
                return \Carbon\Carbon::parse($item->pickup_time)->format('Y-m-d');
            });

        return [
            'groupedSales' => $groupedSales
        ];
    }
}; ?>

<x-layouts.app>
    @volt('reports-debug-sales')
        <x-app.container>
            <x-app.heading 
                title="Debug Data Penjualan (Juli 2025)"
                description="Tabel ini berisi data mentah per item pesanan, dikelompokkan per hari, dengan total harian untuk validasi perhitungan."
                :border="true" />

            <div class="mt-8 flow-root">
                <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                    <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                        <div class="overflow-hidden shadow-sm ring-1 ring-black ring-opacity-5 sm:rounded-lg">
                            <table class="min-w-full">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">No. Pesanan</th>
                                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">SKU</th>
                                        <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Jml</th>
                                        <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Omset</th>
                                        <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">COGS</th>
                                        <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Profit Item</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-900">
                                    @forelse($groupedSales as $date => $details)
                                        {{-- Baris Header per Hari --}}
                                        <tr class="bg-gray-100 dark:bg-gray-800">
                                            <td colspan="6" class="px-6 py-2 text-left text-sm font-bold text-gray-900 dark:text-white">
                                                Tanggal: {{ \Carbon\Carbon::parse($date)->isoFormat('dddd, D MMMM YYYY') }}
                                            </td>
                                        </tr>
                                        
                                        {{-- Loop untuk setiap item di hari tersebut --}}
                                        @foreach($details as $detail)
                                            @php
                                                $totalHargaModal = $detail->total_harga_modal ?? null;
                                                $profitItem = $detail->profit_item ?? null;
                                                $isAnomaly = !is_null($profitItem) && $profitItem < 0;
                                                $isMissingData = is_null($totalHargaModal);
                                            @endphp
                                            <tr class="{{ $isAnomaly ? 'bg-red-50 dark:bg-red-900/20' : ($isMissingData ? 'bg-yellow-50 dark:bg-yellow-900/20' : '') }}">
                                                <td class="whitespace-nowrap py-4 pl-6 pr-3 text-sm">
                                                    <div class="font-medium text-gray-900 dark:text-white">{{ $detail->order_sn }}</div>
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 font-mono">
                                                    {{ $detail->variant_sku }}
                                                     @if($isMissingData) <span class="block text-xs text-yellow-600 font-semibold">Data modal tdk ada</span> @endif
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-800 dark:text-gray-200">{{ $detail->quantity }}</td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-800 dark:text-gray-200">Rp {{ number_format($detail->total_beli, 0, ',', '.') }}</td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-800 dark:text-gray-200">Rp {{ number_format($totalHargaModal, 0, ',', '.') }}</td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right font-semibold {{ $isAnomaly ? 'text-red-600' : 'text-green-600' }}">
                                                     @if(!is_null($profitItem)) Rp {{ number_format($profitItem, 0, ',', '.') }} @else - @endif
                                                </td>
                                            </tr>
                                        @endforeach

                                        {{-- Baris Total per Hari --}}
                                        <tr class="bg-gray-200 dark:bg-gray-700 font-bold">
                                            <td class="py-3 pl-6 pr-3 text-sm text-right text-gray-900 dark:text-white" colspan="3">Total Harian:</td>
                                            <td class="px-3 py-3 text-sm text-right text-gray-900 dark:text-white">Rp {{ number_format($details->sum('total_beli'), 0, ',', '.') }}</td>
                                            <td class="px-3 py-3 text-sm text-right text-gray-900 dark:text-white">Rp {{ number_format($details->sum('total_harga_modal'), 0, ',', '.') }}</td>
                                            @php $totalProfitHarian = $details->sum('profit_item'); @endphp
                                            <td class="px-3 py-3 text-sm text-right {{ $totalProfitHarian < 0 ? 'text-red-500' : 'text-gray-900 dark:text-white' }}">Rp {{ number_format($totalProfitHarian, 0, ',', '.') }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center py-12 px-6 text-gray-500">
                                                Tidak ada data penjualan yang ditemukan untuk Juli 2025.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </x-app.container>
    @endvolt
</x-layouts.app>