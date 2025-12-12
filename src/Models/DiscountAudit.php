<?php

namespace LaravelUserDiscounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;

class DiscountAudit extends Model
{
    protected $table = 'discount_audits';
    protected $guarded = ['id'];
    protected $casts = [
        'initial_value' => 'float',
        'discount_value' => 'float',
        'context' => 'json',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Config::get('user_discounts.user_model', 'App\Models\User'), 'user_id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }
}