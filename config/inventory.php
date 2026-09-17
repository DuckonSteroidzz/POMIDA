<?php

/*
|--------------------------------------------------------------------------
| Inventory-driven menu display
|--------------------------------------------------------------------------
|
| Settings for how ingredient stock (the `inventory` table, via each menu
| item's recipe) is reflected on the customer-facing menu.
|
*/

return [

    // Below this many remaining servings (and above 0 — 0 stays "Out of
    // Stock"), a menu item shows a "N stocks left" badge instead of being
    // shown normally. MenuItem::isLowOnIngredientStock() / remainingServings()
    // are what compute the number; this only sets where the line is drawn.
    'low_stock_threshold' => (int) env('LOW_STOCK_THRESHOLD', 3),

];
