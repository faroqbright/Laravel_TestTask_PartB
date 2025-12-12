<?php

namespace LaravelUserDiscounts\Services;

// ... (Use statements)
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LaravelUserDiscounts\Events\DiscountApplied;
use LaravelUserDiscounts\Events\DiscountAssigned;
use LaravelUserDiscounts\Events\DiscountRevoked;
use LaravelUserDiscounts\Models\Discount;
use LaravelUserDiscounts\Models\DiscountAudit;
use LaravelUserDiscounts\Models\UserDiscount;
use Throwable;

class DiscountManager
{
    /**
     * Assigns a discount to a user with a specific usage limit.
     */
    public function assign(Model $user, string $discountCode, int $usageLimit = 1): UserDiscount
    {
        $discount = Discount::where('code', $discountCode)->firstOrFail();

        $userDiscount = UserDiscount::updateOrCreate(
            ['user_id' => $user->id, 'discount_id' => $discount->id],
            ['usage_limit' => $usageLimit, 'is_revoked' => false]
        );

        $this->createAudit($user, $discount, 'assigned', context: ['limit' => $usageLimit]);
        DiscountAssigned::dispatch($userDiscount);

        return $userDiscount;
    }

    /**
     * Revokes a discount from a user.
     */
    public function revoke(Model $user, string $discountCode): bool
    {
        $discount = Discount::where('code', $discountCode)->firstOrFail();

        $revoked = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->update(['is_revoked' => true]);
            
        if ($revoked) {
            $userDiscount = UserDiscount::where('user_id', $user->id)
                ->where('discount_id', $discount->id)
                ->first();
                
            $this->createAudit($user, $discount, 'revoked');
            DiscountRevoked::dispatch($userDiscount);
        }

        return (bool) $revoked;
    }

    /**
     * Retrieves discounts the user is eligible for.
     */
    public function eligibleFor(Model $user, bool $checkUsageCap = true): Collection
    {
        // Find all non-revoked UserDiscount records
        return UserDiscount::with('discount')
            ->where('user_id', $user->id)
            ->where('is_revoked', false)
            ->get()
            ->filter(function (UserDiscount $ud) use ($checkUsageCap) {
                // Exclude expired/inactive discounts
                if (!$ud->discount->is_active || $ud->discount->expires_at?->isPast()) {
                    return false;
                }

                // Exclude discounts where user usage limit is reached
                if ($checkUsageCap && !$ud->hasUsageLeft()) {
                    return false;
                }
                
                // Exclude discounts that haven't started yet
                if ($ud->discount->starts_at?->isFuture()) {
                    return false;
                }
                
                return true;
            })
            ->values(); // Reset keys after filtering
    }


    /**
     * Applies all eligible and stacked discounts to an original value.
     *
     * Rules enforced:
     * 1. Deterministic Stacking based on config (Percentage-First or Fixed-First)
     * 2. Max percentage cap enforced against the original price.
     * 3. Concurrency-safe usage increment within a transaction.
     * 4. Rounding applied to final value.
     */
    public function apply(
        Model $user,
        float $originalValue,
        bool $incrementUsage = true,
        int $contextItemId = null
    ): array {
        // Pre-check for early exit
        if ($originalValue <= 0) {
            return ['final_value' => 0.0, 'discount_value' => 0.0, 'applied_discounts' => []];
        }

        // Get eligible discounts (checks validity/revocation/starts_at/expires_at)
        $eligibleDiscounts = $this->eligibleFor($user, $incrementUsage);

        $order = config('user_discounts.stacking_order');
        $maxCap = config('user_discounts.max_percentage_cap');
        $rounding = config('user_discounts.rounding');

        // 1. DETERMINE STACKING ORDER
        $eligibleDiscounts = $eligibleDiscounts->sortBy(function (UserDiscount $ud) use ($order) {
            // Sort: 0 (applies first) to 1 (applies second)
            $isPercentage = $ud->discount->isPercentage();
            return $order === 'percentage'
                ? ($isPercentage ? 0 : 1) // Percentage first, Fixed second
                : ($isPercentage ? 1 : 0); // Fixed first, Percentage second
        })->values();

        $currentValue = $originalValue;
        $appliedDiscountDetails = [];
        $appliedUserDiscounts = new Collection();
        $totalPercentageReduction = 0.0;
        
        DB::beginTransaction();
        try {
            // 2. APPLY DISCOUNTS AND CHECK CAPPING
            foreach ($eligibleDiscounts as $userDiscount) {
                $discount = $userDiscount->discount;
                $reduction = 0.0;
                $initialCalculationValue = $currentValue;

                if ($discount->isPercentage()) {
                    $potentialNewPercentage = $totalPercentageReduction + $discount->value;
                    
                    // Check Max Percentage Cap Rule (Applied against Original Price)
                    if ($maxCap !== null && $potentialNewPercentage > $maxCap) {
                        $effectivePercentage = max(0, $maxCap - $totalPercentageReduction);
                        // Capped reduction is calculated on the ORIGINAL price for consistency.
                        $reduction = $originalValue * $effectivePercentage;
                        $totalPercentageReduction = $maxCap; // Cap reached
                    } else {
                        // Waterfall stacking: Apply on current remaining value (normal application)
                        $reduction = $initialCalculationValue * $discount->value;
                        $totalPercentageReduction = $potentialNewPercentage; // Increment total %
                    }
                } else { // Fixed amount
                    // Fixed amounts apply after any percentage stack and never push below zero.
                    $reduction = min($currentValue, $discount->value);
                }
                
                // Final value application
                if ($reduction > 0) {
                    $reduction = round($reduction, $rounding['precision']); // Round reduction value for cleaner audit/detail
                    $currentValue -= $reduction;

                    $appliedDiscountDetails[] = [
                        'code' => $discount->code,
                        'type' => $discount->type,
                        'value' => $discount->value,
                        'applied_reduction' => $reduction,
                    ];
                    $appliedUserDiscounts->push($userDiscount);
                }
            }
            
            // Finalize the value with required precision and mode.
            $finalValue = round($currentValue, $rounding['precision'], $rounding['mode']);
            $recalculatedDiscountAmount = max(0, $originalValue - $finalValue); // The total real discount amount

            // 3. CONCURRENCY-SAFE ATOMIC USAGE INCREMENT AND EVENTS
            if ($incrementUsage && $appliedUserDiscounts->isNotEmpty()) {
                foreach ($appliedUserDiscounts as $userDiscount) {
                    // Atomic operation: Only increment if times_used < usage_limit
                    $incremented = UserDiscount::where('id', $userDiscount->id)
                        ->whereColumn('times_used', '<', 'usage_limit')
                        ->increment('times_used');

                    if ($incremented) { // Usage successfully incremented
                        $userDiscount->refresh(); 

                        $this->createAudit(
                            $user, 
                            $userDiscount->discount, 
                            'applied', 
                            $originalValue, 
                            $recalculatedDiscountAmount,
                            ['context_item_id' => $contextItemId]
                        );
                        // Fire the event (Requirement: DiscountApplied)
                        DiscountApplied::dispatch($userDiscount, $originalValue, $finalValue, $appliedDiscountDetails);
                    }
                }
            }

            DB::commit();

            return [
                'final_value' => $finalValue,
                'discount_value' => $recalculatedDiscountAmount,
                'applied_discounts' => $appliedDiscountDetails,
            ];

        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
    
    
    /**
     * Helper to create an audit log.
     */
    private function createAudit(
        Model $user, 
        Discount $discount, 
        string $action, 
        ?float $initialValue = null, 
        ?float $discountValue = null, 
        array $context = []
    ): void {
        if (!config('user_discounts.auditing_enabled')) {
            return;
        }

        DiscountAudit::create([
            'user_id' => $user->id,
            'discount_id' => $discount->id,
            'action' => $action,
            'initial_value' => $initialValue,
            'discount_value' => $discountValue,
            'context' => $context,
        ]);
    }
}