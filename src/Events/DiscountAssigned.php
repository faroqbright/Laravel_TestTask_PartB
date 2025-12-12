<?php
namespace LaravelUserDiscounts\Events;
use LaravelUserDiscounts\Models\UserDiscount;
use Illuminate\Foundation\Events\Dispatchable;

class DiscountAssigned
{
    use Dispatchable;
    
    public function __construct(public UserDiscount $userDiscount) {
        // 
    }
}