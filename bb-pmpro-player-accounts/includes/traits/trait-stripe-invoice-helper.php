<?php

namespace BB_PMPro_Player_Accounts\Traits;

trait Stripe_Invoice_Helper {

    /**
     * Create Stripe product for player accounts
     */
    protected function create_stripe_product(string $name, string $description = '') {
        if (!class_exists('\\Stripe\\Stripe')) {
            return null;
        }

        try {
            $product = \Stripe\Product::create([
                'name' => $name,
                'description' => $description,
                'metadata' => [
                    'pmpro_product' => 'player_accounts',
                ],
            ]);

            return $product;
        } catch (\Exception $e) {
            error_log('Stripe Product Creation Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create Stripe price for product
     */
    protected function create_stripe_price($product_id, float $amount, string $currency = 'eur') {
        if (!class_exists('\\Stripe\\Stripe')) {
            return null;
        }

        try {
            $price = \Stripe\Price::create([
                'product' => $product_id,
                'unit_amount' => $amount * 100, // Convert to cents
                'currency' => $currency,
                'recurring' => [
                    'interval' => 'year',
                    'interval_count' => 1,
                ],
            ]);

            return $price;
        } catch (\Exception $e) {
            error_log('Stripe Price Creation Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Format line item for Stripe invoice
     */
    protected function format_stripe_line_item(
        string $name,
        string $description,
        float $amount,
        int $quantity = 1
    ): array {
        return [
            'price_data' => [
                'currency' => 'eur',
                'product_data' => [
                    'name' => $name,
                    'description' => $description,
                ],
                'unit_amount' => $amount * 100, // Convert to cents
            ],
            'quantity' => $quantity,
        ];
    }
}