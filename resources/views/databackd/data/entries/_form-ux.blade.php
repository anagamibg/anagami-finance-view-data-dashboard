{{-- AFV (S12/A07): shared behaviour of the data entry forms (entry, account, dimension, legal entity) - ui-guidelines §12.
     Fields opt in with data-dbk-rule="required" | "amount" | "required amount". Usage in the inline script of a form:
       dbkFormUx.attach('#form-id', {submit: function (withModifier) { ... }}); // blur validation + Enter / Ctrl+Enter submits
       if (!dbkFormUx.check('#form-id')) return;                       // before sending: shows every error, focuses the first
       dbkFormUx.afterServerErrors('#form-id', url, done);             // after the server returns 422: focus the first error
     The script is idempotent: the forms are loaded again and again into the same modal. --}}
<script>
    (function () {
        if (window.dbkFormUx) {
            return;
        }

        var T = @json(__('databackd-data-common.form_ux'));

        // Same rules as the server (StoreFinEntry::normalizeAmount, FinEntryBulkController::parseAmount):
        // "1234.56", "1234,56", "1 234,56", "1.234,56", "1,234.56". Returns null when it is not a number.
        function parseAmount(value) {
            var s = String(value === null || value === undefined ? '' : value).replace(/[\s  ]|€|EUR|eur|лв\.?/g, '');

            if (s === '' || !/^[+-]?[\d.,]+$/.test(s)) {
                return null;
            }

            var negative = s.charAt(0) === '-';
            s = s.replace(/^[+-]/, '');

            var comma = s.lastIndexOf(','), dot = s.lastIndexOf('.');

            if (comma !== -1 && dot !== -1) {
                var decimal = comma > dot ? ',' : '.', thousands = decimal === ',' ? '.' : ',';
                s = s.split(thousands).join('').replace(decimal, '.');
            } else if (comma !== -1) {
                s = (s.split(',').length - 1) > 1 ? s.split(',').join('') : s.replace(',', '.');
            } else if (dot !== -1 && (s.split('.').length - 1) > 1) {
                s = s.split('.').join('');
            }

            var number = parseFloat(s);

            if (isNaN(number) || !/^\d*\.?\d*$/.test(s) || number >= 1e12) {
                return null;
            }

            number = Math.round(number * 100) / 100;

            return negative ? -number : number;
        }

        function formatAmount(number) {
            var parts = Math.abs(number).toFixed(2).split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

            return (number < 0 ? '-' : '') + parts[0] + ',' + parts[1];
        }

        function holder($field) {
            var $group = $field.closest('.input-group');

            return $group.length ? $group : $field.closest('.form-group');
        }

        function setError($field, message) {
            var $holder = holder($field);
            var id = ($field.attr('id') || $field.attr('name') || 'field') + '-error';

            $holder.find('.invalid-feedback').remove();
            $holder.find('.bootstrap-select').toggleClass('is-invalid', !!message);
            $field.toggleClass('is-invalid', !!message).attr('aria-invalid', message ? 'true' : null);

            if (message) {
                $('<div class="d-block invalid-feedback" role="alert"></div>').attr('id', id).text(message).appendTo($holder);
                $field.attr('aria-describedby', id);
            } else if ($field.attr('aria-describedby') === id) {
                $field.removeAttr('aria-describedby');
            }
        }

        function validate($field) {
            var rules = String($field.data('dbk-rule') || '').split(' ');
            var value = $.trim(String($field.val() === null ? '' : $field.val()));

            if ($field.prop('readonly') || $field.prop('disabled')) {
                return '';
            }

            if (rules.indexOf('required') !== -1 && value === '') {
                return T.required;
            }

            if (rules.indexOf('amount') !== -1 && value !== '') {
                var number = parseAmount(value);

                if (number === null || number <= 0) {
                    return T.amount;
                }
            }

            return '';
        }

        function run($field) {
            var message = validate($field);
            setError($field, message);

            return message === '';
        }

        function focusField($field) {
            if (!$field || !$field.length) {
                return;
            }

            var $target = $field.hasClass('select-picker') || $field.closest('.bootstrap-select').length
                ? $field.closest('.form-group').find('.bootstrap-select .dropdown-toggle').first()
                : $field;

            if (!$target.length) {
                $target = $field;
            }

            if ($target[0] && $target[0].scrollIntoView) {
                $target[0].scrollIntoView({block: 'center'});
            }

            $target.trigger('focus');
        }

        function focusFirst(form) {
            focusField($(form).find('input.is-invalid, select.is-invalid, textarea.is-invalid').first());
        }

        window.dbkFormUx = {
            parseAmount: parseAmount,
            formatAmount: formatAmount,
            setError: setError,
            focusFirst: focusFirst,

            attach: function (form, options) {
                var $form = $(form);
                options = options || {};

                // Validate when the user leaves the field (not while typing); once shown, the error clears as soon as it is fixed
                $form.on('blur', 'input[data-dbk-rule], textarea[data-dbk-rule]', function () {
                    var $field = $(this);

                    if (run($field) && String($field.data('dbk-rule')).indexOf('amount') !== -1 && $.trim(this.value) !== '') {
                        this.value = formatAmount(parseAmount(this.value));
                    }
                });

                $form.on('input', 'input.is-invalid[data-dbk-rule], textarea.is-invalid[data-dbk-rule]', function () {
                    if (validate($(this)) === '') {
                        setError($(this), '');
                    }
                });

                $form.on('change', 'select[data-dbk-rule]', function () {
                    run($(this));
                });

                // Fast keyboard entry: Enter in a single line field (not in the search box of a select) or Ctrl/Cmd+Enter anywhere saves
                $form.on('keydown', 'input, textarea, select', function (event) {
                    if (event.key !== 'Enter' || event.isComposing || typeof options.submit !== 'function') {
                        return;
                    }

                    var withModifier = event.ctrlKey || event.metaKey;

                    if (!withModifier && ($(this).is('textarea') || $(this).closest('.bs-searchbox').length || $(this).is(':checkbox, :radio, [type=button]'))) {
                        return;
                    }

                    event.preventDefault();
                    // let the date picker / formatting handlers of the field run first
                    $(this).trigger('blur');
                    options.submit(withModifier);
                });
            },

            check: function (form) {
                var valid = true;

                $(form).find('[data-dbk-rule]').each(function () {
                    if (!run($(this))) {
                        valid = false;
                    }
                });

                if (!valid) {
                    focusFirst(form);
                }

                return valid;
            },

            // froiden-helper shows the 422 messages under the fields but only scrolls the page; move the focus to the first one.
            // done (optional) runs when the request has finished, whatever the result (e.g. to release a "busy" flag).
            afterServerErrors: function (form, url, done) {
                var handler = function (event, xhr, settings) {
                    if (settings.url !== url) {
                        return;
                    }

                    $(document).off('ajaxComplete', handler);

                    if (xhr.status === 422 || (xhr.responseJSON && xhr.responseJSON.status === 'fail' && xhr.responseJSON.errors)) {
                        focusFirst(form);
                    }

                    if (typeof done === 'function') {
                        done(xhr);
                    }
                };

                $(document).on('ajaxComplete', handler);
            }
        };
    })();
</script>
