<?php

namespace BB_PMPro_Player_Accounts\Includes;

class Rest_API {

    /**
     * @var Player_Accounts_Manager
     */
    private $accounts_manager;

    /**
     * @var Membership_Level_Settings
     */
    private $level_settings;

    /**
     * Constructor
     */
    public function __construct(
        Player_Accounts_Manager $accounts_manager,
        Membership_Level_Settings $level_settings
    ) {
        $this->accounts_manager = $accounts_manager;
        $this->level_settings = $level_settings;
    }

    /**
     * Initialize
     */
    public function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route('pmpro/v1', '/user-features', [
            'methods' => 'GET',
            'callback' => [$this, 'get_user_features'],
            'permission_callback' => [$this, 'permission_check'],
            'args' => [
                'user_id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
            ],
        ]);

        register_rest_route('pmpro/v1', '/user-accounts', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_user_accounts'],
                'permission_callback' => [$this, 'permission_check'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'update_user_accounts'],
                'permission_callback' => [$this, 'permission_check'],
                'args' => [
                    'accounts' => [
                        'required' => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param) && $param > 0;
                        }
                    ],
                ],
            ],
        ]);
    }

    /**
     * Permission check
     */
    public function permission_check($request) {
        $user_id = $request->get_param('user_id');

        // If user_id is provided, check if current user can edit that user
        if ($user_id) {
            return current_user_can('edit_user', $user_id);
        }

        // Otherwise, user must be logged in
        return is_user_logged_in();
    }

    /**
     * Get user features
     */
    public function get_user_features($request) {
        $user_id = $request->get_param('user_id') ?: get_current_user_id();

        $level = pmpro_getMembershipLevelForUser($user_id);

        if (!$level) {
            return new \WP_Error(
                'no_membership',
                __('User has no active membership', 'bb-pmpro-player-accounts'),
                ['status' => 404]
            );
        }

        $settings = $this->level_settings->get_level_settings($level->id);
        $accounts = $this->accounts_manager->get_user_accounts($user_id);

        return [
            'membership' => [
                'id' => $level->id,
                'name' => $level->name,
            ],
            'features' => array_map('trim', explode(',', $settings['features'])),
            'accounts' => [
                'current' => $accounts,
                'default' => $settings['default_accounts'],
                'max' => $settings['max_accounts'] ?: 25,
                'allow_extra' => $settings['allow_extra_accounts'],
                'price_per_extra' => $settings['price_per_account'],
            ],
        ];
    }

    /**
     * Get user accounts
     */
    public function get_user_accounts($request) {
        $user_id = $request->get_param('user_id') ?: get_current_user_id();

        return [
            'accounts' => $this->accounts_manager->get_user_accounts($user_id),
        ];
    }

    /**
     * Update user accounts
     */
    public function update_user_accounts($request) {
        $user_id = get_current_user_id();
        $new_accounts = (int) $request->get_param('accounts');

        if ($this->accounts_manager->update_user_accounts($user_id, $new_accounts)) {
            return [
                'success' => true,
                'accounts' => $new_accounts,
                'message' => __('Accounts updated successfully', 'bb-pmpro-player-accounts'),
            ];
        }

        return new \WP_Error(
            'update_failed',
            __('Failed to update accounts', 'bb-pmpro-player-accounts'),
            ['status' => 400]
        );
    }
}