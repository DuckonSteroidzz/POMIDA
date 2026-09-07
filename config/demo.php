<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto top-up of demonstration sales history
    |--------------------------------------------------------------------------
    |
    | When true, loading Admin → Analytics will silently generate fabricated
    | historical orders if the Sales Forecast does not have enough days of
    | completed sales to render. See App\Services\DemoSalesTopUp.
    |
    | THIS FABRICATES SALES DATA. It exists so the capstone demo cannot quietly
    | rot back to "insufficient data" as the calendar moves. It must never be
    | on for the café's real business.
    |
    | Two independent conditions must BOTH hold before a single row is written
    | (see DemoSalesTopUp::isEnabled()):
    |
    |   1. This flag is explicitly true.
    |   2. app()->environment('local') is true.
    |
    | Both default to the safe answer. This flag is false unless
    | DEMO_SALES_AUTO_TOPUP is explicitly set truthy, and config('app.env')
    | falls back to 'production' when APP_ENV is missing or empty — so an
    | incomplete or half-copied .env fails CLOSED, not open.
    |
    */

    'auto_top_up_sales' => (bool) env('DEMO_SALES_AUTO_TOPUP', false),

];
