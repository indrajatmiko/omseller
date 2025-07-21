<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmvPerformanceDetail extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function campaignReport(): BelongsTo
    {
        return $this->belongsTo(CampaignReport::class);
    }

    /**
     * Helper function baru yang cerdas untuk mengubah format angka Indonesia (Rp1.234,56)
     * menjadi format float yang bisa dihitung oleh PHP (1234.56).
     */
    private function parseIndonesianNumericString(?string $value): float
    {
        if (empty($value) || $value === '-') {
            return 0.0;
        }

        // 1. Hapus pemisah ribuan ('.') dan simbol/spasi non-numerik lainnya.
        $cleaned = str_replace(['Rp', '.', ' '], '', $value);
        
        // 2. Ganti pemisah desimal (',') dengan titik ('.') agar valid untuk PHP.
        $cleaned = str_replace(',', '.', $cleaned);

        // 3. Handle notasi 'k' (ribuan), misalnya '2.5k' menjadi 2500.
        if (str_ends_with(strtolower($cleaned), 'k')) {
            return (float) rtrim(strtolower($cleaned), 'k') * 1000;
        }

        return (float) $cleaned;
    }

    /**
     * [REVISI] Menggunakan parser baru.
     */
    public function getCleanBiayaAttribute(): float
    {
        return $this->parseIndonesianNumericString($this->attributes['biaya_iklan_value'] ?? '0');
    }

    /**
     * [REVISI] Sumber omzet sekarang HANYA dari 'penjualan_dari_iklan_value'.
     */
    public function getCleanOmzetAttribute(): float
    {
        return $this->parseIndonesianNumericString($this->attributes['penjualan_dari_iklan_value'] ?? '0');
    }
}