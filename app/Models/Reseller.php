<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Indonesia\City;
use App\Models\Indonesia\District;
use App\Models\Indonesia\Province;

class Reseller extends Model
{
    use HasFactory;

    // Sesuaikan $fillable dengan nama kolom yang baru
    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'email',
        'province_code', // <-- Diubah
        'city_code',     // <-- Diubah
        'district_code', // <-- Diubah
        'address',
        'discount_percentage',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
    
    // Sesuaikan relasi dengan foreign key dan owner key yang benar
    public function province()
    {
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_code', 'code');
    }

    public function district()
    {
        return $this->belongsTo(District::class, 'district_code', 'code');
    }
}