<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignReport extends Model
{
    use HasFactory;

    // Tidak lagi menggunakan $fillable, ganti dengan guarded
    // agar lebih fleksibel jika ada penambahan kolom di masa depan.
    protected $guarded = [];

    protected $casts = [
        'scrape_date' => 'date',
    ];

    public function keywordPerformances(): HasMany
    {
        return $this->hasMany(KeywordPerformance::class);
    }

    public function recommendationPerformances(): HasMany
    {
        return $this->hasMany(RecommendationPerformance::class);
    }

    /**
     * Mendapatkan semua detail performa GMV yang terkait dengan laporan ini.
     */
    public function gmvPerformanceDetails(): HasMany
    {
        return $this->hasMany(GmvPerformanceDetail::class);
    }

    /**
     * Parsing nilai 'biaya' dari format string (misal: 'Rp2.5k') menjadi float.
     * Menggunakan accessor agar nilai ini otomatis di-parse saat diakses.
     */
    public function getCleanBiayaAttribute(): float
    {
        $value = str_replace(['Rp', '.', ' '], '', $this->attributes['biaya']);
        if (str_ends_with(strtolower($value), 'k')) {
            return (float) rtrim(strtolower($value), 'k') * 100;
        }
        return (float) $value;
    }

    /**
     * Parsing nilai 'omzet_iklan' dari format string menjadi float.
     */
    public function getCleanOmzetAttribute(): float
    {
        $value = str_replace(['Rp', '.', ' '], '', $this->attributes['omzet_iklan']);
        if (str_ends_with(strtolower($value), 'k')) {
            return (float) rtrim(strtolower($value), 'k') * 100;
        }
        return (float) $value;
    }

    /**
     * Parsing nilai 'efektivitas_iklan' (ROAS) dari format string '11,72' menjadi float.
     */
    public function getCleanRoasAttribute(): float
    {
        return (float) str_replace(',', '.', $this->attributes['efektivitas_iklan']);
    }

    /**
     * [REVISI] Accessor untuk mengekstrak nilai numerik dari string modal.
     * Lebih andal dengan menghapus semua karakter non-digit.
     */
    public function getModalNumericValueAttribute(): float
    {
        $modalString = $this->attributes['modal'] ?? '0';
        // Hapus semua karakter yang bukan angka
        return (float) preg_replace('/[^\d]/', '', $modalString);
    }

    /**
     * [PENAMBAHAN BARU] Accessor untuk menampilkan modal yang bersih.
     * Menghapus kata "Harian " dan hanya menyisakan "Rp 100.000".
     */
    public function getCleanModalDisplayAttribute(): string
    {
        $modalString = $this->attributes['modal'] ?? 'Rp 0';
        // Hapus "Harian " jika ada di awal string
        return str_replace('Harian', '', $modalString);
    }
}