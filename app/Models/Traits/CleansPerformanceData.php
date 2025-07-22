<?php

namespace App\Models\Traits;

trait CleansPerformanceData
{
    /**
     * Membersihkan string numerik non-moneter (menghapus '.' dan handle '-').
     */
    private function parseNumericValue(?string $value): int
    {
        if (empty($value) || $value === '-') {
            return 0;
        }
        return (int) str_replace('.', '', $value);
    }

    /**
     * Membersihkan string Rupiah (menghapus 'Rp', '.', handle ',', dan 'k').
     */
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

    // --- Accessor untuk nilai-nilai yang akan disummary ---

    public function getCleanBiayaAttribute(): float
    {
        return $this->parseIndonesianNumericString($this->attributes['biaya_iklan_value'] ?? '0');
    }

    public function getCleanOmzetAttribute(): float
    {
        return $this->parseIndonesianNumericString($this->attributes['penjualan_dari_iklan_value'] ?? '0');
    }
    
    public function getCleanDilihatAttribute(): int
    {
        return $this->parseNumericValue($this->attributes['iklan_dilihat_value'] ?? '0');
    }
    
    public function getCleanKlikAttribute(): int
    {
        return $this->parseNumericValue($this->attributes['jumlah_klik_value'] ?? '0');
    }
    
    public function getCleanTerjualAttribute(): int
    {
        return $this->parseNumericValue($this->attributes['produk_terjual_value'] ?? '0');
    }
}