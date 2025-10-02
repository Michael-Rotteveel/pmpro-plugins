<?php
/**
 * Account Management Template
 *
 * Available variables:
 * @var object $level - Current membership level
 * @var array $settings - Level settings
 * @var int $current_accounts - Current number of accounts
 * @var int $user_id - Current user ID
 * @var string|false $next_payment_date - Next payment date
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

?>

<div id="bb-account-manager" class="pmpro-account-manager-wrapper">

    <h2><?php _e('Manage Your Player Accounts', 'bb-pmpro-account-manager'); ?></h2>

    <div class="account-manager-info">
        <div class="info-grid">
            <div class="info-item">
                <label><?php _e('Current Membership:', 'bb-pmpro-account-manager'); ?></label>
                <strong><?php echo esc_html($level->name); ?></strong>
            </div>

            <div class="info-item">
                <label><?php _e('Current Player Accounts:', 'bb-pmpro-account-manager'); ?></label>
                <strong id="current-accounts-display"><?php echo $current_accounts; ?></strong>
            </div>

            <?php if ($next_payment_date): ?>
                <div class="info-item">
                    <label><?php _e('Next Payment Date:', 'bb-pmpro-account-manager'); ?></label>
                    <strong><?php echo date_i18n(get_option('date_format'), $next_payment_date); ?></strong>
                </div>
            <?php endif; ?>

            <div class="info-item">
                <label><?php _e('Included Accounts:', 'bb-pmpro-account-manager'); ?></label>
                <strong><?php echo $settings['default_accounts']; ?></strong>
            </div>

            <?php if ($settings['price_per_account'] > 0): ?>
                <div class="info-item">
                    <label><?php _e('Price per Extra Account:', 'bb-pmpro-account-manager'); ?></label>
                    <strong>€<?php echo number_format($settings['price_per_account'] * 12, 2, ',', '.'); ?> <?php _e('per year', 'bb-pmpro-account-manager'); ?></strong>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="account-manager-form">
        <h3><?php _e('Adjust Your Player Accounts', 'bb-pmpro-account-manager'); ?></h3>

        <form id="account-adjustment-form">
            <div class="form-row">
                <label for="new_accounts">
                    <?php _e('Number of Player Accounts:', 'bb-pmpro-account-manager'); ?>
                </label>
                <div class="account-selector">
                    <button type="button" class="account-adjust-btn" data-action="decrease">−</button>
                    <input type="number"
                           id="new_accounts"
                           name="new_accounts"
                           value="<?php echo $current_accounts; ?>"
                           min="<?php echo $settings['default_accounts']; ?>"
                           max="<?php echo $settings['max_accounts'] ?: 25; ?>"
                           data-current="<?php echo $current_accounts; ?>"
                           data-default="<?php echo $settings['default_accounts']; ?>"
                           data-price="<?php echo $settings['price_per_account']; ?>"
                           readonly />
                    <button type="button" class="account-adjust-btn" data-action="increase">+</button>
                </div>

                <div class="account-limits">
                    <small>
                        <?php
                        printf(
                            __('Min: %d | Max: %d', 'bb-pmpro-account-manager'),
                            $settings['default_accounts'],
                            $settings['max_accounts'] ?: 25
                        );
                        ?>
                    </small>
                </div>
            </div>

            <div id="proration-preview" class="proration-preview" style="display: none;">
                <h4><?php _e('Proration Preview', 'bb-pmpro-account-manager'); ?></h4>
                <div class="proration-details">
                    <!-- Populated by JavaScript -->
                </div>
            </div>

            <div class="form-actions">
                <button type="button" id="preview-changes" class="pmpro_btn pmpro_btn-select">
                    <?php _e('Preview Changes', 'bb-pmpro-account-manager'); ?>
                </button>
                <button type="submit" id="apply-changes" class="pmpro_btn pmpro_btn-submit" style="display: none;">
                    <?php _e('Apply Changes', 'bb-pmpro-account-manager'); ?>
                </button>
                <button type="button" id="cancel-changes" class="pmpro_btn pmpro_btn-cancel" style="display: none;">
                    <?php _e('Cancel', 'bb-pmpro-account-manager'); ?>
                </button>
            </div>

            <input type="hidden" id="current_accounts" value="<?php echo $current_accounts; ?>" />
            <?php wp_nonce_field('bb_account_manager', 'account_manager_nonce'); ?>
        </form>
    </div>

    <div id="account-manager-messages" class="account-manager-messages"></div>

    <?php if ($settings['enable_proration']): ?>
        <div class="proration-info">
            <h4><?php _e('How Proration Works', 'bb-pmpro-account-manager'); ?></h4>
            <ul>
                <li><?php _e('When you add accounts, you\'ll be charged for the remaining days in your current billing period.', 'bb-pmpro-account-manager'); ?></li>
                <li><?php _e('When you remove accounts, you\'ll receive a credit for the remaining days.', 'bb-pmpro-account-manager'); ?></li>
                <li><?php _e('Changes take effect immediately after confirmation.', 'bb-pmpro-account-manager'); ?></li>
            </ul>
        </div>
    <?php endif; ?>

</div>