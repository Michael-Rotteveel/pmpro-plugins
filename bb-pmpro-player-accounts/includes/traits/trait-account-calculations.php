<?php

namespace BB_PMPro_Player_Accounts\Traits;

trait Account_Calculations {

    /**
     * Calculate additional cost for extra accounts (annual)
     */
    protected function calculate_additional_cost(
        int $selected_accounts,
        int $default_accounts,
        float $price_per_account_monthly
    ): float {
        $extra_accounts = max(0, $selected_accounts - $default_accounts);
        // Convert monthly price to annual (12 months)
        $annual_price_per_account = $price_per_account_monthly * 12;
        return $extra_accounts * $annual_price_per_account;
    }

    /**
     * Get annual price from monthly price
     */
    protected function get_annual_price(float $monthly_price): float {
        return $monthly_price * 12;
    }

    /**
     * Calculate prorated amount based on time remaining
     */
    protected function calculate_prorated_amount(
        float $annual_amount,
        int $days_remaining,
        int $days_in_period = 365
    ): float {
        return round($annual_amount * ($days_remaining / $days_in_period), 2);
    }

    /**
     * Validate account count against limits
     */
    protected function validate_account_count(
        int $accounts,
        int $min_accounts,
        int $max_accounts
    ): bool {
        if ($accounts < $min_accounts) {
            return false;
        }

        // 0 means unlimited, but cap at 25
        $effective_max = $max_accounts ?: 25;

        if ($accounts > $effective_max) {
            return false;
        }

        return true;
    }

    /**
     * Get tier features as array
     */
    protected function parse_features(string $features): array {
        return array_filter(array_map('trim', explode(',', $features)));
    }
}