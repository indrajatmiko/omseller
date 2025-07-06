<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Order;
use App\Models\BuyerProfile; // <-- Tambahkan model baru
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

middleware('auth');
name('customer-name-update');

new class extends Component {
    use WithPagination;

    public array $buyerNames = [];
    
    // (BARU) Array untuk melacak profil mana yang sudah dikenal
    public array $knownProfiles = []; 

    public function mount(): void
    {
        // 1. Ambil semua order hari ini
        $todaysOrders = Order::where('user_id', auth()->id())
            ->whereDate('created_at', today())
            ->get();

        // 2. (EFISIEN) Buat daftar unik pembeli dari order hari ini
        $uniqueBuyers = $todaysOrders->map(function ($order) {
            return [
                'buyer_username' => $order->buyer_username,
                // Gunakan hash sha1 dari alamat sebagai identifier yang konsisten & pendek
                'address_identifier' => sha1(trim($order->address_full)), 
            ];
        })->unique();

        // 3. (EFISIEN) Cek ke tabel `buyer_profiles` HANYA SEKALI untuk semua pembeli unik
        $knownBuyerProfiles = BuyerProfile::where('user_id', auth()->id())
            ->where(function ($query) use ($uniqueBuyers) {
                foreach ($uniqueBuyers as $buyer) {
                    $query->orWhere(function ($q) use ($buyer) {
                        $q->where('buyer_username', $buyer['buyer_username'])
                          ->where('address_identifier', $buyer['address_identifier']);
                    });
                }
            })
            ->get();
        
        // 4. (EFISIEN) Buat "peta" untuk pencarian cepat di memori (PHP)
        $profileMap = $knownBuyerProfiles->keyBy(function ($profile) {
            return $profile->buyer_username . '|' . $profile->address_identifier;
        });

        // 5. Isi input `buyerNames` dan tandai mana yang sudah dikenal (`knownProfiles`)
        foreach ($todaysOrders as $order) {
            $identifierKey = $order->buyer_username . '|' . sha1(trim($order->address_full));
            
            if (isset($profileMap[$identifierKey])) {
                // Jika profil ditemukan, isi nama dan tandai sebagai "dikenal"
                $this->buyerNames[$order->id] = $profileMap[$identifierKey]->buyer_real_name;
                $this->knownProfiles[$order->id] = true;
            } else {
                // Jika tidak, biarkan kosong dan tandai sebagai "tidak dikenal"
                $this->buyerNames[$order->id] = ''; // atau $order->buyer_name jika ada data lama
                $this->knownProfiles[$order->id] = false;
            }
        }
    }

    public function saveBuyerName(int $orderId): void
    {
        $order = Order::where('user_id', auth()->id())->find($orderId);
        $nameToSave = trim($this->buyerNames[$orderId] ?? '');

        if ($order && !empty($nameToSave)) {
            // (BARU) Gunakan `updateOrCreate` untuk efisiensi maksimal!
            // Ini akan membuat profil jika belum ada, atau mengupdate jika sudah ada.
            BuyerProfile::updateOrCreate(
                [
                    // Kunci untuk mencari
                    'user_id' => auth()->id(),
                    'buyer_username' => $order->buyer_username,
                    'address_identifier' => sha1(trim($order->address_full)),
                ],
                [
                    // Data yang akan diisi/diupdate
                    'buyer_real_name' => $nameToSave,
                ]
            );

            // Simpan juga di order saat ini untuk konsistensi data
            $order->update(['buyer_name' => $nameToSave]);
            
            // Tandai profil ini sebagai "dikenal" di tampilan secara real-time
            $this->knownProfiles[$orderId] = true; 
        }
    }

    public function with(): array
    {
        return [
            'orders' => Order::where('user_id', auth()->id())
                ->whereDate('created_at', today())
                ->orderBy('created_at', 'desc')
                ->paginate(10),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('customer-name-update')
        <div>
            <x-app.container>
                <x-app.heading 
                    title="Input Nama Pembeli"
                    description="Isi nama pembeli. Sistem akan mengingatnya untuk pesanan berikutnya dari pembeli yang sama."
                    :border="true" />

                <div class="mt-6 space-y-3 sm:space-y-4">
                    @forelse ($this->orders as $order)
                        <div wire:key="order-{{ $order->id }}" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 transition-all duration-300">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-center">
                                
                                <div class="sm:col-span-1 space-y-2">
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Order SN</p>
                                        <p class="font-bold text-gray-900 dark:text-gray-100 select-all">{{ $order->order_sn }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Alamat</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-300">
                                            {{ Str::words($order->address_full, 6, '...') }}
                                        </p>
                                    </div>
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="buyer_name_{{ $order->id }}" class="sr-only">Nama Pembeli</label>
                                    <input
                                        type="text"
                                        id="buyer_name_{{ $order->id }}"
                                        wire:model="buyerNames.{{ $order->id }}"
                                        wire:blur="saveBuyerName({{ $order->id }})"
                                        placeholder="Ketik nama pembeli di sini..."
                                        {{-- (BARU) Kunci input jika profil sudah dikenal --}}
                                        @if($this->knownProfiles[$order->id] ?? false)
                                            disabled 
                                        @endif
                                        class="block w-full border rounded-lg shadow-sm focus:ring-opacity-50 transition-colors duration-200 bg-gray-50 dark:bg-gray-900/50 text-gray-900 dark:text-gray-100 placeholder-gray-400
                                        {{-- Border merah untuk yang belum dikenal & kosong --}}
                                        @if(!($this->knownProfiles[$order->id] ?? false) && empty($this->buyerNames[$order->id]))
                                            border-red-400 dark:border-red-500 focus:border-red-500 dark:focus:border-red-500 focus:ring-red-500
                                        {{-- Border abu-abu untuk yang sudah dikenal --}}
                                        @elseif($this->knownProfiles[$order->id] ?? false)
                                            border-gray-300 dark:border-gray-600 bg-gray-100 dark:bg-gray-800 cursor-not-allowed
                                        @else
                                            border-gray-300 dark:border-gray-600 focus:border-black dark:focus:border-white focus:ring-black dark:focus:ring-white
                                        @endif
                                        "
                                    >
                                </div>

                            </div>
                        </div>
                    @empty
                        <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-dashed border-gray-300 dark:border-gray-700">
                           <p>Tidak ada pesanan untuk hari ini.</p>
                        </div>
                    @endforelse
                </div>

                @if ($this->orders->hasPages())
                    <div class="mt-6">
                        {{ $this->orders->links() }}
                    </div>
                @endif

            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>