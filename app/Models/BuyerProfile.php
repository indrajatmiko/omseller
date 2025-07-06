<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}