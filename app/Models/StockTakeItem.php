<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class StockTakeItem extends Model {
    use HasFactory;
    protected $fillable = ['stock_take_id', 'product_variant_id', 'system_stock', 'counted_stock'];
    public function stockTake() { return $this->belongsTo(StockTake::class); }
    public function productVariant() { return $this->belongsTo(ProductVariant::class); }
    public function getVarianceAttribute(): int { return $this->counted_stock - $this->system_stock; }
}