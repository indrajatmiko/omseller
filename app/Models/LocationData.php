<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationData extends Model
{
    protected $fillable = [
    'buyer_profile_id', 'city', 'district', 'province', 'country_code', 'zip_code'
    ];

    public function buyerProfile()
    {
        return $this->belongsTo(BuyerProfile::class);
    }
}
