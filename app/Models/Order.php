<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'shopee_order_id',
        'order_sn',
        'buyer_username',
        'total_price',
        'payment_method',
        'order_status',
        'status_description',
        'shipping_provider',
        'tracking_number',
        'order_detail_url',
        'scraped_at',
        'address_full',
        'final_income', // Kolom baru ditambahkan
    ];
    
    protected $casts = [
        'scraped_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // (BARU) Relasi ke tabel baru
    public function paymentDetails()
    {
        return $this->hasMany(OrderPaymentDetail::class);
    }

    public function histories()
    {
        return $this->hasMany(OrderHistory::class);
    }
}