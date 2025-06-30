<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdsTransaction extends Model
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [ 'transaction_time' => 'datetime' ];
}