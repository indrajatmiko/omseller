<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany; // Tambahkan ini

class ServiceFee extends Model
{
use HasFactory;

protected $fillable = [
'platform',
'seller_type',
'fee_type',
'name',
'description', // Deskripsi tetap ada untuk biaya program
'value',
'value_type',
'max_cap',
'is_active',
];

protected $casts = [
'value' => 'float',
'is_active' => 'boolean',
];

/**
* Mendapatkan semua detail subkategori untuk biaya ini.
*/
public function details(): HasMany
{
return $this->hasMany(ServiceFeeDetail::class);
}
}