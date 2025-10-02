<?php

namespace BB_PMPro_Account_Manager\Includes;

use function BB_PMPro_Player_Accounts\Includes\bb_pmpro_Stripe_get_secretkey;

class Account_Update_Handler {

    /**
     * @var Proration_Calculator
     */
    private Proration_Calculator $proration_calculator;

    /**
     * Constructor
     */
    public function __construct(Proration_Calculator $proration_calculator) {
        $this->proration_calculator = $proration_calculator;
    }

    /**
     * Initialize
     */
    public function init(): void {
        // Add hooks for handling updates
        add_action('bb_pmpro_accounts_updated', [$this, 'send_confirmation_email'], 10, 3);

        // Add filter to apply credits on checkout/renewal
        add_filter('pmpro_checkout_level', [$this, 'apply_pending_credits'], 10, 1);

        // Apply credits when orders are created for renewals
        add_action('pmpro_added_order', [$this, 'apply_credits_to_order'], 10, 1);
    }

    public function update_accounts(int $user_id, int $new_accounts): array {
        // Get current membership level
        $level = pmpro_getMembershipLevelForUser($user_id);

        if (!$level) {
            return [
                'success' => false,
                'message' => __('No active membership found.', 'bb-pmpro-account-manager'),
            ];
        }

        // Get level settings
        $settings = $this->get_level_settings($level->id);

        // Validate new account count
        if ($new_accounts < $settings['default_accounts']) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Minimum %d accounts required for your membership level.', 'bb-pmpro-account-manager'),
                    $settings['default_accounts']
                ),
            ];
        }

        $max_accounts = $settings['max_accounts'] ?: 25;
        if ($new_accounts > $max_accounts) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Maximum %d accounts allowed for your membership level.', 'bb-pmpro-account-manager'),
                    $max_accounts
                ),
            ];
        }

        // Get current accounts
        $current_accounts = get_user_meta($user_id, 'pmpro_player_accounts', true) ?: $settings['default_accounts'];

        // Check if change is needed
        if ($new_accounts == $current_accounts) {
            return [
                'success' => false,
                'message' => __('No change in account count.', 'bb-pmpro-account-manager'),
            ];
        }

        // Calculate proration
        $proration_data = $this->proration_calculator->calculate(
            $user_id,
            $current_accounts,
            $new_accounts,
            $level->id
        );

        // FIXED: Get the ACTUAL payment method, not from proration orders
        $payment_method = $this->get_user_payment_method($user_id);

        error_log('BB Account Manager: Detected payment method: ' . $payment_method);
        error_log('BB Account Manager: Proration amount: ' . $proration_data['amount']);

        // Process payment adjustment based on detected payment method
        if ($proration_data['proration_enabled'] && $proration_data['amount'] != 0) {
            if ($payment_method === 'check') {
                error_log('BB Account Manager: Processing as pay-by-check invoice');
                $payment_result = $this->process_invoice_adjustment($user_id, $proration_data);
            } elseif ($payment_method === 'stripe') {
                error_log('BB Account Manager: Processing as Stripe subscription');
                $payment_result = $this->process_stripe_proration($user_id, $proration_data);
            } else {
                // No payment method available, still create invoice for manual payment
                error_log('BB Account Manager: No clear payment method, creating invoice for manual payment');
                $payment_result = $this->process_invoice_adjustment($user_id, $proration_data);
            }

            if (!$payment_result['success']) {
                return $payment_result;
            }
        } else {
            $payment_result = ['success' => true];
        }

        // Update user meta
        update_user_meta($user_id, 'pmpro_player_accounts', $new_accounts);
        update_user_meta($user_id, 'pmpro_player_accounts_last_update', current_time('timestamp'));

        // Log the change
        $this->log_account_change($user_id, $current_accounts, $new_accounts, $proration_data);

        // Trigger action for other plugins
        do_action('bb_pmpro_accounts_updated', $user_id, $new_accounts, $current_accounts);

        // Merge payment result with response
        $response = [
            'success' => true,
            'message' => $payment_result['message'] ?? __('Your player accounts have been updated successfully!', 'bb-pmpro-account-manager'),
            'new_accounts' => $new_accounts,
            'previous_accounts' => $current_accounts,
            'proration' => $proration_data,
        ];

        // Add invoice URL if created
        if (isset($payment_result['invoice_url'])) {
            $response['invoice_url'] = $payment_result['invoice_url'];
        }

        return $response;
    }

    /**
     * Detect the user's actual payment method
     * Looks for the most recent non-proration order or checks for Stripe subscription
     */
    private function get_user_payment_method(int $user_id): string {
        global $wpdb;

        // First check if user has an active Stripe subscription
        $stripe_subscription_id = get_user_meta($user_id, 'pmpro_stripe_subscription_id', true);
        if (!empty($stripe_subscription_id)) {
            error_log('BB Account Manager: Found Stripe subscription: ' . $stripe_subscription_id);
            return 'stripe';
        }

        // Look for the most recent NON-proration order to determine payment method
        $sql = $wpdb->prepare(
            "SELECT gateway FROM $wpdb->pmpro_membership_orders 
        WHERE user_id = %d 
        AND gateway != 'proration'
        AND gateway != ''
        AND status IN ('success', 'pending')
        ORDER BY timestamp DESC 
        LIMIT 1",
            $user_id
        );

        $gateway = $wpdb->get_var($sql);

        if ($gateway) {
            error_log('BB Account Manager: Found payment gateway from orders: ' . $gateway);

            // Normalize gateway names
            if ($gateway === 'check') {
                return 'check';
            } elseif (in_array($gateway, ['stripe', 'stripe_checkout'])) {
                return 'stripe';
            }
        }

        // Check if there's a stored preference
        $stored_method = get_user_meta($user_id, 'pmpro_preferred_payment_method', true);
        if ($stored_method) {
            error_log('BB Account Manager: Using stored payment method: ' . $stored_method);
            return $stored_method;
        }

        // Default to check/invoice if no method found
        error_log('BB Account Manager: No payment method found, defaulting to check');
        return 'check';
    }

    /**
     * Process Stripe proration (renamed from process_stripe_adjustment)
     * Only for users with active Stripe subscriptions
     */
    private function process_stripe_proration(int $user_id, array $proration_data): array {
        // Check if user has Stripe subscription
        $subscription_id = get_user_meta($user_id, 'pmpro_stripe_subscription_id', true);

        if (!$subscription_id) {
            error_log('BB Account Manager: No Stripe subscription found');
            return [
                'success' => false,
                'message' => __('No active Stripe subscription found.', 'bb-pmpro-account-manager'),
            ];
        }

        // Initialize Stripe
        if (!class_exists('\\Stripe\\Stripe')) {
            return [
                'success' => false,
                'message' => __('Stripe payment processing is not available.', 'bb-pmpro-account-manager'),
            ];
        }

        try {
            $stripe_gateway = new \PMProGateway_stripe();
            \Stripe\Stripe::setApiKey(bb_pmpro_Stripe_get_secretkey());

            $customer_id = get_user_meta($user_id, 'pmpro_stripe_customer_id', true);

            if (!$customer_id) {
                throw new \Exception('No Stripe customer ID found');
            }

            if ($proration_data['type'] === 'charge') {
                // Create invoice item for immediate charge
                $invoice_item = \Stripe\InvoiceItem::create([
                    'customer' => $customer_id,
                    'amount' => round($proration_data['amount'] * 100), // Convert to cents
                    'currency' => 'eur',
                    'description' => sprintf(
                        __('Player accounts upgrade (%d to %d accounts) - %d days remaining', 'bb-pmpro-account-manager'),
                        $proration_data['current_accounts'],
                        $proration_data['new_accounts'],
                        $proration_data['days_remaining']
                    ),
                ]);

                // Create and pay invoice immediately
                $invoice = \Stripe\Invoice::create([
                    'customer' => $customer_id,
                    'auto_advance' => true,
                    'collection_method' => 'charge_automatically',
                ]);

                $invoice->finalizeInvoice();
                $paid_invoice = $invoice->pay();

                // Store invoice ID for reference
                update_user_meta($user_id, 'pmpro_last_adjustment_invoice', $paid_invoice->id);

                return [
                    'success' => true,
                    'message' => __('Payment processed successfully through Stripe.', 'bb-pmpro-account-manager'),
                ];

            } elseif ($proration_data['type'] === 'credit') {
                // Create credit note for downgrade
                \Stripe\InvoiceItem::create([
                    'customer' => $customer_id,
                    'amount' => round($proration_data['amount'] * 100), // Negative amount
                    'currency' => 'eur',
                    'description' => sprintf(
                        __('Player accounts downgrade credit (%d to %d accounts)', 'bb-pmpro-account-manager'),
                        $proration_data['current_accounts'],
                        $proration_data['new_accounts']
                    ),
                ]);

                return [
                    'success' => true,
                    'message' => __('Credit applied to your Stripe account.', 'bb-pmpro-account-manager'),
                ];
            }

        } catch (\Exception $e) {
            error_log('BB Account Manager Stripe Error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => sprintf(
                    __('Stripe error: %s', 'bb-pmpro-account-manager'),
                    $e->getMessage()
                ),
            ];
        }

        return [
            'success' => true,
            'message' => __('No payment adjustment needed.', 'bb-pmpro-account-manager'),
        ];
    }

    /**
     * Process invoice adjustment for pay-by-check users
     */
    private function process_invoice_adjustment(int $user_id, array $proration_data): array {
        global $wpdb;

        // For upgrades, create a proration order AND Stripe invoice
        if ($proration_data['type'] === 'charge' && $proration_data['amount'] > 0) {
            // First, create the PMPro order for tracking
            $order = new \MemberOrder();
            $order->user_id = $user_id;
            $order->membership_id = pmpro_getMembershipLevelForUser($user_id)->id;

            // Set the actual proration amount
            $order->InitialPayment = $proration_data['amount'];
            $order->subtotal = $proration_data['amount'];
            $order->total = $proration_data['amount'];
            $order->PaymentAmount = 0; // One-time charge
            $order->BillingPeriod = '';
            $order->BillingFrequency = '';

            // Use proration gateway
            $order->gateway = 'proration';
            $order->gateway_environment = pmpro_getOption('gateway_environment');
            $order->status = 'pending';
            $order->code = $order->getRandomCode();

            // Set billing info
            $user = get_userdata($user_id);
            $order->FirstName = get_user_meta($user_id, 'pmpro_bfirstname', true);
            $order->LastName = get_user_meta($user_id, 'pmpro_blastname', true);
            $order->Email = $user->user_email;

            $order->notes = sprintf(
                __('Player account adjustment: %d to %d accounts (€%.2f for %d days remaining)', 'bb-pmpro-account-manager'),
                $proration_data['current_accounts'],
                $proration_data['new_accounts'],
                $proration_data['amount'],
                $proration_data['days_remaining']
            );

            // Save the PMPro order
            if ($order->saveOrder()) {
                // Store adjustment details
                update_pmpro_membership_order_meta($order->id, 'is_proration_adjustment', true);
                update_pmpro_membership_order_meta($order->id, 'adjustment_type', 'upgrade');
                update_pmpro_membership_order_meta($order->id, 'old_accounts', $proration_data['current_accounts']);
                update_pmpro_membership_order_meta($order->id, 'new_accounts', $proration_data['new_accounts']);
                update_pmpro_membership_order_meta($order->id, 'proration_days', $proration_data['days_remaining']);

                // Create Stripe invoice for the proration
                $stripe_invoice_result = $this->create_stripe_proration_invoice($user_id, $order, $proration_data);

                if (!$stripe_invoice_result['success']) {
                    // Log error but don't fail the whole process
                    error_log('BB Account Manager: Failed to create Stripe invoice: ' . $stripe_invoice_result['message']);
                } else {
                    // Update order with Stripe invoice ID
                    $order->payment_transaction_id = $stripe_invoice_result['invoice_id'];
                    $order->saveOrder();
                }

                // Update future billing amount
                $this->update_future_billing($user_id, $proration_data['new_accounts']);

                // Send invoice email
                $this->send_adjustment_invoice($order, $proration_data);

                return [
                    'success' => true,
                    'message' => __('Account adjustment invoice created. Check your email for payment instructions.', 'bb-pmpro-account-manager'),
                    'order_id' => $order->id,
                    'invoice_url' => pmpro_url('invoice', '?invoice=' . $order->code),
                    'stripe_invoice_id' => $stripe_invoice_result['invoice_id'] ?? null,
                ];
            }
        }

        // Handle credits...
        return $this->process_credit_adjustment($user_id, $proration_data);
    }

    /**
     * Create Stripe invoice for proration amount
     */
    private function create_stripe_proration_invoice(int $user_id, $order, array $proration_data): array {
        // Check if Stripe is available
        if (!class_exists('\\Stripe\\Stripe')) {
            return ['success' => false, 'message' => 'Stripe not available'];
        }

        try {
            // Initialize Stripe
            $stripe_gateway = new \PMProGateway_stripe();
            \Stripe\Stripe::setApiKey(bb_pmpro_Stripe_get_secretkey());

            // Get or create Stripe customer
            $customer_id = get_user_meta($user_id, 'pmpro_stripe_customer_id', true);

            if (!$customer_id) {
                // Create customer if doesn't exist
                $user = get_userdata($user_id);
                $customer = \Stripe\Customer::create([
                    'email' => $user->user_email,
                    'name' => $user->display_name,
                    'metadata' => [
                        'user_id' => $user_id,
                        'username' => $user->user_login,
                    ],
                ]);
                $customer_id = $customer->id;
                update_user_meta($user_id, 'pmpro_stripe_customer_id', $customer_id);
            }

            error_log('BB Account Manager: Creating invoice for customer ' . $customer_id . ', amount: €' . $proration_data['amount']);

            // Method 1: Create invoice with line items directly (RECOMMENDED)
            $invoice = \Stripe\Invoice::create([
                'customer' => $customer_id,
                'collection_method' => 'send_invoice',
                'days_until_due' => 30, // 30 days to pay
                'description' => sprintf(
                    'Player account adjustment: %d to %d accounts',
                    $proration_data['current_accounts'],
                    $proration_data['new_accounts']
                ),
                'metadata' => [
                    'pmpro_order_id' => $order->id,
                    'type' => 'proration_adjustment',
                    'old_accounts' => $proration_data['current_accounts'],
                    'new_accounts' => $proration_data['new_accounts'],
                ],
                // Add line items directly to the invoice
                'pending_invoice_items_behavior' => 'include', // Include any pending items
            ]);

            // Create the invoice item and attach it to this specific invoice
            $invoice_item = \Stripe\InvoiceItem::create([
                'customer' => $customer_id,
                'invoice' => $invoice->id, // Attach directly to this invoice
                'amount' => round($proration_data['amount'] * 100), // Convert to cents
                'currency' => 'eur',
                'description' => sprintf(
                    'Player account adjustment: %d → %d accounts (%d days remaining)',
                    $proration_data['current_accounts'],
                    $proration_data['new_accounts'],
                    $proration_data['days_remaining']
                ),
                'metadata' => [
                    'pmpro_order_id' => $order->id,
                    'adjustment_type' => 'proration',
                    'old_accounts' => $proration_data['current_accounts'],
                    'new_accounts' => $proration_data['new_accounts'],
                ],
            ]);

            error_log('BB Account Manager: Created invoice item ' . $invoice_item->id . ' for invoice ' . $invoice->id);

            // Refresh the invoice to get the updated total
            $invoice = \Stripe\Invoice::retrieve($invoice->id);

            // Verify the invoice has items
            if ($invoice->amount_due == 0) {
                error_log('BB Account Manager: WARNING - Invoice has 0 amount, checking items...');

                // Try alternative method: Create item first, then invoice
                $invoice->delete(); // Delete the empty invoice

                // Create item without invoice specified
                $invoice_item = \Stripe\InvoiceItem::create([
                    'customer' => $customer_id,
                    'amount' => round($proration_data['amount'] * 100),
                    'currency' => 'eur',
                    'description' => sprintf(
                        'Player account adjustment: %d → %d accounts (%d days remaining)',
                        $proration_data['current_accounts'],
                        $proration_data['new_accounts'],
                        $proration_data['days_remaining']
                    ),
                ]);

                // Now create invoice which should pull in the item
                $invoice = \Stripe\Invoice::create([
                    'customer' => $customer_id,
                    'collection_method' => 'send_invoice',
                    'days_until_due' => 30,
                    'auto_advance' => false, // Don't auto-finalize yet
                    'description' => 'Player account adjustment',
                    'metadata' => [
                        'pmpro_order_id' => $order->id,
                        'type' => 'proration_adjustment',
                    ],
                ]);
            }

            // Finalize the invoice
            $invoice = $invoice->finalizeInvoice();

            error_log('BB Account Manager: Finalized invoice ' . $invoice->id . ' with amount: ' . $invoice->amount_due);

            // Send the invoice
            if ($invoice->status === 'open') {
                $invoice->sendInvoice();
                error_log('BB Account Manager: Sent invoice ' . $invoice->id);
            }

            return [
                'success' => true,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'invoice_url' => $invoice->hosted_invoice_url,
                'amount' => $invoice->amount_due / 100, // Convert back to euros
            ];

        } catch (\Exception $e) {
            error_log('BB Account Manager: Stripe invoice creation error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update future billing to reflect new account count
     */
    /**
     * Update future billing to reflect new account count
     */
    private function update_future_billing(int $user_id, int $new_accounts): void {
        // Update user meta for future reference
        update_user_meta($user_id, 'pmpro_player_accounts', $new_accounts);

        // Get membership level
        $level = pmpro_getMembershipLevelForUser($user_id);
        if (!$level) {
            return;
        }

        // Get level settings
        $settings = $this->get_level_settings($level->id);

        // Calculate new totals
        $extra_accounts = max(0, $new_accounts - $settings['default_accounts']);
        $old_accounts = get_user_meta($user_id, 'pmpro_player_accounts_last_quantity', true) ?: $settings['default_accounts'];
        $old_extra = max(0, $old_accounts - $settings['default_accounts']);

        error_log('BB Account Manager: Updating future billing - Old accounts: ' . $old_accounts . ', New accounts: ' . $new_accounts);
        error_log('BB Account Manager: Extra accounts - Old: ' . $old_extra . ', New: ' . $extra_accounts);

        // Update Stripe subscription if exists
        $subscription_id = get_user_meta($user_id, 'pmpro_stripe_subscription_id', true);
        $stripe_customer_id = get_user_meta($user_id, 'pmpro_stripe_customerid', true);
        if ($subscription_id) {
            $result = $this->update_stripe_subscription_quantity($subscription_id, $level->id, $old_accounts, $new_accounts, $settings);

            if ($result['success']) {
                // Store the new quantity for future reference
                update_user_meta($user_id, 'pmpro_player_accounts_last_quantity', $new_accounts);
                error_log('BB Account Manager: Successfully updated Stripe subscription quantities');
            } else {
                error_log('BB Account Manager: Failed to update Stripe subscription: ' . $result['message']);
            }
        }

        elseif ($stripe_customer_id){
            error_log('BB Account Manager: User has Stripe customer ID but no stripe subscription id present, trying to find subscription through customer id');
            // Try to find active subscription for this customer
            if (class_exists('\\Stripe\\Stripe')) {
                try {
                    \Stripe\Stripe::setApiKey(bb_pmpro_Stripe_get_secretkey());

                    $customer = \Stripe\Customer::retrieve([
                        'id' => $stripe_customer_id,
                        'expand' => ['subscriptions']
                    ]);
                    $subscriptions = $customer->subscriptions;


                    if ($subscriptions->data && count($subscriptions->data) > 0) {
                        $subscription = $subscriptions->data[0];
                        $subscription_id = $subscription->id;
                        update_user_meta($user_id, 'pmpro_stripe_subscription_id', $subscription_id);
                        error_log('BB Account Manager: Found active subscription ' . $subscription_id . ' for customer ' . $stripe_customer_id);

                        // Now update the subscription quantities
                        $result = $this->update_stripe_subscription_quantity($subscription_id, $level->id, $old_accounts, $new_accounts, $settings);

                        if ($result['success']) {
                            // Store the new quantity for future reference
                            update_user_meta($user_id, 'pmpro_player_accounts_last_quantity', $new_accounts);
                            error_log('BB Account Manager: Successfully updated Stripe subscription quantities');
                        } else {
                            error_log('BB Account Manager: Failed to update Stripe subscription: ' . $result['message']);
                        }
                    } else {
                        error_log('BB Account Manager: No active subscriptions found for customer ' . $stripe_customer_id);
                    }
                } catch (\Exception $e) {
                    error_log('BB Account Manager: Error retrieving subscriptions for customer ' . $stripe_customer_id . ': ' . $e->getMessage());
                }
            } else {
                error_log('BB Account Manager: Stripe class not available to retrieve subscriptions');
            }
        }

        else {
            // For non-Stripe users, just store the values
            update_user_meta($user_id, 'pmpro_next_billing_accounts', $new_accounts);

            // Calculate and store the new billing amount
            $base_amount = floatval($level->billing_amount);
            $extra_cost = $extra_accounts * ($settings['price_per_account'] * 12);
            $new_total = $base_amount + $extra_cost;

            update_user_meta($user_id, 'pmpro_next_billing_amount', $new_total);
            error_log('BB Account Manager: Stored future billing amount: €' . $new_total . ' for ' . $new_accounts . ' accounts');
        }
    }

    /**
     * Update Stripe subscription quantities for player accounts
     */
    /**
     * Update Stripe subscription quantities for player accounts
     */
    private function update_stripe_subscription_quantity(
        string $subscription_id,
        int $level_id,
        int $old_accounts,
        int $new_accounts,
        array $settings
    ): array {
        try {
            // Initialize Stripe
            \Stripe\Stripe::setApiKey(bb_pmpro_Stripe_get_secretkey());

            // Retrieve the subscription with expanded items
            $subscription = \Stripe\Subscription::retrieve([
                'id' => $subscription_id,
                'expand' => ['items.data.price.product']
            ]);

            if (!$subscription || $subscription->status === 'canceled') {
                return ['success' => false, 'message' => 'Subscription not found or canceled'];
            }

            error_log('BB Account Manager: Retrieved subscription ' . $subscription_id . ' with ' . count($subscription->items->data) . ' items');

            // Calculate extra accounts needed
            $default_accounts = $settings['default_accounts'];
            $new_extra = max(0, $new_accounts - $default_accounts);
            $old_extra = max(0, $old_accounts - $default_accounts);

            error_log('BB Account Manager: Account calculation - Default: ' . $default_accounts . ', Old total: ' . $old_accounts . ', New total: ' . $new_accounts);
            error_log('BB Account Manager: Extra accounts - Old: ' . $old_extra . ', New: ' . $new_extra);

            // Find the Extra Player Account product
            $extra_accounts_item = null;
            $base_membership_item = null;

            foreach ($subscription->items->data as $item) {
                $product = $item->price->product;

                // Get product object if we only have ID
                if (is_string($product)) {
                    $product = \Stripe\Product::retrieve($product);
                }

                $product_name = $product->name ?? '';
                error_log('BB Account Manager: Found product: ' . $product_name . ' (Item: ' . $item->id . ', Quantity: ' . $item->quantity . ')');

                // Check if this is the extra accounts product
                if (stripos($product_name, 'extra player account') !== false ||
                    stripos($product_name, 'additional player account') !== false ||
                    (isset($product->metadata->type) && $product->metadata->type === 'extra_accounts')) {

                    $extra_accounts_item = $item;
                    error_log('BB Account Manager: Identified as Extra Accounts product');

                } elseif (stripos($product_name, 'standaard') !== false ||
                    stripos($product_name, 'plus') !== false ||
                    stripos($product_name, 'membership') !== false ||
                    (isset($product->metadata->type) && $product->metadata->type === 'base_membership')) {

                    $base_membership_item = $item;
                    error_log('BB Account Manager: Identified as Base Membership product');
                }
            }

            // Prepare updates
            $updates = [];
            $needs_update = false;

            if ($new_extra > 0) {
                if ($extra_accounts_item) {
                    // Update existing extra accounts item if quantity changed
                    if ($extra_accounts_item->quantity != $new_extra) {
                        $updates[] = [
                            'id' => $extra_accounts_item->id,
                            'quantity' => $new_extra
                        ];
                        $needs_update = true;
                        error_log('BB Account Manager: Will update extra accounts from ' . $extra_accounts_item->quantity . ' to ' . $new_extra);
                    } else {
                        error_log('BB Account Manager: Extra accounts quantity already correct at ' . $new_extra);
                    }
                } else {
                    // Need to add extra accounts product to subscription
                    error_log('BB Account Manager: No extra accounts product found in subscription');

                    // Try to find existing price or create new one
                    $price_id = $this->find_or_create_extra_accounts_price_for_level($level_id, $settings);

                    if ($price_id) {
                        $updates[] = [
                            'price' => $price_id,
                            'quantity' => $new_extra
                        ];
                        $needs_update = true;
                        error_log('BB Account Manager: Will add extra accounts product with price ' . $price_id . ' and quantity ' . $new_extra);
                    } else {
                        return ['success' => false, 'message' => 'Could not create extra accounts price'];
                    }
                }
            } elseif ($extra_accounts_item && $new_extra == 0) {
                // Remove extra accounts item if no longer needed
                $updates[] = [
                    'id' => $extra_accounts_item->id,
                    'deleted' => true
                ];
                $needs_update = true;
                error_log('BB Account Manager: Will remove extra accounts product (quantity going to 0)');
            }

            // Apply updates if needed
            if ($needs_update) {
                $update_params = [
                    'items' => $updates,
                    'proration_behavior' => 'none', // We handle proration separately
                    'metadata' => [
                        'player_accounts_total' => $new_accounts,
                        'player_accounts_extra' => $new_extra,
                        'last_update' => current_time('mysql'),
                        'update_source' => 'account_manager'
                    ]
                ];

                error_log('BB Account Manager: Updating subscription with params: ' . json_encode($update_params));

                $updated_subscription = \Stripe\Subscription::update($subscription_id, $update_params);

                // Verify the update
                $new_total = 0;
                foreach ($updated_subscription->items->data as $item) {
                    $amount = ($item->price->unit_amount * $item->quantity) / 100;
                    $new_total += $amount;
                    error_log('BB Account Manager: Updated item - ' . $item->id . ' - Quantity: ' . $item->quantity . ' - Amount: €' . $amount);
                }

                error_log('BB Account Manager: Subscription updated successfully. New total: €' . $new_total . '/year');

                return [
                    'success' => true,
                    'message' => sprintf('Subscription updated: %d accounts (€%.2f/year)', $new_accounts, $new_total),
                    'total_amount' => $new_total,
                    'next_invoice_amount' => $new_total
                ];
            } else {
                // Just update metadata if no quantity changes needed
                \Stripe\Subscription::update($subscription_id, [
                    'metadata' => [
                        'player_accounts_total' => $new_accounts,
                        'player_accounts_extra' => $new_extra,
                        'last_update' => current_time('mysql')
                    ]
                ]);

                return [
                    'success' => true,
                    'message' => 'No quantity changes needed, metadata updated'
                ];
            }

        } catch (\Exception $e) {
            error_log('BB Account Manager: Stripe error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Find or create the Extra Player Accounts price for a membership level
     */
    private function find_or_create_extra_accounts_price_for_level(int $level_id, array $settings): ?string {
        try {
            \Stripe\Stripe::setApiKey(bb_pmpro_Stripe_get_secretkey());

            // Look for existing prices with the right amount
            $annual_amount = $settings['price_per_account'] * 12 * 100; // €18.00 = 1800 cents

            // Search for existing price
            $prices = \Stripe\Price::all([
                'currency' => 'eur',
                'recurring' => ['interval' => 'year'],
                'active' => true,
                'limit' => 100,
                'expand' => ['data.product']
            ]);

            foreach ($prices->data as $price) {
                if ($price->unit_amount == $annual_amount) {
                    $product = $price->product;

                    // Check if this is an extra accounts product
                    if (is_object($product)) {
                        $product_name = strtolower($product->name ?? '');
                        if (strpos($product_name, 'extra player account') !== false) {
                            error_log('BB Account Manager: Found existing price ' . $price->id . ' for extra accounts');
                            return $price->id;
                        }
                    }
                }
            }

            // If not found, create new product and price
            error_log('BB Account Manager: Creating new Extra Player Account product and price');

            // Get level name for product naming
            $level = pmpro_getLevel($level_id);
            $level_name = $level->name ?? 'Membership';

            // Create product
            $product = \Stripe\Product::create([
                'name' => 'Extra Player Account for ' . $level_name,
                'description' => 'Additional player account beyond membership default',
                'metadata' => [
                    'type' => 'extra_accounts',
                    'level_id' => $level_id
                ]
            ]);

            // Create price
            $price = \Stripe\Price::create([
                'product' => $product->id,
                'unit_amount' => $annual_amount,
                'currency' => 'eur',
                'recurring' => [
                    'interval' => 'year',
                    'interval_count' => 1
                ],
                'metadata' => [
                    'type' => 'extra_accounts',
                    'level_id' => $level_id
                ]
            ]);

            // Store for future use
            update_option('pmpro_stripe_extra_accounts_price_level_' . $level_id, $price->id);

            error_log('BB Account Manager: Created price ' . $price->id . ' for product ' . $product->id);

            return $price->id;

        } catch (\Exception $e) {
            error_log('BB Account Manager: Error finding/creating price: ' . $e->getMessage());
            return null;
        }
    }
    /**
     * Store pending credit for future use
     */
    private function store_pending_credit(int $user_id, float $credit_amount, int $order_id): void {
        $pending_credits = get_user_meta($user_id, 'pmpro_pending_credits', true) ?: [];

        $pending_credits[] = [
            'amount' => $credit_amount,
            'order_id' => $order_id,
            'date' => current_time('mysql'),
            'status' => 'available',
            'source' => 'account_adjustment',
        ];

        update_user_meta($user_id, 'pmpro_pending_credits', $pending_credits);
    }

    /**
     * Process credit adjustment for downgrades
     */
    private function process_credit_adjustment(int $user_id, array $proration_data): array {
        // Handle credits (downgrades)
        if ($proration_data['type'] === 'credit' && $proration_data['amount'] < 0) {
            // Create a credit order for record keeping
            $order = new \MemberOrder();
            $order->user_id = $user_id;
            $order->membership_id = pmpro_getMembershipLevelForUser($user_id)->id;

            // Negative amount for credit
            $order->InitialPayment = $proration_data['amount']; // Already negative
            $order->subtotal = $proration_data['amount'];
            $order->total = $proration_data['amount'];
            $order->PaymentAmount = 0;
            $order->BillingPeriod = '';
            $order->BillingFrequency = '';

            // Use proration gateway
            $order->gateway = 'proration';
            $order->gateway_environment = pmpro_getOption('gateway_environment');

            // Set as success since it's a credit
            $order->status = 'success';

            $order->code = $order->getRandomCode();

            $user = get_userdata($user_id);
            $order->FirstName = get_user_meta($user_id, 'pmpro_bfirstname', true);
            $order->LastName = get_user_meta($user_id, 'pmpro_blastname', true);
            $order->Email = $user->user_email;

            $order->notes = sprintf(
                __('Player account downgrade credit: %d to %d accounts (€%.2f credit for %d days remaining)', 'bb-pmpro-account-manager'),
                $proration_data['current_accounts'],
                $proration_data['new_accounts'],
                abs($proration_data['amount']),
                $proration_data['days_remaining']
            );

            if ($order->saveOrder()) {
                // Store credit details
                update_pmpro_membership_order_meta($order->id, 'is_proration_adjustment', true);
                update_pmpro_membership_order_meta($order->id, 'adjustment_type', 'credit');
                update_pmpro_membership_order_meta($order->id, 'old_accounts', $proration_data['current_accounts']);
                update_pmpro_membership_order_meta($order->id, 'new_accounts', $proration_data['new_accounts']);
                update_pmpro_membership_order_meta($order->id, 'credit_amount', abs($proration_data['amount']));
                update_pmpro_membership_order_meta($order->id, 'proration_days', $proration_data['days_remaining']);

                // Store as pending credit for future use
                $this->store_pending_credit($user_id, abs($proration_data['amount']), $order->id);

                // Create Stripe credit note if customer exists
                $this->create_stripe_credit_note($user_id, $proration_data);

                // Update future billing amount (important for next renewal)
                $this->update_future_billing($user_id, $proration_data['new_accounts']);

                // Send credit confirmation email
                $this->send_credit_email($user_id, $order, $proration_data);

                return [
                    'success' => true,
                    'message' => sprintf(
                        __('Credit of €%.2f has been added to your account and will be applied to your next invoice.', 'bb-pmpro-account-manager'),
                        abs($proration_data['amount'])
                    ),
                    'order_id' => $order->id,
                    'credit_amount' => abs($proration_data['amount']),
                ];
            } else {
                return [
                    'success' => false,
                    'message' => __('Failed to process credit adjustment.', 'bb-pmpro-account-manager'),
                ];
            }
        }

        // No adjustment needed (amount is 0 or type is 'none')
        return [
            'success' => true,
            'message' => __('No payment adjustment needed for this change.', 'bb-pmpro-account-manager'),
        ];
    }

    /**
     * Create Stripe credit note for downgrades
     */
    private function create_stripe_credit_note(int $user_id, array $proration_data): void {
        try {
            $customer_id = get_user_meta($user_id, 'pmpro_stripe_customer_id', true);

            if (!$customer_id) {
                return; // No Stripe customer, skip
            }

            // Initialize Stripe
            \Stripe\Stripe::setApiKey(bb_pmpro_Stripe_get_secretkey());

            // Create a negative invoice item (credit) for the customer
            // This will be automatically applied to the next invoice
            $credit_item = \Stripe\InvoiceItem::create([
                'customer' => $customer_id,
                'amount' => round($proration_data['amount'] * 100), // Negative amount in cents
                'currency' => 'eur',
                'description' => sprintf(
                    'Credit: Player account downgrade (%d → %d accounts, %d days remaining)',
                    $proration_data['current_accounts'],
                    $proration_data['new_accounts'],
                    $proration_data['days_remaining']
                ),
                'metadata' => [
                    'type' => 'proration_credit',
                    'old_accounts' => $proration_data['current_accounts'],
                    'new_accounts' => $proration_data['new_accounts'],
                    'days_remaining' => $proration_data['days_remaining'],
                ],
            ]);

            error_log('BB Account Manager: Created Stripe credit item ' . $credit_item->id . ' for customer ' . $customer_id . ': €' . abs($proration_data['amount']));

        } catch (\Exception $e) {
            error_log('BB Account Manager: Failed to create Stripe credit: ' . $e->getMessage());
            // Don't fail the whole process if Stripe credit creation fails
        }
    }

    /**
     * Send credit confirmation email
     */
    private function send_credit_email(int $user_id, $order, array $proration_data): void {
        $user = get_userdata($user_id);

        if (!$user) {
            return;
        }

        $subject = sprintf(
            __('Credit Applied - Player Account Adjustment - %s', 'bb-pmpro-account-manager'),
            get_bloginfo('name')
        );

        $message = sprintf(__("Dear %s,\n\n", 'bb-pmpro-account-manager'), $user->display_name);

        $message .= __("A credit has been applied to your account for your player account adjustment.\n\n", 'bb-pmpro-account-manager');

        $message .= __("Credit Details:\n", 'bb-pmpro-account-manager');
        $message .= sprintf(__("Reference Number: %s\n", 'bb-pmpro-account-manager'), $order->code);
        $message .= sprintf(__("Credit Amount: €%.2f\n", 'bb-pmpro-account-manager'), abs($proration_data['amount']));
        $message .= sprintf(
            __("Account Change: %d → %d accounts\n", 'bb-pmpro-account-manager'),
            $proration_data['current_accounts'],
            $proration_data['new_accounts']
        );
        $message .= sprintf(
            __("Days Remaining in Period: %d\n\n", 'bb-pmpro-account-manager'),
            $proration_data['days_remaining']
        );

        $message .= __("This credit will be automatically applied to your next invoice.\n\n", 'bb-pmpro-account-manager');

        $message .= sprintf(
            __("If you have any questions, please contact us.\n\nBest regards,\n%s", 'bb-pmpro-account-manager'),
            get_bloginfo('name')
        );

        // Send email to user
        wp_mail($user->user_email, $subject, $message);

        // Also notify admin
        $admin_subject = sprintf(
            __('Credit Applied - Player Account Downgrade - %s', 'bb-pmpro-account-manager'),
            $user->user_login
        );
        wp_mail(get_option('admin_email'), $admin_subject, $message);
    }

    /**
     * Send adjustment invoice email
     */
    private function send_adjustment_invoice($order, $proration_data): void {
        $user = get_userdata($order->user_id);

        $subject = sprintf(
            __('Invoice for Player Account Upgrade - %s', 'bb-pmpro-account-manager'),
            get_bloginfo('name')
        );

        // Build email body
        $message = sprintf(__("Dear %s,\n\n", 'bb-pmpro-account-manager'), $user->display_name);
        $message .= __("An invoice has been created for your player account upgrade.\n\n", 'bb-pmpro-account-manager');

        $message .= __("Invoice Details:\n", 'bb-pmpro-account-manager');
        $message .= sprintf(__("Invoice Number: %s\n", 'bb-pmpro-account-manager'), $order->code);
        $message .= sprintf(__("Amount Due: €%.2f\n", 'bb-pmpro-account-manager'), $order->InitialPayment);
        $message .= sprintf(
            __("Account Change: %d → %d accounts\n", 'bb-pmpro-account-manager'),
            $proration_data['current_accounts'],
            $proration_data['new_accounts']
        );
        $message .= sprintf(
            __("Days Remaining in Period: %d\n\n", 'bb-pmpro-account-manager'),
            $proration_data['days_remaining']
        );

        // Add invoice link
        $invoice_url = pmpro_url('invoice', '?invoice=' . $order->code);
        $message .= sprintf(__("View Invoice: %s\n\n", 'bb-pmpro-account-manager'), $invoice_url);

        // Add payment instructions
        $message .= __("Payment Instructions:\n", 'bb-pmpro-account-manager');
        $message .= pmpro_getOption('instructions') . "\n\n";

        $message .= sprintf(
            __("Thank you,\n%s", 'bb-pmpro-account-manager'),
            get_bloginfo('name')
        );

        // Send email
        wp_mail($user->user_email, $subject, $message);

        // Also send admin notification
        $admin_email = get_option('admin_email');
        $admin_subject = sprintf(
            __('New Player Account Upgrade Invoice - %s', 'bb-pmpro-account-manager'),
            $user->user_login
        );
        wp_mail($admin_email, $admin_subject, $message);
    }

    /**
     * Log account change
     */
    private function log_account_change(
        int $user_id,
        int $old_accounts,
        int $new_accounts,
        array $proration_data
    ): void {
        global $wpdb;

        // Store in user meta
        $log = get_user_meta($user_id, 'pmpro_account_changes_log', true) ?: [];

        $log_entry = [
            'timestamp' => current_time('timestamp'),
            'old_accounts' => $old_accounts,
            'new_accounts' => $new_accounts,
            'proration_amount' => $proration_data['amount'] ?? 0,
            'proration_type' => $proration_data['type'] ?? 'none',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        $log[] = $log_entry;

        // Keep only last 100 entries
        $log = array_slice($log, -100);

        update_user_meta($user_id, 'pmpro_account_changes_log', $log);

        // Also log to notes if PMPro notes addon is active
        if (function_exists('pmpron_add_note')) {
            $note = sprintf(
                __('Player accounts changed from %d to %d. Proration: %s€%.2f', 'bb-pmpro-account-manager'),
                $old_accounts,
                $new_accounts,
                ($proration_data['type'] ?? '') === 'credit' ? '-' : '+',
                abs((float) ($proration_data['amount'] ?? 0))
            );
            pmpron_add_note($user_id, $note);
        }
    }

    /**
     * Send confirmation email
     */
    public function send_confirmation_email(int $user_id, int $new_accounts, int $old_accounts): void {
        $user = get_userdata($user_id);

        if (!$user) {
            return;
        }

        $subject = sprintf(
            __('Player Accounts Updated - %s', 'bb-pmpro-account-manager'),
            get_bloginfo('name')
        );

        $message = sprintf(
            __('Dear %s,\n\n', 'bb-pmpro-account-manager'),
            $user->display_name
        );

        $message .= __('Your player accounts have been successfully updated.\n\n', 'bb-pmpro-account-manager');

        $message .= sprintf(__('Previous accounts: %d\n', 'bb-pmpro-account-manager'), $old_accounts);
        $message .= sprintf(__('New accounts: %d\n\n', 'bb-pmpro-account-manager'), $new_accounts);

        if ($new_accounts > $old_accounts) {
            $message .= __('The additional charges will be processed according to your billing cycle.\n\n', 'bb-pmpro-account-manager');
        } elseif ($new_accounts < $old_accounts) {
            $message .= __('Credit will be applied to your account for the removed accounts.\n\n', 'bb-pmpro-account-manager');
        }

        $message .= sprintf(__('If you have any questions, please contact us.\n\nBest regards,\n%s', 'bb-pmpro-account-manager'), get_bloginfo('name'));

        // Send email
        wp_mail($user->user_email, $subject, $message);
    }

    /**
     * Apply pending credits to checkout level
     */
    public function apply_pending_credits($level) {
        if (!is_user_logged_in() || empty($level)) {
            return $level;
        }

        $user_id = get_current_user_id();
        $pending_adjustments = get_user_meta($user_id, 'pmpro_pending_account_adjustments', true);

        if (!empty($pending_adjustments)) {
            $total_credit = 0;

            foreach ($pending_adjustments as $adjustment) {
                if ($adjustment['type'] === 'credit' && $adjustment['status'] === 'pending') {
                    $total_credit += abs($adjustment['amount']);
                }
            }

            if ($total_credit > 0) {
                // Apply credit to this checkout
                $original_price = $level->initial_payment;
                $level->initial_payment = max(0, $level->initial_payment - $total_credit);

                // Store the credit amount in session for later reference
                $_SESSION['pmpro_credit_applied'] = $total_credit;
                $_SESSION['pmpro_original_price'] = $original_price;

                // Add note about credit
                pmpro_setMessage(
                    sprintf(
                        __('Credit of €%.2f has been applied to this order.', 'bb-pmpro-account-manager'),
                        $total_credit
                    ),
                    'pmpro_success'
                );
            }
        }

        return $level;
    }

    /**
     * Mark credits as applied when order is completed
     */
    public function apply_credits_to_order($order) {
        // Only process if credit was applied
        if (empty($_SESSION['pmpro_credit_applied'])) {
            return;
        }

        $user_id = $order->user_id;
        $credit_applied = $_SESSION['pmpro_credit_applied'];

        // Get pending adjustments
        $pending_adjustments = get_user_meta($user_id, 'pmpro_pending_account_adjustments', true);

        if (!empty($pending_adjustments)) {
            $credits_to_apply = $credit_applied;

            // Mark credits as applied
            foreach ($pending_adjustments as &$adjustment) {
                if ($adjustment['type'] === 'credit' &&
                    $adjustment['status'] === 'pending' &&
                    $credits_to_apply > 0) {

                    $credit_amount = abs($adjustment['amount']);

                    if ($credit_amount <= $credits_to_apply) {
                        // Full credit applied
                        $adjustment['status'] = 'applied';
                        $adjustment['applied_date'] = current_time('mysql');
                        $adjustment['applied_to_order'] = $order->id;
                        $credits_to_apply -= $credit_amount;
                    } else {
                        // Partial credit applied
                        $adjustment['amount'] = -($credit_amount - $credits_to_apply);

                        // Create a new entry for the applied portion
                        $applied_adjustment = $adjustment;
                        $applied_adjustment['amount'] = -$credits_to_apply;
                        $applied_adjustment['status'] = 'applied';
                        $applied_adjustment['applied_date'] = current_time('mysql');
                        $applied_adjustment['applied_to_order'] = $order->id;
                        $pending_adjustments[] = $applied_adjustment;

                        $credits_to_apply = 0;
                    }

                    if ($credits_to_apply <= 0) {
                        break;
                    }
                }
            }

            // Update user meta
            update_user_meta($user_id, 'pmpro_pending_account_adjustments', $pending_adjustments);

            // Add note to order
            if (!empty($order->notes)) {
                $order->notes .= "\n";
            }
            $order->notes .= sprintf(
                __('Credit applied: €%.2f', 'bb-pmpro-account-manager'),
                $_SESSION['pmpro_credit_applied']
            );
            $order->saveOrder();

            // Store credit info in order meta
            update_pmpro_membership_order_meta($order->id, 'credit_applied', $_SESSION['pmpro_credit_applied']);
            update_pmpro_membership_order_meta($order->id, 'original_price', $_SESSION['pmpro_original_price']);
        }

        // Clear session
        unset($_SESSION['pmpro_credit_applied']);
        unset($_SESSION['pmpro_original_price']);
    }

    /**
     * Get level settings
     */
    private function get_level_settings(int $level_id): array {
        return (array) get_option('pmpro_player_accounts_level_' . $level_id, [
            'default_accounts' => 1,
            'allow_extra_accounts' => false,
            'price_per_account' => 0,
            'max_accounts' => 1,
            'enable_proration' => true,
            'features' => '',
        ]);
    }
}
