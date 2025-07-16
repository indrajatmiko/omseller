<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Order;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

middleware('auth');
name('reports.shipping-anomaly');

new class extends Component {
    use WithPagination;

    public string $startDate;
    public string $endDate;
    public string $search = '';
    public string $anomalyType = 'loss'; // Opsi: 'all', 'loss', 'gain'
    public string $sortBy = 'selisih';
    public string $sortDir = 'desc';

    public function mount(): void
    {
        // Set default ke rentang yang sangat lebar untuk memastikan ada data
        $this->endDate = now()->format('Y-m-d');
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortDir = $column === 'selisih' ? 'asc' : 'desc';
        }
        $this->sortBy = $column;
        $this->resetPage();
    }

    public function updating($property): void
    {
        if (in_array($property, ['search', 'anomalyType', 'startDate', 'endDate'])) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $userId = auth()->id();

        // Subquery untuk tanggal pengiriman, tetap sama.
        $uniqueOshSubquery = DB::table('order_status_histories')
            ->select('order_id', DB::raw('MIN(pickup_time) as first_pickup_time'))
            ->where('status', 'Sudah Kirim')
            ->whereNotNull('pickup_time')
            ->groupBy('order_id');

        // Bangun query dasar dengan semua join dan filter utama.
        $anomaliesQuery = Order::query()
            ->from('orders as o')
            ->join('order_payment_details as opd', 'o.id', '=', 'opd.order_id')
            ->leftJoinSub($uniqueOshSubquery, 'unique_osh', 'o.id', '=', 'unique_osh.order_id')
            ->where('o.user_id', $userId);
            
        // Terapkan filter tanggal, pencarian, dan tipe anomali
        if ($this->startDate && $this->endDate) {
            $anomaliesQuery->whereBetween('o.created_at', [
                Carbon::parse($this->startDate)->startOfDay(),
                Carbon::parse($this->endDate)->endOfDay()
            ]);
        }
        
        $anomaliesQuery->when($this->search, function ($query) {
            $query->where(function ($q) {
                $q->where('o.order_sn', 'like', '%' . $this->search . '%')
                ->orWhere('o.tracking_number', 'like', '%' . $this->search . '%')
                ->orWhere('o.buyer_username', 'like', '%' . $this->search . '%');
            });
        });

        $anomaliesQuery->when($this->anomalyType === 'loss', fn($q) => $q->whereRaw('(opd.shipping_fee_paid_by_buyer + opd.shopee_shipping_subsidy + opd.shipping_fee_paid_to_logistic) < 0'));
        $anomaliesQuery->when($this->anomalyType === 'gain', fn($q) => $q->whereRaw('(opd.shipping_fee_paid_by_buyer + opd.shopee_shipping_subsidy + opd.shipping_fee_paid_to_logistic) > 0'));
        
        // =========================================================================
        // == PERUBAHAN BARU: HITUNG TOTAL SEBELUM PAGINASI ==
        // =========================================================================
        // Clone query yang sudah difilter untuk menghitung total sum.
        $queryForSum = $anomaliesQuery->clone();
        $totalSelisih = $queryForSum->sum(DB::raw('opd.shipping_fee_paid_by_buyer + opd.shopee_shipping_subsidy + opd.shipping_fee_paid_to_logistic'));
        // =========================================================================

        // Lanjutkan dengan query asli untuk menambahkan select, ordering, dan paginasi.
        $paginatedAnomalies = $anomaliesQuery
            ->select(
                'o.order_sn', 'o.buyer_username', 'o.shipping_provider', 'o.tracking_number',
                'o.order_detail_url', 'o.created_at as tanggal_pesan',
                'unique_osh.first_pickup_time as tanggal_kirim',
                'opd.shipping_fee_paid_by_buyer', 'opd.shopee_shipping_subsidy', 'opd.shipping_fee_paid_to_logistic',
                DB::raw('(opd.shipping_fee_paid_by_buyer + opd.shopee_shipping_subsidy + opd.shipping_fee_paid_to_logistic) AS selisih')
            )
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(15);
        
        // Kirim kedua data (total dan data paginasi) ke view.
        return [
            'anomalies' => $paginatedAnomalies,
            'totalSelisih' => $totalSelisih,
        ];
    }
}; ?>

<x-layouts.app>
    @volt('reports.shipping-anomaly')
    <x-app.container>
        <x-app.heading 
            title="Laporan Anomali Biaya Pengiriman" 
            description="Temukan pesanan dengan selisih biaya pengiriman yang merugikan atau tidak wajar." 
        />

        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label for="startDate" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tanggal Mulai</label>
                <input wire:model.live="startDate" id="startDate" type="date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:bg-gray-800 dark:border-gray-600 dark:text-gray-200">
            </div>
            <div>
                <label for="endDate" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tanggal Akhir</label>
                <input wire:model.live="endDate" id="endDate" type="date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:bg-gray-800 dark:border-gray-600 dark:text-gray-200">
            </div>
            <div>
                <label for="anomalyType" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tipe Anomali</label>
                <select wire:model.live="anomalyType" id="anomalyType" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:bg-gray-800 dark:border-gray-600 dark:text-gray-200">
                    <option value="loss">Kerugian (Selisih < 0)</option>
                    <option value="gain">Kelebihan (Selisih > 0)</option>
                    <option value="all">Tampilkan Semua</option>
                </select>
            </div>
            <div>
                <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cari Order SN / Resi</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="text" placeholder="Masukkan Order SN atau No. Resi..." class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:bg-gray-800 dark:border-gray-600 dark:text-gray-200">
            </div>
        </div>
        
        <div wire:loading.flex class="w-full items-center justify-center py-8">
            <svg class="animate-spin h-8 w-8 text-indigo-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="ml-3 text-gray-600 dark:text-gray-300">Memuat data...</span>
        </div>

        <div wire:loading.remove class="mt-8 flow-root">
            @if ($anomalies->isNotEmpty())
                {{-- ================================================ --}}
                {{-- == KARTU RINGKASAN BARU == --}}
                {{-- ================================================ --}}
                <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-4 mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Selisih Sesuai Filter</h4>
                            <p class="text-xs text-gray-400 dark:text-gray-500">Jumlah dari semua halaman, bukan hanya yang ditampilkan.</p>
                        </div>
                        <div class="text-right">
                            <p class="text-2xl font-bold {{ $totalSelisih < 0 ? 'text-red-500' : ($totalSelisih > 0 ? 'text-green-600' : 'text-gray-900 dark:text-white') }}">
                                {{ $totalSelisih > 0 ? '+' : '' }}Rp {{ number_format($totalSelisih, 0, ',', '.') }}
                            </p>
                        </div>
                    </div>
                </div>
                {{-- ================================================ --}}
                
                <div>
                    {{-- Tampilan Tabel untuk DESKTOP --}}
                    <div class="hidden md:block">
                        <div class="overflow-x-auto shadow-sm ring-1 ring-black ring-opacity-5 sm:rounded-lg">
                            <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6 cursor-pointer" wire:click="sort('selisih')">
                                            Selisih @if($sortBy === 'selisih') <span class="ml-1">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
                                        </th>
                                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Info Pesanan</th>
                                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Rincian Kalkulasi Ongkir</th>
                                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Ekspedisi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                                    @foreach ($anomalies as $anomaly)
                                        <tr wire:key="desktop-{{ $anomaly->order_sn }}">
                                            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-base font-bold sm:pl-6 {{ $anomaly->selisih < 0 ? 'text-red-500' : 'text-green-600' }}">
                                                {{ $anomaly->selisih < 0 ? '' : '+' }}Rp {{ number_format($anomaly->selisih, 0, ',', '.') }}
                                            </td>
                                            <td class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                                <div class="font-medium text-gray-900 dark:text-white"><a href="{{ $anomaly->order_detail_url }}" target="_blank" class="hover:text-indigo-600">{{ $anomaly->order_sn }}</a></div>
                                                <div class="mt-1">Pembeli: {{ $anomaly->buyer_username }}</div>
                                                @if ($anomaly->tanggal_kirim)
                                                    <div>Tgl Kirim: {{ \Carbon\Carbon::parse($anomaly->tanggal_kirim)->isoFormat('DD MMM YYYY') }}</div>
                                                @else
                                                    <div class="text-yellow-500">Tgl Pesan: {{ \Carbon\Carbon::parse($anomaly->tanggal_pesan)->isoFormat('DD MMM YYYY') }}</div>
                                                    <div class="text-xs text-yellow-600">(Belum dikirim)</div>
                                                @endif
                                            </td>
                                            <td class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                                <div class="flex justify-between">
                                                    <span>(+) Buyer Bayar</span>
                                                    <span class="font-medium text-gray-700 dark:text-gray-300">Rp {{ number_format($anomaly->shipping_fee_paid_by_buyer, 0, ',', '.') }}</span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span>(+) Subsidi Shopee</span>
                                                    <span class="font-medium text-gray-700 dark:text-gray-300">Rp {{ number_format($anomaly->shopee_shipping_subsidy, 0, ',', '.') }}</span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span>(-) Bayar ke Logistik</span>
                                                    <span class="font-medium text-gray-700 dark:text-gray-300">Rp {{ number_format($anomaly->shipping_fee_paid_to_logistic, 0, ',', '.') }}</span>
                                                </div>
                                                <div class="flex justify-between border-t border-dashed mt-1 pt-1">
                                                    <span><b>(=) Selisih</b></span>
                                                    <span class="font-semibold {{ $anomaly->selisih < 0 ? 'text-red-500' : ($anomaly->selisih > 0 ? 'text-green-600' : 'text-gray-900 dark:text-white') }}">
                                                        Rp {{ number_format($anomaly->selisih, 0, ',', '.') }}
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                                <div class="font-medium text-gray-900 dark:text-white">{{ $anomaly->shipping_provider }}</div>
                                                <div>{{ $anomaly->tracking_number }}</div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Tampilan Kartu untuk MOBILE --}}
                    <div class="space-y-4 md:hidden">
                        @foreach ($anomalies as $anomaly)
                        <div wire:key="mobile-{{ $anomaly->order_sn }}" class="bg-white dark:bg-gray-800 shadow-sm rounded-lg overflow-hidden">
                            <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400"><a href="{{ $anomaly->order_detail_url }}" target="_blank">{{ $anomaly->order_sn }}</a></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $anomaly->buyer_username }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Selisih Ongkir</p>
                                        <p class="text-lg font-bold {{ $anomaly->selisih < 0 ? 'text-red-500' : 'text-green-500' }}">{{ $anomaly->selisih < 0 ? '' : '+' }}Rp {{ number_format($anomaly->selisih, 0, ',', '.') }}</p>
                                    </div>
                                </div>
                                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    @if ($anomaly->tanggal_kirim)
                                        <p>Tgl Kirim: {{ \Carbon\Carbon::parse($anomaly->tanggal_kirim)->isoFormat('DD MMM YYYY, HH:mm') }}</p>
                                    @else
                                        <p class="text-yellow-500">Tgl Pesan: {{ \Carbon\Carbon::parse($anomaly->tanggal_pesan)->isoFormat('DD MMM YYYY, HH:mm') }} <span class="text-xs">(Belum dikirim)</span></p>
                                    @endif
                                    <p>{{ $anomaly->shipping_provider }} - {{ $anomaly->tracking_number }}</p>
                                </div>
                            </div>
                            <div class="px-4 py-3 bg-gray-50 dark:bg-gray-800/50 text-xs space-y-1">
                                <div class="flex justify-between">
                                    <span class="text-gray-600 dark:text-gray-300">(+) Buyer Bayar</span>
                                    <span>Rp {{ number_format($anomaly->shipping_fee_paid_by_buyer, 0, ',', '.') }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600 dark:text-gray-300">(+) Subsidi Shopee</span>
                                    <span>Rp {{ number_format($anomaly->shopee_shipping_subsidy, 0, ',', '.') }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600 dark:text-gray-300">(-) Bayar ke Logistik</span>
                                    <span>Rp {{ number_format($anomaly->shipping_fee_paid_to_logistic, 0, ',', '.') }}</span>
                                </div>
                                <div class="flex justify-between border-t border-gray-200 dark:border-gray-700 mt-2 pt-2 text-sm">
                                    <span class="font-bold text-gray-700 dark:text-gray-200">(=) Selisih</span>
                                    <span class="font-bold {{ $anomaly->selisih < 0 ? 'text-red-500' : ($anomaly->selisih > 0 ? 'text-green-500' : 'text-gray-900 dark:text-white') }}">
                                        Rp {{ number_format($anomaly->selisih, 0, ',', '.') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Paginasi --}}
                <div class="mt-6">
                    {{ $anomalies->links() }}
                </div>
            @else
                <div class="text-center py-12 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" /></svg>
                    <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">Tidak ada anomali ditemukan</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Coba ubah filter atau rentang tanggal Anda. Pastikan ada data pesanan pada rentang tersebut.</p>
                </div>
            @endif
        </div>
    </x-app.container>
    @endvolt
</x-layouts.app>