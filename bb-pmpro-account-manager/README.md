# BB PMPro Account Manager

Account management UI for BB PMPro Player Accounts plugin.

## Description

This plugin provides a user-friendly interface for members to manage their player accounts mid-cycle with automatic proration calculations.

## Features

- **Account Management Interface**: Easy-to-use UI for adjusting player accounts
- **Proration Support**: Automatic calculation of charges/credits for mid-cycle changes
- **Stripe Integration**: Seamless payment processing for account upgrades
- **Invoice Support**: Works with pay-by-check (invoice) payment method
- **Admin Controls**: Administrators can adjust member accounts from the WordPress admin
- **Dutch Translation**: Full Dutch language support included

## Requirements

- WordPress 5.0 or higher
- PHP 7.1 or higher
- BB PMPro Player Accounts plugin (required)
- Paid Memberships Pro plugin (required)

## Installation

1. Install and activate the required plugins:
    - Paid Memberships Pro
    - BB PMPro Player Accounts

2. Upload the `bb-pmpro-account-manager` folder to `/wp-content/plugins/`

3. Run `composer install` in the plugin directory

4. Activate the plugin through the WordPress admin

5. A "Manage Player Accounts" page will be automatically created

## Usage

### For Members

1. Navigate to the Account page or "Manage Player Accounts" page
2. Adjust your player accounts using the + / - buttons
3. Click "Preview Changes" to see proration calculations
4. Click "Apply Changes" to confirm

### For Administrators

1. Edit any user profile in WordPress admin
2. Find the "Player Accounts" field under membership information
3. Adjust the account count and save

## Shortcodes

### [pmpro_account_manager]

Displays the account management interface.

**Attributes:**
- None required

**Example:**
```
[pmpro_account_manager]
```

## Hooks and Filters

### Actions

- `bb_pmpro_accounts_updated` - Fired after accounts are successfully updated
    - Parameters: `$user_id`, `$new_accounts`, `$old_accounts`

### Filters

- `bb_pmpro_account_manager_max_accounts` - Filter maximum accounts allowed
- `bb_pmpro_account_manager_proration_enabled` - Enable/disable proration per user

## Configuration

The plugin uses settings from the BB PMPro Player Accounts plugin for:
- Default accounts per membership level
- Price per additional account
- Maximum account limits
- Proration settings

## Support

For support, please contact the plugin developer or submit an issue on the project repository.

## License

GPL v2 or later

## Changelog

### 1.0.0
- Initial release
- Account management interface
- Proration calculations
- Stripe integration
- Dutch translation

