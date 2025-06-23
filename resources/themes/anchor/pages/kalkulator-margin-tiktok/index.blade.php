<?php
use function Laravel\Folio\{middleware, name};
use App\Models\KalkulatorShopee;
use Livewire\Volt\Component;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Illuminate\Support\Facades\Storage;
use Illuminate\Pagination\LengthAwarePaginator;

middleware('auth');
name('kalkulator-margin-tiktok');

new class extends Component {
    public $kalkulator_margin;
    public $harga_modal = 0;
    public $harga_jual = 0;
    public $margin = 0;
    public $keuntungan_rupiah = 0;
    public $produk_pre_order = false;
    public $gratis_xtra = false;
    public $gratisOngkirXtraLimited = false;
    public $promo_xtra = false;
    public $promoXtraLimited = false;
    public $biaya_iklan = 0;
    public $biaya_operasional = 0;
    public $selectedKategori = 0;
    public $komisi_affiliasi = 0;

    public $search = '';
    public $tempSearch = '';
    public $showModal = false;
    public $perPage = 5;
    public $page = 1;
    public $kategoriProduk = [];
    public $tipePenjual = 'non_star';
    public $q_kalkulator;

    public function mount()
    {
        $this->loadKategoriProduk();
    }

    public function updatedTipePenjual()
    {
        $this->selectedKategori = 0;
        $this->loadKategoriProduk();
    }

    private function loadKategoriProduk()
    {
        $filename = $this->tipePenjual === 'mall' ? 'kategori_produk_mall.json' : 'kategori_produk.json';

        $path = public_path('data/' . $filename);
        $this->kategoriProduk = json_decode(file_get_contents($path), true);
    }

    // Mengubah data JSON (dengan struktur pengelompokan) menjadi daftar baris
    public function getFlattenedRowsProperty()
    {
        $rows = [];
        foreach ($this->kategoriProduk as $group) {
            foreach ($group['subcategories'] as $sub) {
                $rows[] = [
                    'main_category' => $group['main_category'],
                    'subcategory' => $sub['name'],
                    'description' => $sub['description'],
                ];
            }
        }
        if (!empty($this->search)) {
            $rows = array_filter($rows, function ($row) {
                return stripos($row['description'], $this->search) !== false;
            });
        }
        return array_values($rows);
    }

    // Membuat pagination dari daftar baris yang telah difilter
    public function getPaginatedRowsProperty()
    {
        $allRows = $this->flattenedRows;
        $currentPage = $this->page ?: 1;
        $total = count($allRows);
        $perPage = $this->perPage;
        $slice = array_slice($allRows, ($currentPage - 1) * $perPage, $perPage);
        return new LengthAwarePaginator($slice, $total, $perPage, $currentPage, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    // Method untuk menerapkan pencarian
    public function applySearch()
    {
        $this->search = $this->tempSearch;
        $this->page = 1;
    }

    public function updatingPerPage()
    {
        $this->page = 1;
    }

    public function nextPage()
    {
        $paginator = $this->paginatedRows;
        if ($this->page < $paginator->lastPage()) {
            $this->page++;
        }
    }

    public function previousPage()
    {
        if ($this->page > 1) {
            $this->page--;
        }
    }

    public function formatHargaModal()
    {
        // Ambil angka saja
        $numericValue = preg_replace('/\D/', '', $this->harga_modal);
        $numericValue = $numericValue ?: 0;
        // Format menjadi Rupiah
        $this->harga_modal = 'Rp ' . number_format($numericValue, 0, ',', '.');
    }

    public function formatHargaJual()
    {
        $numericValue = preg_replace('/\D/', '', $this->harga_jual);
        $numericValue = $numericValue ?: 0;
        $this->harga_jual = 'Rp ' . number_format($numericValue, 0, ',', '.');
    }

    public function calculateMargin()
    {
        $this->q_kalkulator = auth()->user()->kalkulatorShopees()->get()->count('hitung');
        if ($this->q_kalkulator < 3) {
            auth()
                ->user()
                ->kalkulatorShopees()
                ->create(['hitung' => 1]);
        }

        // Konversi format Rupiah ke angka (menghapus semua karakter non-digit)
        $harga_modal = (float) preg_replace('/\D/', '', $this->harga_modal);
        $harga_jual = (float) preg_replace('/\D/', '', $this->harga_jual);

        if ($harga_modal == 0 || $harga_jual == 0) {
            $this->margin = 0;
            $this->keuntungan_rupiah = 0;
            return;
        }

        // Jika kategori 10, gunakan komisi 8%
        $persentase_admin = (float) $this->selectedKategori;
        if ($this->selectedKategori == 10) {
            $persentase_admin = 8;
        }

        // Hitung potongan biaya admin
        $potongan_admin = $harga_jual * ($persentase_admin / 100);

        // Hitung biaya tambahan
        $biaya_tambahan = 0;
        if ($this->produk_pre_order) {
            $gratisOngkirXtra = $harga_jual * 0.03; // 3%
            $this->gratisOngkirXtraLimited = $gratisOngkirXtra > 10000;
            $biaya_tambahan += min($gratisOngkirXtra, 10000);
        } else {
            $this->gratisOngkirXtraLimited = false;
        }

        if ($this->promo_xtra) {
            $promoXtra = $harga_jual * 0.018; // 1.8%
            $this->promoXtraLimited = $promoXtra > 50000;
            $biaya_tambahan += min($promoXtra, 50000);
        } else {
            $this->promoXtraLimited = false;
        }

        $potongan_iklan = ($this->biaya_iklan / 100) * $harga_jual;
        $potongan_operasional = ($this->biaya_operasional / 100) * $harga_jual;
        $biaya_toko = $potongan_iklan + $potongan_operasional;

        $potongan_affiliasi = ($this->komisi_affiliasi / 100) * $harga_jual;


        // Hitung keuntungan bersih
        $keuntungan_bersih = $harga_jual - $harga_modal - $potongan_admin - $biaya_tambahan - $biaya_toko - $potongan_affiliasi;

        // Hitung margin berdasarkan harga jual
        if ($harga_jual > 0) {
            $this->margin = ($keuntungan_bersih / $harga_jual) * 100;
        } else {
            $this->margin = 0;
        }

        // Format tampilan
        $this->margin = number_format($this->margin, 2) . ' %';
        $this->keuntungan_rupiah = 'Rp ' . number_format($keuntungan_bersih, 0, ',', '.');
    }
};
?>

<x-layouts.app>
    @volt('kalkulator-margin-tiktok')
        <div> <!-- Elemen root tunggal yang membungkus semua konten -->
            <x-app.container>
                <div class="flex items-center justify-between mb-4">
                    <x-app.heading title="Kalkulator Margin Tiktok Tokopedia"
                        description="Kalkulator untuk menghitung berapa margin keuntungan dari produk yang akan Anda jual di Marketplace Tiktok Tokopedia. Data per 24 Januari 2025."
                        :border="true" />
                </div>

                <form class="space-y-4 mt-6" wire:submit.prevent="calculateMargin">
                    <div class="bg-gradient-to-br from-gray-50 via-white to-gray-100 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 rounded-2xl shadow-md p-8 mb-8 border border-gray-200 dark:border-gray-700 transition-all duration-300">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label for="harga_modal" class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Harga Modal</label>
                                <input
                                    type="text"
                                    id="harga_modal"
                                    wire:model="harga_modal"
                                    wire:blur="formatHargaModal"
                                    class="block w-full mt-1 border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:border-black dark:focus:border-white focus:ring-opacity-50 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition"
                                >
                                @error('harga_modal') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label for="harga_jual" class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Harga Jual</label>
                                <input
                                    type="text"
                                    id="harga_jual"
                                    wire:model="harga_jual"
                                    wire:blur="formatHargaJual"
                                    class="block w-full mt-1 border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:border-black dark:focus:border-white focus:ring-opacity-50 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition"
                                >
                                @error('harga_jual') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <hr class="my-4 border-t-2 border-gray-100 dark:border-gray-200 rounded">
                        <div class="mb-4">
                            <label class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Komisi Platform</label>
                            <div class="flex gap-6">
                                <label class="flex items-center space-x-2 cursor-pointer">
                                    <input 
                                        type="radio" 
                                        value="non_star" 
                                        wire:model.live="tipePenjual" 
                                        class="form-radio text-black dark:text-white focus:ring-black dark:focus:ring-white"
                                    >
                                    <span class="text-base text-gray-700 dark:text-gray-200">Marketplace</span>
                                </label>
                                <label class="flex items-center space-x-2 cursor-pointer">
                                    <input 
                                        type="radio" 
                                        value="mall" 
                                        wire:model.live="tipePenjual" 
                                        class="form-radio text-black dark:text-white focus:ring-black dark:focus:ring-white"
                                        @if(auth()->user()->hasRole('registered') OR auth()->user()->hasRole('basic'))
                                            onclick="event.preventDefault(); new FilamentNotification()
                                                .title('Hanya untuk User Premium dan Pro!')
                                                .danger()
                                                .body('Cuman seharga Rp 100.000 per bulan, kamu bisa akses fitur ini dan lainnya. Langsung upgrade sekarang!')
                                                .actions([
                                                    new FilamentNotificationAction('Ya, Upgrade Sekarang')
                                                        .button()
                                                        .url('/settings/subscription')
                                                        .openUrlInNewTab(),
                                                    new FilamentNotificationAction('Nanti dulu')
                                                        .color('gray'),
                                                ])
                                                .send()"
                                        @endif
                                    >
                                    <span class="text-base text-gray-700 dark:text-gray-200">Mall</span>
                                </label>
                            </div>
                        </div>
                        <div class="gap-6 mb-4">
                            <div>
                                <label for="kategori-select" class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Kategori Produk</label>
                                <select 
                                    id="kategori-select" 
                                    wire:model="selectedKategori"
                                    class="block w-full mt-1 border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:border-black dark:focus:border-white focus:ring-opacity-50 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition text-wrap"
                                >
                                    <option value="0">Pilih Kategori Produk</option>
                                    @if($tipePenjual === 'non_star')
                                        <!-- Elektronik -->
                                        <optgroup label="Elektronik">
                                            <option value="4.25">Computers & Office Equipment Desktop & Laptop Components Sound Cards, Power Supply Units, Monitors, Fans & Heatsinks, UPS & Stabilizers, RAM, Processors, PC Cases, Optical Drives, Motherboards, Graphics Cards, TV Tuner & Video Capture Cards</option>
                                            <option value="5.75">Computers & Office Equipment Computer Accessories Laptop Stands & Trays, USB Hubs & Card Readers, Webcams</option>
                                            <option value="4.25">Computers & Office Equipment Data Storage & Software Flash Drives & OTG Cables, Hard Disk Enclosures & Docking Stations, SSD, Network Attached Storage (NAS), Micro SD Cards, Hard Drives, Compact Discs</option>
                                            <option value="5.75">Computers & Office Equipment Data Storage & Software Software</option>
                                            <option value="5.75">Computers & Office Equipment Network Components Modems & Wireless Routers, Wireless Adapters & Network Cards, Network Cables & Connectors, Network Switches & PoE, Powerline Adapters, Repeaters, Print Servers, KVM Switches</option>
                                            <option value="4.25">Computers & Office Equipment Office Equipment Label Printers, Money Counters, Laminators, Office Equipment Parts, Advertisement Printing Equipment, 3D Printing Supplies</option>
                                            <option value="10">Computers & Office Equipment Office Stationery & Supplies Art Supplies, Accounting Supplies, Calendars & Accessories, Desk Organizers & Accessories, Envelopes & Postal Supplies, Gifts & Wrapping, Identification Badges & Supplies, Labels, Index Dividers & Stamps, Notebooks & Paper, Office Cutting Supplies, Office Filing Products, Office Measuring Supplies, Safes, School & Educational Supplies, Tape, Adhesives & Fasteners, Writing & Correction Tools</option>
                                            <option value="4.25">Computers & Office Equipment Office Equipment Ink & Toner Cartridges, Paper Shredders, Printers & Scanners, Barcode Scanners, Access Control & Attendance Devices, Typewriters, Smart Retail Equipment</option>
                                            <option value="4.25">Household Appliances Kitchen Appliances Juicers & Blenders, Electric Hot Pots, Fryers, Kitchen Appliance Parts, Rice & Pressure Cookers, Countertop Ovens, Mixers, Coffee Machines & Accessories, Toasters, Food Processors, Water Coolers & Dispensers, Vacuum Sealers, Induction Hobs, Electric Kettles, Electric Grills, Electric Steamers, Specialty Kitchen Appliances, Ice Makers, Bread Makers, Microwaves, Electric & Gas Stoves, Water Filters, Soda Makers, Food Waste Disposers</option>
                                            <option value="5.75">Household Appliances Home Appliances Vacuum Cleaners & Sweeping Robots, Fans, Irons, Humidifiers</option>
                                            <option value="7.5">Household Appliances Home Appliances Electronic Mosquito Killers</option>
                                             <option value="4.25">Household Appliances Home Appliances Hand Dryers, Home Sterilizers</option>
                                        </optgroup>
                                    
                                        <!-- Fashion -->
                                        <optgroup label="Fashion">
                                            <option value="10">Fashion Accessories Clothes Accessories Belts, Hats, Gloves, Collar Clips & Brooches, Face Covering Masks & Accessories, Scarves & Shawls, Ties & Bow ties, Fashion Accessory Sets</option>
                                            <option value="10">Fashion Accessories Hair Extensions & Wigs</option>
                                             <option value="10">Luggage & Bags Women's Bags, Men's Bags, Luggage & Travel Bags, Functional Bags, Bag Accessories</option>
                                            <option value="10">Menswear & Underwear Men's Tops, Men's Bottoms, Men's Special Clothing, Men's Underwear, Men's Sleepwear & Loungewear, Men's Suits & Overalls</option>
                                            <option value="10">Muslim Fashion Fashion Hijabs, Women's Islamic Clothing Shirts & Blouses, Clothing Sets, Dresses, Gamis, Abayas, Tunics, Skirts, Outerwear, Culottes & Palazzo Pants, Kaftans, Jumpsuits, Family Clothing Sets, Robes, Couples' Clothing Sets, Leggings, Turtlenecks & Inners, Kids' Islamic Clothing, Islamic Accessories, Prayer Attire & Equipment</option>
                                             <option value="10">Pre-Owned Fashion Accessories</option>
                                            <option value="10">Sports & Outdoor Ball Sports Equipment, Water Sports Equipment, Winter Sports Equipment, Fitness Equipment, Camping & Hiking Equipment</option>
                                            <option value="10">Sports & Outdoor Leisure & Outdoor Recreation Equipment Aerobics, Airsoft, Archery, Ballet & Dance, Boxing & Martial Arts, Cheerleading, Climbing, Darts, Disc Sports, E-sports, Fencing, Fishing, Gymnastics, Horse Riding, Hunting, Indoor Recreation, Judo, Karate, Nunchucks, Paintball, Racing, Roller Skating, Running, Skydiving, Skateboarding, Taekwondo, Track & Field, Triathlon, Wrestling, Yoga & Pilates</option>
                                            <option value="10">Sports & Outdoor Sports & Outdoor Accessories Sports Bags, Sports Water Bottles, Sports Eyewear, Stopwatches & Timers, Sports Gloves, Sports & Outdoor Hats, Pedometers, Sports Socks, Sports Sleeves & Support, Protective Gear, Sports Tapes, Face Covers & Mask, Life Jackets & Vests, Sports Wristbands, Swimming Caps, Sports Headbands, Shoe Bags, Trophies, Medals & Awards, Hand Chalk, Coach & Referee Gear</option>
                                            <option value="10">Sports & Outdoor Sports & Outdoor Clothing, Sports Footwear</option>
                                            <option value="10">Sports & Outdoor Swimwear, Surfwear & Wetsuits</option>
                                            <option value="10">Womenswear & Underwear Women's Tops, Women's Bottoms, Women's Dresses, Women's Special Clothing</option>
                                    
                                        </optgroup>
                                    
                                        <!-- FMCG -->
                                        <optgroup label="FMCG">
                                          <option value="10">Baby & Maternity Baby Care & Health Baby Toys Baby Sound Toys, Baby Pretend Play </option>
                                          <option value="10">Baby & Maternity Baby Fashion Accessories Baby Hats & Caps, Bibs & Burp Cloths, Baby Bags, Gift Sets, Baby Earmuffs, Baby Costume Jewelry, Baby Hair Accessories, Baby Gloves, Sunglasses, Baby Scarves, Baby Face Masks</option>
                                            <option value="10">Beauty & Personal Care Bath & Body Care Body Creams & Lotions, Body Care Kits, Body Wash & Soap, Body Scrubs & Peels, Hair Removal Cream, Wax & Shave, Sunscreen & Sun Care, Deodorants & Antiperspirants, Body & Massage Oil, Body Masks, Breast Care, Body Shaping Cream, Talcum Powder</option>
                                            <option value="10">Beauty & Personal Care Eye & Ear Care Contact Lens, Lens Solutions & Eyedrops, Earwax Removal Products, Contact Lens Conditioning Kits, Colored Contact Lens, Sleep Masks, Reading Glasses, Ear Plugs</option>
                                            <option value="10">Beauty & Personal Care Hand, Foot & Nail Care</option>
                                            <option value="10">Beauty & Personal Care Makeup</option>
                                            <option value="10">Beauty & Personal Care Men's Care</option>
                                            <option value="10">Beauty & Personal Care Nasal & Oral Care, Feminine Care</option>
                                            <option value="10">Beauty & Personal Care Perfume Unisex Perfume, Perfume Sets, Women's Perfume, Men's Perfume, Perfume</option>
                                            <option value="10">Beauty & Personal Care Personal Care Appliances</option>
                                            <option value="7.5">Health Food Supplements Beauty Supplement, Wellness Supplements, Fitness Supplements, Weight Management</option>
                                        </optgroup>
                                    
                                        <!-- Lifestyle -->
                                        <optgroup label="Lifestyle">
                                            <option value="10">Automotive & Motorcycle Auto Replacement Parts Wheels, Rims & Accessories</option>
                                            <option value="10">Automotive & Motorcycle Motorcycle Parts Lighting, Mirrors & Accessories, Shocks, Struts & Suspension, Sparkplug</option>
                                            <option value="10">Fashion Fashion Accessories Hair Extensions & Wigs</option>
                                            <option value="10">Home Improvement Solar & Wind Power</option>
                                            <option value="10">Home Supplies Home Organizers</option>
                                          <option value="10">Kitchenware  Barbecue Utensils, Cooking Utensils</option>
                                        </optgroup>
                                    
                                        <!--Lainnya-->
                                            <optgroup label="Lainnya">
                                                <option value="7.5">Virtual Products Physical Voucher</option>
                                                <option value="10">Wedding Accessories</option>
                                            </optgroup>
                                    @else
                                        <!-- Elektronik -->
                                        <optgroup label="Elektronik">
                                            <option value="2.50">Computers & Office Equipment Desktop & Laptop Components Sound Cards, Power Supply Units, Monitors, Fans & Heatsinks, UPS & Stabilizers, RAM, Processors, PC Cases, Optical Drives, Motherboards, Graphics Cards, TV Tuner & Video Capture Cards</option>
                                            <option value="4.00">Household Appliances Kitchen Appliances Juicers & Blenders, Electric Hot Pots, Fryers, Kitchen Appliance Parts, Rice & Pressure Cookers, Countertop Ovens, Mixers, Coffee Machines & Accessories, Toasters, Food Processors, Water Coolers & Dispensers, Vacuum Sealers, Induction Hobs, Electric Kettles, Electric Grills, Electric Steamers, Specialty Kitchen Appliances, Ice Makers, Bread Makers, Microwaves, Electric & Gas Stoves, Water Filters, Soda Makers, Food Waste Disposers</option>
                                        </optgroup>
                                    
                                        <!-- Fashion -->
                                        <optgroup label="Fashion">
                                            <option value="8.50">Muslim Fashion Fashion Hijabs, Women's Islamic Clothing Shirts & Blouses, Clothing Sets, Dresses, Gamis, Abayas, Tunics, Skirts, Outerwear, Culottes & Palazzo Pants, Kaftans, Jumpsuits, Family Clothing Sets, Robes, Couples' Clothing Sets, Leggings, Turtlenecks & Inners, Kids' Islamic Clothing, Islamic Accessories, Prayer Attire & Equipment</option>
                                            <option value="8.50">Sports & Outdoor Sports & Outdoor Clothing, Sports Footwear</option>
                                            <option value="8.50">Sports & Outdoor Swimwear, Surfwear & Wetsuits</option>
                                        </optgroup>
                                    
                                        <!-- FMCG -->
                                        <optgroup label="FMCG">
                                            <option value="4.00">Health Food Supplements Beauty Supplement, Wellness Supplements, Fitness Supplements, Weight Management</option>
                                            <option value="8.50">Beauty & Personal Care Makeup</option>
                                            <option value="8.50">Beauty & Personal Care Skincare Skin Care Kits, Serums & Essences, Moisturizers & Mists, Facial Sunscreen & Sun Care, Facial Cleansers, Face Masks, Acne Treatments, Toners, Lip Treatments, Face Scrubs & Peels, Eye Treatments</option>
                                        </optgroup>
                                    
                                        <!-- Lifestyle -->
                                        <optgroup label="Lifestyle">
                                            <option value="8.50">Automotive & Motorcycle Auto Replacement Parts Windshield Wipers & Washers, Wheels, Rims & Accessories, Shocks, Struts & Suspension</option>
                                            <option value="8.50">Automotive & Motorcycle Motorcycle Parts Lighting, Mirrors & Accessories, Shocks, Struts & Suspension, Sparkplug</option>
                                            <option value="8.50">Fashion Fashion Accessories Hair Extensions & Wigs</option>
                                            <option value="8.50">Home Improvement Solar & Wind Power</option>
                                            <option value="8.50">Home Supplies Home Organizers</option>
                                        </optgroup>
                                    
                                        <!-- Lainnya -->
                                        <optgroup label="Lainnya">
                                            <option value="1.00">Virtual Products Physical Voucher</option>
                                            <option value="8.50">Wedding Accessories</option>
                                        </optgroup>
                                    @endif
                                </select>
                                @if($selectedKategori == 10)
                                    <div class="text-sm text-blue-600 mt-1 ml-2">
                                        Semua subkategori produk dengan Tarif Komisi Marketplace sebesar <span class="font-bold">10,00%</span> akan menikmati diskon komisi sebesar <span class="font-bold">20%</span>. Dengan kata lain, Tarif Komisi efektifnya adalah <span class="font-bold">8,00%</span>.
                                    </div>
                                @endif
                            </div>
                            {{-- <div class="flex items-end">
                                <button class="px-4 py-2 bg-black dark:bg-white text-white dark:text-black rounded-lg hover:bg-gray-800 dark:hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-black dark:focus:ring-white focus:ring-opacity-50 transition" wire:click="$set('showModal', true)">
                                    Lihat Kategori Produk
                                </button>
                            </div> --}}
                        </div>

                        @if ($showModal)
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
                            <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-3xl p-0 overflow-hidden border border-gray-200 dark:border-gray-700">
                                <!-- Header -->
                                <div class="flex items-center justify-between px-6 py-4 bg-gradient-to-r from-black/90 via-gray-800/90 to-black/80 dark:from-white/10 dark:via-gray-800/80 dark:to-white/10 border-b border-gray-200 dark:border-gray-700">
                                    <h2 class="text-xl font-bold text-white dark:text-white flex items-center gap-2">
                                        @if ($tipePenjual === 'mall')
                                            Rincian Kategori Produk Penjual Mall
                                        @else
                                            Rincian Kategori Produk Penjual Non-Star & Star/Star+
                                        @endif
                                    </h2>
                                    <button class="ml-4 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold shadow transition" wire:click="$set('showModal', false)">
                                        Tutup
                                    </button>
                                </div>
                                <!-- Search -->
                                <div class="flex gap-2 px-6 py-4 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
                                    <input type="text" class="w-full p-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-black dark:focus:ring-white transition"
                                        placeholder="Cari kategori produk..." wire:model.defer="tempSearch">
                                    <button class="px-4 py-2 bg-black dark:bg-white text-white dark:text-black rounded-lg font-semibold hover:bg-gray-800 dark:hover:bg-gray-200 transition"
                                        wire:click="applySearch">
                                        Cari
                                    </button>
                                </div>
                                <!-- Table -->
                                <div class="max-h-[400px] overflow-y-auto px-6 py-4">
                                    <table class="w-full border-collapse text-sm">
                                        <thead>
                                            <tr class="bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-100">
                                                <th class="px-4 py-2 font-bold border-b border-gray-300 dark:border-gray-600 text-left">Kategori</th>
                                                <th class="px-4 py-2 font-bold border-b border-gray-300 dark:border-gray-600 text-left">Rincian</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse ($this->paginatedRows as $row)
                                                <tr class="hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                                                    <td class="px-2 py-1 border-b border-gray-100 dark:border-gray-800 font-semibold text-center">{{ $row['main_category'] }}</td>
                                                    <td class="px-2 py-1 border-b border-gray-100 dark:border-gray-800">
                                                        <span class="font-bold">{{ $row['subcategory'] }}</span>
                                                        <span class="text-gray-500 dark:text-gray-400">: {{ $row['description'] }}</span>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="2" class="text-center py-8 text-gray-400">Tidak ada kategori ditemukan.</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                <!-- Pagination -->
                                <div class="flex justify-between items-center px-6 py-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
                                    <button class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-100 rounded-lg font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition"
                                        wire:click="previousPage" @if ($page <= 1) disabled @endif>
                                        Previous
                                    </button>
                                    <span class="text-gray-700 dark:text-gray-100">Halaman {{ $page }} dari {{ $this->paginatedRows->lastPage() }}</span>
                                    <button class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-100 rounded-lg font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition"
                                        wire:click="nextPage" @if ($page >= $this->paginatedRows->lastPage()) disabled @endif>
                                        Next
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif
                    
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <div class="flex items-center mb-2">
                                    <input id="produk_pre_order" type="checkbox" wire:model="produk_pre_order" class="w-5 h-5 text-black dark:text-white bg-gray-100 dark:bg-gray-800 border-gray-300 dark:border-gray-600 rounded focus:ring-black dark:focus:ring-white focus:ring-2">
                                    <label for="produk_pre_order" class="ml-3 text-base font-bold text-gray-900 dark:text-gray-100">Produk Pre-order</label>
                                </div>
                            </div>
                            <div>
                                <div class="flex items-center mb-2">
                                    <input id="promo_xtra" type="checkbox" wire:model="promo_xtra" class="w-5 h-5 text-black dark:text-white bg-gray-100 dark:bg-gray-800 border-gray-300 dark:border-gray-600 rounded focus:ring-black dark:focus:ring-white focus:ring-2">
                                    <label for="promo_xtra" class="ml-3 text-base font-bold text-gray-900 dark:text-gray-100">Layanan Mall</label>
                                </div>
                                @if($promoXtraLimited)
                                    <div class="text-sm text-blue-600 mt-1 ml-8">
                                        Biaya Layanan Mall dibatasi maksimal Rp 50.000
                                    </div>
                                @endif
                            </div>
                        </div>
                        <hr class="my-4 border-t-2 border-gray-100 dark:border-gray-200 rounded">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                            <div>
                                <label for="komisi_affiliasi" class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Komisi Affiliasi (%)</label>
                                <div class="relative">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        id="komisi_affiliasi"
                                        wire:model="komisi_affiliasi"
                                        class="block w-full mt-1 pr-10 border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:border-black dark:focus:border-white focus:ring-opacity-50 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition"
                                        value="0"
                                        required
                                    >
                                    <span class="absolute right-3 top-2.5 text-gray-500 dark:text-gray-400 font-bold select-none">%</span>
                                </div>
                                @php
                                    $harga_jual_num = (float) preg_replace('/\D/', '', $harga_jual);
                                    $potongan_affiliasi = ($komisi_affiliasi ?? 0) / 100 * $harga_jual_num;
                                @endphp
                                @if($harga_jual_num > 0 && $komisi_affiliasi > 0)
                                    <div class="text-sm text-blue-600 mt-1 ml-2">
                                        Alokasi Affiliasi: <span class="font-bold">Rp {{ number_format($potongan_affiliasi, 0, ',', '.') }}</span> / produk
                                    </div>
                                @endif
                                @error('komisi_affiliasi') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label for="biaya_iklan" class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Biaya Iklan (%)</label>
                                <div class="relative">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        id="biaya_iklan"
                                        wire:model="biaya_iklan"
                                        class="block w-full mt-1 pr-10 border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:border-black dark:focus:border-white focus:ring-opacity-50 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition"
                                        value="0"
                                        required
                                    >
                                    <span class="absolute right-3 top-2.5 text-gray-500 dark:text-gray-400 font-bold select-none">%</span>
                                </div>
                                @php
                                    $potongan_iklan = ($biaya_iklan ?? 0) / 100 * $harga_jual_num;
                                @endphp
                                @if($harga_jual_num > 0 && $biaya_iklan > 0)
                                    <div class="text-sm text-blue-600 mt-1 ml-2">
                                        Alokasi Iklan: <span class="font-bold">Rp {{ number_format($potongan_iklan, 0, ',', '.') }}</span> / produk
                                    </div>
                                @endif
                                @error('biaya_iklan') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label for="biaya_operasional" class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Biaya Operasional Toko (%)</label>
                                <div class="relative">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        id="biaya_operasional"
                                        wire:model="biaya_operasional"
                                        class="block w-full mt-1 pr-10 border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:border-black dark:focus:border-white focus:ring-opacity-50 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition"
                                        value="0"
                                        required
                                    >
                                    <span class="absolute right-3 top-2.5 text-gray-500 dark:text-gray-400 font-bold select-none">%</span>
                                </div>
                                @php
                                    $potongan_operasional = ($biaya_operasional ?? 0) / 100 * $harga_jual_num;
                                @endphp
                                @if($harga_jual_num > 0 && $biaya_operasional > 0)
                                    <div class="text-sm text-blue-600 mt-1 ml-2">
                                        Alokasi Operasional: <span class="font-bold">Rp {{ number_format($potongan_operasional, 0, ',', '.') }}</span> / produk
                                    </div>
                                @endif
                                @error('biaya_operasional') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <hr class="my-4 border-t-2 border-gray-100 dark:border-gray-200 rounded">
                        <div>
                            <button type="submit"
                                class="w-full px-4 py-3 bg-black dark:bg-white text-white dark:text-black rounded-lg hover:bg-gray-800 dark:hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-black dark:focus:ring-white focus:ring-opacity-50 transition font-semibold text-lg"
                                @if ($this->q_kalkulator == 3 and auth()->user()->hasRole('registered')) onclick="event.preventDefault(); new FilamentNotification()
                                    .title('Hanya untuk User Premium dan Pro!')
                                    .danger()
                                    .body('Cuman seharga Rp 100.000 per bulan, kamu bisa akses fitur ini dan lainnya. Langsung upgrade sekarang!')
                                    .actions([
                                        new FilamentNotificationAction('Ya, Upgrade Sekarang')
                                            .button()
                                            .url('/settings/subscription')
                                            .openUrlInNewTab(),
                                        new FilamentNotificationAction('Nanti dulu')
                                            .color('gray'),
                                    ])
                                    .send()" @endif>
                                Hitung Margin
                            </button>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-8">
                            <div>
                                <label class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Keuntungan</label>
                                <div class="w-full py-3 px-4 rounded-lg bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 text-2xl font-extrabold text-center shadow border border-green-200 dark:border-green-700 select-all transition">
                                    {{ $keuntungan_rupiah }}
                                </div>
                            </div>
                            <div>
                                <label class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Persentase</label>
                                <div class="w-full py-3 px-4 rounded-lg bg-blue-50 dark:bg-blue-900 text-blue-700 dark:text-blue-200 text-2xl font-extrabold text-center shadow border border-blue-200 dark:border-blue-700 select-all transition">
                                    {{ $margin }}
                                </div>
                            </div>
                        </div>
                        <div class="text-sm italic text-red-500 mt-4 ml-2">
                            <strong><u>Catatan:</u></strong>
                            <ol class="list-decimal ml-6">
                                <li>Belum termasuk biaya Diskon, Promosi, Campaign dan Voucher toko.</li>
                                <li>Tiktok Shop berhak sewaktu-waktu mengubah, menambah, atau memodifikasi Syarat & Ketentuan tanpa pemberitahuan terlebih dahulu.</li>
                            </ol>
                        </div>
                    </div>
                </form>
            </x-app.container>
        </div> <!-- Akhir dari elemen root tunggal -->
    @endvolt
</x-layouts.app>
