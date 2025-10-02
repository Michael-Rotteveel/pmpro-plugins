<?php

namespace BB_PMPro_Player_Accounts\Includes;

use BB_PMPro_Player_Accounts\Traits\Stripe_Invoice_Helper;
use function BB_PMPro_Player_Accounts\Includes\get_currency_code;
use function BB_PMPro_Player_Accounts\Includes\get_order_meta;
use function BB_PMPro_Player_Accounts\Includes\update_order_meta;
use function BB_PMPro_Player_Accounts\Includes\is_pmpro_active;

class Invoice_Customizer {

    use Stripe_Invoice_Helper;

    /**
     * @var Membership_Level_Settings
     */
    private $level_settings;

    /**
     * @var Player_Accounts_Manager
     */
    private $accounts_manager;

    /**
     * Constructor
     */
    public function __construct(
        Membership_Level_Settings $level_settings,
        Player_Accounts_Manager $accounts_manager
    ) {
        $this->level_settings = $level_settings;
        $this->accounts_manager = $accounts_manager;
    }

    /**
     * Initialize
     */
    public function init(): void {
        // Only initialize if PMPro is active
        if (!is_pmpro_active()) {
            return;
        }

        // Customize invoice display
        add_action('pmpro_invoice_bullets_bottom', [$this, 'add_account_info_to_invoice'], 10, 1);

        // For regular Stripe checkout - customize line items
        add_filter('pmpro_stripe_checkout_session_parameters', [$this, 'customize_stripe_checkout_session'], 5, 2);
        add_filter('pmpro_stripe_checkout_line_items', [$this, 'customize_stripe_line_items_checkout'], 10, 3);

        // For pay-by-check - create Stripe invoice
        add_action('pmpro_added_order', [$this, 'create_stripe_invoice_for_check'], 10, 1);
        add_action('pmpro_checkout_confirmed', [$this, 'handle_check_gateway_checkout'], 10, 2);

        // Show invoice link on confirmation
        add_action('pmpro_confirmation_message_bottom', [$this, 'show_stripe_invoice_link']);

        // Store player accounts before checkout processing
        add_action('pmpro_checkout_before_processing', [$this, 'store_player_accounts_in_session']);

        // Track original pricing
        add_filter('pmpro_checkout_level', [$this, 'track_original_price'], 1);
    }

    /**
     * Track original price before modifications
     */
    public function track_original_price($level) {
        if (!empty($level)) {
            // Store the original level pricing
            $_SESSION['pmpro_original_initial_payment'] = $level->initial_payment;
            $_SESSION['pmpro_original_billing_amount'] = $level->billing_amount;
        }
        return $level;
    }

    /**
     * Store player accounts in session for Stripe processing
     */
    public function store_player_accounts_in_session(): void {
        if (isset($_REQUEST['player_accounts'])) {
            $_SESSION['pmpro_player_accounts_checkout'] = (int) $_REQUEST['player_accounts'];
        }
    }

    /**
     * Customize Stripe Checkout Session parameters (for regular Stripe payments)
     */
    public function customize_stripe_checkout_session($params, $order) {
        // Skip if this is a check payment
        if ($order->gateway === 'check') {
            return $params;
        }

        // Get player accounts from session or request
        $player_accounts = $_SESSION['pmpro_player_accounts_checkout'] ??
            $_REQUEST['player_accounts'] ??
            null;

        if (!$player_accounts || !isset($order->membership_id)) {
            return $params;
        }

        $settings = $this->level_settings->get_level_settings($order->membership_id);
        $extra_accounts = max(0, $player_accounts - $settings['default_accounts']);

        // Get membership level for naming
        $membership = pmpro_getLevel($order->membership_id);
        if (!$membership) {
            return $params;
        }

        // Calculate prices
        $annual_price_per_account = $settings['price_per_account'] * 12;
        $extra_cost_total = $extra_accounts * $annual_price_per_account;

        // Get the original base price (before our modifications)
        $base_price = $_SESSION['pmpro_original_initial_payment'] ?? $membership->initial_payment;

        // If extra cost was added to the total, remove it from base price
        if ($extra_cost_total > 0 && $order->total > $base_price) {
            $base_price = $order->total - $extra_cost_total;
        }

        // Completely rebuild line_items array
        $new_line_items = [];

        // Add base membership line item
        $new_line_items[] = [
            'price_data' => [
                'currency' => get_currency_code(),
                'product_data' => [
                    'name' => $membership->name,
                    'description' => sprintf(
                        __('Annual membership with %d player account(s) included', 'bb-pmpro-player-accounts'),
                        $settings['default_accounts']
                    ),
                    'metadata' => [
                        'pmpro_membership_level_id' => $membership->id,
                        'type' => 'membership_base',
                    ],
                ],
                'tax_behavior' => 'exclusive',
                'unit_amount' => (int)($base_price * 100), // Convert to cents
                'recurring' => [
                    'interval' => strtolower($membership->cycle_period ?: 'year'),
                    'interval_count' => $membership->cycle_number ?: 1
                ],
            ],
            'quantity' => 1,
        ];

        // Add extra accounts line item if applicable
        if ($extra_accounts > 0 && $settings['price_per_account'] > 0) {
            $new_line_items[] = [
                'price_data' => [
                    'currency' => get_currency_code(),
                    'product_data' => [
                        'name' => __('Extra Player Account', 'bb-pmpro-player-accounts'),
                        'description' => sprintf(
                            __('Additional player account for %s (€%s/month, billed annually)', 'bb-pmpro-player-accounts'),
                            $membership->name,
                            number_format($settings['price_per_account'], 2)
                        ),
                        'metadata' => [
                            'pmpro_membership_level_id' => $membership->id,
                            'type' => 'extra_player_account',
                        ],
                    ],
                    'tax_behavior' => 'exclusive',
                    'unit_amount' => (int)($annual_price_per_account * 100), // Convert to cents
                    'recurring' => [
                        'interval' => strtolower($membership->cycle_period ?: 'year'),
                        'interval_count' => $membership->cycle_number ?: 1
                    ],
                ],
                'quantity' => $extra_accounts,
            ];
        }

        // Override the line_items completely
        $params['line_items'] = $new_line_items;

        // Add metadata to track player accounts
        if (!isset($params['metadata'])) {
            $params['metadata'] = [];
        }
        $params['metadata']['player_accounts_total'] = $player_accounts;
        $params['metadata']['player_accounts_extra'] = $extra_accounts;
        $params['metadata']['player_accounts_default'] = $settings['default_accounts'];

        // Ensure mode is set correctly
        if (empty($params['mode'])) {
            $params['mode'] = 'subscription';
        }

        return $params;
    }

    /**
     * Alternative method to customize line items specifically
     */
    public function customize_stripe_line_items_checkout($line_items, $order, $level) {
        // Skip if this is a check payment
        if ($order->gateway === 'check') {
            return $line_items;
        }

        // Get player accounts from session or request
        $player_accounts = $_SESSION['pmpro_player_accounts_checkout'] ??
            $_REQUEST['player_accounts'] ??
            null;

        if (!$player_accounts || !isset($order->membership_id)) {
            return $line_items;
        }

        $settings = $this->level_settings->get_level_settings($order->membership_id);
        $extra_accounts = max(0, $player_accounts - $settings['default_accounts']);

        if ($extra_accounts === 0 || !$settings['allow_extra_accounts']) {
            return $line_items;
        }

        // Get membership level
        $membership = pmpro_getLevel($order->membership_id);
        if (!$membership) {
            return $line_items;
        }

        // Calculate prices
        $annual_price_per_account = $settings['price_per_account'] * 12;

        // Get the original base price
        $base_price = $_SESSION['pmpro_original_initial_payment'] ?? $membership->initial_payment;

        // Clear existing line items and rebuild
        $line_items = [];

        // Base membership
        $line_items[] = [
            'price_data' => [
                'currency' => get_currency_code(),
                'product_data' => [
                    'name' => $membership->name,
                    'description' => sprintf(
                        __('Annual membership with %d player account(s) included', 'bb-pmpro-player-accounts'),
                        $settings['default_accounts']
                    ),
                ],
                'tax_behavior' => 'exclusive',
                'unit_amount' => (int)($base_price * 100),
                'recurring' => [
                    'interval' => strtolower($membership->cycle_period ?: 'year'),
                    'interval_count' => $membership->cycle_number ?: 1
                ],
            ],
            'quantity' => 1,
        ];

        // Extra accounts
        if ($extra_accounts > 0) {
            $line_items[] = [
                'price_data' => [
                    'currency' => get_currency_code(),
                    'product_data' => [
                        'name' => __('Extra Player Account', 'bb-pmpro-player-accounts'),
                        'description' => sprintf(
                            __('Additional account - €%s/month', 'bb-pmpro-player-accounts'),
                            number_format($settings['price_per_account'], 2)
                        ),
                    ],
                    'tax_behavior' => 'exclusive',
                    'unit_amount' => (int)($annual_price_per_account * 100),
                    'recurring' => [
                        'interval' => strtolower($membership->cycle_period ?: 'year'),
                        'interval_count' => $membership->cycle_number ?: 1
                    ],
                ],
                'quantity' => $extra_accounts,
            ];
        }

        return $line_items;
    }

    /**
     * Handle check gateway checkout to create Stripe invoice
     */
    public function handle_check_gateway_checkout($confirmed, $morder) {
        if (!$confirmed || $morder->gateway !== 'check') {
            return $confirmed;
        }

        // Create Stripe invoice for check orders
        $this->create_stripe_invoice_for_order($morder);

        return $confirmed;
    }

    /**
     * Create Stripe invoice for check payment
     */
    public function create_stripe_invoice_for_check($order) {
        // Only process check gateway orders that are pending
        if ($order->gateway !== 'check' || $order->status !== 'pending') {
            return;
        }

        // Check if invoice already created
        $existing_invoice_id = get_order_meta($order->id, 'stripe_invoice_id', true);
        if ($existing_invoice_id) {
            return;
        }

        $this->create_stripe_invoice_for_order($order);
    }

    /**
     * Create Stripe invoice for an order
     */
    private function create_stripe_invoice_for_order($order) {
        // Check if Stripe is configured
        if (!class_exists('\\Stripe\\Stripe')) {
            error_log('BB Player Accounts: Stripe SDK not available for invoice creation');
            return false;
        }

        // Get Stripe gateway to access API keys
        $stripe_gateway = new \PMProGateway_stripe();
        if (empty(bb_pmpro_Stripe_get_secretkey())) {
            error_log('BB Player Accounts: Stripe secret key not configured');
            return false;
        }

        try {
            // Initialize Stripe
            \Stripe\Stripe::setApiKey(bb_pmpro_Stripe_get_secretkey());

            // Get or create Stripe customer
            $customer_id = $this->get_or_create_stripe_customer($order);
            if (!$customer_id) {
                throw new \Exception('Failed to create Stripe customer');
            }

            // Get membership level and settings
            $level = pmpro_getLevel($order->membership_id);
            $settings = $this->level_settings->get_level_settings($order->membership_id);

            // Get player accounts for this order
            $player_accounts = $_SESSION['pmpro_player_accounts_checkout'] ??
                get_order_meta($order->id, 'player_accounts', true) ?:
                $settings['default_accounts'];

            $extra_accounts = max(0, $player_accounts - $settings['default_accounts']);

            // Calculate prices
            $base_price = $_SESSION['pmpro_original_initial_payment'] ?? $level->initial_payment;
            $annual_price_per_account = $settings['price_per_account'] * 12;
            $extra_cost_total = $extra_accounts * $annual_price_per_account;

            // Debug logging
            error_log('BB Player Accounts: Creating subscription for customer ' . $customer_id);
            error_log('BB Player Accounts: Base price: ' . $base_price);
            error_log('BB Player Accounts: Extra accounts: ' . $extra_accounts);
            error_log('BB Player Accounts: Extra cost: ' . $extra_cost_total);

            // Create or retrieve products and prices for the subscription
            $subscription_items = [];

            // 1. Create/retrieve membership product and price
            $membership_product_id = get_option('bb_pmpro_stripe_product_level_' . $level->id);
            if (!$membership_product_id) {
                $membership_product = \Stripe\Product::create([
                    'name' => $level->name,
                    'description' => sprintf(
                        __('Annual membership with %d player account(s) included', 'bb-pmpro-player-accounts'),
                        $settings['default_accounts']
                    ),
                    'metadata' => [
                        'pmpro_level_id' => $level->id,
                        'type' => 'membership'
                    ]
                ]);
                $membership_product_id = $membership_product->id;
                update_option('bb_pmpro_stripe_product_level_' . $level->id, $membership_product_id);
                error_log('BB Player Accounts: Created membership product: ' . $membership_product_id);
            }

            // Create price for membership
            $membership_price = \Stripe\Price::create([
                'product' => $membership_product_id,
                'unit_amount' => round($base_price * 100), // Convert to cents
                'currency' => get_currency_code(),
                'recurring' => [
                    'interval' => 'year',
                    'interval_count' => 1
                ],
                'metadata' => [
                    'pmpro_level_id' => $level->id,
                    'pmpro_order_id' => $order->id
                ]
            ]);

            $subscription_items[] = [
                'price' => $membership_price->id,
                'quantity' => 1
            ];

            error_log('BB Player Accounts: Created membership price: ' . $membership_price->id . ' for amount: ' . ($base_price * 100));

            // 2. Create/retrieve extra accounts product and price if needed
            if ($extra_accounts > 0 && $settings['price_per_account'] > 0) {
                $extra_accounts_product_id = get_option('bb_pmpro_stripe_product_extra_accounts_' . $level->id);
                if (!$extra_accounts_product_id) {
                    $extra_accounts_product = \Stripe\Product::create([
                        'name' => sprintf(__('Extra Player Account for %s', 'bb-pmpro-player-accounts'), $level->name),
                        'description' => __('Additional player account (billed annually)', 'bb-pmpro-player-accounts'),
                        'metadata' => [
                            'pmpro_level_id' => $level->id,
                            'type' => 'extra_player_account'
                        ]
                    ]);
                    $extra_accounts_product_id = $extra_accounts_product->id;
                    update_option('bb_pmpro_stripe_product_extra_accounts_' . $level->id, $extra_accounts_product_id);
                    error_log('BB Player Accounts: Created extra accounts product: ' . $extra_accounts_product_id);
                }

                // Create price for extra accounts
                $extra_accounts_price = \Stripe\Price::create([
                    'product' => $extra_accounts_product_id,
                    'unit_amount' => round($annual_price_per_account * 100), // Annual price per account in cents
                    'currency' => get_currency_code(),
                    'recurring' => [
                        'interval' => 'year',
                        'interval_count' => 1
                    ],
                    'metadata' => [
                        'pmpro_level_id' => $level->id,
                        'type' => 'extra_account'
                    ]
                ]);

                $subscription_items[] = [
                    'price' => $extra_accounts_price->id,
                    'quantity' => $extra_accounts
                ];

                error_log('BB Player Accounts: Created extra accounts price: ' . $extra_accounts_price->id . ' for amount: ' . ($annual_price_per_account * 100) . ' x ' . $extra_accounts);
            }

            // Create the subscription with collection_method = send_invoice
            $subscription_params = [
                'customer' => $customer_id,
                'items' => $subscription_items,
                'collection_method' => 'send_invoice',
                'days_until_due' => 30,
                'metadata' => [
                    'pmpro_order_id' => $order->id,
                    'pmpro_user_id' => $order->user_id,
                    'pmpro_level_id' => $order->membership_id,
                    'player_accounts' => $player_accounts,
                    'player_accounts_extra' => $extra_accounts,
                ],
                // Prorate from today (no trial period)
                'proration_behavior' => 'create_prorations',
                // Add invoice settings
//                'invoice_settings' => [
//                    'footer' => sprintf(
//                        __('Thank you for your membership. This invoice is for your %s membership with %d player accounts.', 'bb-pmpro-player-accounts'),
//                        $level->name,
//                        $player_accounts
//                    ),
//                    'custom_fields' => [
//                        [
//                            'name' => __('Player Accounts', 'bb-pmpro-player-accounts'),
//                            'value' => (string) $player_accounts,
//                        ],
//                    ],
//                ],
            ];

            // Add description if available
            if (!empty($level->description)) {
                $subscription_params['description'] = $level->description;
            }

            // Create the subscription
            $subscription = \Stripe\Subscription::create($subscription_params);

            error_log('BB Player Accounts: Created subscription: ' . $subscription->id);
            error_log('BB Player Accounts: Subscription status: ' . $subscription->status);
            error_log('BB Player Accounts: Latest invoice: ' . $subscription->latest_invoice);

            // Retrieve the latest invoice that was created with the subscription
            $invoice = null;
            if ($subscription->latest_invoice) {
                if (is_string($subscription->latest_invoice)) {
                    $invoice = \Stripe\Invoice::retrieve($subscription->latest_invoice);
                } else {
                    $invoice = $subscription->latest_invoice;
                }

                error_log('BB Player Accounts: Retrieved invoice: ' . $invoice->id . ' with amount: ' . $invoice->amount_due);

                // Send the invoice if it's not already sent
                if ($invoice->status === 'draft') {
                    $invoice = $invoice->finalizeInvoice();
                    error_log('BB Player Accounts: Finalized invoice: ' . $invoice->id);
                }

                if ($invoice->status === 'open') {
                    $invoice = $invoice->sendInvoice();
                    error_log('BB Player Accounts: Sent invoice: ' . $invoice->id);
                }
            }

            // Store subscription and invoice details in order meta
            update_order_meta($order->id, 'stripe_subscription_id', $subscription->id);
            update_order_meta($order->id, 'stripe_subscription_status', $subscription->status);
            update_order_meta($order->id, 'stripe_subscription_next_invoice', date('Y-m-d', $subscription->current_period_end));

            if ($invoice) {
                update_order_meta($order->id, 'stripe_invoice_id', $invoice->id);
                update_order_meta($order->id, 'stripe_invoice_url', $invoice->hosted_invoice_url);
                update_order_meta($order->id, 'stripe_invoice_pdf', $invoice->invoice_pdf);
                update_order_meta($order->id, 'stripe_invoice_status', $invoice->status);
                update_order_meta($order->id, 'stripe_invoice_amount', $invoice->amount_due / 100);
            }

            update_order_meta($order->id, 'player_accounts', $player_accounts);
            if ($extra_accounts > 0) {
                update_order_meta($order->id, 'player_accounts_extra', $extra_accounts);
                update_order_meta($order->id, 'player_accounts_extra_cost', $extra_cost_total);
            }

            // Store subscription ID in user meta for future reference
            update_user_meta($order->user_id, 'bb_pmpro_stripe_subscription_id', $subscription->id);

            // Log success with details
            error_log(sprintf(
                'BB Player Accounts: Stripe subscription created - ID: %s, Invoice: %s, Amount: %s %s',
                $subscription->id,
                $invoice ? $invoice->id : 'N/A',
                $invoice ? ($invoice->amount_due / 100) : '0',
                strtoupper($subscription->currency ?: get_currency_code())
            ));

            // Add order note with subscription details
            $note = sprintf(
                __('Stripe Subscription Created: %s', 'bb-pmpro-player-accounts'),
                $subscription->id
            );

            if ($invoice) {
                $note .= sprintf(
                    __(' | Invoice: %s (Amount: %s %s)', 'bb-pmpro-player-accounts'),
                    $invoice->id,
                    number_format($invoice->amount_due / 100, 2),
                    strtoupper($invoice->currency)
                );
            }

            $note .= sprintf(
                __(' | Next invoice: %s', 'bb-pmpro-player-accounts'),
                date('Y-m-d', $subscription->current_period_end)
            );

            $order->notes = trim($order->notes . "\n" . $note);
            $order->saveOrder();

            return $invoice ?: $subscription;

        } catch (\Exception $e) {
            error_log('BB Player Accounts: Stripe subscription creation failed - ' . $e->getMessage());
            error_log('BB Player Accounts: Stack trace - ' . $e->getTraceAsString());

            // Add error note to order
            $order->notes = trim($order->notes . "\n" . 'Stripe Subscription Error: ' . $e->getMessage());
            $order->saveOrder();

            return false;
        }
    }

    /**
     * Alternative approach to create invoice with explicit line items
     */
    private function create_stripe_invoice_alternative($order, $customer_id, $level, $settings, $player_accounts) {
        try {
            error_log('BB Player Accounts: Using alternative invoice creation method');

            $extra_accounts = max(0, $player_accounts - $settings['default_accounts']);
            $base_price = $_SESSION['pmpro_original_initial_payment'] ?? $level->initial_payment;
            $annual_price_per_account = $settings['price_per_account'] * 12;
            $extra_cost_total = $extra_accounts * $annual_price_per_account;

            // Create a draft invoice first
            $invoice = \Stripe\Invoice::create([
                'customer' => $customer_id,
                'collection_method' => 'send_invoice',
                'days_until_due' => 30,
                'metadata' => [
                    'pmpro_order_id' => $order->id,
                    'pmpro_user_id' => $order->user_id,
                    'pmpro_level_id' => $order->membership_id,
                    'player_accounts' => $player_accounts,
                ],
            ]);

            // Now add invoice items directly to this invoice
            if ($base_price > 0) {
                \Stripe\InvoiceItem::create([
                    'customer' => $customer_id,
                    'invoice' => $invoice->id, // Attach directly to this invoice
                    'amount' => round($base_price * 100),
                    'currency' => get_currency_code(),
                    'description' => sprintf(
                        __('%s - Annual membership with %d player account(s) included', 'bb-pmpro-player-accounts'),
                        $level->name,
                        $settings['default_accounts']
                    ),
                ]);
            }

            if ($extra_accounts > 0 && $settings['price_per_account'] > 0) {
                \Stripe\InvoiceItem::create([
                    'customer' => $customer_id,
                    'invoice' => $invoice->id, // Attach directly to this invoice
                    'amount' => round($extra_cost_total * 100),
                    'currency' => get_currency_code(),
                    'description' => sprintf(
                        __('%dx Extra player account for %s', 'bb-pmpro-player-accounts'),
                        $extra_accounts,
                        $level->name
                    ),
                ]);
            }

            // Retrieve the invoice to get updated totals
            $invoice = \Stripe\Invoice::retrieve($invoice->id);

            // Finalize and send
            if ($invoice->amount_due > 0) {
                $invoice = $invoice->finalizeInvoice();
                $invoice = $invoice->sendInvoice();
                error_log('BB Player Accounts: Alternative method successful - Invoice: ' . $invoice->id . ' Amount: ' . $invoice->amount_due);
            }

            return $invoice;

        } catch (\Exception $e) {
            error_log('BB Player Accounts: Alternative invoice method failed - ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get or create Stripe customer
     */
    private function get_or_create_stripe_customer($order) {
        try {
            // Check if user already has a Stripe customer ID
            $customer_id = get_user_meta($order->user_id, 'pmpro_stripe_customer_id', true);

            if ($customer_id) {
                // Verify customer exists in Stripe
                try {
                    $customer = \Stripe\Customer::retrieve($customer_id);
                    if ($customer && !$customer->deleted) {
                        return $customer_id;
                    }
                } catch (\Exception $e) {
                    // Customer doesn't exist, create new one
                    $customer_id = null;
                }
            }

            // Get user info
            $user = get_userdata($order->user_id);

            // Create new customer
            $customer = \Stripe\Customer::create([
                'email' => $user->user_email,
                'name' => trim($order->billing->name) ?: $user->display_name,
                'description' => sprintf('User #%d - %s', $order->user_id, $user->user_login),
                'metadata' => [
                    'pmpro_user_id' => $order->user_id,
                    'pmpro_level_id' => $order->membership_id,
                ],
                'address' => [
                    'line1' => $order->billing->street,
                    'city' => $order->billing->city,
                    'state' => $order->billing->state,
                    'postal_code' => $order->billing->zip,
                    'country' => $order->billing->country,
                ],
                'phone' => $order->billing->phone,
            ]);

            // Save customer ID
            update_user_meta($order->user_id, 'pmpro_stripe_customer_id', $customer->id);

            return $customer->id;

        } catch (\Exception $e) {
            error_log('BB Player Accounts: Failed to create Stripe customer - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Show Stripe invoice link on confirmation page
     */
    public function show_stripe_invoice_link() {
        global $pmpro_invoice;

        if (empty($pmpro_invoice) || $pmpro_invoice->gateway !== 'check') {
            return;
        }

        $invoice_url = get_order_meta($pmpro_invoice->id, 'stripe_invoice_url', true);
        $invoice_id = get_order_meta($pmpro_invoice->id, 'stripe_invoice_id', true);
        $subscription_id = get_order_meta($pmpro_invoice->id, 'stripe_subscription_id', true);
        $next_invoice_date = get_order_meta($pmpro_invoice->id, 'stripe_subscription_next_invoice', true);

        if ($invoice_url || $subscription_id) {
            ?>
            <div class="pmpro_stripe_invoice_link" style="margin: 20px 0; padding: 15px; background: #f0f8ff; border: 1px solid #0073aa; border-radius: 5px;">
                <h3><?php _e('Your Annual Subscription', 'bb-pmpro-player-accounts'); ?></h3>

                <?php if ($subscription_id): ?>
                    <p><?php _e('Your annual subscription has been created and will automatically renew each year.', 'bb-pmpro-player-accounts'); ?></p>
                <?php endif; ?>

                <?php if ($invoice_url): ?>
                    <p><?php _e('An invoice has been sent to your email address for this year\'s payment.', 'bb-pmpro-player-accounts'); ?></p>
                    <p>
                        <a href="<?php echo esc_url($invoice_url); ?>" target="_blank" class="pmpro_btn pmpro_btn-submit" style="display: inline-block; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none; border-radius: 3px;">
                            <?php _e('View and Pay Invoice', 'bb-pmpro-player-accounts'); ?>
                        </a>
                    </p>
                <?php endif; ?>

                <p style="color: #666; font-size: 0.9em;">
                    <?php if ($invoice_id): ?>
                        <?php printf(__('Current Invoice: %s', 'bb-pmpro-player-accounts'), $invoice_id); ?><br>
                    <?php endif; ?>
                    <?php if ($subscription_id): ?>
                        <?php printf(__('Subscription ID: %s', 'bb-pmpro-player-accounts'), $subscription_id); ?><br>
                    <?php endif; ?>
                    <?php if ($next_invoice_date): ?>
                        <?php printf(__('Next invoice date: %s', 'bb-pmpro-player-accounts'), $next_invoice_date); ?>
                    <?php endif; ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Add account info to invoice display (ACTION hook)
     */
    public function add_account_info_to_invoice($invoice): void {
        if (empty($invoice) || empty($invoice->membership_id)) {
            return;
        }

        $player_accounts = get_order_meta($invoice->id, 'player_accounts', true);
        $extra_cost = get_order_meta($invoice->id, 'player_accounts_extra_cost', true);
        $stripe_invoice_id = get_order_meta($invoice->id, 'stripe_invoice_id', true);
        $stripe_invoice_url = get_order_meta($invoice->id, 'stripe_invoice_url', true);

        // Since this is an action, we echo the content directly
        if ($player_accounts) {
            ?>
            <li>
                <strong><?php _e('Player Accounts:', 'bb-pmpro-player-accounts'); ?></strong>
                <?php echo esc_html($player_accounts); ?>
            </li>
            <?php
        }

        if ($extra_cost > 0) {
            ?>
            <li>
                <strong><?php _e('Extra Accounts Cost:', 'bb-pmpro-player-accounts'); ?></strong>
                €<?php echo number_format($extra_cost, 2, ',', '.'); ?>
            </li>
            <?php
        }

        if ($stripe_invoice_id && $invoice->gateway === 'check') {
            ?>
            <li>
                <strong><?php _e('Stripe Invoice:', 'bb-pmpro-player-accounts'); ?></strong>
                <?php echo esc_html($stripe_invoice_id); ?>
                <?php if ($stripe_invoice_url): ?>
                    - <a href="<?php echo esc_url($stripe_invoice_url); ?>" target="_blank">
                        <?php _e('View Invoice', 'bb-pmpro-player-accounts'); ?>
                    </a>
                <?php endif; ?>
            </li>
            <?php
        }
    }
}