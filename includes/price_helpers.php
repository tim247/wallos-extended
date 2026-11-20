<?php
// Shared price helpers, usable by all modules without the side effects
// of including stats_calculations.php.

if (!function_exists('getPricePerMonth')) {
    function getPricePerMonth($cycle, $frequency, $price)
    {
        $frequency = max(1, (int) $frequency); // upstream 5.11.2: never divide by a zero/negative frequency
        switch ($cycle) {
            case 1:
                $numberOfPaymentsPerMonth = (30 / $frequency);
                return $price * $numberOfPaymentsPerMonth;
            case 2:
                $numberOfPaymentsPerMonth = (4.35 / $frequency);
                return $price * $numberOfPaymentsPerMonth;
            case 3:
                $numberOfPaymentsPerMonth = (1 / $frequency);
                return $price * $numberOfPaymentsPerMonth;
            case 4:
                $numberOfMonths = (12 * $frequency);
                return $price / $numberOfMonths;
            case 5:
                return 0;
        }
        return 0;
    }
}

require_once __DIR__ . '/currency_rates.php';

if (!function_exists('getPriceConverted')) {
    function getPriceConverted($price, $currency, $database, $userId = null)
    {
        if ($userId === null && isset($GLOBALS['userId'])) {
            $userId = $GLOBALS['userId'];
        }
        return wallos_convert_price($price, $currency, $database, $userId);
    }
}
