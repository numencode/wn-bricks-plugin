/*
 * NumenCode.Bricks: hides brick types from the "Add brick" list of a bricks field.
 *
 * The field's container has data-bricks-hidden="code,code" (types not allowed in this field, or not permitted for
 * the user). The repeater keeps their groups so existing bricks of those types keep their values; only new ones
 * can't be added. The list is a <script data-group-palette-template> inside the repeater, re-rendered on AJAX
 * updates (e.g. a locale switch), so it is processed on every "render" event.
 */
+function ($) {
    'use strict';

    function hiddenCodes($container) {
        return String($container.attr('data-bricks-hidden') || '').split(',').filter(function (code) {
            return code !== '';
        });
    }

    function hidePaletteItems() {
        $('[data-bricks-hidden]').each(function () {
            var $container = $(this),
                hidden = hiddenCodes($container);

            $container.find('script[data-group-palette-template]').each(function () {
                if (this.getAttribute('data-bricks-processed') || !hidden.length) {
                    return;
                }

                var $template = $('<div>').html(this.innerHTML);

                $template.find('[data-repeater-add]').each(function () {
                    var match = String($(this).attr('data-request-data') || '').match(/_repeater_group:\s*'([^']*)'/);

                    if (match && hidden.indexOf(match[1]) !== -1) {
                        $(this).closest('.repeater-group-item').remove();
                    }
                });

                this.innerHTML = $template.html();
                this.setAttribute('data-bricks-processed', '1');
            });
        });
    }

    $(document).on('render', hidePaletteItems);
    $(hidePaletteItems);
}(window.jQuery);
