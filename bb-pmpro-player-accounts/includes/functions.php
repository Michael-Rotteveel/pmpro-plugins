<?php

namespace BB_PMPro_Player_Accounts\Includes;

/**
 * Helper functions and compatibility checks
 */

/**
 * Check if PMPro is active
 */
function is_pmpro_active(): bool {
    return defined('PMPRO_VERSION');
}

/**
 * Safe wrapper for pmpro_get_currency
 */
function get_currency_code(): string {
    if (function_exists('pmpro_get_currency')) {
        if (is_array(pmpro_get_currency())) {
            return isset(pmpro_get_currency()['code']) ? strtolower(pmpro_get_currency()['code']) : 'eur';
        }
        return strtolower(pmpro_get_currency());
    }
    return 'eur';
}

/**
 * Safe wrapper for getting membership order meta
 */
function get_order_meta($order_id, $key, $single = true) {
    if (function_exists('get_pmpro_membership_order_meta')) {
        return get_pmpro_membership_order_meta($order_id, $key, $single);
    }
    return get_metadata('pmpro_membership_order', $order_id, $key, $single);
}

/**
 * Safe wrapper for updating membership order meta
 */
function update_order_meta($order_id, $key, $value) {
    if (function_exists('update_pmpro_membership_order_meta')) {
        return update_pmpro_membership_order_meta($order_id, $key, $value);
    }
    return update_metadata('pmpro_membership_order', $order_id, $key, $value);
}

function bb_pmpro_Stripe_get_secretkey()
{
    $secretkey = get_option('pmpro_gateway_environment') === 'live'
        ? get_option('pmpro_live_stripe_connect_secretkey')
        : get_option('pmpro_sandbox_stripe_connect_secretkey');

    return $secretkey;
}