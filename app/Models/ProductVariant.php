<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;

    // TAMBAHKAN KOLOM BARU DI SINI
    protected $fillable = [
        'product_id',
        'shopee_variant_id',
        'variant_name',
        'variant_sku',
        'price',
        'promo_price',
        'stock',
        'cost_price',       // <-- BARU
        'warehouse_stock',  // <-- BARU
        'reserved_stock',   // <-- BARU
        'sku_type',
        'reseller',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // TAMBAHKAN RELASI BARU
    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    // TAMBAHKAN ACCESSOR BARU UNTUK KEMUDAHAN
    // Ini akan menghitung stok yang benar-benar bisa dijual
    public function getAvailableStockAttribute(): int
    {
        return $this->warehouse_stock - $this->reserved_stock;
    }

    /**
     * Menghitung ulang total stok gudang berdasarkan semua pergerakan stok
     * dan menyimpannya ke database.
     * 
     * @return void
     */
    public function updateWarehouseStock(): void
    {
        // Hitung total stok dengan menjumlahkan semua kolom 'quantity'
        // dari pergerakan stok yang terkait.
        $newStock = $this->stockMovements()->sum('quantity');

        // Update kolom 'warehouse_stock' di model ini
        $this->warehouse_stock = $newStock;
        $this->save();
        
        // **PENTING**: Karena satu SKU bisa digunakan di banyak produk,
        // kita juga perlu menyinkronkan total stok ini ke semua varian lain
        // yang memiliki SKU yang sama untuk menjaga konsistensi data.
        if ($this->variant_sku) {
            ProductVariant::where('variant_sku', $this->variant_sku)
                          // Pastikan tidak mengupdate diri sendiri dua kali
                          ->where('id', '!=', $this->id)
                          ->update(['warehouse_stock' => $newStock]);
        }
    }
}