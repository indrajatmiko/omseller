<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderHistory extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'status', 'description', 'event_time'];

    protected $casts = [
        'event_time' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}