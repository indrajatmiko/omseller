<?php

use function Laravel\Folio\{middleware, name};
use App\Models\LocationData;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

middleware('auth');
name('customer.location-report');

new class extends Component {
    use WithPagination;

    public string $search = '';
    public array $openProvinces = [];
    public array $cityDetails = [];
    
    // Properti untuk Modal Detail Pelanggan
    public bool $showCustomerModal = false;
    public ?string $selectedLocationName = null;
    public ?Collection $customersInLocation = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->reset('openProvinces', 'cityDetails');
    }
    
    public function toggleProvince(string $provinceName): void
    {
        if (in_array($provinceName, $this->openProvinces)) {
            $this->openProvinces = array_diff($this->openProvinces, [$provinceName]);
            unset($this->cityDetails[$provinceName]);
        } else {
            $this->openProvinces[] = $provinceName;
            $this->loadCityDetailsFor($provinceName);
        }
    }

    public function loadCityDetailsFor(string $provinceName): void
    {
        // Query untuk detail kota di dalam provinsi
        $this->cityDetails[$provinceName] = LocationData::query()
            ->join('buyer_profiles', 'location_data.buyer_profile_id', '=', 'buyer_profiles.id')
            ->join('orders', 'buyer_profiles.buyer_username', '=', 'orders.buyer_username')
            ->where('buyer_profiles.user_id', auth()->id())
            ->where('location_data.province', $provinceName)
            ->select(
                'location_data.city', 
                DB::raw('COUNT(DISTINCT location_data.buyer_profile_id) as customer_count'),
                DB::raw('SUM(orders.total_price) as total_spend')
            )
            ->groupBy('location_data.city')
            ->orderBy('customer_count', 'desc')
            ->get()
            ->toArray();
    }
    
    /**
     * Menampilkan modal dengan detail pelanggan di kota tertentu.
     */
    public function showCustomerDetails(string $province, string $city): void
    {
        $this->selectedLocationName = "Pelanggan di {$city}, {$province}";
        
        $this->customersInLocation = LocationData::query()
            ->with('buyerProfile:id,buyer_real_name')
            ->where('province', $province)
            ->where('city', $city)
            ->whereHas('buyerProfile', fn($q) => $q->where('user_id', auth()->id()))
            ->orderBy('district')
            ->get();
        
        $this->showCustomerModal = true;
    }

    public function closeCustomerModal(): void
    {
        $this->showCustomerModal = false;
        $this->selectedLocationName = null;
        $this->customersInLocation = null;
    }

    /**
     * Computed property untuk data utama (agregat per provinsi).
     */
    public function getAggregatedProvincesProperty()
    {
        // Query ini memerlukan join tambahan ke tabel orders untuk menghitung total penjualan
        return LocationData::query()
            ->join('buyer_profiles', 'location_data.buyer_profile_id', '=', 'buyer_profiles.id')
            ->join('orders', 'buyer_profiles.buyer_username', '=', 'orders.buyer_username')
            ->where('buyer_profiles.user_id', auth()->id())
            ->where('location_data.province', 'LIKE', '%' . $this->search . '%')
            ->select(
                'location_data.province', 
                DB::raw('COUNT(DISTINCT location_data.city) as city_count'),
                DB::raw('COUNT(DISTINCT location_data.buyer_profile_id) as customer_count'),
                DB::raw('SUM(orders.total_price) as total_spend')
            )
            ->groupBy('location_data.province')
            ->orderBy('customer_count', 'desc')
            ->paginate(50);
    }
}; ?>

<x-layouts.app>
    @volt('location-report')
    <div x-data="{ showModal: @entangle('showCustomerModal').live }">
        <x-app.container>
            <x-app.heading 
                title="Laporan Hierarkis Lokasi & Penjualan"
                description="Analisis performa penjualan dan persebaran pelanggan berdasarkan Provinsi dan Kota/Kabupaten."
                :border="true" 
            />
            
            <!-- Kotak Pencarian -->
            <div class="mt-8">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari Provinsi..." class="block w-full max-w-sm py-2 pl-10 border-gray-300 rounded-md shadow-sm dark:bg-gray-800 dark:border-gray-600 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>
            
            <div class="mt-4 overflow-hidden rounded-lg shadow ring-1 ring-black ring-opacity-5">
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th scope="col" class="w-12 px-6 py-3"></th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Provinsi</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Jml. Kota/Kab</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Total Pelanggan</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Total Penjualan</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-900">
                            @forelse($this->aggregatedProvinces as $province)
                                <tr wire:key="province-{{ $province->province }}" class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <td class="px-6 py-4 text-center">
                                        <button wire:click="toggleProvince('{{ $province->province }}')" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                                            @if(in_array($province->province, $openProvinces))
                                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                                            @else
                                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                                            @endif
                                        </button>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $province->province }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500 dark:text-gray-400">
                                        {{ $province->city_count }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center font-semibold text-gray-700 dark:text-gray-300">
                                        {{ $province->customer_count }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold text-gray-900 dark:text-white">
                                        Rp {{ number_format($province->total_spend, 0, ',', '.') }}
                                    </td>
                                </tr>
                                
                                {{-- Baris Detail Kota (Accordion) --}}
                                @if(in_array($province->province, $openProvinces))
                                <tr wire:key="details-{{ $province->province }}" class="bg-gray-50 dark:bg-gray-800/50">
                                    <td colspan="5" class="p-0">
                                        <div class="px-8 py-4">
                                            <table class="min-w-full">
                                                <thead>
                                                    <tr>
                                                        <th class="py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Kota / Kabupaten</th>
                                                        <th class="py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Jml. Pelanggan</th>
                                                        <th class="py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Total Penjualan</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($cityDetails[$province->province] ?? [] as $city)
                                                    <tr wire:click="showCustomerDetails('{{ $province->province }}', '{{ $city['city'] }}')" class="border-t border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700/50 cursor-pointer">
                                                        <td class="py-3 text-sm text-gray-700 dark:text-gray-300">{{ $city['city'] }}</td>
                                                        <td class="py-3 text-center text-sm font-semibold text-gray-900 dark:text-white">{{ $city['customer_count'] }}</td>
                                                        <td class="py-3 text-right text-sm font-semibold text-gray-900 dark:text-white">Rp {{ number_format($city['total_spend'], 0, ',', '.') }}</td>
                                                    </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                                @endif
                            @empty
                                {{-- Pesan Kosong --}}
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6">
                {{ $this->aggregatedProvinces->links() }}
            </div>
        </x-app.container>

        <!-- Modal Detail Pelanggan -->
<!-- Modal Detail Pelanggan -->
@if($customersInLocation)
<div x-show="showModal" 
     x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200"
     x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-50 overflow-y-auto bg-gray-500 bg-opacity-75"
     aria-labelledby="modal-title" role="dialog" aria-modal="true" @keydown.escape.window="$wire.closeCustomerModal()">
    
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <div x-show="showModal"
             x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             @click.away="$wire.closeCustomerModal()"
             class="relative inline-block w-full max-w-2xl px-4 pt-5 pb-4 overflow-hidden text-left align-bottom transition-all transform bg-white rounded-lg shadow-xl dark:bg-gray-800 sm:my-8 sm:align-middle sm:p-6">
            
            <div class="flex items-start justify-between pb-3 border-b border-gray-200 dark:border-gray-700">
                <div>
                    <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-white" id="modal-title">
                        {{ $selectedLocationName }}
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Total: {{ $customersInLocation->count() }} pelanggan
                    </p>
                </div>
                <button wire:click="closeCustomerModal" type="button" class="text-gray-400 bg-transparent rounded-md hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    <span class="sr-only">Close</span>
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <div class="mt-4 pr-2 overflow-y-auto max-h-96">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="sticky top-0 bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Nama Pelanggan</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Kecamatan</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y dark:bg-gray-800/50 dark:divide-gray-700">
                        @foreach($customersInLocation as $customer)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{{ $customer->buyerProfile->buyer_real_name }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $customer->district }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif
    </div>
    @endvolt
</x-layouts.app>