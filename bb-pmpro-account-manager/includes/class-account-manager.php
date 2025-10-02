<?php

namespace BB_PMPro_Account_Manager\Includes;

class Account_Manager {

    /**
     * @var Proration_Calculator
     */
    private $proration_calculator;

    /**
     * @var Account_Update_Handler
     */
    private $update_handler;

    /**
     * Constructor
     */
    public function __construct(
        Proration_Calculator $proration_calculator,
        Account_Update_Handler $update_handler
    ) {
        $this->proration_calculator = $proration_calculator;
        $this->update_handler = $update_handler;
    }

    /**
     * Initialize
     */
    public function init(): void {
        // Register shortcode
        add_shortcode('pmpro_account_manager', [$this, 'render_account_manager']);

        // Add to PMPro account page - Fixed: use action hook properly
        add_action('pmpro_account_bullets_bottom', [$this, 'add_account_info_and_link']);

        // Add to member profile in admin
        add_action('pmpro_membership_level_profile_fields', [$this, 'admin_member_accounts'], 10, 2);

        // AJAX handlers
        add_action('wp_ajax_bb_preview_account_change', [$this, 'ajax_preview_change']);
        add_action('wp_ajax_bb_update_accounts', [$this, 'ajax_update_accounts']);

        // Handle admin profile updates
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('personal_options_update', [$this, 'save_admin_account_update']);
        add_action('edit_user_profile_update', [$this, 'save_admin_account_update']);
    }

    /**
     * Add account info and management link to PMPro account page
     * Combined method that works with the action hook
     */
    public function add_account_info_and_link(): void {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return;
        }

        $level = pmpro_getMembershipLevelForUser($user_id);

        if (!$level) {
            return;
        }

        $settings = $this->get_level_settings($level->id);
        $current_accounts = get_user_meta($user_id, 'pmpro_player_accounts', true) ?: $settings['default_accounts'];

        ?>
        <li>
            <strong><?php _e('Player Accounts:', 'bb-pmpro-account-manager'); ?></strong>
            <?php
            echo sprintf(
                __('%d accounts', 'bb-pmpro-account-manager'),
                $current_accounts
            );
            ?>
            <?php if ($settings['allow_extra_accounts']): ?>
                <?php
                $page_id = get_option('bb_pmpro_account_manager_page_id');
                if ($page_id):
                    ?>
                    <a href="<?php echo esc_url(get_permalink($page_id)); ?>" class="pmpro_btn pmpro_btn-select">
                        <?php _e('Manage Accounts', 'bb-pmpro-account-manager'); ?>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </li>
        <?php
    }

    /**
     * Render account manager shortcode
     */
    public function render_account_manager($atts): string {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return '<div class="pmpro_message pmpro_error">' .
                __('Please log in to manage your player accounts.', 'bb-pmpro-account-manager') .
                '</div>';
        }

        $user_id = get_current_user_id();
        $level = pmpro_getMembershipLevelForUser($user_id);

        if (!$level) {
            return '<div class="pmpro_message pmpro_error">' .
                __('You need an active membership to manage player accounts.', 'bb-pmpro-account-manager') .
                '</div>';
        }

        // Get account settings from main plugin
        $settings = $this->get_level_settings($level->id);

        if (!$settings['allow_extra_accounts']) {
            return '<div class="pmpro_message pmpro_message_alert">' .
                __('Your membership level does not allow account adjustments.', 'bb-pmpro-account-manager') .
                '</div>';
        }

        // Get current accounts
        $current_accounts = get_user_meta($user_id, 'pmpro_player_accounts', true) ?: $settings['default_accounts'];

        // Get next payment date for proration calculation
        $next_payment_date = pmpro_next_payment($user_id);

        // Load template
        ob_start();
        include BB_PMPRO_ACCOUNT_MANAGER_DIR . 'templates/account-management.php';
        return ob_get_clean();
    }

    /**
     * Show account info in admin member profile
     */
    public function admin_member_accounts($user, $level): void {
        if (!$level) {
            return;
        }

        $settings = $this->get_level_settings($level->id);
        $current_accounts = get_user_meta($user->ID, 'pmpro_player_accounts', true) ?: $settings['default_accounts'];

        ?>
        <tr>
            <th><?php _e('Player Accounts', 'bb-pmpro-account-manager'); ?></th>
            <td>
                <input type="number"
                       id="admin_player_accounts"
                       name="player_accounts"
                       value="<?php echo esc_attr($current_accounts); ?>"
                       min="<?php echo esc_attr($settings['default_accounts']); ?>"
                       max="<?php echo esc_attr($settings['max_accounts'] ?: 25); ?>"
                    <?php echo current_user_can('manage_options') ? '' : 'readonly'; ?> />
                <?php if (current_user_can('manage_options')): ?>
                    <p class="description">
                        <?php printf(
                            __('Adjust player accounts for this member. Default: %d, Max: %d', 'bb-pmpro-account-manager'),
                            $settings['default_accounts'],
                            $settings['max_accounts'] ?: 25
                        ); ?>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Save admin account update
     */
    public function save_admin_account_update($user_id): void {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        if (isset($_POST['player_accounts'])) {
            $new_accounts = (int) $_POST['player_accounts'];

            // Get the user's level to validate
            $level = pmpro_getMembershipLevelForUser($user_id);
            if ($level) {
                $settings = $this->get_level_settings($level->id);

                // Validate the new account count
                if ($new_accounts >= $settings['default_accounts'] &&
                    ($settings['max_accounts'] == 0 || $new_accounts <= $settings['max_accounts'])) {

                    update_user_meta($user_id, 'pmpro_player_accounts', $new_accounts);

                    // Log admin adjustment
                    $this->log_adjustment($user_id, $new_accounts, 'admin');
                }
            }
        }
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            'pmpro-dashboard',
            __('Account Credits', 'bb-pmpro-account-manager'),
            __('Account Credits', 'bb-pmpro-account-manager'),
            'manage_options',
            'pmpro-account-credits',
            [$this, 'render_credits_page']
        );
    }

    public function render_credits_page(): void {
        ?>
        <div class="wrap">
            <h1><?php _e('Pending Account Credits', 'bb-pmpro-account-manager'); ?></h1>
            <?php
            global $wpdb;
            $users_with_credits = $wpdb->get_results(
                "SELECT user_id, meta_value 
            FROM $wpdb->usermeta 
            WHERE meta_key = 'pmpro_pending_account_adjustments'"
            );

            if ($users_with_credits) {
                ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                    <tr>
                        <th><?php _e('User', 'bb-pmpro-account-manager'); ?></th>
                        <th><?php _e('Credit Amount', 'bb-pmpro-account-manager'); ?></th>
                        <th><?php _e('Status', 'bb-pmpro-account-manager'); ?></th>
                        <th><?php _e('Date', 'bb-pmpro-account-manager'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    foreach ($users_with_credits as $credit_data) {
                        $user = get_userdata($credit_data->user_id);
                        $adjustments = maybe_unserialize($credit_data->meta_value);

                        foreach ($adjustments as $adjustment) {
                            if ($adjustment['type'] === 'credit') {
                                ?>
                                <tr>
                                    <td><?php echo esc_html($user->display_name); ?></td>
                                    <td>€<?php echo number_format(abs($adjustment['amount']), 2); ?></td>
                                    <td><?php echo esc_html($adjustment['status']); ?></td>
                                    <td><?php echo esc_html($adjustment['date']); ?></td>
                                </tr>
                                <?php
                            }
                        }
                    }
                    ?>
                    </tbody>
                </table>
                <?php
            } else {
                echo '<p>' . __('No pending credits found.', 'bb-pmpro-account-manager') . '</p>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * AJAX: Preview account change
     */
    public function ajax_preview_change(): void {
        check_ajax_referer('bb_account_manager', 'nonce');

        $user_id = get_current_user_id();
        $new_accounts = (int) ($_POST['new_accounts'] ?? 0);
        $current_accounts = (int) ($_POST['current_accounts'] ?? 0);

        if (!$user_id) {
            wp_send_json_error(['message' => __('Not logged in', 'bb-pmpro-account-manager')]);
            return;
        }

        $level = pmpro_getMembershipLevelForUser($user_id);
        if (!$level) {
            wp_send_json_error(['message' => __('No active membership', 'bb-pmpro-account-manager')]);
            return;
        }

        // Calculate proration
        $proration_data = $this->proration_calculator->calculate(
            $user_id,
            $current_accounts,
            $new_accounts,
            $level->id
        );

        wp_send_json_success($proration_data);
    }

    /**
     * AJAX: Update accounts
     */
    public function ajax_update_accounts(): void {
        check_ajax_referer('bb_account_manager', 'nonce');

        $user_id = get_current_user_id();
        $new_accounts = (int) ($_POST['new_accounts'] ?? 0);

        if (!$user_id) {
            wp_send_json_error(['message' => __('Not logged in', 'bb-pmpro-account-manager')]);
            return;
        }

        // Process update
        $result = $this->update_handler->update_accounts($user_id, $new_accounts);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Get level settings from main plugin
     */
    private function get_level_settings(int $level_id): array {
        $default_settings = [
            'default_accounts' => 1,
            'allow_extra_accounts' => false,
            'price_per_account' => 0,
            'max_accounts' => 1,
            'enable_proration' => true,
            'features' => '',
        ];

        $settings = get_option('pmpro_player_accounts_level_' . $level_id, $default_settings);

        // Ensure all keys exist
        return wp_parse_args($settings, $default_settings);
    }

    /**
     * Log account adjustment
     */
    private function log_adjustment(int $user_id, int $new_accounts, string $source = 'user'): void {
        $log = get_user_meta($user_id, 'pmpro_account_adjustments_log', true) ?: [];

        $log[] = [
            'date' => current_time('mysql'),
            'accounts' => $new_accounts,
            'source' => $source,
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'admin_user' => $source === 'admin' ? get_current_user_id() : null,
        ];

        // Keep only last 50 entries
        $log = array_slice($log, -50);

        update_user_meta($user_id, 'pmpro_account_adjustments_log', $log);
    }
}