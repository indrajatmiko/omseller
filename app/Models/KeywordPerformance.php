<?php

namespace App\Models;

use App\Models\Traits\CleansPerformanceData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KeywordPerformance extends Model
{
    use HasFactory, CleansPerformanceData;
    protected $guarded = [];

    private function parseIndonesianNumericString(?string $value): float
    {
        if (empty($value) || $value === '-') {
            return 0.0;
        }
        $cleaned = str_replace(['Rp', '.', ' '], '', $value);
        $cleaned = str_replace(',', '.', $cleaned);
        if (str_ends_with(strtolower($cleaned), 'k')) {
            return (float) rtrim(strtolower($cleaned), 'k') * 1000;
        }
        return (float) $cleaned;
    }

    // public function getCleanBiayaAttribute(): float
    // {
    //     return $this->parseIndonesianNumericString($this->attributes['biaya_iklan_value'] ?? '0');
    // }

    // public function getCleanOmzetAttribute(): float
    // {
    //     // Keyword performance biasanya hanya punya omzet dari iklan
    //     return $this->parseIndonesianNumericString($this->attributes['penjualan_dari_iklan_value'] ?? '0');
    // }
}