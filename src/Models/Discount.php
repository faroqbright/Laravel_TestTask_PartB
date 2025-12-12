<?php

namespace LaravelUserDiscounts\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discount extends Model
{
    use HasFactory;

    protected $guarded = ['id'];
    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'value' => 'float',
        'global_max_uses' => 'integer',
    ];

    public function userDiscounts(): HasMany
    {
        return $this->hasMany(UserDiscount::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where(function ($q) {
                         $now = now();
                         $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                     })
                     ->where(function ($q) {
                         $now = now();
                         $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
                     });
    }

    public function isPercentage(): bool
    {
        return $this->type === 'percentage';
    }
}