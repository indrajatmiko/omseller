<?php
use function Laravel\Folio\{middleware, name};
use Livewire\Volt\Component;
use App\Models\ServiceFee;
use App\Models\ServiceFeeDetail; // Menggunakan model ServiceFeeDetail untuk rincian

middleware('auth');
name('kalkulator-margin-shopee');

new class extends Component {
    // Properti untuk input dan hasil
    public $harga_modal = 0;
    public $harga_jual = 0;
    public $margin = 0;
    public $keuntungan_rupiah = 0;

    // Properti untuk program layanan Shopee
    public $gratis_ongkir_xtra = false;
    public $promo_xtra = false;
    public $gratisOngkirXtraFee;
    public $promoXtraFee;
    public $gratisOngkirXtraLimited = false;
    public $promoXtraLimited = false;
    public $biayaProsesFee = 0;

    // Properti untuk biaya lainnya
    public $biaya_iklan = 0;
    public $biaya_operasional = 0;
    public $komisi_affiliasi = 0;

    // Properti untuk pemilihan kategori
    public $selectedKategori = 0;
    public $adminFees = [];
    public $tipePenjual = 'non_star';

    // Properti untuk modal dan pencarian
    public $search = '';
    public $showModal = false;

    public $q_kalkulator;

    /**
     * Inisialisasi komponen, memuat data awal dari database.
     */
    public function mount()
    {
        $this->loadAdminFees();
        $this->loadProgramFees();
    }

    /**
     * Memuat biaya admin (kategori) berdasarkan tipe penjual yang dipilih.
     */
    public function loadAdminFees()
    {
        $this->adminFees = ServiceFee::where('platform', 'shopee')
            ->where('fee_type', 'admin_fee')
            ->where('seller_type', $this->tipePenjual)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Memuat biaya program layanan seperti Gratis Ongkir Xtra.
     */
    public function loadProgramFees()
    {
        $programFees = ServiceFee::where('platform', 'shopee')
            ->where('fee_type', 'program_fee')
            ->where('is_active', true)
            ->get();
        
        $this->gratisOngkirXtraFee = $programFees->firstWhere('name', 'Gratis Ongkir Xtra');
        $this->promoXtraFee = $programFees->firstWhere('name', 'Promo Xtra');
        $this->biayaProsesFee = $programFees->firstWhere('name', 'Biaya Proses Pesanan');
    }

    /**
     * Dipicu saat properti $tipePenjual berubah.
     */
    public function updatedTipePenjual()
    {
        $this->selectedKategori = 0; // Reset pilihan kategori
        $this->loadAdminFees(); // Muat ulang kategori sesuai tipe penjual
    }

    /**
     * Computed property untuk mengambil dan memfilter rincian kategori untuk modal.
     */
    public function getCategoryDetailsProperty()
    {
        // Query ke tabel detail, bukan tabel induk
        return ServiceFeeDetail::with('serviceFee') // Eager load relasi untuk efisiensi
            ->whereHas('serviceFee', function ($query) {
                // Filter berdasarkan tipe penjual dari tabel induknya
                $query->where('platform', 'shopee')
                      ->where('seller_type', $this->tipePenjual);
            })
            ->where(function ($query) {
                // Terapkan filter pencarian pada beberapa kolom
                $query->where('subcategory_name', 'like', '%' . $this->search . '%')
                      ->orWhere('description', 'like', '%' . $this->search . '%')
                      ->orWhereHas('serviceFee', function ($q) {
                          // Juga cari berdasarkan nama kategori utama (cth: "Kategori A")
                          $q->where('name', 'like', '%' . $this->search . '%');
                      });
            })
            ->paginate(6); // Gunakan pagination bawaan Laravel
    }

    /**
     * Memformat input harga modal ke format Rupiah.
     */
    public function formatHargaModal()
    {
        $numericValue = preg_replace('/\D/', '', $this->harga_modal) ?: 0;
        $this->harga_modal = 'Rp ' . number_format($numericValue, 0, ',', '.');
    }

    /**
     * Memformat input harga jual ke format Rupiah.
     */
    public function formatHargaJual()
    {
        $numericValue = preg_replace('/\D/', '', $this->harga_jual) ?: 0;
        $this->harga_jual = 'Rp ' . number_format($numericValue, 0, ',', '.');
    }

    /**
     * Logika utama untuk menghitung margin keuntungan.
     */
    public function calculateMargin()
    {
        $harga_modal = (float) preg_replace('/\D/', '', $this->harga_modal);
        $harga_jual = (float) preg_replace('/\D/', '', $this->harga_jual);

        if ($harga_modal == 0 || $harga_jual == 0) {
            $this->margin = 0;
            $this->keuntungan_rupiah = 0;
            return;
        }

        // Biaya Admin Kategori
        $persentase_admin = (float) $this->selectedKategori;
        $potongan_admin = $harga_jual * ($persentase_admin / 100);

        // Biaya Program Layanan
        $biaya_tambahan = 0;
        $this->gratisOngkirXtraLimited = false;
        $this->promoXtraLimited = false;

        if ($this->gratis_ongkir_xtra && $this->gratisOngkirXtraFee) {
            $potongan = $harga_jual * ($this->gratisOngkirXtraFee->value / 100);
            if ($this->gratisOngkirXtraFee->max_cap && $potongan > $this->gratisOngkirXtraFee->max_cap) {
                $potongan = $this->gratisOngkirXtraFee->max_cap;
                $this->gratisOngkirXtraLimited = true;
            }
            $biaya_tambahan += $potongan;
        }

        if ($this->promo_xtra && $this->promoXtraFee) {
            $potongan = $harga_jual * ($this->promoXtraFee->value / 100);
            if ($this->promoXtraFee->max_cap && $potongan > $this->promoXtraFee->max_cap) {
                $potongan = $this->promoXtraFee->max_cap;
                $this->promoXtraLimited = true;
            }
            $biaya_tambahan += $potongan;
        }

        // Biaya Internal Toko
        $potongan_iklan = ($this->biaya_iklan / 100) * $harga_jual;
        $potongan_operasional = ($this->biaya_operasional / 100) * $harga_jual;
        $potongan_affiliasi = ($this->komisi_affiliasi / 100) * $harga_jual;
        $biaya_toko = $potongan_iklan + $potongan_operasional + $potongan_affiliasi;

        // Perhitungan Final
        $keuntungan_bersih = $harga_jual - $harga_modal - $potongan_admin - $biaya_tambahan - $this->biayaProsesFee->value - $biaya_toko;

        $this->margin = ($harga_jual > 0) ? ($keuntungan_bersih / $harga_jual) * 100 : 0;

        // Formatting Hasil
        $this->margin = number_format($this->margin, 2) . ' %';
        $this->keuntungan_rupiah = 'Rp ' . number_format($keuntungan_bersih, 0, ',', '.');
    }
};
?>

<x-layouts.app>
    @volt('kalkulator-margin-shopee')
        <div wire:ignore.self>
            <x-app.container>
                <div class="flex items-center justify-between mb-4">
                    <x-app.heading title="Kalkulator Margin Shopee"
                        description="Kalkulator untuk menghitung margin keuntungan produk yang akan dijual di Shopee. Data diperbarui secara berkala."
                        :border="true" />
                </div>

                <form class="space-y-4 mt-6" wire:submit.prevent="calculateMargin">
                    <div class="bg-gradient-to-br from-gray-50 via-white to-gray-100 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 rounded-2xl shadow-md p-8 mb-8 border border-gray-200 dark:border-gray-700 transition-all duration-300">

                        {{-- Input Harga Modal & Jual --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label for="harga_modal" class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Harga Modal</label>
                                <input type="text" id="harga_modal" wire:model="harga_modal" wire:blur="formatHargaModal" class="block w-full mt-1 border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:border-black dark:focus:border-white focus:ring-opacity-50 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition">
                            </div>
                            <div>
                                <label for="harga_jual" class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Harga Jual</label>
                                <input type="text" id="harga_jual" wire:model="harga_jual" wire:blur="formatHargaJual" class="block w-full mt-1 border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:border-black dark:focus:border-white focus:ring-opacity-50 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition">
                            </div>
                        </div>
                        <hr class="my-4 border-t-2 border-gray-100 dark:border-gray-200 rounded">
                        
                        {{-- Pilihan Tipe Penjual --}}
                        <div class="mb-4">
                            <label class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Tipe Penjual</label>
                            <div class="flex flex-wrap gap-6">
                                <label class="flex items-center space-x-2 cursor-pointer">
                                    <input type="radio" value="non_star" wire:model.live="tipePenjual" class="form-radio text-black dark:text-white focus:ring-black dark:focus:ring-white">
                                    <span class="text-base text-gray-700 dark:text-gray-200">Penjual Non Star & Star Seller</span>
                                </label>
                                <label class="flex items-center space-x-2 cursor-pointer">
                                    <input type="radio" value="mall" wire:model.live="tipePenjual" class="form-radio text-black dark:text-white focus:ring-black dark:focus:ring-white"
                                        @if(auth()->user()->hasRole('registered') OR auth()->user()->hasRole('basic'))
                                            onclick="event.preventDefault(); new FilamentNotification().title('Hanya untuk User Premium dan Pro!').danger().body('Fitur ini memerlukan upgrade akun. Upgrade sekarang untuk akses penuh!').actions([new FilamentNotificationAction('Ya, Upgrade Sekarang').button().url('/settings/subscription').openUrlInNewTab(),new FilamentNotificationAction('Nanti dulu').color('gray'),]).send()"
                                        @endif
                                    >
                                    <span class="text-base text-gray-700 dark:text-gray-200">Penjual Mall</span>
                                </label>
                            </div>
                        </div>

                        {{-- Dropdown Kategori & Tombol Lihat Rincian --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label for="kategori-select" class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Kategori Produk</label>
                                <select id="kategori-select" wire:model="selectedKategori" class="block w-full mt-1 border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:border-black dark:focus:border-white focus:ring-opacity-50 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition">
                                    <option value="0">Pilih Kategori Produk</option>
                                    @foreach($adminFees as $fee)
                                        <option value="{{ $fee->value }}">{{ $fee->name }} ({{ $fee->value }}%)</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button type="button" class="px-4 py-2 bg-black dark:bg-white text-white dark:text-black rounded-lg hover:bg-gray-800 dark:hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-black dark:focus:ring-white focus:ring-opacity-50 transition" wire:click="$set('showModal', true)">
                                    Lihat Rincian Kategori
                                </button>
                            </div>
                        </div>

                        {{-- Modal untuk Menampilkan Rincian Kategori --}}
                        @if ($showModal)
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" x-data="{ show: @entangle('showModal') }" x-show="show" x-on:keydown.escape.window="show = false">
                            <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-6xl p-0 overflow-hidden border border-gray-200 dark:border-gray-700 flex flex-col" @click.away="show = false">
                                {{-- Header Modal --}}
                                <div class="flex items-center justify-between px-6 py-4 bg-gradient-to-r from-black/90 to-gray-800/90 dark:from-gray-800 dark:to-gray-900 border-b border-gray-700">
                                    <h2 class="text-xl font-bold text-white">
                                        Rincian Kategori Produk ({{ $tipePenjual === 'mall' ? 'Mall' : 'Non-Star & Star' }})
                                    </h2>
                                    <button class="ml-4 text-gray-300 hover:text-white" @click="show = false">&times;</button>
                                </div>
                                {{-- Search Bar Modal --}}
                                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700">
                                    <input type="text" class="w-full p-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-black dark:focus:ring-white transition"
                                        placeholder="Cari berdasarkan Kategori (A, B), Sub Kategori, atau Deskripsi..." wire:model.live.debounce.300ms="search">
                                </div>
                                {{-- Tabel Konten Modal --}}
                                <div class="flex-grow max-h-[60vh] overflow-y-auto">
                                    <table class="w-full border-collapse text-sm min-w-full">
                                        <thead class="sticky top-0 bg-gray-200 dark:bg-gray-700 z-10">
                                            <tr>
                                                <th class="px-4 py-3 font-bold text-gray-700 dark:text-gray-100 border-b border-gray-300 dark:border-gray-600 text-left w-24">Kategori</th>
                                                <th class="px-4 py-3 font-bold text-gray-700 dark:text-gray-100 border-b border-gray-300 dark:border-gray-600 text-left w-1/4">Sub Kategori</th>
                                                <th class="px-4 py-3 font-bold text-gray-700 dark:text-gray-100 border-b border-gray-300 dark:border-gray-600 text-left">Deskripsi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse ($this->categoryDetails as $detail)
                                                <tr class="hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                                                    <td class="px-4 py-2 border-b border-gray-200 dark:border-gray-800 font-semibold align-top text-center">
                                                        {{ str_replace('Kategori ', '', $detail->serviceFee->name) }}
                                                        <span class="block text-xs text-gray-500">({{ number_format($detail->serviceFee->value, 2, ',', '.') }}%)</span>
                                                    </td>
                                                    <td class="px-4 py-2 border-b border-gray-200 dark:border-gray-800 font-semibold align-top">{{ $detail->subcategory_name }}</td>
                                                    <td class="px-4 py-2 border-b border-gray-200 dark:border-gray-800 text-gray-600 dark:text-gray-400 align-top">{{ $detail->description }}</td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="3" class="text-center py-8 text-gray-400">Tidak ada rincian kategori ditemukan.</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                {{-- Footer Modal (Pagination) --}}
                                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-200 dark:border-gray-700">
                                    {{ $this->categoryDetails->links() }}
                                </div>
                            </div>
                        </div>
                        @endif
                    
                        {{-- Program Layanan --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <div class="flex items-center mb-2">
                                    <input id="gratis_ongkir_xtra" type="checkbox" wire:model="gratis_ongkir_xtra" class="w-5 h-5 text-black dark:text-white bg-gray-100 dark:bg-gray-800 border-gray-300 dark:border-gray-600 rounded focus:ring-black dark:focus:ring-white focus:ring-2">
                                    <label for="gratis_ongkir_xtra" class="ml-3 text-base font-bold text-gray-900 dark:text-gray-100">Gratis Ongkir Xtra</label>
                                </div>
                                @if($gratisOngkirXtraLimited)
                                    <div class="text-sm text-blue-600 mt-1 ml-8">
                                        Potongan dibatasi maks. Rp {{ number_format($gratisOngkirXtraFee->max_cap ?? 10000, 0, ',', '.') }}
                                    </div>
                                @endif
                            </div>
                            <div>
                                <div class="flex items-center mb-2">
                                    <input id="promo_xtra" type="checkbox" wire:model="promo_xtra" class="w-5 h-5 text-black dark:text-white bg-gray-100 dark:bg-gray-800 border-gray-300 dark:border-gray-600 rounded focus:ring-black dark:focus:ring-white focus:ring-2">
                                    <label for="promo_xtra" class="ml-3 text-base font-bold text-gray-900 dark:text-gray-100">Promo Xtra</label>
                                </div>
                                @if($promoXtraLimited)
                                    <div class="text-sm text-blue-600 mt-1 ml-8">
                                        Potongan dibatasi maks. Rp {{ number_format($promoXtraFee->max_cap ?? 10000, 0, ',', '.') }}
                                    </div>
                                @endif
                            </div>
                        </div>
                        <hr class="my-4 border-t-2 border-gray-100 dark:border-gray-200 rounded">

                        {{-- DIUBAH: Bagian ini sekarang menyertakan perhitungan alokasi biaya --}}
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
                                        wire:model.live="komisi_affiliasi"
                                        class="block w-full mt-1 pr-10 border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:border-black dark:focus:border-white focus:ring-opacity-50 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition"
                                        value="0"
                                        required
                                    >
                                    <span class="absolute right-3 top-2.5 text-gray-500 dark:text-gray-400 font-bold select-none"></span>
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
                                        wire:model.live="biaya_iklan"
                                        class="block w-full mt-1 pr-10 border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:border-black dark:focus:border-white focus:ring-opacity-50 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition"
                                        value="0"
                                        required
                                    >
                                    <span class="absolute right-3 top-2.5 text-gray-500 dark:text-gray-400 font-bold select-none"></span>
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
                                <label for="biaya_operasional" class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Biaya Operasional (%)</label>
                                <div class="relative">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        id="biaya_operasional"
                                        wire:model.live="biaya_operasional"
                                        class="block w-full mt-1 pr-10 border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:border-black dark:focus:border-white focus:ring-opacity-50 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition"
                                        value="0"
                                        required
                                    >
                                    <span class="absolute right-3 top-2.5 text-gray-500 dark:text-gray-400 font-bold select-none"></span>
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
                        
                        {{-- Tombol Hitung & Hasil --}}
                        <div>
                            <button type="submit" class="w-full px-4 py-3 bg-black dark:bg-white text-white dark:text-black rounded-lg hover:bg-gray-800 dark:hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-black dark:focus:ring-white focus:ring-opacity-50 transition font-semibold text-lg">
                                Hitung Margin
                            </button>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-8">
                            <div>
                                <label class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Keuntungan (Rupiah)</label>
                                <div class="w-full py-3 px-4 rounded-lg bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 text-2xl font-extrabold text-center shadow border border-green-200 dark:border-green-700 select-all transition">
                                    {{ $keuntungan_rupiah }}
                                </div>
                            </div>
                            <div>
                                <label class="block mb-2 text-base font-bold text-gray-800 dark:text-gray-100">Margin Keuntungan (%)</label>
                                <div class="w-full py-3 px-4 rounded-lg bg-blue-50 dark:bg-blue-900 text-blue-700 dark:text-blue-200 text-2xl font-extrabold text-center shadow border border-blue-200 dark:border-blue-700 select-all transition">
                                    {{ $margin }}
                                </div>
                            </div>
                        </div>
                        <div class="text-sm italic text-red-500 mt-4 ml-2">
                            <strong><u>Catatan:</u></strong>
                            <ol class="list-decimal ml-6">
                                <li>Perhitungan belum termasuk biaya Diskon, Promosi, Campaign, dan Voucher Toko.</li>
                                <li>Shopee berhak sewaktu-waktu mengubah Syarat & Ketentuan tanpa pemberitahuan terlebih dahulu.</li>
                            </ol>
                        </div>
                    </div>
                </form>
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>