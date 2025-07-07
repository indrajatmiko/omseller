<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB; // <-- Tambahkan ini


class BuyerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'buyer_username',
        'address_identifier',
        'buyer_real_name',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'buyer_username', 'buyer_username')
            ->where('user_id', $this->user_id)
            // Mencocokkan address_identifier dengan hash dari address_full di tabel orders.
            ->where(DB::raw('sha1(trim(address_full))'), $this->address_identifier);
    }
}