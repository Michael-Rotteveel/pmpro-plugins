<?php

namespace BB_PMPro_Player_Accounts\Includes;

use BB_PMPro_Player_Accounts\Traits\Account_Calculations;

class Proration_Handler {

    use Account_Calculations;

    /**
     * Initialize
     */
    public function init(): void {
        add_filter('pmpro_upgrade_discount_code', [$this, 'apply_proration_discount'], 10, 3);
        add_filter('pmpro_downgrade_discount_code', [$this, 'apply_proration_discount'], 10, 3);
    }

    /**
     * Calculate proration amount
     */
    public function calculate_proration(
        int $user_id,
        int $current_accounts,
        int $new_accounts,
        array $settings
    ): float {
        // Get user's subscription info
        $last_order = $this->get_last_order($user_id);
        if (!$last_order) {
            return 0;
        }

        // Calculate days remaining in billing period
        $next_payment_date = pmpro_next_payment($user_id);
        if (!$next_payment_date) {
            return 0;
        }

        $days_remaining = (strtotime($next_payment_date) - time()) / DAY_IN_SECONDS;
        $days_in_period = 365; // Annual billing

        // Calculate account difference
        $account_diff = $new_accounts - $current_accounts;

        // Calculate proration (convert monthly price to annual)
        $annual_price_per_account = $settings['price_per_account'] * 12;
        $proration_amount = $account_diff * $annual_price_per_account * ($days_remaining / $days_in_period);

        return round($proration_amount, 2);
    }

    /**
     * Apply proration to user's subscription
     */
    public function apply_proration(int $user_id, float $proration_amount): bool {
        // Check if user has Stripe subscription
        $subscription_id = get_user_meta($user_id, 'pmpro_stripe_subscription_id', true);

        if (!$subscription_id) {
            return false;
        }

        // Apply proration through Stripe
        if (class_exists('\\Stripe\\Stripe') && !empty($subscription_id)) {
            try {
                // Get PMPro Stripe gateway
                $gateway = new \PMProGateway_stripe();
                \Stripe\Stripe::setApiKey($gateway->get_secretkey());

                // Get customer ID
                $customer_id = get_user_meta($user_id, 'pmpro_stripe_customer_id', true);

                if (!$customer_id) {
                    return false;
                }

                // Create invoice item for proration
                if ($proration_amount > 0) {
                    // Charge for upgrade
                    \Stripe\InvoiceItem::create([
                        'customer' => $customer_id,
                        'amount' => (int)($proration_amount * 100), // Convert to cents
                        'currency' => strtolower(pmpro_get_currency()),
                        'description' => __('Player accounts adjustment (upgrade)', 'bb-pmpro-player-accounts'),
                    ]);
                } else {
                    // Credit for downgrade
                    \Stripe\InvoiceItem::create([
                        'customer' => $customer_id,
                        'amount' => (int)($proration_amount * 100), // Negative amount for credit
                        'currency' => strtolower(pmpro_get_currency()),
                        'description' => __('Player accounts adjustment (downgrade)', 'bb-pmpro-player-accounts'),
                    ]);
                }

                return true;
            } catch (\Exception $e) {
                // Log error
                error_log('BB Player Accounts Proration Error: ' . $e->getMessage());
                return false;
            }
        }

        return false;
    }

    /**
     * Get user's last order
     */
    private function get_last_order(int $user_id) {
        global $wpdb;

        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $wpdb->pmpro_membership_orders 
            WHERE user_id = %d 
            AND status NOT IN('refunded', 'error', 'token', 'review') 
            ORDER BY id DESC 
            LIMIT 1",
            $user_id
        ));

        if ($order_id) {
            return new \MemberOrder($order_id);
        }

        return null;
    }

    /**
     * Apply proration discount (for PMPro integration)
     */
    public function apply_proration_discount($discount_code, $level_id, $user_id) {
        // This can be extended to work with PMPro's discount code system if needed
        return $discount_code;
    }
}