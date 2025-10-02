<?php
/**
 * Plugin Name: BB PMPro Player Accounts
 * Description: Player account-based pricing system for Paid Memberships Pro
 * Version: 1.0.0
 * Author: Michael Rotteveel
 * Author URI: https://gametailors.com/
 * Text Domain: bb-pmpro-player-accounts
 * Domain Path: /languages
 *
 * @package BB_PMPro_Player_Accounts
 */

namespace BB_PMPro_Player_Accounts;

use DI\Container;
use DI\ContainerBuilder;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BB_PMPRO_PLAYER_ACCOUNTS_VERSION', '1.0.0');
define('BB_PMPRO_PLAYER_ACCOUNTS_DIR', plugin_dir_path(__FILE__));
define('BB_PMPRO_PLAYER_ACCOUNTS_URL', plugin_dir_url(__FILE__));
define('BB_PMPRO_PLAYER_ACCOUNTS_BASENAME', plugin_basename(__FILE__));

// Check for Composer autoloader
if (!file_exists(BB_PMPRO_PLAYER_ACCOUNTS_DIR . 'vendor/autoload.php')) {
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('BB PMPro Player Accounts: Please run "composer install" in the plugin directory.', 'bb-pmpro-player-accounts'); ?></p>
        </div>
        <?php
    });
    return;
}

// Autoloader
require_once BB_PMPRO_PLAYER_ACCOUNTS_DIR . 'vendor/autoload.php';

// Load helper functions
require_once BB_PMPRO_PLAYER_ACCOUNTS_DIR . 'includes/functions.php';

/**
 * Main plugin class
 */
class BB_PMPro_Player_Accounts {

    /**
     * @var Container
     */
    private $container;

    /**
     * @var BB_PMPro_Player_Accounts
     */
    private static $instance = null;

    /**
     * Dependencies check flag
     */
    private $dependencies_met = false;

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
        $this->init_hooks();
    }

    /**
     * Setup DI Container
     */
    private function setup_container(): void {
        try {
            $containerBuilder = new ContainerBuilder();

            // Add definitions
            $containerBuilder->addDefinitions([
                // Core classes
                'PlayerAccountsManager' => \DI\autowire(Includes\Player_Accounts_Manager::class),
                'MembershipLevelSettings' => \DI\autowire(Includes\Membership_Level_Settings::class),
                'CheckoutHandler' => \DI\autowire(Includes\Checkout_Handler::class),
                'AccountAdjuster' => \DI\autowire(Includes\Account_Adjuster::class),
                'ProrationHandler' => \DI\autowire(Includes\Proration_Handler::class),
                'InvoiceCustomizer' => \DI\autowire(Includes\Invoice_Customizer::class),
                'RestAPI' => \DI\autowire(Includes\Rest_API::class),
                'AdminSettings' => \DI\autowire(Admin\Admin_Settings::class),
            ]);

            $this->container = $containerBuilder->build();
        } catch (\Exception $e) {
            error_log('BB PMPro Player Accounts: Failed to build DI container - ' . $e->getMessage());
        }
    }

    /**
     * Check plugin dependencies
     */
    private function check_dependencies(): bool {
        if (!defined('PMPRO_VERSION')) {
            add_action('admin_notices', [$this, 'pmpro_missing_notice']);
            return false;
        }

        $this->dependencies_met = true;
        return true;
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        add_action('init', [$this, 'load_textdomain']);
        add_action('plugins_loaded', [$this, 'init_plugin'], 20); // Priority 20 to ensure PMPro loads first

        // Activation/Deactivation hooks
        register_activation_hook(BB_PMPRO_PLAYER_ACCOUNTS_BASENAME, [$this, 'activate']);
        register_deactivation_hook(BB_PMPRO_PLAYER_ACCOUNTS_BASENAME, [$this, 'deactivate']);
    }

    /**
     * Initialize plugin components
     */
    public function init_plugin(): void {
        if (!$this->check_dependencies()) {
            return;
        }

        // Setup container after dependencies are confirmed
        $this->setup_container();

        if (!$this->container) {
            error_log('BB PMPro Player Accounts: Container not initialized');
            return;
        }

        try {
            // Initialize components
            $this->container->get('PlayerAccountsManager')->init();
            $this->container->get('MembershipLevelSettings')->init();
            $this->container->get('CheckoutHandler')->init();
            $this->container->get('AccountAdjuster')->init();
            $this->container->get('ProrationHandler')->init();
            $this->container->get('InvoiceCustomizer')->init();
            $this->container->get('RestAPI')->init();
            $this->container->get('AdminSettings')->init();
        } catch (\Exception $e) {
            error_log('BB PMPro Player Accounts: Failed to initialize components - ' . $e->getMessage());
        }

        // Enqueue assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'bb-pmpro-player-accounts',
            false,
            dirname(BB_PMPRO_PLAYER_ACCOUNTS_BASENAME) . '/languages'
        );
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets(): void {
        if (!function_exists('pmpro_getOption')) {
            return;
        }

        $checkout_page_id = pmpro_getOption('checkout_page_id');

        if (is_page($checkout_page_id)) {
            wp_enqueue_script(
                'bb-pmpro-checkout-accounts',
                BB_PMPRO_PLAYER_ACCOUNTS_URL . 'assets/js/checkout-accounts.js',
                ['jquery'],
                BB_PMPRO_PLAYER_ACCOUNTS_VERSION,
                true
            );

            wp_localize_script('bb-pmpro-checkout-accounts', 'bbPlayerAccounts', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bb_player_accounts'),
                'strings' => [
                    'extra_accounts' => __('Extra accounts', 'bb-pmpro-player-accounts'),
                    'price_per_account' => __('Price per account', 'bb-pmpro-player-accounts'),
                    'calculating' => __('Calculating...', 'bb-pmpro-player-accounts'),
                    'per_month' => __('per month', 'bb-pmpro-player-accounts'),
                    'per_year' => __('per year', 'bb-pmpro-player-accounts'),
                    'total_extra' => __('Total extra annual cost', 'bb-pmpro-player-accounts'),
                    'update_accounts' => __('Update Accounts', 'bb-pmpro-player-accounts'),
                    'additional_charge' => __('Additional charge', 'bb-pmpro-player-accounts'),
                    'credit_applied' => __('Credit applied', 'bb-pmpro-player-accounts'),
                    'error_occurred' => __('An error occurred. Please try again.', 'bb-pmpro-player-accounts'),
                    'min_accounts_error' => __('Minimum %d player accounts required.', 'bb-pmpro-player-accounts'),
                    'max_accounts_error' => __('Maximum %d player accounts allowed.', 'bb-pmpro-player-accounts'),
                ],
            ]);

            wp_enqueue_style(
                'bb-pmpro-checkout-accounts',
                BB_PMPRO_PLAYER_ACCOUNTS_URL . 'assets/css/checkout-accounts.css',
                [],
                BB_PMPRO_PLAYER_ACCOUNTS_VERSION
            );
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook): void {
        if (strpos($hook, 'pmpro') !== false || strpos($hook, 'membership') !== false) {
            wp_enqueue_script(
                'bb-pmpro-admin-settings',
                BB_PMPRO_PLAYER_ACCOUNTS_URL . 'assets/js/admin-settings.js',
                ['jquery'],
                BB_PMPRO_PLAYER_ACCOUNTS_VERSION,
                true
            );

            wp_enqueue_style(
                'bb-pmpro-admin-settings',
                BB_PMPRO_PLAYER_ACCOUNTS_URL . 'assets/css/admin-settings.css',
                [],
                BB_PMPRO_PLAYER_ACCOUNTS_VERSION
            );
        }
    }

    /**
     * PMPro missing notice
     */
    public function pmpro_missing_notice(): void {
        ?>
        <div class="notice notice-error">
            <p><?php _e('BB PMPro Player Accounts requires Paid Memberships Pro to be installed and activated.', 'bb-pmpro-player-accounts'); ?></p>
        </div>
        <?php
    }

    /**
     * Plugin activation
     */
    public function activate(): void {
        // Create default options
        add_option('bb_pmpro_player_accounts_version', BB_PMPRO_PLAYER_ACCOUNTS_VERSION);

        // Flush rewrite rules for REST API
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
    public function get_container(): ?Container {
        return $this->container;
    }
}

// Initialize plugin
BB_PMPro_Player_Accounts::get_instance();