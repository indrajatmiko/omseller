<?php

use function Laravel\Folio\{middleware, name};
use App\Models\BuyerProfile;
use App\Models\Order;
use App\Models\OrderItem;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

middleware('auth');
name('customer.detail');

new class extends Component {
    public string $search = '';
    // PERUBAHAN: Beralih dari ID integer ke identifier string
    public ?string $selectedBuyerIdentifier = null;
    public array $customerDetails = [];

    public function mount(?string $identifier = null): void
    {
        if ($identifier) {
            $this->selectBuyer($identifier);
        }
    }

    public function updatedSearch(): void
    {
        $this->selectedBuyerIdentifier = null;
        $this->customerDetails = [];
    }

    // PERUBAHAN: Method ini sekarang menjadi `selectBuyer` dan menerima string
    public function selectBuyer(string $identifier): void
    {
        $this->selectedBuyerIdentifier = $identifier;
        $this->loadBuyerDetails();
    }
    
    // PERUBAHAN: Method ini sekarang bekerja berdasarkan identifier, bukan profile ID
    public function loadBuyerDetails(): void
    {
        if (!$this->selectedBuyerIdentifier) return;

        // Pecah kuncinya menjadi username dan hash alamat
        list($buyerUsername, $addressIdentifier) = explode('|', $this->selectedBuyerIdentifier, 2);
        
        // Coba cari profil yang sudah ada
        $profile = BuyerProfile::where('user_id', auth()->id())
            ->where('buyer_username', $buyerUsername)
            ->where('address_identifier', $addressIdentifier)
            ->first();

        // Ambil semua order yang cocok dengan kunci ini, terlepas dari profilnya ada atau tidak
        $orders = Order::where('user_id', auth()->id())
            ->where('buyer_username', $buyerUsername)
            ->where(DB::raw('sha1(trim(address_full))'), $addressIdentifier)
            ->with('items')
            ->get();
            
        if($orders->isEmpty()) {
            $this->selectedBuyerIdentifier = null; // Batal jika tidak ada order
            return;
        }

        $orderIds = $orders->pluck('id');
        $lastOrder = $orders->sortByDesc('created_at')->first();
        
        $totalSpend = $orders->sum('total_price');
        $totalItems = $orders->pluck('items')->flatten()->sum('quantity');
        $paymentMethods = $orders->pluck('payment_method')->filter()->unique()->implode(', ');
        $shippingProviders = $orders->pluck('shipping_provider')->filter()->unique()->implode(', ');

        $topProducts = OrderItem::whereIn('order_id', $orderIds)
            ->select('product_name', 'variant_sku', DB::raw('SUM(quantity) as total_quantity'))
            ->groupBy('product_name', 'variant_sku')->orderByDesc('total_quantity')->limit(10)->get();

        $this->customerDetails = [
            'display_name' => $profile->buyer_real_name ?? $buyerUsername, // Gunakan nama asli jika ada, jika tidak, username
            'is_provisional' => is_null($profile), // Tandai jika ini profil sementara
            'total_spend' => 'Rp ' . number_format($totalSpend, 0, ',', '.'),
            'total_items' => $totalItems, 'total_orders' => $orderIds->count(),
            'payment_methods' => $paymentMethods, 'shipping_providers' => $shippingProviders,
            'last_order_details' => $lastOrder, 
            'days_since_last_order' => $lastOrder ? Carbon::parse($lastOrder->created_at)->diffForHumans() : 'N/A',
            'top_products' => $topProducts,
            'username' => $buyerUsername,
        ];
    }
    
    public function with(): array
    {
        $profilesData = collect(); 
        
        if (strlen($this->search) >= 3) {
            $searchTerm = '%' . $this->search . '%';

            $foundProfiles = BuyerProfile::query()
                ->where('user_id', auth()->id())
                ->where(function ($query) use ($searchTerm) {
                    $query->where('buyer_real_name', 'like', $searchTerm)
                          ->orWhere('buyer_username', 'like', $searchTerm);
                })
                ->get();

            $foundIdentifiers = $foundProfiles->map(fn($p) => $p->buyer_username . '|' . $p->address_identifier)->all();
            
            $foundOrders = Order::query()
                ->where('user_id', auth()->id())
                ->where(function($query) use ($searchTerm) {
                    $query->where('buyer_username', 'like', $searchTerm)
                          ->orWhere('order_sn', 'like', $searchTerm)
                          ->orWhere('address_full', 'like', $searchTerm);
                })
                ->whereNotIn(DB::raw("CONCAT(buyer_username, '|', sha1(trim(address_full)))"), $foundIdentifiers)
                ->latest()
                ->get()
                ->unique(fn($order) => $order->buyer_username . '|' . sha1(trim($order->address_full)));

            $formattedProfiles = $foundProfiles->map(function($profile) {
                $lastOrder = $profile->orders()->latest()->first();
                return (object) [
                    'identifier' => $profile->buyer_username . '|' . $profile->address_identifier,
                    'display_name' => $profile->buyer_real_name,
                    'username' => $profile->buyer_username,
                    'is_provisional' => false,
                    'address' => Str::words(optional($lastOrder)->address_full ?? '', 6, '...'),
                    'last_order_date' => optional($lastOrder)->created_at
                ];
            });

            $formattedNewBuyers = $foundOrders->map(function($order) {
                $key = $order->buyer_username . '|' . sha1(trim($order->address_full));
                return (object) [
                    'identifier' => $key,
                    'display_name' => $order->buyer_username,
                    'username' => $order->buyer_username,
                    'is_provisional' => true,
                    'address' => Str::words($order->address_full, 6, '...'),
                    'last_order_date' => $order->created_at,
                ];
            });

            $profilesData = collect([])->merge($formattedProfiles)->merge($formattedNewBuyers);

        } else {
            $recentOrders = Order::where('user_id', auth()->id())->where('created_at', '>=', today()->subDay())->latest()->get();
            $latestOrderByBuyer = $recentOrders->unique(fn($order) => $order->buyer_username . '|' . sha1(trim($order->address_full)));

            if ($latestOrderByBuyer->isNotEmpty()) {
                $buyerIdentifiers = $latestOrderByBuyer->map(fn($order) => ['username' => $order->buyer_username, 'address_hash' => sha1(trim($order->address_full))]);
                $existingProfiles = BuyerProfile::where('user_id', auth()->id())
                    ->where(function ($query) use ($buyerIdentifiers) {
                        foreach ($buyerIdentifiers as $identifier) {
                            $query->orWhere(fn($q) => $q->where('buyer_username', $identifier['username'])->where('address_identifier', $identifier['address_hash']));
                        }
                    })->get()->keyBy(fn($p) => $p->buyer_username . '|' . $p->address_identifier);

                $profilesData = $latestOrderByBuyer->map(function ($order) use ($existingProfiles) {
                    $key = $order->buyer_username . '|' . sha1(trim($order->address_full));
                    $profile = $existingProfiles->get($key);
                    return (object) [
                        'identifier' => $key,
                        'display_name' => $profile->buyer_real_name ?? $order->buyer_username,
                        'buyer_username' => $order->buyer_username,
                        'username' => $order->buyer_username,
                        'is_provisional' => is_null($profile),
                        'address' => Str::words($order->address_full, 6, '...'),
                        'last_order_date' => $order->created_at,
                    ];
                });
            }
        }
        
        return [
            'profiles' => $profilesData,
        ];
    }
}; ?>

<x-layouts.app>
    @volt('customer-detail')
        <div>
            <x-app.container>
                <x-app.heading title="Detail Pelanggan" description="Cari pelanggan atau lihat daftar pelanggan terbaru untuk melihat riwayat lengkap mereka." :border="true" />
                <div class="mt-6">
                    <div class="flex items-center gap-2">
                        <input type="search" wire:model.live.debounce.300ms="search" class="block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 px-3 text-sm placeholder:text-gray-400 focus:border-black dark:focus:border-white focus:ring-1 focus:ring-black dark:focus:ring-white sm:text-sm" placeholder="Ketik nama, username, no. pesanan, atau alamat (min. 3 huruf)...">
                    </div>
                </div>

                <div class="mt-4">
                    {{-- PERUBAHAN: Kondisi utama sekarang memeriksa identifier, bukan ID --}}
                    @if ($selectedBuyerIdentifier && !empty($customerDetails))
                        <!-- Tampilan Detail Pelanggan -->
                        <div class="space-y-6">
                            {{-- PERUBAHAN: Header dibungkus flexbox untuk mengakomodasi alamat --}}
                            <div class="flex justify-between items-start">
                                {{-- Grup untuk Nama dan Alamat --}}
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $customerDetails['display_name'] }}</h2>
                                    
                                    @if(!$customerDetails['is_provisional'])
                                        {{-- BARU: Menggunakan flexbox untuk menyusun ikon dan teks --}}
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400 flex items-center gap-2">
                                            {{-- BARU: Ikon/SVG untuk username --}}
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-500 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-6-3a2 2 0 11-4 0 2 2 0 014 0zm-2 4a5 5 0 00-4.546 2.916A5.986 5.986 0 0010 16a5.986 5.986 0 004.546-2.084A5 5 0 0010 11z" clip-rule="evenodd" />
                                            </svg>
                                            <span>{{ $customerDetails['username'] }}</span>
                                        </p>
                                    @endif

                                    {{-- BARU: Menampilkan alamat lengkap dari pesanan terakhir --}}
                                    @if($address = optional($customerDetails['last_order_details'])->address_full)
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400 flex items-start gap-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-500 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                                            </svg>
                                            <span>{{ $address }}</span>
                                        </p>
                                    @endif
                                </div>

                                {{-- Tombol Kembali --}}
                                <button wire:click="$set('selectedBuyerIdentifier', null)" class="flex-shrink-0 rounded-lg bg-black dark:bg-white px-3 py-2 text-white dark:text-black hover:bg-gray-800 dark:hover:bg-gray-200 transition-colors">< Kembali</button>
                            </div>

                            @if($customerDetails['is_provisional'])
                                <div class="p-4 bg-yellow-50 dark:bg-yellow-900/50 border-l-4 border-yellow-400 text-yellow-700 dark:text-yellow-200">
                                    <p class="font-bold">Profil Sementara</p>
                                    <p class="text-sm">Lengkapi nama pembeli di halaman <a href="customer/name-update" class="underline font-semibold">Update Nama</a> untuk menyimpan profil ini secara permanen.</p>
                                </div>
                            @endif
                            <hr class="my-4 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Statistik Pelanggan</h3>
                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                <x-app.stat-card title="Total Belanja" :value="$customerDetails['total_spend']" />
                                <x-app.stat-card title="Total Pesanan" :value="$customerDetails['total_orders']" />
                                <x-app.stat-card title="Total Barang" :value="$customerDetails['total_items']" />
                                <x-app.stat-card title="Pesanan Terakhir" :value="$customerDetails['days_since_last_order']" />
                            </div>
                            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                <p class="text-sm font-medium text-gray-500">Metode Pembayaran: <span class="text-gray-900 dark:text-gray-200">{{ $customerDetails['payment_methods'] ?: 'N/A' }}</span></p>
                                <p class="text-sm font-medium text-gray-500 mt-1">Ekspedisi: <span class="text-gray-900 dark:text-gray-200">{{ $customerDetails['shipping_providers'] ?: 'N/A' }}</span></p>
                            </div>
                            @if($order = $customerDetails['last_order_details'])
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Detail Pesanan Terakhir</h3>
                                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg space-y-3">
                                    <div class="flex justify-between text-sm">
                                        <span class="font-medium text-gray-500">Tanggal:</span>
                                        <span class="font-semibold text-gray-900 dark:text-gray-200">{{ Carbon::parse($order->created_at)->translatedFormat('d F Y, H:i') }}</span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="font-medium text-gray-500">Total Bayar:</span>
                                        <span class="font-semibold text-gray-900 dark:text-gray-200">Rp {{ number_format($order->total_price, 0, ',', '.') }}</span>
                                    </div>
                                    <hr class="dark:border-gray-700">
                                    <p class="font-medium text-gray-500 text-sm">Barang yang dibeli:</p>
                                    <ul class="space-y-2 text-sm">
                                        @foreach($order->items as $item)
                                        <li class="flex justify-between items-center">
                                            <span class="text-gray-700 dark:text-gray-300">{{ Str::words($item->product_name ?? '', 6, '...') }}</span>
                                            <span class="font-mono bg-gray-200 dark:bg-gray-700 rounded px-2 py-1 text-xs font-bold">x{{ $item->quantity }}</span>
                                        </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                            @endif

                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Produk Teratas</h3>
                                <div class="overflow-x-auto bg-white dark:bg-gray-800 rounded-lg shadow">
                                    <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                                        <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                                            <tr><th scope="col" class="px-6 py-3">Produk</th><th scope="col" class="px-6 py-3">SKU</th><th scope="col" class="px-6 py-3 text-right">Jumlah Dibeli</th></tr>
                                        </thead>
                                        <tbody>
                                            @forelse($customerDetails['top_products'] as $item)
                                            <tr class="bg-white dark:bg-gray-800 border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                <th scope="row" class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ Str::words($item->product_name ?? '', 6, '...') }}</th>
                                                <td class="px-6 py-4">{{ $item->variant_sku ?: '-' }}</td>
                                                <td class="px-6 py-4 text-right font-bold">{{ $item->total_quantity }}</td>
                                            </tr>
                                            @empty
                                            <tr><td colspan="3" class="px-6 py-4 text-center">Tidak ada data produk.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @else
                        <!-- Tampilan Daftar Pelanggan -->
                        <div wire:loading class="text-center text-gray-500 py-4">Mencari...</div>
                        <div wire:loading.remove>
                            @if($profiles->isNotEmpty())
                                <h3 class="text-base font-semibold text-gray-500 dark:text-gray-400 mb-3">{{ strlen($search) >= 3 ? 'Hasil Pencarian' : 'Pelanggan dengan Pesanan (2 Hari Terakhir)' }}</h3>
                                <div class="space-y-2">
                                    @foreach($profiles as $profile)
                                        {{-- PERUBAHAN: Semua item sekarang bisa diklik menggunakan identifier universal --}}
                                        <div wire:key="profile-{{ $profile->identifier }}" 
                                             wire:click="selectBuyer('{{ $profile->identifier }}')"
                                             class="p-4 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 transition hover:bg-blue-50 dark:hover:bg-blue-900/50 cursor-pointer">
                                            <div class="flex justify-between items-center">
                                                <p class="font-bold text-gray-900 dark:text-gray-100">
                                                    {{ $profile->display_name }}
                                                    @if($profile->is_provisional)
                                                        <span class="ml-2 text-xs font-semibold bg-yellow-200 text-yellow-800 px-2 py-0.5 rounded-full">Baru</span>
                                                    @endif
                                                </p>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ Carbon::parse($profile->last_order_date)->diffForHumans() }}</span>
                                            </div>
                                            {{-- BARU: Tampilkan username jika profil tidak sementara --}}
                                            @if(!$profile->is_provisional)
                                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                    @<span>{{ $profile->username }}</span>
                                                </p>
                                            @endif
                                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $profile->address }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-center text-gray-400 py-8">
                                    <p>{{ strlen($search) >= 3 ? 'Tidak ada hasil.' : 'Tidak ada pesanan dalam 2 hari terakhir.' }}</p>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>