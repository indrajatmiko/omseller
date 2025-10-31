<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceFeeDetail extends Model
{
use HasFactory;

protected $fillable = [
'service_fee_id',
'subcategory_name',
'description',
];

/**
* Mendapatkan data induk (service_fee) dari detail ini.
*/
public function serviceFee(): BelongsTo
{
return $this->belongsTo(ServiceFee::class);
}
}