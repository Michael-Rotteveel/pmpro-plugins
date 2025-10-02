<?php

namespace BB_PMPro_Player_Accounts\Includes;

use BB_PMPro_Player_Accounts\Traits\Account_Calculations;

class Checkout_Handler {

    use Account_Calculations;

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
        // Use the after_pricing_fields hook for better placement
        add_action('pmpro_checkout_after_pricing_fields', [$this, 'render_account_selector']);
        add_filter('pmpro_checkout_level', [$this, 'modify_checkout_level']);
        add_action('pmpro_checkout_before_processing', [$this, 'validate_accounts']);
        add_action('pmpro_after_checkout', [$this, 'save_user_accounts'], 10, 2);
        add_filter('pmpro_registration_checks', [$this, 'validate_account_selection']);
    }

    /**
     * Render account selector field
     */
    public function render_account_selector(): void {
        global $pmpro_level;

        if (empty($pmpro_level)) {
            return;
        }

        $settings = $this->level_settings->get_level_settings($pmpro_level->id);

        // Always show the field if accounts are configurable, even if not allowing extras
        // This ensures users see their included accounts
        $template_file = BB_PMPRO_PLAYER_ACCOUNTS_DIR . 'templates/checkout/account-selector.php';
        if (file_exists($template_file)) {
            include $template_file;
        }
    }

    /**
     * Modify checkout level with additional costs
     */
    public function modify_checkout_level($level) {
        if (empty($level) || empty($_REQUEST['player_accounts'])) {
            return $level;
        }

        $settings = $this->level_settings->get_level_settings($level->id);

        // Only calculate additional cost if extra accounts are allowed
        if (!$settings['allow_extra_accounts']) {
            return $level;
        }

        $requested_accounts = (int) $_REQUEST['player_accounts'];

        // Calculate additional cost (monthly price * 12 for annual)
        $additional_cost = $this->calculate_additional_cost(
            $requested_accounts,
            $settings['default_accounts'],
            $settings['price_per_account'] // This is monthly, will be converted to annual in the trait
        );

        if ($additional_cost > 0) {
            // Store original pricing for Stripe separation
            $_SESSION['pmpro_base_price'] = $level->initial_payment;
            $_SESSION['pmpro_extra_accounts_cost'] = $additional_cost;

            // Modify the level object for this checkout
            $level->initial_payment = $level->initial_payment + $additional_cost;
            $level->billing_amount = $level->billing_amount + $additional_cost;

            // Store in session for later reference
            $_SESSION['pmpro_player_accounts_extra_cost'] = $additional_cost;
            $_SESSION['pmpro_player_accounts_count'] = $requested_accounts;
        }

        return $level;
    }

    /**
     * Validate account selection
     */
    public function validate_account_selection($pmpro_continue_registration) {
        if (!$pmpro_continue_registration) {
            return $pmpro_continue_registration;
        }

        global $pmpro_level;

        if (empty($pmpro_level)) {
            return $pmpro_continue_registration;
        }

        $settings = $this->level_settings->get_level_settings($pmpro_level->id);

        if ($settings['allow_extra_accounts'] && isset($_REQUEST['player_accounts'])) {
            $requested_accounts = (int) $_REQUEST['player_accounts'];

            if ($requested_accounts < $settings['default_accounts']) {
                pmpro_setMessage(
                    sprintf(
                        __('Minimum %d player accounts required.', 'bb-pmpro-player-accounts'),
                        $settings['default_accounts']
                    ),
                    'pmpro_error'
                );
                return false;
            }

            $max = $settings['max_accounts'] ?: 25;
            if ($requested_accounts > $max) {
                pmpro_setMessage(
                    sprintf(
                        __('Maximum %d player accounts allowed.', 'bb-pmpro-player-accounts'),
                        $max
                    ),
                    'pmpro_error'
                );
                return false;
            }
        }

        return $pmpro_continue_registration;
    }

    /**
     * Validate accounts before processing
     */
    public function validate_accounts(): void {
        global $pmpro_level;

        if (empty($pmpro_level)) {
            return;
        }

        $settings = $this->level_settings->get_level_settings($pmpro_level->id);

        // Set default accounts if not provided
        if (!isset($_REQUEST['player_accounts'])) {
            $_REQUEST['player_accounts'] = $settings['default_accounts'];
        }
    }

    /**
     * Save user accounts after checkout
     */
    public function save_user_accounts($user_id, $order): void {
        $accounts = $_SESSION['pmpro_player_accounts_count'] ??
            $_REQUEST['player_accounts'] ??
            null;

        if ($accounts !== null) {
            update_user_meta($user_id, 'pmpro_player_accounts', (int) $accounts);

            // Store in order meta
            if ($order && !empty($order->id)) {
                if (function_exists('update_pmpro_membership_order_meta')) {
                    update_pmpro_membership_order_meta($order->id, 'player_accounts', (int) $accounts);

                    if (!empty($_SESSION['pmpro_player_accounts_extra_cost'])) {
                        update_pmpro_membership_order_meta(
                            $order->id,
                            'player_accounts_extra_cost',
                            $_SESSION['pmpro_player_accounts_extra_cost']
                        );
                    }
                } else {
                    // Fallback to regular meta
                    update_metadata('pmpro_membership_order', $order->id, 'player_accounts', (int) $accounts);

                    if (!empty($_SESSION['pmpro_player_accounts_extra_cost'])) {
                        update_metadata(
                            'pmpro_membership_order',
                            $order->id,
                            'player_accounts_extra_cost',
                            $_SESSION['pmpro_player_accounts_extra_cost']
                        );
                    }
                }
            }

            // Clear session
            unset($_SESSION['pmpro_player_accounts_count']);
            unset($_SESSION['pmpro_player_accounts_extra_cost']);
        }
    }
}