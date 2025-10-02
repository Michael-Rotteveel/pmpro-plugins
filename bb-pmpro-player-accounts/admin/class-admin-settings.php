<?php

namespace BB_PMPro_Player_Accounts\Admin;

class Admin_Settings {

    /**
     * Initialize
     */
    public function init(): void {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'pmpro-dashboard',
            __('Player Accounts Settings', 'bb-pmpro-player-accounts'),
            __('Player Accounts', 'bb-pmpro-player-accounts'),
            'manage_options',
            'pmpro-player-accounts',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings(): void {
        register_setting('bb_pmpro_player_accounts', 'bb_pmpro_player_accounts_options');

        add_settings_section(
            'bb_pmpro_player_accounts_general',
            __('General Settings', 'bb-pmpro-player-accounts'),
            [$this, 'render_general_section'],
            'bb_pmpro_player_accounts'
        );

        add_settings_field(
            'enable_debug',
            __('Enable Debug Mode', 'bb-pmpro-player-accounts'),
            [$this, 'render_debug_field'],
            'bb_pmpro_player_accounts',
            'bb_pmpro_player_accounts_general'
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('bb_pmpro_player_accounts');
                do_settings_sections('bb_pmpro_player_accounts');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render general section
     */
    public function render_general_section(): void {
        echo '<p>' . __('Configure general settings for Player Accounts.', 'bb-pmpro-player-accounts') . '</p>';
    }

    /**
     * Render debug field
     */
    public function render_debug_field(): void {
        $options = get_option('bb_pmpro_player_accounts_options', []);
        $debug = $options['enable_debug'] ?? false;
        ?>
        <input type="checkbox"
               name="bb_pmpro_player_accounts_options[enable_debug]"
               value="1"
            <?php checked($debug, 1); ?> />
        <label><?php _e('Enable debug logging', 'bb-pmpro-player-accounts'); ?></label>
        <?php
    }
}