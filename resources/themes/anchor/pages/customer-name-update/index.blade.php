<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Order;
use App\Models\BuyerProfile;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

middleware('auth');
name('customer-name-update');

new class extends Component {
    use WithPagination;

    public array $buyerNames = [];
    public array $knownProfiles = [];

    public function mount(): void
    {
        $todaysOrders = Order::where('user_id', auth()->id())
            ->whereDate('created_at', today())
            ->get();

        if ($todaysOrders->isEmpty()) {
            return;
        }

        $uniqueBuyers = $todaysOrders->map(function ($order) {
            return [
                'buyer_username' => $order->buyer_username,
                'address_identifier' => sha1(trim($order->address_full)),
            ];
        })->unique()->values();

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
        
        $profileMap = $knownBuyerProfiles->keyBy(function ($profile) {
            return $profile->buyer_username . '|' . $profile->address_identifier;
        });

        foreach ($todaysOrders as $order) {
            $identifierKey = $order->buyer_username . '|' . sha1(trim($order->address_full));
            
            if (isset($profileMap[$identifierKey])) {
                $this->buyerNames[$order->id] = $profileMap[$identifierKey]->buyer_real_name;
                $this->knownProfiles[$order->id] = true;
            } else {
                $this->buyerNames[$order->id] = $order->buyer_name ?? '';
                $this->knownProfiles[$order->id] = false;
            }
        }
    }

    public function saveBuyerName(int $orderId): void
    {
        $order = Order::where('user_id', auth()->id())->find($orderId);
        $nameToSave = trim($this->buyerNames[$orderId] ?? '');

        if ($order && !empty($nameToSave)) {
            BuyerProfile::updateOrCreate(
                [
                    'user_id' => auth()->id(),
                    'buyer_username' => $order->buyer_username,
                    'address_identifier' => sha1(trim($order->address_full)),
                ],
                [
                    'buyer_real_name' => $nameToSave,
                ]
            );

            if ($order->buyer_name !== $nameToSave) {
                 $order->update(['buyer_name' => $nameToSave]);
            }
            
            $this->knownProfiles[$orderId] = true;
        }
    }

    public function with(): array
    {
        return [
            // Variabel 'orders' ini akan menjadi $orders di Blade
            'orders' => Order::where('user_id', auth()->id())
                ->whereDate('created_at', today())
                ->orderBy('created_at', 'desc')
                ->paginate(50),
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
                    {{-- (DIUBAH) Menggunakan $orders, bukan $this->orders --}}
                    @forelse ($orders as $order)
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
                                        @if($this->knownProfiles[$order->id] ?? false)
                                            disabled 
                                        @endif
                                        class="block w-full border rounded-lg shadow-sm focus:ring-opacity-50 transition-colors duration-200 bg-gray-50 dark:bg-gray-900/50 text-gray-900 dark:text-gray-100 placeholder-gray-400
                                        @if(!($this->knownProfiles[$order->id] ?? false) && empty($this->buyerNames[$order->id]))
                                            border-red-400 dark:border-red-500 focus:border-red-500 dark:focus:border-red-500 focus:ring-red-500
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
                           <p class="text-gray-500">Tidak ada pesanan untuk hari ini.</p>
                        </div>
                    @endforelse
                </div>
                
                {{-- (DIUBAH) Menggunakan $orders, bukan $this->orders --}}
                @if ($orders->hasPages())
                    <div class="mt-6">
                        {{ $orders->links() }}
                    </div>
                @endif

            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>