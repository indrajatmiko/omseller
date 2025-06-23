<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'shopee_product_id',
        'product_name',
        'parent_sku',
        'image_url',
        'total_sales',
        'total_stock',
    ];

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}