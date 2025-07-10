<?php

// app/Models/StockAdjustment.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'variant_sku',
        'product_variant_id',
        'stock_before',
        'quantity_adjusted',
        'stock_after',
        'notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }
}