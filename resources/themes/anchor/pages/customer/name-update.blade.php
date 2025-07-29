<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Order;
use App\Models\BuyerProfile;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

middleware('auth');
name('customer-name-update');

new class extends Component {
    use WithPagination;

    public array $buyerNames = [];
    public string $search = '';

    // Method mount sekarang jauh lebih sederhana, karena kita tidak perlu lagi
    // mem-preload status 'known'/'unknown'. Semua yang ditampilkan adalah 'unknown'.
    public function mount(): void
    {
        // Cukup inisialisasi array.
        $this->buyerNames = [];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function saveBuyerName(int $orderId): void
    {
        $order = Order::find($orderId);
        // Pastikan order ada dan milik user yang login
        if (!$order || $order->user_id !== auth()->id()) {
            return;
        }

        $nameToSave = trim($this->buyerNames[$orderId] ?? '');

        if (!empty($nameToSave)) {
            // Logika untuk menyimpan profil tetap sama
            BuyerProfile::updateOrCreate(
                ['user_id' => auth()->id(), 'buyer_username' => $order->buyer_username, 'address_identifier' => sha1(trim($order->address_full))],
                ['buyer_real_name' => $nameToSave]
            );
            if ($order->buyer_name !== $nameToSave) {
                 $order->update(['buyer_name' => $nameToSave]);
            }
            // Setelah disimpan, item ini akan otomatis hilang dari daftar saat komponen re-render,
            // jadi tidak perlu aksi tambahan.
        }
    }

    public function with(): array
    {
        // 1. Ambil SEMUA profil pembeli yang sudah dikenal oleh user ini.
        // Ini adalah satu query yang sangat cepat.
        $knownProfiles = BuyerProfile::where('user_id', auth()->id())
            ->get(['buyer_username', 'address_identifier'])
            ->keyBy(fn($profile) => $profile->buyer_username . '|' . $profile->address_identifier);

        // 2. Ambil SEMUA order dari kemarin & hari ini yang cocok dengan kriteria pencarian.
        // Kita belum melakukan paginasi di sini.
        $allMatchingOrders = Order::query()
            ->where('user_id', auth()->id())
            ->where('order_status', '!=', 'Dibatalkan')
            // PERUBAHAN 2: Ambil order dari kemarin dan hari ini
            ->where('created_at', '>=', today()->subDay(3))
            ->where('address_full', '!=', '')
            ->when($this->search, function ($query) {
                $query->where('order_sn', 'like', '%' . $this->search . '%');
            })
            ->orderBy('created_at', 'desc')
            ->get();

        // 3. PERUBAHAN 3: Filter order di sisi PHP untuk menyembunyikan yang sudah dikenal.
        // Ini dilakukan setelah mengambil data dari DB, tapi sebelum paginasi.
        // Ini adalah cara paling handal tanpa query SQL yang sangat kompleks.
        $unidentifiedOrders = $allMatchingOrders->filter(function ($order) use ($knownProfiles) {
            $identifierKey = $order->buyer_username . '|' . sha1(trim($order->address_full));
            // Hanya tampilkan order jika identifier-nya TIDAK ADA di daftar profil yang sudah dikenal.
            return !$knownProfiles->has($identifierKey);
        });
        
        // 4. Buat paginasi secara manual dari koleksi yang sudah difilter.
        $page = $this->getPage();
        $perPage = 50;
        $paginatedOrders = new LengthAwarePaginator(
            $unidentifiedOrders->forPage($page, $perPage),
            $unidentifiedOrders->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
        
        return [
            'orders' => $paginatedOrders
        ];
    }
}; ?>

<x-layouts.app>
    @volt('customer-name-update')
        <div>
            <x-app.container>
                <x-app.heading 
                    title="Input Nama Pembeli"
                    description="Daftar pesanan dari kemarin & hari ini yang belum dilengkapi namanya. Item akan hilang setelah disimpan."
                    :border="true" />

                <div class="mt-6">
    {{-- Form membungkus container flexbox --}}
    <form wire:submit.prevent>
        {{-- Container Flexbox untuk menyusun input dan tombol secara berdampingan --}}
        <div class="flex items-center gap-2">
            {{-- Input sekarang mengambil semua ruang yang tersedia --}}
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                {{-- Padding kiri (pl-10) tidak lagi diperlukan --}}
                class="block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 px-3 text-sm placeholder:text-gray-400 focus:border-black dark:focus:border-white focus:ring-1 focus:ring-black dark:focus:ring-white sm:text-sm"
                placeholder="Cari berdasarkan Nomor Pesanan (Order SN)...">
            
            {{-- Tombol terpisah untuk ikon, memberikan area klik yang jelas --}}
            <button type="button" class="flex-shrink-0 rounded-lg bg-black dark:bg-white px-3 py-2 text-white dark:text-black hover:bg-gray-800 dark:hover:bg-gray-200 transition-colors">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                </svg>
            </button>
        </div>
    </form>
</div>

                <div class="mt-4 space-y-3 sm:space-y-4">
                    <h3 class="text-base font-semibold text-gray-500 dark:text-gray-400 mb-3">
                                    Pelanggan dengan Pesanan (2 Hari Terakhir)
                                </h3>
                    @forelse ($orders as $order)
                        <div wire:key="order-{{ $order->id }}" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 transition-all duration-300">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-center">
                                <div class="sm:col-span-1 space-y-2">
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Order SN</p>
                                        <p class="font-bold text-gray-900 dark:text-gray-100 select-all" title="{{ $order->order_sn }}">
                                            {{ substr($order->order_sn, -4) }}
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Alamat</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-300">
                                            {{ Str::words($order->address_full, 6, '...') }}
                                        </p>
                                    </div>
                                </div>
                                    <div class="sm:col-span-2 relative">
                                        <label for="buyer_name_{{ $order->id }}" class="sr-only">Nama Pembeli</label>
                                        
                                        {{-- Grupkan input dan tombol --}}
                                        <div class="flex items-center space-x-2">
                                            <input
                                                type="text" id="buyer_name_{{ $order->id }}" wire:model="buyerNames.{{ $order->id }}"
                                                wire:keydown.enter="saveBuyerName({{ $order->id }})" 
                                                placeholder="Ketik nama pembeli di sini..."
                                                class="block w-full border rounded-lg shadow-sm focus:ring-opacity-50 transition-colors duration-200 bg-gray-50 dark:bg-gray-900/50 text-gray-900 dark:text-gray-100 placeholder-gray-400
                                                @if(empty($this->buyerNames[$order->id])) border-red-400 dark:border-red-500 focus:border-red-500 dark:focus:border-red-500 focus:ring-red-500
                                                @else border-gray-300 dark:border-gray-600 focus:border-black dark:focus:border-white focus:ring-black dark:focus:ring-white @endif"
                                            >
                                            
                                            {{-- Tombol Simpan Eksplisit --}}
                                            <button 
                                                type="button" 
                                                wire:click="saveBuyerName({{ $order->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="saveBuyerName({{ $order->id }})"
                                                class="flex-shrink-0 rounded-lg bg-black dark:bg-white px-3 py-2 text-sm font-medium text-white dark:text-black hover:bg-gray-800 dark:hover:bg-gray-200 transition-colors disabled:opacity-50 disabled:cursor-wait"
                                            >
                                                {{-- Tampilkan ikon loading atau teks "Simpan" --}}
                                                <span wire:loading.remove wire:target="saveBuyerName({{ $order->id }})">Simpan</span>
                                                <svg wire:loading wire:target="saveBuyerName({{ $order->id }})" class="animate-spin h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-dashed border-gray-300 dark:border-gray-700">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                               <path vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                @if ($this->search)
                                    Tidak ada hasil ditemukan
                                @else
                                    Semua data sudah lengkap!
                                @endif
                            </h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                @if ($this->search)
                                    Coba kata kunci pencarian yang lain.
                                @else
                                    Tidak ada pesanan yang perlu diisi untuk rentang waktu ini.
                                @endif
                            </p>
                        </div>
                    @endforelse
                </div>
                
                @if ($orders->hasPages())
                    <div class="mt-6">
                        {{ $orders->links() }}
                    </div>
                @endif
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>