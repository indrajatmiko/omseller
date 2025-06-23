<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecommendationPerformance extends Model
{
    use HasFactory;
    protected $guarded = [];
    // Hapus $casts karena tidak ada lagi kolom JSON
}