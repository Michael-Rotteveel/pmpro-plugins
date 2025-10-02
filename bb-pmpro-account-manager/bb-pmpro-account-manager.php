<?php
/**
 * Plugin Name: BB PMPro Account Manager
 * Description: Account management UI for BB PMPro Player Accounts - allows users to manage accounts mid-cycle
 * Version: 1.0.0
 * Author: Michael Rotteveel
 * Author URI: https://gametailors.com/
 * Text Domain: bb-pmpro-account-manager
 * Domain Path: /languages
 * License: GPL v2 or later
 * Requires Plugins: bb-pmpro-player-accounts
 *
 * @package BB_PMPro_Account_Manager
 */

namespace BB_PMPro_Account_Manager;

use DI\Container;
use DI\ContainerBuilder;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BB_PMPRO_ACCOUNT_MANAGER_VERSION', '1.0.0');
define('BB_PMPRO_ACCOUNT_MANAGER_DIR', plugin_dir_path(__FILE__));
define('BB_PMPRO_ACCOUNT_MANAGER_URL', plugin_dir_url(__FILE__));
define('BB_PMPRO_ACCOUNT_MANAGER_BASENAME', plugin_basename(__FILE__));

// Autoloader
require_once BB_PMPRO_ACCOUNT_MANAGER_DIR . 'vendor/autoload.php';

/**
 * Main plugin class
 */
class BB_PMPro_Account_Manager {

    /**
     * @var Container
     */
    private $container;

    /**
     * @var BB_PMPro_Account_Manager
     */
    private static $instance = null;

    /**
     * @var array Required dependencies
     */
    private $dependencies = [
        'bb-pmpro-player-accounts/bb-pmpro-player-accounts.php' => 'BB PMPro Player Accounts',
        'paid-memberships-pro/paid-memberships-pro.php' => 'Paid Memberships Pro'
    ];

    /**
     * Get singleton instance
     */
    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->setup_container();
        $this->init_hooks();
        $this->register_proration_gateway();
    }

    /**
     * Setup DI Container
     */
    private function setup_container(): void {
        $containerBuilder = new ContainerBuilder();

        // Add definitions
        $containerBuilder->addDefinitions([
            'AccountManager' => \DI\autowire(Includes\Account_Manager::class),
            'ProrationCalculator' => \DI\autowire(Includes\Proration_Calculator::class),
            'AccountUpdateHandler' => \DI\autowire(Includes\Account_Update_Handler::class),
        ]);

        $this->container = $containerBuilder->build();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        add_action('init', [$this, 'load_textdomain']);
        add_action('plugins_loaded', [$this, 'check_dependencies']);
        add_action('plugins_loaded', [$this, 'init_plugin'], 20); // Run after dependencies

        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    private function register_proration_gateway(): void {
        // Register proration gateway (but hide from checkout)
        add_filter('pmpro_gateways', [$this, 'add_proration_gateway'], 10, 1);

        // Hide proration from checkout options
        add_filter('pmpro_gateways_with_recurring_support', [$this, 'hide_proration_gateway'], 20, 1);
        add_filter('pmpro_valid_gateways', [$this, 'hide_proration_gateway'], 20, 1);

        // Prevent billing fields for proration orders
        add_filter('pmpro_required_billing_fields', [$this, 'skip_billing_for_proration'], 10, 2);

        // Handle proration order display
        add_filter('pmpro_invoice_gateway_display', [$this, 'format_proration_gateway_display'], 10, 2);

        // Skip payment processing for proration orders
        add_action('pmpro_checkout_before_processing', [$this, 'handle_proration_checkout'], 1);

        // Modify invoice amount based on player accounts
        add_filter('pmpro_stripe_create_invoice', [$this, 'modify_subscription_invoice'], 10, 3);
    }

    /**
     * Add proration gateway to PMPro gateways list
     */
    public function add_proration_gateway($gateways): array {
        // Add proration as an internal gateway
        $gateways['proration'] = __('Account Adjustment', 'bb-pmpro-player-accounts');
        return $gateways;
    }

    /**
     * Hide proration gateway from user-facing lists
     */
    public function hide_proration_gateway($gateways): array {
        // Remove proration from checkout and settings
        unset($gateways['proration']);
        return $gateways;
    }

    /**
     * Skip billing fields for proration orders
     */
    public function skip_billing_for_proration($fields, $gateway): array {
        if ($gateway === 'proration') {
            return []; // No billing fields needed
        }
        return $fields;
    }

    /**
     * Format proration gateway display name
     */
    public function format_proration_gateway_display($display, $gateway): string {
        if ($gateway === 'proration') {
            return __('Account Adjustment Invoice', 'bb-pmpro-player-accounts');
        }
        return $display;
    }

    /**
     * Handle proration checkout processing
     */
    public function handle_proration_checkout(): void
    {
        global $gateway;

        if ($gateway === 'proration') {
            // Skip normal checkout processing
            add_filter('pmpro_checkout_confirmed', '__return_false', 999);
        }
    }

    public function modify_subscription_invoice($invoice_args, $customer, $order) {
        if (!$order || !$order->user_id) {
            return $invoice_args;
        }

        $user_id = $order->user_id;

        // Get the stored next billing information
        $next_accounts = get_user_meta($user_id, 'pmpro_next_billing_accounts', true);
        $next_amount = get_user_meta($user_id, 'pmpro_next_billing_amount', true);

        if ($next_accounts && $next_amount) {
            // Update the invoice amount
            error_log('BB Player Accounts: Adjusting next invoice to €' . $next_amount . ' for ' . $next_accounts . ' accounts');

            // Note: This would need to modify the subscription items
            // For now, store it for manual adjustment
            update_pmpro_membership_order_meta($order->id, 'adjusted_for_accounts', $next_accounts);
            update_pmpro_membership_order_meta($order->id, 'adjusted_amount', $next_amount);
        }

        return $invoice_args;
    }


    /**
     * Check plugin dependencies
     */
    public function check_dependencies(): void {
        $missing = [];

        foreach ($this->dependencies as $plugin_file => $plugin_name) {
            if (!is_plugin_active($plugin_file)) {
                $missing[] = $plugin_name;
            }
        }

        if (!empty($missing)) {
            add_action('admin_notices', function() use ($missing) {
                $this->dependency_notice($missing);
            });

            // Deactivate self
            add_action('admin_init', function() {
                deactivate_plugins(BB_PMPRO_ACCOUNT_MANAGER_BASENAME);
            });

            return;
        }
    }

    /**
     * Initialize plugin components
     */
    public function init_plugin(): void {
        // Check if dependencies are active
        if (!defined('BB_PMPRO_PLAYER_ACCOUNTS_VERSION') || !defined('PMPRO_VERSION')) {
            return;
        }

        // Initialize components
        $this->container->get('AccountManager')->init();
        $this->container->get('ProrationCalculator')->init();
        $this->container->get('AccountUpdateHandler')->init();

        // Enqueue assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'bb-pmpro-account-manager',
            false,
            dirname(BB_PMPRO_ACCOUNT_MANAGER_BASENAME) . '/languages'
        );
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets(): void {
        // Only load on account page or pages with our shortcode
        if (is_page(pmpro_getOption('account_page_id')) || has_shortcode(get_post()->post_content ?? '', 'pmpro_account_manager')) {
            wp_enqueue_script(
                'bb-pmpro-account-manager',
                BB_PMPRO_ACCOUNT_MANAGER_URL . 'assets/js/account-manager.js',
                ['jquery'],
                BB_PMPRO_ACCOUNT_MANAGER_VERSION,
                true
            );

            wp_localize_script('bb-pmpro-account-manager', 'bbAccountManager', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bb_account_manager'),
                'strings' => [
                    'confirm_upgrade' => __('Are you sure you want to upgrade your accounts? You will be charged for the additional accounts.', 'bb-pmpro-account-manager'),
                    'confirm_downgrade' => __('Are you sure you want to downgrade your accounts? Credit will be applied to your next invoice.', 'bb-pmpro-account-manager'),
                    'processing' => __('Processing...', 'bb-pmpro-account-manager'),
                    'error' => __('An error occurred. Please try again.', 'bb-pmpro-account-manager'),
                    'success' => __('Your accounts have been updated successfully!', 'bb-pmpro-account-manager'),
                    'calculating' => __('Calculating proration...', 'bb-pmpro-account-manager'),
                ],
                'currency_symbol' => '€',
                'currency_position' => 'left',
            ]);

            wp_enqueue_style(
                'bb-pmpro-account-manager',
                BB_PMPRO_ACCOUNT_MANAGER_URL . 'assets/css/account-manager.css',
                [],
                BB_PMPRO_ACCOUNT_MANAGER_VERSION
            );
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook): void {
        // Load on PMPro pages and user edit pages
        if (strpos($hook, 'pmpro') !== false || $hook === 'user-edit.php' || $hook === 'profile.php') {
            wp_enqueue_style(
                'bb-pmpro-account-manager-admin',
                BB_PMPRO_ACCOUNT_MANAGER_URL . 'assets/css/account-manager.css',
                [],
                BB_PMPRO_ACCOUNT_MANAGER_VERSION
            );
        }
    }

    /**
     * Dependency missing notice
     */
    private function dependency_notice(array $missing): void {
        $message = sprintf(
            __('BB PMPro Account Manager requires the following plugins to be installed and activated: %s', 'bb-pmpro-account-manager'),
            implode(', ', $missing)
        );
        ?>
        <div class="notice notice-error">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    /**
     * Plugin activation
     */
    public function activate(): void {
        // Create account management page if it doesn't exist
        $page_id = get_option('bb_pmpro_account_manager_page_id');

        if (!$page_id || !get_post($page_id)) {
            $page_id = wp_insert_post([
                'post_title' => __('Manage Player Accounts', 'bb-pmpro-account-manager'),
                'post_content' => '[pmpro_account_manager]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => get_current_user_id(),
            ]);

            update_option('bb_pmpro_account_manager_page_id', $page_id);
        }

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Get container instance
     */
    public function get_container(): Container {
        return $this->container;
    }
}

// Initialize plugin
BB_PMPro_Account_Manager::get_instance();