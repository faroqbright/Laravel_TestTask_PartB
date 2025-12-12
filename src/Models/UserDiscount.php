<?php

namespace LaravelUserDiscounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;

class UserDiscount extends Model
{
    protected $table = 'user_discounts';
    protected $guarded = ['id'];
    protected $casts = [
        'is_revoked' => 'boolean',
        'usage_limit' => 'integer',
        'times_used' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Config::get('user_discounts.user_model', 'App\Models\User'), 'user_id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function isExpiredOrInactive(): bool
    {
        return $this->is_revoked || !$this->discount->is_active || $this->discount->expires_at?->isPast();
    }
    
    public function hasUsageLeft(): bool
    {
        return $this->usage_limit === 0 || $this->times_used < $this->usage_limit;
    }
}