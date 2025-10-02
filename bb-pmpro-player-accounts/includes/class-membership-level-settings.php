<?php

namespace BB_PMPro_Player_Accounts\Includes;

class Membership_Level_Settings {

    /**
     * Default settings for levels
     */
    private const DEFAULT_SETTINGS = [
        'default_accounts' => 1,
        'allow_extra_accounts' => false,
        'price_per_account' => 0,
        'max_accounts' => 1,
        'enable_proration' => true,
        'features' => '',
    ];

    /**
     * Initialize
     */
    public function init(): void {
        add_action('pmpro_membership_level_after_other_settings', [$this, 'render_level_settings']);
        add_action('pmpro_save_membership_level', [$this, 'save_level_settings']);
        add_action('pmpro_delete_membership_level', [$this, 'delete_level_settings']);
    }

    /**
     * Get level settings
     */
    public function get_level_settings(int $level_id): array {
        $settings = get_option('pmpro_player_accounts_level_' . $level_id, []);
        return wp_parse_args($settings, self::DEFAULT_SETTINGS);
    }

    /**
     * Save level settings
     */
    public function save_level_settings(int $level_id): void {
        if (!isset($_REQUEST['default_player_accounts'])) {
            return;
        }

        $settings = [
            'default_accounts' => (int) $_REQUEST['default_player_accounts'],
            'allow_extra_accounts' => !empty($_REQUEST['allow_extra_accounts']),
            'price_per_account' => (float) $_REQUEST['price_per_account'],
            'max_accounts' => (int) $_REQUEST['max_accounts'],
            'enable_proration' => !empty($_REQUEST['enable_proration']),
            'features' => sanitize_text_field($_REQUEST['tier_features'] ?? ''),
        ];

        update_option('pmpro_player_accounts_level_' . $level_id, $settings);
    }

    /**
     * Delete level settings
     */
    public function delete_level_settings(int $level_id): void {
        delete_option('pmpro_player_accounts_level_' . $level_id);
    }

    /**
     * Render level settings in admin
     */
    public function render_level_settings(): void {
        $level_id = intval($_REQUEST['edit'] ?? 0);

        if ($level_id > 0) {
            $settings = $this->get_level_settings($level_id);
            include BB_PMPRO_PLAYER_ACCOUNTS_DIR . 'admin/views/membership-level-settings.php';
        }
    }

    /**
     * Check if level has feature
     */
    public function level_has_feature(int $level_id, string $feature): bool {
        $settings = $this->get_level_settings($level_id);
        $features = array_map('trim', explode(',', $settings['features']));
        return in_array($feature, $features);
    }
}