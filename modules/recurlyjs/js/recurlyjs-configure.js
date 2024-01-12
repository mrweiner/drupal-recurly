/**
 * @file
 * Configure recurly.js.
 */
(function ($) {
  'use strict';

  Drupal.recurly = Drupal.recurly || {};

  /**
   * Configures form for recurly hosted fields.
   */
  Drupal.behaviors.recurlyJSConfigureForm = {
    attach: function (context, settings) {
      // The recurly object should exist in the global scope as long as the
      // Recurly JS client is added to the page.
      if (typeof recurly != 'undefined') {
        recurly.configure({
          publicKey: settings.recurlyjs.recurly_public_key,
          style: {
            number: {
              placeholder: 'xxxx xxxx xxxx xxxx'
            },
            cvv: {
              placeholder: '123'
            },
            month: {
              placeholder: 'MM'
            },
            year: {
              placeholder: 'YYYY'
            }
          }
        });

        // Add listener for changes to credit card field's value.
        if (typeof Drupal.recurly.recurlyJSPaymentMethod != 'undefined') {
          recurly.on('change', Drupal.recurly.recurlyJSPaymentMethod);
        }

        // Create a new pricing module, and track change events for pricing related
        // elements on the form.
        if (typeof Drupal.recurly.recurlyJSPricing != 'undefined') {
          var pricing = recurly.Pricing.Checkout();
          pricing.on('set.plan', function (plan) {
            var buffer = '';
            if (plan.addons) {
              buffer = $.map(plan.addons, function (addon) {
                // usage add-ons need to be displayed separately
                if (addon.add_on_type === 'usage') return;
                return '<label for="addon-' + addon.code + '">' + addon.name + ' ('
                  + '<span data-recurly="currency_symbol"></span>'
                  + '<span data-recurly="addon_price" data-recurly-addon="' + addon.code + '"></span>'
                  + ')</label>'
                  + '<input type="text" data-recurly="addon" data-recurly-addon="' + addon.code + '" value="0" id="addon-' + addon.code + '">';
              }).join('');
            }
            // Populate the addon list and show/hide the addon label accordingly
            $('#addons').html(buffer);
            $('#addons-title')[buffer ? 'removeClass' : 'addClass']('recurlyjs-element__hidden');
          });

          pricing.attach('.recurly-form-wrapper form');
          pricing.on('change', Drupal.recurly.recurlyJSPricing);
          pricing.on('error', function (error) {
            // Checking for coupon validation errors.
            if (typeof error.message !== 'undefined') {
              var messageMarkup = '<div class="messages error coupon">' + error.message + '</div>';
              $('#recurly-form-errors').html(messageMarkup);
            }
          });
        }
      }
    }
  };
})(jQuery);
