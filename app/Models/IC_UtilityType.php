<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IC_UtilityType extends Model
{
    use HasFactory;

    protected $table = 'ic_utilitytypes';

    protected $fillable = [
        'type',
        'name',
        'description',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Scopes
    public function scopeIncome($query)
    {
        return $query->where('type', 'income');
    }

    public function scopeExpense($query)
    {
        return $query->where('type', 'expense');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Accessors
    public function getTypeLabelAttribute(): string
    {
        return $this->type === 'income' ? '💰 Income' : '📉 Expense';
    }

    public function getTypeIconAttribute(): string
    {
        return $this->type === 'income' ? '💰' : '📉';
    }
}