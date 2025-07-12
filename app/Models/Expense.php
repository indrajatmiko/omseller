<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'user_id', 
        'expense_category_id', 
        'amount', 
        'description', 
        'transaction_date'
    ];
    
    protected $casts = [
        'transaction_date' => 'date',
    ];

    public function category() {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function user() {
        return $this->belongsTo(User::class);
    }
}