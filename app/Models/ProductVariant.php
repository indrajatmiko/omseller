<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'shopee_variant_id',
        'variant_name',
        'variant_sku',
        'price',
        'promo_price',
        'stock',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}