<?php

namespace BB_PMPro_Player_Accounts\Includes;

use BB_PMPro_Player_Accounts\Traits\Account_Calculations;

class Player_Accounts_Manager {

    use Account_Calculations;

    /**
     * @var Membership_Level_Settings
     */
    private $level_settings;

    /**
     * Constructor with dependency injection
     */
    public function __construct(Membership_Level_Settings $level_settings) {
        $this->level_settings = $level_settings;
    }

    /**
     * Initialize
     */
    public function init(): void {
        add_filter('pmpro_has_membership_access_filter', [$this, 'check_account_access'], 10, 4);
        add_action('pmpro_after_change_membership_level', [$this, 'handle_membership_change'], 10, 3);
    }

    /**
     * Get user's current player accounts
     */
    public function get_user_accounts(int $user_id): int {
        $accounts = get_user_meta($user_id, 'pmpro_player_accounts', true);

        if (empty($accounts)) {
            $level = pmpro_getMembershipLevelForUser($user_id);
            if ($level) {
                $settings = $this->level_settings->get_level_settings($level->id);
                $accounts = $settings['default_accounts'];
                update_user_meta($user_id, 'pmpro_player_accounts', $accounts);
            }
        }

        return (int) $accounts;
    }

    /**
     * Update user's player accounts
     */
    public function update_user_accounts(int $user_id, int $accounts): bool {
        $level = pmpro_getMembershipLevelForUser($user_id);

        if (!$level) {
            return false;
        }

        $settings = $this->level_settings->get_level_settings($level->id);

        // Validate account limits
        if (!$this->validate_account_limit($accounts, $settings)) {
            return false;
        }

        update_user_meta($user_id, 'pmpro_player_accounts', $accounts);

        // Log the change
        $this->log_account_change($user_id, $accounts);

        return true;
    }

    /**
     * Validate account limit
     */
    private function validate_account_limit(int $accounts, array $settings): bool {
        if ($accounts < $settings['default_accounts']) {
            return false;
        }

        if ($settings['max_accounts'] > 0 && $accounts > $settings['max_accounts']) {
            return false;
        }

        // Cap at 25 for unlimited
        if ($settings['max_accounts'] == 0 && $accounts > 25) {
            return false;
        }

        return true;
    }

    /**
     * Handle membership change
     * @param int $level_id The new level ID (0 if cancelling)
     * @param int $user_id The user ID
     * @param int|null $cancel_level_id The cancelled level ID (null if not cancelling)
     */
    public function handle_membership_change(int $level_id, int $user_id, $cancel_level_id = null): void {
        // Convert to int if not null
        if (!is_null($cancel_level_id)) {
            $cancel_level_id = (int) $cancel_level_id;
        }

        if ($level_id > 0) {
            // New or changed membership
            $settings = $this->level_settings->get_level_settings($level_id);
            $current_accounts = get_user_meta($user_id, 'pmpro_player_accounts', true);

            // Only update if not already set or if changing levels
            if (empty($current_accounts) || !is_null($cancel_level_id)) {
                update_user_meta($user_id, 'pmpro_player_accounts', $settings['default_accounts']);

                // If changing from another level, check if we need to adjust accounts
                if (!is_null($cancel_level_id) && $cancel_level_id > 0) {
                    $old_settings = $this->level_settings->get_level_settings($cancel_level_id);

                    // If the new level has fewer max accounts, adjust if needed
                    if ($settings['max_accounts'] > 0 && $current_accounts > $settings['max_accounts']) {
                        update_user_meta($user_id, 'pmpro_player_accounts', $settings['max_accounts']);
                    }
                }
            }
        } else {
            // Membership cancelled
            delete_user_meta($user_id, 'pmpro_player_accounts');

            // Also clean up any pending adjustments
            delete_user_meta($user_id, 'pmpro_pending_account_adjustments');
        }
    }

    /**
     * Check account access
     */
    public function check_account_access($hasaccess, $post, $user, $post_membership_levels) {
        // Additional access checks based on player accounts if needed
        return $hasaccess;
    }

    /**
     * Log account change
     */
    private function log_account_change(int $user_id, int $new_accounts): void {
        $log_entry = [
            'user_id' => $user_id,
            'accounts' => $new_accounts,
            'timestamp' => current_time('mysql'),
        ];

        // Store in user meta as array
        $log = get_user_meta($user_id, 'pmpro_player_accounts_log', true) ?: [];
        $log[] = $log_entry;

        // Keep only last 10 entries
        $log = array_slice($log, -10);

        update_user_meta($user_id, 'pmpro_player_accounts_log', $log);
    }
}