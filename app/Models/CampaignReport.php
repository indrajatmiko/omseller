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
}