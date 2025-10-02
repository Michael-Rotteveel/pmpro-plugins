jQuery(document).ready(function($) {
    'use strict';

    const accountInput = $('#player_accounts');
    const costPreview = $('#player_accounts_cost_preview');

    if (accountInput.length === 0) {
        return;
    }

    const defaultAccounts = parseInt(accountInput.data('default'));
    const pricePerAccountMonthly = parseFloat(accountInput.data('price-monthly'));
    const pricePerAccountAnnual = parseFloat(accountInput.data('price-annual'));
    const levelCost = parseFloat(accountInput.data('level-cost'));

    function formatCurrency(amount) {
        return '€' + amount.toFixed(2).replace('.', ',');
    }

    function updateCostPreview() {
        const selectedAccounts = parseInt(accountInput.val()) || defaultAccounts;
        const extraAccounts = Math.max(0, selectedAccounts - defaultAccounts);
        const extraCostAnnual = extraAccounts * pricePerAccountAnnual;

        if (extraAccounts > 0 && pricePerAccountAnnual > 0) {
            let html = '<div class="pmpro-cost-breakdown">';
            html += '<div class="pmpro-cost-breakdown-item">';
            html += '<span>' + bbPlayerAccounts.strings.extra_accounts + ':</span>';
            html += '<span>' + extraAccounts + 'x</span>';
            html += '</div>';
            html += '<div class="pmpro-cost-breakdown-item">';
            html += '<span>' + bbPlayerAccounts.strings.price_per_account + ':</span>';
            html += '<span>' + formatCurrency(pricePerAccountMonthly) + '/maand (' + formatCurrency(pricePerAccountAnnual) + '/jaar)</span>';
            html += '</div>';
            html += '<div class="pmpro-cost-breakdown-item">';
            html += '<span><strong>' + bbPlayerAccounts.strings.total_extra + ':</strong></span>';
            html += '<span><strong>' + formatCurrency(extraCostAnnual) + '</strong></span>';
            html += '</div>';
            html += '</div>';

            costPreview.html(html);

            // Update total cost in the pricing summary if it exists
            updatePricingSummary(levelCost, extraCostAnnual);
        } else {
            costPreview.empty();

            // Reset pricing summary
            updatePricingSummary(levelCost, 0);
        }
    }

    function updatePricingSummary(basePrice, extraCost) {
        // Update PMPro v3 pricing display
        const pricingDisplay = $('.pmpro_price');
        if (pricingDisplay.length) {
            const totalPrice = basePrice + extraCost;
            pricingDisplay.find('.pmpro_price-parts').text(formatCurrency(totalPrice));
        }

        // Update legacy pricing display
        const levelCostElement = $('#pmpro_level_cost strong');
        if (levelCostElement.length) {
            const totalPrice = basePrice + extraCost;
            levelCostElement.text(formatCurrency(totalPrice));
        }
    }

    // Update on change
    accountInput.on('change input', updateCostPreview);

    // Initial update
    updateCostPreview();

    // Handle form submission validation
    $('#pmpro_form').on('submit', function() {
        const selectedAccounts = parseInt(accountInput.val());
        const minAccounts = parseInt(accountInput.attr('min'));
        const maxAccounts = parseInt(accountInput.attr('max'));

        // Remove any existing error classes
        accountInput.removeClass('pmpro_error');
        $('#player_accounts_error').remove();

        if (selectedAccounts < minAccounts || selectedAccounts > maxAccounts) {
            accountInput.addClass('pmpro_error');

            // Add error message
            const errorMsg = selectedAccounts < minAccounts
                ? bbPlayerAccounts.strings.min_accounts_error.replace('%d', minAccounts)
                : bbPlayerAccounts.strings.max_accounts_error.replace('%d', maxAccounts);

            accountInput.after('<div id="player_accounts_error" class="pmpro_message pmpro_error">' + errorMsg + '</div>');

            // Scroll to error
            $('html, body').animate({
                scrollTop: accountInput.offset().top - 100
            }, 500);

            return false;
        }

        return true;
    });

    // Handle account adjustment form if present
    $('#bb-adjust-accounts-form').on('submit', function(e) {
        e.preventDefault();

        const form = $(this);
        const submitBtn = form.find('button[type="submit"]');
        const resultDiv = $('#adjustment-result');
        const newAccounts = $('#new_accounts').val();

        // Disable submit button
        submitBtn.prop('disabled', true).text(bbPlayerAccounts.strings.calculating);

        // Clear previous messages
        resultDiv.removeClass('pmpro_success pmpro_error').empty();

        $.ajax({
            url: bbPlayerAccounts.ajaxurl,
            type: 'POST',
            data: {
                action: 'bb_adjust_player_accounts',
                accounts: newAccounts,
                nonce: bbPlayerAccounts.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.addClass('pmpro_success').html(
                        '<p>' + response.data.message + '</p>'
                    );

                    if (response.data.proration !== 0) {
                        const prorationMsg = response.data.proration > 0
                            ? bbPlayerAccounts.strings.additional_charge + ': ' + formatCurrency(response.data.proration)
                            : bbPlayerAccounts.strings.credit_applied + ': ' + formatCurrency(Math.abs(response.data.proration));
                        resultDiv.append('<p>' + prorationMsg + '</p>');
                    }
                } else {
                    resultDiv.addClass('pmpro_error').html(
                        '<p>' + response.data.message + '</p>'
                    );
                }
            },
            error: function() {
                resultDiv.addClass('pmpro_error').html(
                    '<p>' + bbPlayerAccounts.strings.error_occurred + '</p>'
                );
            },
            complete: function() {
                submitBtn.prop('disabled', false).text(bbPlayerAccounts.strings.update_accounts);
            }
        });
    });
});