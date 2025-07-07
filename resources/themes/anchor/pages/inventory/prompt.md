Tentu. Berdasarkan arsitektur yang telah kita sepakati, berikut adalah rencana implementasi detail untuk keempat fitur manajemen inventaris, yang dirancang khusus untuk struktur model dan SaaS Anda.

Rencana ini akan memandu Anda dari perubahan database hingga implementasi di frontend menggunakan Livewire.

Fitur 1: Manajemen Produk (Stok Awal & Harga Modal)

Tujuan fitur ini adalah memberikan antarmuka bagi pengguna untuk mengelola data inti setiap SKU: stok fisik gudang dan harga modalnya. Ini adalah fondasi dari seluruh sistem inventaris.

Tahap 1: Perubahan Database (Migrations)

Modifikasi Tabel product_variants:

Buat file migrasi baru: php artisan make:migration add_inventory_fields_to_product_variants_table --table=product_variants

Isi migrasi:

Generated php
Schema::table('product_variants', function (Blueprint $table) {
    // Harga beli produk dari supplier
    $table->decimal('cost_price', 15, 2)->nullable()->after('promo_price');
    // Stok fisik aktual di gudang
    $table->integer('warehouse_stock')->default(0)->after('cost_price');
    // Stok yang sudah dipesan tapi belum dikirim
    $table->integer('reserved_stock')->default(0)->after('warehouse_stock');
});


Buat Tabel stock_movements (Ledger/Buku Besar Stok):

Buat file migrasi: php artisan make:migration create_stock_movements_table

Isi migrasi:

Generated php
Schema::create('stock_movements', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('product_variant_id')->constrained()->onDelete('cascade');
    $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
    
    // Jenis pergerakan: masuk, keluar, penyesuaian, retur, dll.
    $table->string('type'); 
    
    // Jumlah barang. Positif untuk masuk, negatif untuk keluar.
    $table->integer('quantity'); 
    
    $table->text('notes')->nullable(); // Catatan untuk penyesuaian manual
    $table->timestamps();
});
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END
Tahap 2: Pembaruan Model (Eloquent)

Buat Model StockMovement.php:

Buat file model: php artisan make:model StockMovement

Isi model:

Generated php
class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'product_variant_id', 'order_id', 
        'type', 'quantity', 'notes'
    ];

    public function productVariant() {
        return $this->belongsTo(ProductVariant::class);
    }
    
    public function user() {
        return $this->belongsTo(User::class);
    }

    public function order() {
        return $this->belongsTo(Order::class);
    }
}
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END

Update Model ProductVariant.php:

Tambahkan kolom baru ke properti $fillable.

Tambahkan relasi ke StockMovement.

Tambahkan accessor untuk kemudahan.

Generated php
// in App\Models\ProductVariant.php
protected $fillable = [
    // ...kolom lama...
    'cost_price',
    'warehouse_stock',
    'reserved_stock',
];

public function stockMovements()
{
    return $this->hasMany(StockMovement::class);
}

// Accessor untuk mendapatkan stok yang benar-benar bisa dijual
public function getAvailableStockAttribute(): int
{
    return $this->warehouse_stock - $this->reserved_stock;
}
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END
Tahap 3: Implementasi Frontend (Livewire)

Buat Halaman Manajemen Produk:

Buat file Livewire/Folio baru, misalnya di resources/views/pages/inventory/products.blade.php.

Komponen ini akan menampilkan daftar produk milik auth()->user().

Logika Komponen Livewire:

Data: Ambil semua Product dengan relasi variants milik pengguna: Product::where('user_id', auth()->id())->with('variants')->get().

Tampilan: Buat tabel atau daftar produk. Setiap produk bisa di-klik untuk menampilkan varian-variannya (collapsible/accordion).

Input: Di setiap baris varian, sediakan dua input field yang terhubung dengan wire:model:

Input untuk cost_price (Harga Modal).

Input untuk warehouse_stock (Stok Gudang).

Aksi save(): Saat pengguna mengubah cost_price, wire:blur bisa langsung menyimpannya. Saat warehouse_stock diubah, method save harus melakukan lebih dari sekadar update:

Ia harus menghitung selisih antara stok lama dan baru.

Membuat entri di StockMovement dengan type = 'adjustment' dan quantity = selisih.

Baru kemudian mengupdate warehouse_stock di tabel product_variants.

Ini menciptakan jejak audit yang jelas.

Alur Kerja Pengguna:

Pengguna membuka halaman "Manajemen Produk".

Ia melihat daftar produknya.

Ia membuka detail varian untuk produk "Baju Polos".

Ia mengisi harga modal untuk varian "Merah, L" sebesar 50000.

Ia mengisi stok gudang awal sebesar 100.

Di balik layar, sistem membuat record StockMovement dengan type = 'adjustment', quantity = 100.

Fitur 2 & 3: Pengurangan Stok Otomatis & Laporan Harian

Ini adalah jantung dari otomatisasi. Kita akan menggunakan Tugas Terjadwal (Cron Job) untuk keandalan maksimum dan membuat halaman laporan sederhana.

Tahap 1: Perubahan Database & Model

Modifikasi Tabel orders:

Buat migrasi: php artisan make:migration add_stock_deducted_flag_to_orders_table --table=orders

Isi migrasi:

Generated php
Schema::table('orders', function (Blueprint $table) {
    $table->boolean('is_stock_deducted')->default(false)->after('final_income');
});
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END

Update Model Order.php:

Tambahkan is_stock_deducted ke $fillable.

Tahap 2: Backend Logic (Artisan Command)

Buat Command: php artisan make:command ProcessPickedUpOrders

Isi Logika Command (app/Console/Commands/ProcessPickedUpOrders.php):

Generated php
// ...
public function handle()
{
    // Ambil semua order yang sudah di-pickup tapi stoknya belum dikurangi.
    $ordersToProcess = Order::where('is_stock_deducted', false)
        ->whereHas('statusHistories', function ($query) {
            $query->whereNotNull('pickup_time');
        })
        ->with('items') // Eager load items untuk efisiensi
        ->get();

    foreach ($ordersToProcess as $order) {
        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                // Cari variant berdasarkan SKU
                $variant = ProductVariant::where('variant_sku', $item->variant_sku)
                                         ->whereHas('product', fn($q) => $q->where('user_id', $order->user_id))
                                         ->first();

                if ($variant) {
                    $quantityToDeduct = $item->quantity;
                    
                    // Kurangi stok gudang
                    $variant->decrement('warehouse_stock', $quantityToDeduct);
                    
                    // (Opsional, jika pakai reserved_stock) Kurangi juga stok cadangan
                    // $variant->decrement('reserved_stock', $quantityToDeduct);

                    // Buat catatan di ledger
                    StockMovement::create([
                        'user_id' => $order->user_id,
                        'product_variant_id' => $variant->id,
                        'order_id' => $order->id,
                        'type' => 'sale',
                        'quantity' => -$quantityToDeduct, // Gunakan nilai negatif
                        'notes' => 'Pengurangan otomatis dari Order SN: ' . $order->order_sn,
                    ]);
                }
            }
            // Tandai order ini sudah diproses
            $order->update(['is_stock_deducted' => true]);
        });
        $this->info("Processed Order SN: {$order->order_sn}");
    }
    $this->info('All picked-up orders have been processed.');
}
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END

Jadwalkan Command (app/Console/Kernel.php):

Generated php
protected function schedule(Schedule $schedule)
{
    // ...
    $schedule->command('app:process-picked-up-orders')->everyFiveMinutes();
}
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END
Tahap 3: Halaman Laporan Barang Keluar Harian

Buat Halaman Laporan:

Buat file Livewire/Folio baru, misalnya di resources/views/pages/reports/daily-shipments.blade.php.

Logika Komponen Livewire:

Properti: public $reportDate; (inisialisasi dengan tanggal hari ini).

Data: Di method with(), ambil data dari StockMovement.

Generated php
$shipments = StockMovement::where('user_id', auth()->id())
    ->where('type', 'sale')
    ->whereDate('created_at', $this->reportDate)
    ->with(['productVariant.product', 'order']) // Eager load untuk info lengkap
    ->get();
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END

Tampilan:

Sediakan date picker yang terhubung ke wire:model.live="reportDate".

Tampilkan data shipments dalam tabel dengan kolom: "Waktu", "Nama Produk", "SKU Varian", "Jumlah Keluar", "No. Pesanan".

Alur Kerja Otomatis:

Ekstensi Chrome mengisi pickup_time untuk sebuah order.

Cron job berjalan setiap 5 menit.

Command ProcessPickedUpOrders menemukan order tersebut.

Stok di product_variants berkurang, dan StockMovement tercatat.

Order ditandai is_stock_deducted = true.

Pengguna membuka halaman "Laporan Barang Keluar" dan langsung melihat data SKU yang baru saja dikirim.

Fitur 4: Sistem Cek Inventori (Stock Opname)

Fitur ini untuk audit, memungkinkan staf gudang membandingkan stok fisik dengan stok sistem dan mencatat perbedaannya.

Tahap 1: Perubahan Database

Buat Tabel stock_takes:

Buat migrasi: php artisan make:migration create_stock_takes_table

Isi migrasi:

Generated php
Schema::create('stock_takes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->dateTime('check_date');
    $table->string('status')->default('in_progress'); // in_progress, completed
    $table->text('notes')->nullable();
    $table->timestamps();
});
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END

Buat Tabel stock_take_items:

Buat migrasi: php artisan make:migration create_stock_take_items_table

Isi migrasi:

Generated php
Schema::create('stock_take_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('stock_take_id')->constrained()->onDelete('cascade');
    $table->foreignId('product_variant_id')->constrained()->onDelete('cascade');
    $table->integer('system_stock'); // Stok sistem saat opname
    $table->integer('counted_stock'); // Stok fisik hasil hitungan
    $table->timestamps();
});
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END
Tahap 2: Model

Buat model StockTake.php dan StockTakeItem.php dengan relasi belongsTo dan hasMany yang sesuai.

Di StockTakeItem.php, tambahkan accessor untuk variance:

Generated php
public function getVarianceAttribute(): int
{
    return $this->counted_stock - $this->system_stock;
}
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END
Tahap 3: Implementasi Frontend (Livewire)

Ini akan menjadi fitur dengan beberapa halaman.

Halaman Daftar (/inventory/stock-takes): Menampilkan riwayat stock opname (ID, Tanggal, Status, Oleh Siapa). Ada tombol "Mulai Stock Opname Baru".

Halaman Proses Opname (/inventory/stock-takes/{id}):

Aksi Mulai: Saat pengguna klik "Mulai Stock Opname Baru", buat record StockTake baru dan redirect ke halaman proses ini.

Tampilan: Tampilkan daftar semua ProductVariant milik pengguna.

Kolom: "Nama Produk", "SKU", "Stok Sistem" (diambil dari warehouse_stock), "Input Stok Fisik", "Selisih".

Logika:

Saat halaman dimuat, ia menampilkan system_stock.

Staf gudang mengisi input "Stok Fisik" (wire:model).

wire:blur akan menyimpan data ini ke tabel stock_take_items, mencatat system_stock saat itu dan counted_stock yang diinput.

Kolom "Selisih" otomatis ter-update ($this->variance).

Aksi Selesai: Ada tombol "Selesaikan Opname". Saat diklik, status StockTake diubah menjadi completed.

Alur Kerja Pengguna:

Admin Gudang membuka halaman "Stock Opname".

Ia menekan "Mulai Stock Opname Baru".

Sistem menampilkan daftar SKU. Di samping SKU "Baju Merah, L", tertulis "Stok Sistem: 98".

Ia menghitung fisik dan menemukan ada 97 buah. Ia mengetik 97 di input.

Kolom "Selisih" otomatis menampilkan -1. Data ini tersimpan.

Setelah selesai semua, ia menekan "Selesaikan Opname".

Sistem tidak mengubah stok. Ia hanya menyimpan laporan. Manajer bisa melihat laporan ini dan memutuskan untuk membuat penyesuaian manual melalui Fitur 1 jika diperlukan.