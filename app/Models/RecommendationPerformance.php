<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecommendationPerformance extends Model
{
    use HasFactory;
    protected $guarded = [];
    // Hapus $casts karena tidak ada lagi kolom JSON

    public function getCleanBiayaAttribute(): float
    {
        $value = str_replace(['Rp', '.', ' ', ','], '', $this->attributes['biaya_iklan_value'] ?? '0');
        if (str_ends_with(strtolower($value), 'k')) {
            return (float) rtrim(strtolower($value), 'k') * 100;
        }
        return (float) $value;
    }

    public function getCleanOmzetAttribute(): float
    {
        $value = str_replace(['Rp', '.', ' ', ','], '', $this->attributes['penjualan_dari_iklan_value'] ?? '0');
        if (str_ends_with(strtolower($value), 'k')) {
            return (float) rtrim(strtolower($value), 'k') * 100;
        }
        return (float) $value;
    }
}