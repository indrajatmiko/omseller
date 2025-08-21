<?php

use function Laravel\Folio\{middleware, name};
use App\Models\BuyerProfile;
use App\Models\Order;
use App\Models\Indonesia\Province;
use App\Models\Indonesia\City;
use App\Models\Indonesia\District;
use Livewire\Volt\Component;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Str;

middleware('auth');
name('customer.map');

new class extends Component {
    // Properti untuk filter
    public ?string $filterProvince = 'all';
    public ?string $filterCity = 'all';
    public ?string $filterSegment = 'all';

    // Properti untuk data dropdown
    public Collection $provinces;
    public Collection $cities;

    private ?Collection $allCustomerData = null;

    public function mount(): void
    {
        // Ambil daftar provinsi dari data wilayah yang valid
        $this->provinces = Province::query()->orderBy('name')->pluck('name');
        $this->cities = collect();
        $this->applyFiltersAndDispatch();
    }
    
    // Lifecycle hooks
    public function updatedFilterProvince($provinceName): void
    {
        $this->filterCity = 'all';
        if ($provinceName !== 'all') {
            $province = Province::where('name', $provinceName)->first();
            if ($province) {
                $this->cities = $province->cities()->orderBy('name')->pluck('name');
            } else {
                $this->cities = collect();
            }
        } else {
            $this->cities = collect();
        }
        $this->applyFiltersAndDispatch();
    }
    public function updatedFilterCity(): void { $this->applyFiltersAndDispatch(); }
    public function updatedFilterSegment(): void { $this->applyFiltersAndDispatch(); }
    
    public function resetFilters(): void
    {
        $this->reset('filterProvince', 'filterCity', 'filterSegment');
        $this->cities = collect();
        $this->applyFiltersAndDispatch();
    }
    
    private function getSegmentDetails(int $r, int $f, int $m): array {
        $rf = (string)$r . (string)$f;
        $details = ['label' => 'Tertidur', 'color' => 'gray'];
        if ($r >= 4 && $f >= 4) { $details = ['label' => 'Juara', 'color' => 'emerald']; }
        elseif ($f >= 4) { $details = ['label' => 'Pelanggan Setia', 'color' => 'blue']; }
        elseif ($m >= 4) { $details = ['label' => 'Pembelanja Besar', 'color' => 'purple']; }
        elseif (preg_match('/^[3-4][3-4]$/', $rf)) { $details = ['label' => 'Pelanggan Potensial', 'color' => 'yellow']; }
        elseif (preg_match('/^[4-5]1$/', $rf)) { $details = ['label' => 'Pelanggan Baru', 'color' => 'teal']; }
        elseif (preg_match('/^[3-4]1$/', $rf)) { $details = ['label' => 'Menjanjikan', 'color' => 'lime']; }
        elseif (preg_match('/^[1-2][3-5]$/', $rf)) { $details = ['label' => 'Butuh Perhatian', 'color' => 'orange']; }
        elseif (preg_match('/^[1-2][1-2]$/', $rf)) { $details = ['label' => 'Hampir Tertidur', 'color' => 'red']; }
        return $details;
    }
    
    /**
     * Fungsi utama yang mandiri untuk mengambil dan memproses semua data.
     */
    private function getProcessedData(): Collection 
    {
        if ($this->allCustomerData !== null) {
            return $this->allCustomerData;
        }

        $cacheKey = 'customer_map_processed_data_v5_laravolt_' . auth()->id();
        
        $this->allCustomerData = Cache::remember($cacheKey, 3600, function() {
            // 1. Ambil data RFM dan alamat mentah dalam satu query
            $customerBaseData = BuyerProfile::query()
                ->where('buyer_profiles.user_id', auth()->id())
                ->join('orders', function ($join) {
                    $join->on('buyer_profiles.buyer_username', '=', 'orders.buyer_username')
                         ->where('orders.user_id', '=', auth()->id())
                         ->where(DB::raw('sha1(trim(orders.address_full))'), '=', DB::raw('buyer_profiles.address_identifier'));
                })
                ->select(
                    'buyer_profiles.id as buyer_profile_id',
                    'buyer_profiles.buyer_real_name',
                    'buyer_profiles.buyer_username',
                    DB::raw('COUNT(orders.id) as frequency'),
                    DB::raw('SUM(orders.total_price) as monetary'),
                    DB::raw('MAX(orders.created_at) as recency_date'),
                    DB::raw('SUBSTRING_INDEX(MAX(orders.address_full), ",", -5) as raw_location')
                )
                ->groupBy('buyer_profiles.id', 'buyer_profiles.buyer_real_name', 'buyer_profiles.buyer_username')
                ->get();

            if ($customerBaseData->isEmpty()) return collect();
            
        /**
         * Fungsi normalisasi yang lebih canggih untuk membersihkan nama lokasi.
         */
        $normalizeLocationName = function(string $name): string {
            $name = Str::lower($name);
            // Hapus "kota adm." atau "kab." atau "kota" atau "kabupaten"
            $name = preg_replace('/\b(kota adm\.|kab\.|kota|kabupaten)\b/', '', $name);
            // Hapus karakter non-alfanumerik kecuali spasi
            $name = preg_replace('/[^a-z0-9\s]/', '', $name);
            // Hapus spasi berlebih
            return trim(preg_replace('/\s+/', ' ', $name));
        };

        // Cache data wilayah dengan kunci yang sudah dinormalisasi
        $indonesiaCities = City::all()->keyBy(fn ($item) => $normalizeLocationName($item->name));
        $indonesiaDistricts = District::all()->keyBy(fn ($item) => $normalizeLocationName($item->name));

                
            // 3. Lakukan scoring RFM
            $getQuintiles = function (Collection $collection) {
                $sorted = $collection->sort()->values(); $count = $sorted->count();
                if ($count < 5) { $val = $sorted->last() ?: 1; return [$val, $val, $val, $val]; }
                return [$sorted->get(intval($count * 0.20)), $sorted->get(intval($count * 0.40)), $sorted->get(intval($count * 0.60)), $sorted->get(intval($count * 0.80))];
            };
            $recencyBoundaries = $getQuintiles($customerBaseData->pluck('recency_date')->map(fn($date) => Carbon::parse($date)->timestamp));
            $frequencyBoundaries = $getQuintiles($customerBaseData->pluck('frequency'));
            $monetaryBoundaries = $getQuintiles($customerBaseData->pluck('monetary'));

            // 4. Proses setiap pelanggan: Parsing, Matching, dan Scoring
        return $customerBaseData->map(function($customer) use ($normalizeLocationName, $indonesiaCities, $indonesiaDistricts, $recencyBoundaries, $frequencyBoundaries, $monetaryBoundaries) {
            $locationParts = array_map('trim', explode(',', $customer->raw_location));
            if (count($locationParts) < 5) return null;
            
            $cityStr = $locationParts[0];
            $districtStr = $locationParts[1];
            $provinceStr = $locationParts[2];

            // Normalisasi nama dari alamat pelanggan
            $normalizedCity = $normalizeLocationName($cityStr);
            $normalizedDistrict = $normalizeLocationName($districtStr);

            // Coba cocokkan
            $matchedDistrict = $indonesiaDistricts->get($normalizedDistrict);
            $matchedCity = $indonesiaCities->get($normalizedCity);
            
            $lat = null; $lng = null; $meta = null;
            if ($matchedDistrict && $matchedDistrict->meta) {
                $meta = $matchedDistrict->meta;
            } elseif ($matchedCity && $matchedCity->meta) {
                $meta = $matchedCity->meta;
            }
            
            if ($meta) {
                $lat = $meta['lat'] ?? null;
                $lng = $meta['long'] ?? null;
            }

            if (!$lat || !$lng) {
                // Log kegagalan untuk di-debug nanti
                Log::info("Match failed for: City '{$cityStr}' (norm: '{$normalizedCity}'), District '{$districtStr}' (norm: '{$normalizedDistrict}')");
                return null;
            }
             // Lewati jika tidak ada koordinat
                $getRecencyScore = function ($value, $boundaries) {
                    if ($value >= $boundaries[3]) return 5; if ($value >= $boundaries[2]) return 4; if ($value >= $boundaries[1]) return 3; if ($value >= $boundaries[0]) return 2; return 1;
                };
                $getScore = function ($value, $boundaries) {
                    if ($value > $boundaries[3]) return 5; if ($value > $boundaries[2]) return 4; if ($value > $boundaries[1]) return 3; if ($value > $boundaries[0]) return 2; return 1;
                };
                $rScore = $getRecencyScore(Carbon::parse($customer->recency_date)->timestamp, $recencyBoundaries);
                $fScore = $getScore($customer->frequency, $frequencyBoundaries);
                $mScore = $getScore($customer->monetary, $monetaryBoundaries);
                $segmentDetails = $this->getSegmentDetails($rScore, $fScore, $mScore);
                
                return (object) [
                    'id' => $customer->buyer_profile_id,
                    'name' => $customer->buyer_real_name,
                    'username' => $customer->buyer_username,
                    'lat' => $lat, 'lng' => $lng,
                    'city' => $cityStr, 'province' => $provinceStr,
                    'segment_label' => $segmentDetails['label'],
                    'segment_color' => $segmentDetails['color'],
                    'total_spend' => $customer->monetary,
                ];
            })->filter();
        });
        
        return $this->allCustomerData;
    }

    public function getDashboardStatsProperty()
    {
        $customers = $this->getProcessedData();
        if ($customers->isEmpty()) {
            return ['total' => 0, 'top_province' => 'N/A', 'top_city' => 'N/A', 'top_province_spend' => collect()];
        }
        $spendByProvince = $customers->groupBy('province')
            ->map(fn($group) => $group->sum('total_spend'))
            ->sortDesc()->take(5);
        return [
            'total' => $customers->count(),
            'top_province' => $customers->groupBy('province')->map->count()->sortDesc()->keys()->first(),
            'top_city' => $customers->groupBy('city')->map->count()->sortDesc()->keys()->first(),
            'top_province_spend' => $spendByProvince,
        ];
    }
    
    public function applyFiltersAndDispatch(): void 
    {
        $customers = $this->getProcessedData();

        if ($this->filterProvince !== 'all') $customers = $customers->where('province', $this->filterProvince);
        if ($this->filterCity !== 'all') $customers = $customers->where('city', $this->filterCity);
        if ($this->filterSegment !== 'all') $customers = $customers->where('segment_label', $this->filterSegment);

        $this->dispatch('updateMapData', customers: $customers->values()->toArray());
    }
}; ?>

<x-layouts.app>
    {{-- Pastikan aset CSS & JS Leaflet.js sudah ada di <head> layout utama Anda --}}
    @volt('customer-map')
    <div x-data="customerMap" x-init="start()">
        <x-app.container>
            <x-app.heading 
                title="Peta & Analisis Geografis Pelanggan"
                description="Visualisasikan persebaran pelanggan Anda untuk menemukan insight dan peluang pasar baru."
                :border="true" />
            
            <!-- Dashboard Statistik (di-ignore untuk performa) -->
            <div wire:ignore>
                @if($this->dashboardStats['total'] > 0)
                <div class="mt-6">
                    <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-gray-100">Ringkasan Geografis</h3>
                    <div class="grid grid-cols-1 gap-5 mt-2 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="px-4 py-5 overflow-hidden bg-white rounded-lg shadow dark:bg-gray-800 sm:p-6">
                            <dt class="text-sm font-medium text-gray-500 truncate dark:text-gray-400">Pelanggan Terpetakan</dt>
                            <dd class="mt-1 text-3xl font-semibold text-gray-900 dark:text-white">{{ $this->dashboardStats['total'] }}</dd>
                        </div>
                        <div class="px-4 py-5 overflow-hidden bg-white rounded-lg shadow dark:bg-gray-800 sm:p-6">
                            <dt class="text-sm font-medium text-gray-500 truncate dark:text-gray-400">Provinsi Terbaik</dt>
                            <dd class="mt-1 text-3xl font-semibold text-gray-900 truncate dark:text-white">{{ $this->dashboardStats['top_province'] }}</dd>
                        </div>
                        <div class="px-4 py-5 overflow-hidden bg-white rounded-lg shadow dark:bg-gray-800 sm:p-6">
                            <dt class="text-sm font-medium text-gray-500 truncate dark:text-gray-400">Kota/Kab Terbaik</dt>
                            <dd class="mt-1 text-3xl font-semibold text-gray-900 truncate dark:text-white">{{ $this->dashboardStats['top_city'] }}</dd>
                        </div>
                        <div class="px-4 py-5 overflow-hidden bg-white rounded-lg shadow dark:bg-gray-800 sm:p-6">
                            <dt class="text-sm font-medium text-gray-500 truncate dark:text-gray-400">Top 5 Provinsi (Spend)</dt>
                            <dd class="mt-2 space-y-1">
                                @foreach($this->dashboardStats['top_province_spend'] as $province => $total)
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($province, 15) }}</span>
                                        <span class="font-semibold text-gray-900 dark:text-white">Rp {{ number_format($total / 1000, 0, ',', '.') }}k</span>
                                    </div>
                                @endforeach
                            </dd>
                        </div>
                    </div>
                </div>
                @endif
            </div>

            <!-- Kontrol Filter & Peta -->
            <div class="mt-8">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <!-- Panel Filter (TIDAK di-ignore agar dropdown bisa update) -->
                    <div class="p-4 bg-white rounded-lg shadow md:col-span-1 dark:bg-gray-800">
                        <h4 class="font-semibold text-gray-800 dark:text-white">Filter Peta</h4>
                        <div class="mt-4 space-y-4">
                            <div>
                                <label for="filterProvince" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Provinsi</label>
                                <select wire:model.live="filterProvince" id="filterProvince" class="w-full mt-1 text-sm border-gray-300 rounded-md shadow-sm dark:bg-gray-700 dark:border-gray-600 focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="all">Semua Provinsi</option>
                                    @foreach($provinces as $province)
                                        <option value="{{ $province }}">{{ $province }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="filterCity" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Kota / Kabupaten</label>
                                <select wire:model.live="filterCity" id="filterCity" class="w-full mt-1 text-sm border-gray-300 rounded-md shadow-sm dark:bg-gray-700 dark:border-gray-600 focus:border-indigo-500 focus:ring-indigo-500" @disabled($cities->isEmpty())>
                                    <option value="all">Semua Kota</option>
                                    @foreach($cities as $city)
                                        <option value="{{ $city }}">{{ $city }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="filterSegment" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Segmen</label>
                                <select wire:model.live="filterSegment" id="filterSegment" class="w-full mt-1 text-sm border-gray-300 rounded-md shadow-sm dark:bg-gray-700 dark:border-gray-600 focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="all">Semua Segmen</option>
                                    @foreach (['Juara', 'Pelanggan Setia', 'Pembelanja Besar', 'Pelanggan Potensial', 'Pelanggan Baru', 'Menjanjikan', 'Butuh Perhatian', 'Hampir Tertidur', 'Tertidur'] as $segment)
                                        <option value="{{ $segment }}">{{ $segment }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <button wire:click="resetFilters" class="w-full px-4 py-2 text-sm font-medium text-white bg-gray-600 rounded-md hover:bg-gray-700">Reset Filter</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Elemen Peta (DI-ignore agar tidak digambar ulang oleh Livewire) -->
                    <div wire:ignore class="rounded-lg shadow md:col-span-3" id="customerMapEl" style="height: 600px;"></div>
                </div>
            </div>
        </x-app.container>
    </div>
    
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('customerMap', () => ({
                map: null,
                markers: null,
                start() {
                    this.map = this.initMap();
                    this.markers = L.markerClusterGroup();
                    this.map.addLayer(this.markers);
                    this.$el.addEventListener('updateMapData', event => {
                        this.updateMarkers(event.detail.customers);
                    });
                },
                initMap() {
                    if (document.getElementById('customerMapEl')?._leaflet_id) {
                        return this.map;
                    }
                    const mapInstance = L.map('customerMapEl').setView([-2.548926, 118.0148634], 5);
                    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors © <a href="https://carto.com/attributions">CARTO</a>'
                    }).addTo(mapInstance);
                    return mapInstance;
                },
                createColoredIcon(colorName) {
                    const colors = { emerald: '#10B981', blue: '#3B82F6', purple: '#8B5CF6', yellow: '#F59E0B', teal: '#14B8A6', lime: '#84CC16', orange: '#F97316', red: '#EF4444', gray: '#6B7280' };
                    const color = colors[colorName] || '#777';
                    const markerHtmlStyles = `background-color: ${color}; width: 1.5rem; height: 1.5rem; display: block; left: -0.75rem; top: -0.75rem; position: relative; border-radius: 1.5rem 1.5rem 0; transform: rotate(45deg); border: 1px solid #FFFFFF; box-shadow: 0 0 5px rgba(0,0,0,0.5);`;
                    return L.divIcon({ className: 'custom-div-icon', html: `<span style="${markerHtmlStyles}" />` });
                },
                updateMarkers(customers) {
                    if (!this.map) return;
                    this.markers.clearLayers();
                    if (!customers || customers.length === 0) {
                        this.map.setView([-2.548926, 118.0148634], 5);
                        return;
                    }
                    customers.forEach(customer => {
                        const popupContent = `
                            <div class="font-bold">${customer.name}</div>
                            <div class="text-xs text-gray-500">${customer.username}</div>
                            <div class="mt-2">
                                <span class="px-2 py-1 text-xs font-semibold text-white rounded-full segment-${customer.segment_color}">${customer.segment_label}</span>
                            </div>
                            <div class="mt-1 text-sm">Total Belanja: <strong>Rp ${new Intl.NumberFormat('id-ID').format(customer.total_spend)}</strong></div>
                            <div class="mt-1 text-xs text-gray-600">${customer.city}</div>
                        `;
                        const marker = L.marker([customer.lat, customer.lng], { icon: this.createColoredIcon(customer.segment_color) }).bindPopup(popupContent);
                        this.markers.addLayer(marker);
                    });
                    this.map.fitBounds(this.markers.getBounds().pad(0.1));
                }
            }));
        });
    </script>
    @endvolt
</x-layouts.app>