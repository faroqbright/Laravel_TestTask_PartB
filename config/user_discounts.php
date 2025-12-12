<?php

return [
    /* Stacking order: Percentage discounts apply first, then Fixed discounts (waterfall model). */
    'stacking_order' => 'percentage', // 'percentage' or 'fixed'

    /* The max combined percentage cap (e.g., 0.8 for 80%). Applied against the original price. */
    'max_percentage_cap' => 0.80,

    /* Rounding: Precision and Mode for final price. */
    'rounding' => [
        'mode' => PHP_ROUND_HALF_UP,
        'precision' => 2,
    ],
];