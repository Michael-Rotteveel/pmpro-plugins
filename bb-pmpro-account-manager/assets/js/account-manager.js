(function($) {
    'use strict';

    const AccountManager = {

        init: function() {
            this.bindEvents();
            this.setupAccountSelector();
        },

        bindEvents: function() {
            // Account adjustment buttons
            $('.account-adjust-btn').on('click', this.adjustAccountCount);

            // Preview button
            $('#preview-changes').on('click', this.previewChanges);

            // Apply changes button
            $('#apply-changes').on('click', this.applyChanges);

            // Cancel button
            $('#cancel-changes').on('click', this.cancelChanges);

            // Form submission
            $('#account-adjustment-form').on('submit', function(e) {
                e.preventDefault();
                AccountManager.applyChanges();
            });

            // Manual input change
            $('#new_accounts').on('change', this.validateAccountInput);
        },

        setupAccountSelector: function() {
            const currentAccounts = parseInt($('#current_accounts').val());
            $('#new_accounts').data('original', currentAccounts);
        },

        adjustAccountCount: function() {
            const $input = $('#new_accounts');
            const action = $(this).data('action');
            const current = parseInt($input.val());
            const min = parseInt($input.attr('min'));
            const max = parseInt($input.attr('max'));

            let newValue = current;

            if (action === 'increase' && current < max) {
                newValue = current + 1;
            } else if (action === 'decrease' && current > min) {
                newValue = current - 1;
            }

            $input.val(newValue);
            AccountManager.checkForChanges();
        },

        validateAccountInput: function() {
            const $input = $(this);
            const value = parseInt($input.val());
            const min = parseInt($input.attr('min'));
            const max = parseInt($input.attr('max'));

            if (value < min) {
                $input.val(min);
            } else if (value > max) {
                $input.val(max);
            }

            AccountManager.checkForChanges();
        },

        checkForChanges: function() {
            const current = parseInt($('#current_accounts').val());
            const newValue = parseInt($('#new_accounts').val());

            if (current !== newValue) {
                $('#preview-changes').show();
                $('#proration-preview').hide();
                $('#apply-changes, #cancel-changes').hide();
            } else {
                $('#preview-changes').hide();
                $('#proration-preview').hide();
                $('#apply-changes, #cancel-changes').hide();
            }
        },

        previewChanges: function() {
            const $button = $(this);
            const originalText = $button.text();

            // Show loading state
            $button.prop('disabled', true).text(bbAccountManager.strings.calculating);

            // Clear previous messages
            $('#account-manager-messages').empty();

            const data = {
                action: 'bb_preview_account_change',
                nonce: $('#account_manager_nonce').val(),
                current_accounts: $('#current_accounts').val(),
                new_accounts: $('#new_accounts').val()
            };

            $.post(bbAccountManager.ajaxurl, data, function(response) {
                if (response.success) {
                    AccountManager.displayProration(response.data);
                    $('#preview-changes').hide();
                    $('#apply-changes, #cancel-changes').show();
                } else {
                    AccountManager.showMessage(response.data.message, 'error');
                }
            }).fail(function() {
                AccountManager.showMessage(bbAccountManager.strings.error, 'error');
            }).always(function() {
                $button.prop('disabled', false).text(originalText);
            });
        },

displayProration: function(data) {
    console.log('Proration Data:', data); // Debugging line
    const $preview = $('#proration-preview');
    const $details = $preview.find('.proration-details');

    let html = '<table class="proration-table">';

    // Account change - Fix for undefined values
    const currentAccounts = data.current_accounts || $('#current_accounts').val();
    const newAccounts = data.new_accounts || $('#new_accounts').val();

    console.log('Current Accounts:', currentAccounts); // Debugging line
    console.log('New Accounts:', newAccounts); // Debugging line

    html += '<tr>';
    html += '<td>' + 'Account Change:' + '</td>';
    html += '<td><strong>' + currentAccounts + ' → ' + newAccounts + '</strong></td>';
    html += '</tr>';

    // Days remaining
    if (data.days_remaining !== undefined) {
        html += '<tr>';
        html += '<td>Days Remaining in Period:</td>';
        html += '<td>' + Math.round(data.days_remaining) + ' days</td>';
        html += '</tr>';
    }

    // Proration amount
    if (data.proration_enabled && data.amount !== undefined) {
        const amountClass = data.type === 'charge' ? 'charge' : (data.type === 'credit' ? 'credit' : '');
        const amountPrefix = data.type === 'credit' ? '-' : (data.type === 'charge' ? '+' : '');

        if (data.type !== 'none') {
            html += '<tr class="proration-amount ' + amountClass + '">';
            html += '<td>Proration Amount:</td>';
            html += '<td><strong>' + amountPrefix + ' €' + Math.abs(data.amount).toFixed(2).replace('.', ',') + '</strong></td>';
            html += '</tr>';
        }
    }

    html += '</table>';

    if (data.message) {
        html += '<div class="proration-message">' + data.message + '</div>';
    }

    // Add confirmation message based on type
    if (data.type === 'charge') {
        html += '<div class="proration-warning">';
        html += '<strong>⚠️ ' + bbAccountManager.strings.confirm_upgrade + '</strong>';
        html += '</div>';
    } else if (data.type === 'credit') {
        html += '<div class="proration-info">';
        html += '<strong>ℹ️ ' + bbAccountManager.strings.confirm_downgrade + '</strong>';
        html += '</div>';
    }

    $details.html(html);
    $preview.slideDown();
},

applyChanges: function() {
    const $button = $('#apply-changes');
    const originalText = $button.text();

    // Confirm with user
    const newAccounts = parseInt($('#new_accounts').val());
    const currentAccounts = parseInt($('#current_accounts').val());

    let confirmMessage = 'Are you sure you want to change your player accounts from ' +
                        currentAccounts + ' to ' + newAccounts + '?';

    if (newAccounts > currentAccounts) {
        confirmMessage += '\n\nAn invoice will be created for the additional charges.';
    } else if (newAccounts < currentAccounts) {
        confirmMessage += '\n\nCredit will be applied to your next invoice.';
    }

    if (!confirm(confirmMessage)) {
        return;
    }

    // Show loading state
    $button.prop('disabled', true).text(bbAccountManager.strings.processing);
    $('#cancel-changes').prop('disabled', true);

    const data = {
        action: 'bb_update_accounts',
        nonce: $('#account_manager_nonce').val(),
        new_accounts: newAccounts
    };

    $.post(bbAccountManager.ajaxurl, data, function(response) {
        if (response.success) {
            let successMessage = response.data.message;

            // If invoice was created, show link
            if (response.data.invoice_url) {
                successMessage += '<br><br><a href="' + response.data.invoice_url + '" class="pmpro_btn">' +
                                'View Invoice' + '</a>';
            }

            AccountManager.showMessage(successMessage, 'success');

            // Update current values
            $('#current_accounts').val(response.data.new_accounts);
            $('#current-accounts-display').text(response.data.new_accounts);
            $('#new_accounts').val(response.data.new_accounts);

            // Reset form
            AccountManager.cancelChanges();

            // Don't auto-reload if there's an invoice to view
            if (!response.data.invoice_url) {
                setTimeout(function() {
                    location.reload();
                }, 3000);
            }
        } else {
            AccountManager.showMessage(response.data.message, 'error');
        }
    }).fail(function() {
        AccountManager.showMessage(bbAccountManager.strings.error, 'error');
    }).always(function() {
        $button.prop('disabled', false).text(originalText);
        $('#cancel-changes').prop('disabled', false);
    });
}

        cancelChanges: function() {
            // Reset to current value
            const current = $('#current_accounts').val();
            $('#new_accounts').val(current);

            // Hide preview and action buttons
            $('#proration-preview').slideUp();
            $('#apply-changes, #cancel-changes').hide();
            $('#preview-changes').show();

            // Clear messages
            $('#account-manager-messages').empty();
        },

        showMessage: function(message, type) {
            const $messages = $('#account-manager-messages');
            const messageClass = type === 'error' ? 'pmpro_error' : 'pmpro_success';

            const $message = $('<div class="pmpro_message ' + messageClass + '">' + message + '</div>');

            $messages.html($message);

            // Scroll to message
            $('html, body').animate({
                scrollTop: $messages.offset().top - 100
            }, 500);
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        if ($('#bb-account-manager').length) {
            AccountManager.init();
        }
    });

})(jQuery);