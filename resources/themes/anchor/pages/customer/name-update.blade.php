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
    public array $buyerAddresses = [];
    public string $search = '';

    // Rules untuk validasi
    protected $rules = [
        'buyerNames.*'    => 'required|min:3',
        'buyerAddresses.*' => 'required|min:10',
    ];

    // Custom error messages
    protected $messages = [
        'buyerNames.*.required'    => 'Nama pembeli wajib diisi.',
        'buyerNames.*.min'         => 'Nama minimal 3 karakter.',
        'buyerAddresses.*.required' => 'Alamat pembeli wajib diisi.',
        'buyerAddresses.*.min'      => 'Alamat minimal 10 karakter.',
    ];

    // Validasi real-time saat input berubah
    public function updated($name, $value)
    {
        if (str_starts_with($name, 'buyerNames.') || str_starts_with($name, 'buyerAddresses.')) {
            $this->validateOnly($name);
        }
    }
    
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

    public function saveBuyerProfile(int $orderId): void
    {
        // Validasi spesifik untuk order ini
        $this->validate([
            "buyerNames.$orderId"    => 'required|min:3',
            "buyerAddresses.$orderId" => 'required|min:10',
        ]);

        $order = Order::find($orderId);
        if (!$order || $order->user_id !== auth()->id()) {
            return;
        }

        $nameToSave = trim($this->buyerNames[$orderId]);
        $addressToSave = trim($this->buyerAddresses[$orderId]);

        BuyerProfile::updateOrCreate(
            [
                'user_id' => auth()->id(),
                'buyer_username' => $order->buyer_username,
                'address_identifier' => sha1(trim($order->address_full))
            ],
            [
                'buyer_real_name' => $nameToSave,
                'buyer_address' => $addressToSave
            ]
        );

        if ($order->buyer_name !== $nameToSave) {
            $order->update(['buyer_name' => $nameToSave]);
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
            // PERUBAHAN 2: Ambil order dari kemarin dan hari ini
            ->where('created_at', '>=', today()->subDay())
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
                                            ...{{ substr($order->order_sn, -4) }}
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Alamat</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-300">
                                            {{ Str::words($order->address_full, 6, '...') }}
                                        </p>
                                    </div>
                                </div>
                                <div class="sm:col-span-2 space-y-3"> <!-- Tambahkan spacing vertikal -->
        <div>
            <label for="buyer_name_{{ $order->id }}" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Nama Pembeli</label>
            <input
                type="text" 
                id="buyer_name_{{ $order->id }}" 
                wire:model="buyerNames.{{ $order->id }}"
                wire:blur="saveBuyerProfile({{ $order->id }})" 
                placeholder="Ketik nama pembeli di sini..."
                class="block w-full border rounded-lg shadow-sm focus:ring-opacity-50 transition-colors duration-200 bg-gray-50 dark:bg-gray-900/50 text-gray-900 dark:text-gray-100 placeholder-gray-400
                    @error('buyerNames.'.$order->id) border-red-400 @else border-gray-300 dark:border-gray-600 @enderror
                    focus:border-black dark:focus:border-white focus:ring-black dark:focus:ring-white"
            >
        </div>
        <div>
            <label for="buyer_address_{{ $order->id }}" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Alamat Lengkap</label>
            <textarea
                id="buyer_address_{{ $order->id }}" 
                wire:model="buyerAddresses.{{ $order->id }}"
                wire:blur="saveBuyerProfile({{ $order->id }})" 
                placeholder="Ketik alamat lengkap pembeli di sini..."
                rows="2"
                class="block w-full border rounded-lg shadow-sm focus:ring-opacity-50 transition-colors duration-200 bg-gray-50 dark:bg-gray-900/50 text-gray-900 dark:text-gray-100 placeholder-gray-400
                    @error('buyerAddresses.'.$order->id) border-red-400 @else border-gray-300 dark:border-gray-600 @enderror
                    focus:border-black dark:focus:border-white focus:ring-black dark:focus:ring-white"
            ></textarea>
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