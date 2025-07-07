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
}