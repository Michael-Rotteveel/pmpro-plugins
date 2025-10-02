<?php
/**
 * Account selector template for checkout
 *
 * @var array $settings
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

global $pmpro_level;
$current_accounts = $_REQUEST['player_accounts'] ?? $settings['default_accounts'];
$annual_price_per_account = $settings['price_per_account'] * 12;
?>

<fieldset id="pmpro_player_accounts_fields" class="pmpro_form_fieldset">
    <div class="pmpro_card">
        <div class="pmpro_card_content">
            <legend class="pmpro_form_legend">
                <h2 class="pmpro_form_heading pmpro_font-large">
                    <?php _e('Player Accounts', 'bb-pmpro-player-accounts'); ?>
                </h2>
            </legend>
            <div class="pmpro_form_fields">
                <div class="pmpro_form_field pmpro_form_field-player_accounts">
                    <label for="player_accounts" class="pmpro_form_label">
                        <?php _e('Number of Player Accounts', 'bb-pmpro-player-accounts'); ?>
                        <?php if ($settings['allow_extra_accounts']): ?>
                            <span class="pmpro_asterisk"> <abbr title="<?php _e('Required Field', 'bb-pmpro-player-accounts'); ?>">*</abbr></span>
                        <?php endif; ?>
                    </label>
                    <input type="number"
                           id="player_accounts"
                           name="player_accounts"
                           class="pmpro_form_input pmpro_form_input-number <?php echo $settings['allow_extra_accounts'] ? 'pmpro_form_input-required' : ''; ?>"
                           min="<?php echo esc_attr($settings['default_accounts']); ?>"
                           max="<?php echo esc_attr($settings['max_accounts'] ?: 25); ?>"
                           value="<?php echo esc_attr($current_accounts); ?>"
                           data-default="<?php echo esc_attr($settings['default_accounts']); ?>"
                           data-price-monthly="<?php echo esc_attr($settings['price_per_account']); ?>"
                           data-price-annual="<?php echo esc_attr($annual_price_per_account); ?>"
                           data-level-cost="<?php echo esc_attr($pmpro_level->initial_payment); ?>" />

                    <?php if ($settings['default_accounts'] > 0): ?>
                        <div class="pmpro_form_hint">
                            <?php
                            printf(
                                __('This membership includes %d player account(s).', 'bb-pmpro-player-accounts'),
                                $settings['default_accounts']
                            );

                            if ($settings['allow_extra_accounts'] && $settings['price_per_account'] > 0) {
                                echo ' ';
                                printf(
                                    __('Additional accounts: €%s per month (€%s per year) each.', 'bb-pmpro-player-accounts'),
                                    number_format($settings['price_per_account'], 2, ',', '.'),
                                    number_format($annual_price_per_account, 2, ',', '.')
                                );
                            }
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($settings['allow_extra_accounts']): ?>
                        <div id="player_accounts_cost_preview" class="pmpro_form_field_description"></div>
                    <?php endif; ?>
                </div> <!-- end pmpro_form_field -->
            </div> <!-- end pmpro_form_fields -->
        </div> <!-- end pmpro_card_content -->
    </div> <!-- end pmpro_card -->
</fieldset>