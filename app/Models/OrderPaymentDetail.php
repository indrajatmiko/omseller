<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderPaymentDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_subtotal',
        'shipping_fee_paid_by_buyer',
        'shipping_fee_paid_to_logistic',
        'shopee_shipping_subsidy',
        'admin_fee',
        'service_fee',
        'coins_spent_by_buyer',
        'seller_voucher',
        'total_income',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}