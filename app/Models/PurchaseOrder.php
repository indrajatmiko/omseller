<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'po_number', 'supplier', 'status',
        'notes', 'total_amount', 'ordered_at', 'received_at'
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'ordered_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(PurchaseOrderItem::class); }
}