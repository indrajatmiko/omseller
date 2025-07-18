<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmvPerformanceDetail extends Model
{
    use HasFactory;

    // Izinkan semua kolom untuk diisi secara massal (mass assignable)
    protected $guarded = [];

    /**
     * Mendefinisikan relasi bahwa detail ini milik satu CampaignReport.
     */
    public function campaignReport(): BelongsTo
    {
        return $this->belongsTo(CampaignReport::class);
    }
}