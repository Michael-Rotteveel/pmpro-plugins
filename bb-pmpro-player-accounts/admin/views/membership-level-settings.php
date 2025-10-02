<?php
/**
 * Membership level settings view
 *
 * @var array $settings
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<h3 class="topborder"><?php _e('Player Account Settings', 'bb-pmpro-player-accounts'); ?></h3>
<table class="form-table">
    <tbody>
    <tr>
        <th scope="row">
            <label for="default_player_accounts">
                <?php _e('Default Player Accounts', 'bb-pmpro-player-accounts'); ?>
            </label>
        </th>
        <td>
            <input type="number"
                   id="default_player_accounts"
                   name="default_player_accounts"
                   min="1"
                   value="<?php echo esc_attr($settings['default_accounts']); ?>" />
            <p class="description">
                <?php _e('Number of player accounts included with this membership level.', 'bb-pmpro-player-accounts'); ?>
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="allow_extra_accounts">
                <?php _e('Allow Extra Accounts', 'bb-pmpro-player-accounts'); ?>
            </label>
        </th>
        <td>
            <input type="checkbox"
                   id="allow_extra_accounts"
                   name="allow_extra_accounts"
                   value="1"
                <?php checked($settings['allow_extra_accounts'], 1); ?> />
            <label for="allow_extra_accounts">
                <?php _e('Allow members to purchase additional player accounts', 'bb-pmpro-player-accounts'); ?>
            </label>
        </td>
    </tr>

    <tr class="extra-accounts-setting" <?php if (!$settings['allow_extra_accounts']) echo 'style="display:none;"'; ?>>
        <th scope="row">
            <label for="price_per_account">
                <?php _e('Monthly Price per Extra Account (€)', 'bb-pmpro-player-accounts'); ?>
            </label>
        </th>
        <td>
            <input type="number"
                   id="price_per_account"
                   name="price_per_account"
                   min="0"
                   step="0.01"
                   value="<?php echo esc_attr($settings['price_per_account']); ?>" />
            <p class="description">
                <?php
                _e('Monthly price for each additional player account.', 'bb-pmpro-player-accounts');
                if ($settings['price_per_account'] > 0) {
                    echo '<br /><strong>';
                    printf(
                        __('Annual cost: €%s per account', 'bb-pmpro-player-accounts'),
                        number_format($settings['price_per_account'] * 12, 2)
                    );
                    echo '</strong>';
                }
                ?>
            </p>
        </td>
    </tr>

    <tr class="extra-accounts-setting" <?php if (!$settings['allow_extra_accounts']) echo 'style="display:none;"'; ?>>
        <th scope="row">
            <label for="max_accounts">
                <?php _e('Maximum Accounts', 'bb-pmpro-player-accounts'); ?>
            </label>
        </th>
        <td>
            <input type="number"
                   id="max_accounts"
                   name="max_accounts"
                   min="0"
                   value="<?php echo esc_attr($settings['max_accounts']); ?>" />
            <p class="description">
                <?php _e('Maximum number of accounts allowed. Set to 0 for unlimited (capped at 25).', 'bb-pmpro-player-accounts'); ?>
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="enable_proration">
                <?php _e('Enable Proration', 'bb-pmpro-player-accounts'); ?>
            </label>
        </th>
        <td>
            <input type="checkbox"
                   id="enable_proration"
                   name="enable_proration"
                   value="1"
                <?php checked($settings['enable_proration'], 1); ?> />
            <label for="enable_proration">
                <?php _e('Enable proration for mid-cycle account adjustments', 'bb-pmpro-player-accounts'); ?>
            </label>
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="tier_features">
                <?php _e('Features', 'bb-pmpro-player-accounts'); ?>
            </label>
        </th>
        <td>
                <textarea id="tier_features"
                          name="tier_features"
                          rows="3"
                          cols="50"><?php echo esc_textarea($settings['features']); ?></textarea>
            <p class="description">
                <?php _e('Comma-separated list of feature flags for this membership level.', 'bb-pmpro-player-accounts'); ?>
                <br />
                <?php _e('Examples: LIMITED_ACCESS, STANDARD_ACCESS, PREMIUM_ACCESS, COLLECTIVE_INVOICE', 'bb-pmpro-player-accounts'); ?>
            </p>
        </td>
    </tr>
    </tbody>
</table>