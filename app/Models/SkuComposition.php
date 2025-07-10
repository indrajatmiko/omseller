<?php

// app/Models/SkuComposition.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SkuComposition extends Model
{
    use HasFactory;
    protected $fillable = ['bundle_sku', 'component_sku', 'quantity', 'user_id'];
}