<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class StockTake extends Model {
    use HasFactory;
    protected $fillable = ['user_id', 'check_date', 'status', 'notes'];
    protected $casts = ['check_date' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(StockTakeItem::class); }
}