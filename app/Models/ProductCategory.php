<?php

// app/Models/Category.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'slug'];

    // Relasi ke User
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    // Relasi ke Produk
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}