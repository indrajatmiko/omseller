<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'transaction_hash',
        'transaction_date',
        'transaction_type',
        'amount',
    ];

    protected $casts = [
        'transaction_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}