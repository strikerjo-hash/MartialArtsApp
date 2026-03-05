/**
 * wallet-pay.js — Stripe Payment Request Button (Apple Pay, Google Pay)
 *
 * Usage:
 *   initWalletPay(stripe, {
 *     amount:      1500,           // Amount in cents
 *     label:       'Event Fee',    // Display label on the wallet sheet
 *     currency:    'usd',          // Optional, defaults to 'usd'
 *     country:     'US',           // Optional, defaults to 'US'
 *     containerId: 'wallet-pay-container',
 *     dividerId:   'wallet-pay-divider',   // Optional: element to show/hide
 *     onToken:     function(paymentMethod) { ... }
 *   });
 *
 * The container div should exist in the DOM. It will be hidden automatically
 * if no wallet is available (e.g. desktop browser without Apple Pay / Google Pay).
 *
 * Samsung Pay works through Google Pay on mobile web — no separate SDK needed.
 */

function initWalletPay(stripe, opts) {
    var container = document.getElementById(opts.containerId);
    var divider   = opts.dividerId ? document.getElementById(opts.dividerId) : null;

    if (!container) return;

    // Hide by default until we confirm a wallet is available
    container.style.display = 'none';
    if (divider) divider.style.display = 'none';

    var paymentRequest = stripe.paymentRequest({
        country:  opts.country || 'US',
        currency: opts.currency || 'usd',
        total: {
            label:  opts.label || 'Total',
            amount: opts.amount || 0,
        },
        requestPayerName:  true,
        requestPayerEmail: false,
    });

    var elements = stripe.elements();
    var prButton = elements.create('paymentRequestButton', {
        paymentRequest: paymentRequest,
        style: {
            paymentRequestButton: {
                type:  'default',
                theme: 'dark',
                height: '48px',
            },
        },
    });

    // Check if a wallet is available
    paymentRequest.canMakePayment().then(function(result) {
        if (result) {
            container.style.display = 'block';
            if (divider) divider.style.display = 'block';
            prButton.mount('#' + opts.containerId);
        }
        // If no wallet available, container stays hidden — graceful fallback
    });

    // Handle the wallet payment
    paymentRequest.on('paymentmethod', function(ev) {
        if (typeof opts.onToken === 'function') {
            opts.onToken(ev.paymentMethod);
        }
        ev.complete('success');
    });

    return {
        paymentRequest: paymentRequest,
        button: prButton,
        // Allow updating the amount dynamically (e.g. after discount applied)
        updateAmount: function(newAmountCents, newLabel) {
            paymentRequest.update({
                total: {
                    label:  newLabel || opts.label || 'Total',
                    amount: newAmountCents,
                },
            });
        },
    };
}
