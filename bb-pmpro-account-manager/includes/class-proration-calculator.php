<?php

namespace BB_PMPro_Account_Manager\Includes;

use function BB_PMPro_Player_Accounts\Includes\bb_pmpro_Stripe_get_secretkey;

class Proration_Calculator {

    /**
     * Initialize
     */
    public function init(): void {
        // Add any hooks if needed
    }

    /**
     * Calculate proration for account change
     */
    public function calculate(
        int $user_id,
        int $current_accounts,
        int $new_accounts,
        int $level_id
    ): array {
        // Get level settings
        $settings = $this->get_level_settings($level_id);

        if (!$settings['enable_proration']) {
            return [
                'proration_enabled' => false,
                'amount' => 0,
                'type' => 'none',
                'current_accounts' => $current_accounts,
                'new_accounts' => $new_accounts,
                'message' => __('Proration is not enabled for your membership level.', 'bb-pmpro-account-manager'),
            ];
        }

        // Get next payment date
        $next_payment = pmpro_next_payment($user_id);

        // Special handling for pay-by-check orders
        if (!$next_payment || strtotime($next_payment) <= time()) {
            // Get the user's most recent order
            global $wpdb;
            $last_order = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $wpdb->pmpro_membership_orders 
            WHERE user_id = %d 
            AND membership_id = %d
            AND status IN('success', 'pending')
            ORDER BY timestamp DESC 
            LIMIT 1",
                $user_id,
                $level_id
            ));

            if ($last_order) {
                // For pay-by-check orders, calculate next payment as 1 year from order date
                if ($last_order->gateway === 'check') {
                    $order_timestamp = strtotime($last_order->timestamp);
                    // Add 1 year for annual billing
                    $next_payment_timestamp = strtotime('+1 year', $order_timestamp);
                    $next_payment = date('Y-m-d', $next_payment_timestamp);
                } else {
                    // For other gateways, try to get from subscription
                    $subscription_id = get_user_meta($user_id, 'pmpro_stripe_subscription_id', true);
                    if ($subscription_id) {
                        // Try to get from Stripe subscription
                        $next_payment_timestamp = $this->get_stripe_next_payment($subscription_id);
                        if ($next_payment_timestamp) {
                            $next_payment = date('Y-m-d', $next_payment_timestamp);
                        }
                    }
                }

                // If still no valid date, calculate from order
                if (!$next_payment || strtotime($next_payment) <= time()) {
                    $order_timestamp = strtotime($last_order->timestamp);
                    $next_payment_timestamp = strtotime('+1 year', $order_timestamp);
                    $next_payment = date('Y-m-d', $next_payment_timestamp);
                }
            } else {
                // No order found, assume new membership starting today
                $next_payment = date('Y-m-d', strtotime('+1 year'));
            }
        }

        // Validate next payment date
        $next_payment_timestamp = strtotime($next_payment);
        $now = time();

        // If next payment is in the past or today, add 1 year
        if ($next_payment_timestamp <= $now) {
            $next_payment_timestamp = strtotime('+1 year', $now);
            $next_payment = date('Y-m-d', $next_payment_timestamp);
        }

        // Calculate days remaining
        $days_remaining = max(0, ($next_payment_timestamp - $now) / DAY_IN_SECONDS);
        $days_in_year = 365;

        // Calculate account difference
        $account_diff = $new_accounts - $current_accounts;

        // Calculate extra accounts (above default)
        $default_accounts = $settings['default_accounts'];
        $current_extra = max(0, $current_accounts - $default_accounts);
        $new_extra = max(0, $new_accounts - $default_accounts);
        $extra_diff = $new_extra - $current_extra;

        // Calculate proration amount
        // Since price_per_account is monthly (€1.50), multiply by 12 for annual
        $annual_price_per_account = $settings['price_per_account'] * 12;
        $proration_amount = $extra_diff * $annual_price_per_account * ($days_remaining / $days_in_year);

        // Round to 2 decimal places
        $proration_amount = round($proration_amount, 2);

        // Determine type
        $type = 'none';
        if ($proration_amount > 0) {
            $type = 'charge';
        } elseif ($proration_amount < 0) {
            $type = 'credit';
        }

        // Format message
        $message = $this->format_proration_message(
            $type,
            abs($proration_amount),
            $days_remaining,
            $account_diff
        );

        return [
            'proration_enabled' => true,
            'amount' => $proration_amount,
            'type' => $type,
            'days_remaining' => round($days_remaining),
            'next_payment_date' => $next_payment,
            'current_accounts' => $current_accounts,
            'new_accounts' => $new_accounts,
            'account_difference' => $account_diff,
            'extra_accounts_difference' => $extra_diff,
            'message' => $message,
            'breakdown' => [
                'annual_price_per_account' => $annual_price_per_account,
                'monthly_price_per_account' => $settings['price_per_account'],
                'days_remaining' => round($days_remaining),
                'days_in_period' => $days_in_year,
                'proration_percentage' => round(($days_remaining / $days_in_year) * 100, 2),
            ],
        ];
    }

    /**
     * Try to get next payment date from Stripe
     */
    private function get_stripe_next_payment($subscription_id) {
        if (!class_exists('\\Stripe\\Stripe') || empty($subscription_id)) {
            return false;
        }

        try {
            $stripe_gateway = new \PMProGateway_stripe();
            \Stripe\Stripe::setApiKey(bb_pmpro_Stripe_get_secretkey());

            $subscription = \Stripe\Subscription::retrieve($subscription_id);
            if ($subscription && $subscription->current_period_end) {
                return $subscription->current_period_end;
            }
        } catch (\Exception $e) {
            // Log error but don't break
            error_log('Stripe subscription retrieve error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Calculate immediate charge for upgrade
     */
    public function calculate_immediate_charge(
        int $user_id,
        int $current_accounts,
        int $new_accounts,
        int $level_id
    ): float {
        $proration_data = $this->calculate($user_id, $current_accounts, $new_accounts, $level_id);

        if ($proration_data['type'] === 'charge') {
            return $proration_data['amount'];
        }

        return 0;
    }

    /**
     * Format proration message
     */
    private function format_proration_message(
        string $type,
        float $amount,
        float $days_remaining,
        int $account_diff
    ): string {
        $days = round($days_remaining);

        switch ($type) {
            case 'charge':
                return sprintf(
                    __('You will be charged €%s for %d additional account(s) for the remaining %d days of your billing period.', 'bb-pmpro-account-manager'),
                    number_format($amount, 2, ',', '.'),
                    abs($account_diff),
                    $days
                );

            case 'credit':
                return sprintf(
                    __('You will receive a credit of €%s for removing %d account(s) for the remaining %d days of your billing period.', 'bb-pmpro-account-manager'),
                    number_format($amount, 2, ',', '.'),
                    abs($account_diff),
                    $days
                );

            default:
                return __('No proration will be applied for this change.', 'bb-pmpro-account-manager');
        }
    }

    /**
     * Get level settings
     */
    private function get_level_settings(int $level_id): array {
        return get_option('pmpro_player_accounts_level_' . $level_id, [
            'default_accounts' => 1,
            'allow_extra_accounts' => false,
            'price_per_account' => 0,
            'max_accounts' => 1,
            'enable_proration' => true,
            'features' => '',
        ]);
    }
}