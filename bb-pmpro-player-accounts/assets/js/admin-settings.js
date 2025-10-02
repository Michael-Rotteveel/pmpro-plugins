jQuery(document).ready(function($) {
    'use strict';

    // Toggle extra accounts settings visibility
    $('#allow_extra_accounts').on('change', function() {
        const extraSettings = $('.extra-accounts-setting');

        if ($(this).is(':checked')) {
            extraSettings.slideDown();
        } else {
            extraSettings.slideUp();
        }
    });

    // Validate max accounts input
    $('#max_accounts').on('change', function() {
        const value = parseInt($(this).val());
        const defaultAccounts = parseInt($('#default_player_accounts').val());

        if (value > 0 && value < defaultAccounts) {
            alert('Maximum accounts must be greater than or equal to default accounts (' + defaultAccounts + ')');
            $(this).val(defaultAccounts);
        }
    });

    // Feature tags helper
    const featureTagsField = $('#tier_features');
    if (featureTagsField.length) {
        const commonFeatures = [
            'LIMITED_ACCESS',
            'STANDARD_ACCESS',
            'PREMIUM_ACCESS',
            'TRIAL_MEMBERSHIP',
            'ACCOUNT_LIMIT_10',
            'ACCOUNT_LIMIT_25',
            'UNLIMITED_ACCOUNTS',
            'EXTRA_ACCOUNTS',
            'COLLECTIVE_INVOICE'
        ];

        // Add feature tag buttons
        const featureButtons = $('<div class="feature-tag-buttons" style="margin-top: 10px;"></div>');

        commonFeatures.forEach(function(feature) {
            const button = $('<button type="button" class="button button-secondary" style="margin: 2px;">' +
                          feature + '</button>');

            button.on('click', function(e) {
                e.preventDefault();
                const currentValue = featureTagsField.val();
                const features = currentValue ? currentValue.split(',').map(f => f.trim()) : [];

                if (!features.includes(feature)) {
                    features.push(feature);
                    featureTagsField.val(features.join(', '));
                }
            });

            featureButtons.append(button);
        });

        featureTagsField.after(featureButtons);
    }

    // Membership level presets
    const presetConfigs = {
        'limited_trial': {
            default_accounts: 1,
            allow_extra: false,
            price_per_account: 0,
            max_accounts: 1,
            features: 'LIMITED_ACCESS, TRIAL_MEMBERSHIP'
        },
        'limited': {
            default_accounts: 1,
            allow_extra: false,
            price_per_account: 0,
            max_accounts: 1,
            features: 'LIMITED_ACCESS'
        },
        'standard': {
            default_accounts: 2,
            allow_extra: true,
            price_per_account: 1.50,
            max_accounts: 10,
            features: 'STANDARD_ACCESS, ACCOUNT_LIMIT_10, EXTRA_ACCOUNTS'
        },
        'plus': {
            default_accounts: 2,
            allow_extra: true,
            price_per_account: 1.50,
            max_accounts: 0,
            features: 'PREMIUM_ACCESS, UNLIMITED_ACCOUNTS, ACCOUNT_LIMIT_25, EXTRA_ACCOUNTS, COLLECTIVE_INVOICE'
        }
    };

    // Add preset selector if on new level page
    if (!$('#level_id').val() || $('#level_id').val() === '0') {
        const presetSelector = $('<div class="pmpro-preset-selector" style="background: #f1f1f1; padding: 15px; margin: 20px 0; border-radius: 5px;">' +
                                '<h4>Quick Setup - Membership Tier Presets</h4>' +
                                '<select id="tier_preset">' +
                                '<option value="">-- Select a preset --</option>' +
                                '<option value="limited_trial">Gelimiteerd [Proef] (Limited Trial)</option>' +
                                '<option value="limited">Gelimiteerd (Limited)</option>' +
                                '<option value="standard">Standaard (Standard)</option>' +
                                '<option value="plus">Plus (Premium)</option>' +
                                '</select>' +
                                '<p class="description">Select a preset to automatically configure player account settings for this membership level.</p>' +
                                '</div>');

        $('h3:contains("Player Account Settings")').after(presetSelector);

        $('#tier_preset').on('change', function() {
            const preset = $(this).val();
            if (preset && presetConfigs[preset]) {
                const config = presetConfigs[preset];

                $('#default_player_accounts').val(config.default_accounts);
                $('#allow_extra_accounts').prop('checked', config.allow_extra);
                $('#price_per_account').val(config.price_per_account);
                $('#max_accounts').val(config.max_accounts);
                $('#tier_features').val(config.features);

                // Trigger change event to update UI
                $('#allow_extra_accounts').trigger('change');
            }
        });
    }
    // Dynamic price preview for monthly to annual conversion
    $('#price_per_account').on('input change', function() {
        const monthlyPrice = parseFloat($(this).val()) || 0;
        const annualPrice = monthlyPrice * 12;

        let description = $(this).closest('td').find('.description');

        if (monthlyPrice > 0) {
            if (description.find('.annual-preview').length === 0) {
                description.append('<br /><strong class="annual-preview"></strong>');
            }
            description.find('.annual-preview').html(
                'Annual cost: €' + annualPrice.toFixed(2) + ' per account'
            );
        } else {
            description.find('.annual-preview').remove();
        }
    });

    // Trigger on page load
    $('#price_per_account').trigger('change');
});