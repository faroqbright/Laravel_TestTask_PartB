<?php
namespace LaravelUserDiscounts\Events;

use LaravelUserDiscounts\Models\UserDiscount;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DiscountApplied
{
    use Dispatchable, SerializesModels;
    
    /**
     * @param UserDiscount $userDiscount The specific user's discount record that was applied.
     * @param float $originalValue The value before discounts.
     * @param float $finalValue The value after stacking, capping, and rounding.
     * @param array $appliedDiscounts An array detailing each individual discount applied.
     */
    public function __construct(
        public UserDiscount $userDiscount, 
        public float $originalValue, 
        public float $finalValue, 
        public array $appliedDiscounts
    ) {}
}