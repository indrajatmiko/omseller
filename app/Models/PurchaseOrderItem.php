<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id', 'product_variant_id', 'quantity', 'cost_price', 'subtotal'
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function productVariant() { return $this->belongsTo(ProductVariant::class); }
}