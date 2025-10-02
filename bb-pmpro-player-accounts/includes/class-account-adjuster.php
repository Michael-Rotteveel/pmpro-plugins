<?php

namespace BB_PMPro_Player_Accounts\Includes;

class Account_Adjuster {

    /**
     * @var Player_Accounts_Manager
     */
    private $accounts_manager;

    /**
     * @var Proration_Handler
     */
    private $proration_handler;

    /**
     * @var Membership_Level_Settings
     */
    private $level_settings;

    /**
     * Constructor
     */
    public function __construct(
        Player_Accounts_Manager $accounts_manager,
        Proration_Handler $proration_handler,
        Membership_Level_Settings $level_settings
    ) {
        $this->accounts_manager = $accounts_manager;
        $this->proration_handler = $proration_handler;
        $this->level_settings = $level_settings;
    }

    /**
     * Initialize
     */
    public function init(): void {
        add_action('wp_ajax_bb_adjust_player_accounts', [$this, 'handle_ajax_adjustment']);
        add_shortcode('pmpro_account_adjuster', [$this, 'render_adjuster']);
    }

    /**
     * Handle AJAX account adjustment
     */
    public function handle_ajax_adjustment(): void {
        check_ajax_referer('bb_player_accounts', 'nonce');

        $user_id = get_current_user_id();
        $new_accounts = (int) ($_POST['accounts'] ?? 0);

        if (!$user_id) {
            wp_send_json_error(['message' => __('Not logged in', 'bb-pmpro-player-accounts')]);
        }

        $level = pmpro_getMembershipLevelForUser($user_id);
        if (!$level) {
            wp_send_json_error(['message' => __('No active membership', 'bb-pmpro-player-accounts')]);
        }

        $settings = $this->level_settings->get_level_settings($level->id);
        $current_accounts = $this->accounts_manager->get_user_accounts($user_id);

        // Validate new account count
        if ($new_accounts < $settings['default_accounts']) {
            wp_send_json_error([
                'message' => sprintf(
                    __('Minimum %d accounts required', 'bb-pmpro-player-accounts'),
                    $settings['default_accounts']
                )
            ]);
        }

        $max = $settings['max_accounts'] ?: 25;
        if ($new_accounts > $max) {
            wp_send_json_error([
                'message' => sprintf(
                    __('Maximum %d accounts allowed', 'bb-pmpro-player-accounts'),
                    $max
                )
            ]);
        }

        // Calculate proration if enabled
        $proration_amount = 0;
        if ($settings['enable_proration']) {
            $proration_amount = $this->proration_handler->calculate_proration(
                $user_id,
                $current_accounts,
                $new_accounts,
                $settings
            );
        }

        // Update accounts
        if ($this->accounts_manager->update_user_accounts($user_id, $new_accounts)) {
            // Handle proration if needed
            if ($proration_amount != 0) {
                $this->proration_handler->apply_proration($user_id, $proration_amount);
            }

            wp_send_json_success([
                'message' => __('Accounts updated successfully', 'bb-pmpro-player-accounts'),
                'new_accounts' => $new_accounts,
                'proration' => $proration_amount,
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to update accounts', 'bb-pmpro-player-accounts')]);
        }
    }

    /**
     * Render account adjuster shortcode
     */
    public function render_adjuster($atts): string {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return '';
        }

        $level = pmpro_getMembershipLevelForUser($user_id);
        if (!$level) {
            return '';
        }

        $settings = $this->level_settings->get_level_settings($level->id);
        if (!$settings['allow_extra_accounts']) {
            return '';
        }

        $current_accounts = $this->accounts_manager->get_user_accounts($user_id);

        ob_start();
        ?>
        <div class="pmpro-account-adjuster">
            <h3><?php _e('Adjust Player Accounts', 'bb-pmpro-player-accounts'); ?></h3>
            <p><?php printf(__('Current accounts: %d', 'bb-pmpro-player-accounts'), $current_accounts); ?></p>
            <form id="bb-adjust-accounts-form">
                <label for="new_accounts">
                    <?php _e('Number of accounts:', 'bb-pmpro-player-accounts'); ?>
                </label>
                <input type="number"
                       id="new_accounts"
                       name="accounts"
                       min="<?php echo $settings['default_accounts']; ?>"
                       max="<?php echo $settings['max_accounts'] ?: 25; ?>"
                       value="<?php echo $current_accounts; ?>" />
                <button type="submit" class="pmpro_btn">
                    <?php _e('Update Accounts', 'bb-pmpro-player-accounts'); ?>
                </button>
            </form>
            <div id="adjustment-result"></div>
        </div>
        <?php
        return ob_get_clean();
    }
}