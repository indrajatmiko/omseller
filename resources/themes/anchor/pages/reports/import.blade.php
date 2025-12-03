<?php

use function Laravel\Folio\{middleware, name};
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPaymentDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

middleware('auth');
name('orders.import');

new class extends Component {
    use WithFileUploads;

    public $file;
    public $marketplace = 'shopee';
    public $importSummary = [];
    public $isFileValid = false;

    public function updatedFile()
    {
        $this->resetErrorBag();
        $this->isFileValid = false;

        try {
            $filename = $this->file->getClientOriginalName();
            $this->validateFilename($filename);
            $this->isFileValid = true;
        } catch (\Exception $e) {
            $this->addError('file', $e->getMessage());
        }
    }

    public function import()
    {
        $this->validate([
            'file' => 'required|file|max:10240', 
            'marketplace' => 'required|in:shopee,tiktok',
        ]);

        $this->importSummary = ['success' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            $filename = $this->file->getClientOriginalName();
            $this->validateFilename($filename);

            $path = $this->file->getRealPath();
            
            // Baca Excel
            $spreadsheet = IOFactory::load($path);
            $worksheet = $spreadsheet->getActiveSheet();
            
            // toArray(null, true, true, true) -> Parameter terakhir 'true' artinya
            // kita mengambil data yang SUDAH DIFORMAT (String "138.000"), bukan raw number.
            $rawRows = $worksheet->toArray(null, true, true, true); 
            
            $rows = [];
            foreach ($rawRows as $row) {
                $rows[] = array_values($row);
            }

            if (count($rows) < 2) {
                throw new \Exception("File Excel kosong atau tidak memiliki data.");
            }

            // Mapping Header
            $headerRaw = $rows[0];
            $headers = array_map(function($h) {
                return Str::slug($h, '_'); 
            }, $headerRaw);

            // Ubah ke Associative Array
            $sheetData = [];
            for ($i = 1; $i < count($rows); $i++) {
                $currentRow = $rows[$i];
                if (implode('', $currentRow) === '') continue;

                if (count($currentRow) < count($headers)) {
                    $currentRow = array_pad($currentRow, count($headers), null);
                }
                if (count($currentRow) > count($headers)) {
                    $currentRow = array_slice($currentRow, 0, count($headers));
                }

                $sheetData[] = array_combine($headers, $currentRow);
            }

            // Proses Import
            if ($this->marketplace === 'shopee') {
                $this->processShopeeImport($sheetData);
            } elseif ($this->marketplace === 'tiktok') {
                throw new \Exception("Import TikTok belum diimplementasikan.");
            }

            $this->dispatch('notify', title: 'Import Selesai', message: "Berhasil: {$this->importSummary['success']}, Skipped: {$this->importSummary['skipped']}");
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('file', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Import Error: ' . $e->getMessage());
            $msg = $e->getMessage();
            if (str_contains($msg, 'ZipArchive::getFromName')) {
                $msg = "File Excel rusak atau terproteksi password.";
            }
            $this->addError('file', 'Error: ' . $msg);
            $this->importSummary['errors']++;
        } finally {
            $this->reset(['file', 'isFileValid']);
        }
    }

    private function validateFilename(string $filename)
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'xls'])) {
            throw new \Exception("Format file harus .xlsx atau .xls");
        }

        if ($this->marketplace === 'shopee') {
            $isValid = Str::startsWith($filename, 'Order.shipping.') || 
                       Str::startsWith($filename, 'Order.completed.');
            
            if (!$isValid) {
                throw new \Exception("Nama file Shopee harus berawalan 'Order.shipping.' atau 'Order.completed.'.");
            }
        } 
    }

    private function processShopeeImport(array $data)
    {
        $groupedOrders = collect($data)->groupBy('no_pesanan');

        DB::transaction(function () use ($groupedOrders) {
            $userId = auth()->id();

            foreach ($groupedOrders as $orderSn => $rows) {
                if (empty($orderSn)) continue;

                $exists = Order::where('user_id', $userId)
                    ->where('shopee_order_id', $orderSn)
                    ->exists();

                if ($exists) {
                    $this->importSummary['skipped']++;
                    continue;
                }

                $firstRow = $rows->first();
                $val = fn($key) => $firstRow[$key] ?? null;

                $orderCreatedAt = $this->parseDate($val('waktu_pesanan_dibuat'));
                $shippingArrangedAt = $this->parseDate($val('waktu_pengiriman_diatur'));
                
                $order = Order::create([
                    'user_id' => $userId,
                    'channel' => 'shopee',
                    'shopee_order_id' => $orderSn,
                    'order_sn' => $orderSn,
                    'order_date' => $orderCreatedAt,
                    'created_at' => $orderCreatedAt,
                    'updated_at' => $shippingArrangedAt,
                    'scraped_at' => $shippingArrangedAt,
                    'buyer_username' => $val('username_pembeli'),
                    'buyer_name' => $val('nama_penerima'),
                    'order_status' => $val('status_pesanan'),
                    'tracking_number' => $val('no_resi'),
                    'shipping_provider' => $val('opsi_pengiriman'),
                    'payment_method' => $val('metode_pembayaran'),
                    'total_price' => $this->parseCurrency($val('total_pembayaran')),
                    'final_income' => 0,
                    'address_full' => ($val('alamat_pengiriman') ?? '') . ', ' . ($val('kotakabupaten') ?? '') . ', ' . ($val('provinsi') ?? ''),
                    'shipping_cost' => $this->parseCurrency($val('perkiraan_ongkos_kirim')),
                ]);

                DB::table('order_status_histories')->insert([
                    'order_id' => $order->id,
                    'status' => 'Sudah Kirim',
                    'description' => 'Pesanan sedang dikirimkan ke Pembeli.',
                    'pickup_time' => $shippingArrangedAt,
                    'scrape_time' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($rows as $row) {
                    $sku = !empty($row['nomor_referensi_sku']) ? $row['nomor_referensi_sku'] : ($row['sku_induk'] ?? null);
                    
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_name' => ($row['nama_produk'] ?? '') . ' - ' . ($row['nama_variasi'] ?? ''),
                        'variant_sku' => $sku,
                        'price' => $this->parseCurrency($row['harga_setelah_diskon'] ?? 0),
                        'quantity' => (int) ($row['jumlah'] ?? 1),
                        'subtotal' => $this->parseCurrency($row['total_harga_produk'] ?? 0),
                    ]);
                }

                OrderPaymentDetail::create([
                    'order_id' => $order->id,
                    'product_subtotal' => $rows->sum(fn($r) => $this->parseCurrency($r['total_harga_produk'] ?? 0)),
                    'shipping_fee_paid_by_buyer' => $this->parseCurrency($val('ongkos_kirim_dibayar_oleh_pembeli')),
                    'shipping_fee_estimate' => $this->parseCurrency($val('estimasi_potongan_biaya_pengiriman')),
                    'admin_fee' => 0,
                    'service_fee' => 0,
                    'ams_commission_fee' => 0,
                    'seller_voucher' => $this->parseCurrency($val('voucher_ditanggung_penjual')),
                    'shop_voucher' => $this->parseCurrency($val('voucher_ditanggung_penjual')),
                    'total_income' => 0,
                ]);

                $this->importSummary['success']++;
            }
        });
    }

    private function parseCurrency($value)
    {
        // PERBAIKAN: Hapus cek is_numeric agar string "138.000" tidak dianggap float 138.0
        if (empty($value)) return 0;

        // Pastikan jadi string
        $value = (string) $value;

        // 1. Hapus 'Rp' dan spasi
        $clean = str_replace(['Rp', ' '], '', $value);
        
        // 2. Hapus titik (.) sebagai pemisah ribuan (Format Indo: 138.000 -> 138000)
        $clean = str_replace('.', '', $clean);
        
        // 3. Ubah koma (,) menjadi titik (.) sebagai desimal (Format Indo: 100,50 -> 100.50)
        $clean = str_replace(',', '.', $clean);

        return (float) $clean;
    }

    private function parseDate($dateValue)
    {
        if (empty($dateValue)) return now();
        try {
            if (is_numeric($dateValue)) {
                return Date::excelToDateTimeObject($dateValue)->format('Y-m-d H:i:s');
            }
            return Carbon::parse($dateValue)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return now();
        }
    }
}; ?>

<x-layouts.app>
    @volt('orders-import')
    <x-app.container>
        <x-app.heading 
            title="Import Pesanan" 
            description="Upload file laporan pesanan dari Marketplace (Excel .xlsx)." 
        />

        <div class="mt-6 max-w-xl">
            <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-6 border border-gray-200 dark:border-gray-700">
                
                <form wire:submit="import" class="space-y-5">
                    
                    {{-- Pilihan Marketplace --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Marketplace</label>
                        <select wire:model="marketplace" class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="shopee">Shopee</option>
                            <option value="tiktok">TikTok Shop (Coming Soon)</option>
                        </select>
                    </div>

                    {{-- Upload File --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            File Laporan (.xlsx)
                        </label>
                        
                        <div class="flex items-center justify-center w-full">
                            <label for="dropzone-file" class="flex flex-col items-center justify-center w-full h-36 border-2 border-gray-300 border-dashed rounded-lg cursor-pointer bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-700 hover:bg-gray-100 transition relative overflow-hidden">
                                
                                {{-- State: Default (Belum ada file) --}}
                                <div class="flex flex-col items-center justify-center pt-5 pb-6" wire:loading.remove wire:target="file">
                                    @if($file)
                                        <div class="text-center">
                                            <p class="mb-1 text-sm text-green-600 dark:text-green-400 font-semibold">
                                                {{ $file->getClientOriginalName() }}
                                            </p>
                                            <p class="text-xs text-gray-500">{{ number_format($file->getSize() / 1024, 2) }} KB</p>
                                        </div>
                                    @else
                                        <svg class="w-8 h-8 mb-3 text-gray-500 dark:text-gray-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 16">
                                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 13h3a3 3 0 0 0 0-6h-.025A5.56 5.56 0 0 0 16 6.5 5.5 5.5 0 0 0 5.207 5.021C5.137 5.017 5.071 5 5 5a4 4 0 0 0 0 8h2.167M10 15V6m0 0L8 8m2-2 2 2"/>
                                        </svg>
                                        <p class="mb-1 text-sm text-gray-500 dark:text-gray-400"><span class="font-semibold">Klik untuk upload</span> atau drag and drop</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Format .xlsx (Excel)</p>
                                    @endif
                                </div>

                                {{-- State: Sedang Upload/Validasi File --}}
                                <div class="absolute inset-0 flex flex-col items-center justify-center bg-gray-50 dark:bg-gray-700 z-10" wire:loading.flex wire:target="file">
                                    <svg class="animate-spin h-8 w-8 text-blue-500 mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span class="text-sm text-gray-600 dark:text-gray-300 font-medium">Mengupload & Memvalidasi...</span>
                                </div>

                                <input id="dropzone-file" type="file" wire:model="file" class="hidden" accept=".xlsx, .xls" />
                            </label>
                        </div>
                        
                        @error('file') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                        @error('marketplace') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                        
                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400 bg-blue-50 dark:bg-blue-900/20 p-2 rounded border border-blue-100 dark:border-blue-800">
                            <strong>Format nama file {{ Str::title($marketplace) }}:</strong><br>
                            @if($marketplace == 'shopee')
                                • <code>Order.shipping.xxxxxxxx.xlsx</code><br>
                                • <code>Order.completed.xxxxxxxx.xlsx</code>
                            @else
                                • Menunggu format TikTok
                            @endif
                        </div>
                    </div>

                    {{-- Progress Bar (Muncul saat Import berjalan) --}}
                    <div wire:loading wire:target="import" class="w-full">
                        <div class="flex justify-between mb-1">
                            <span class="text-sm font-medium text-blue-700 dark:text-blue-400">Memproses Data...</span>
                            <span class="text-sm font-medium text-blue-700 dark:text-blue-400">Mohon tunggu</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700 overflow-hidden">
                            <div class="bg-blue-600 h-2.5 rounded-full animate-progress-indeterminate"></div>
                        </div>
                        <style>
                            @keyframes progress-indeterminate {
                                0% { width: 0%; margin-left: 0%; }
                                50% { width: 70%; margin-left: 30%; }
                                100% { width: 0%; margin-left: 100%; }
                            }
                            .animate-progress-indeterminate {
                                animation: progress-indeterminate 1.5s infinite ease-in-out;
                            }
                        </style>
                    </div>

                    <div class="flex justify-end">
                        {{-- Tombol Import --}}
                        <x-button type="submit" class="w-full sm:w-auto" 
                            wire:loading.attr="disabled" 
                            wire:target="import, file"
                            :disabled="!$isFileValid">
                            
                            {{-- State 1: Sedang Upload File --}}
                            <span wire:loading wire:target="file">Validasi File...</span>

                            {{-- State 2: Sedang Import Data (PERBAIKAN: Hapus SVG manual) --}}
                            <span wire:loading wire:target="import">
                                Memproses...
                            </span>

                            {{-- State 3: Standby --}}
                            <span wire:loading.remove wire:target="import, file">Import Data</span>
                        </x-button>
                    </div>
                </form>

                @if(!empty($importSummary))
                    <div class="mt-6 p-4 rounded-md bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600">
                        <h5 class="font-semibold text-gray-900 dark:text-white mb-3">Ringkasan Import</h5>
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div class="bg-green-100 dark:bg-green-900/30 p-2 rounded">
                                <div class="text-xl font-bold text-green-600 dark:text-green-400">{{ $importSummary['success'] }}</div>
                                <div class="text-xs text-green-800 dark:text-green-300">Berhasil</div>
                            </div>
                            <div class="bg-yellow-100 dark:bg-yellow-900/30 p-2 rounded">
                                <div class="text-xl font-bold text-yellow-600 dark:text-yellow-400">{{ $importSummary['skipped'] }}</div>
                                <div class="text-xs text-yellow-800 dark:text-yellow-300">Dilewati (Ada)</div>
                            </div>
                            <div class="bg-red-100 dark:bg-red-900/30 p-2 rounded">
                                <div class="text-xl font-bold text-red-600 dark:text-red-400">{{ $importSummary['errors'] }}</div>
                                <div class="text-xs text-red-800 dark:text-red-300">Gagal</div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </x-app.container>
    @endvolt
</x-layouts.app>