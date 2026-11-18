/*
 * NumenCode.Bricks: backend behaviour of bricks fields (the repeater FieldBuilder makes; its container has data-bricks).
 *
 * - "Add brick" list: hides the types in data-bricks-hidden="code,code" (not allowed in this field, or not permitted
 *   for the user). The repeater keeps their groups so existing bricks keep their values; only new ones can't be added.
 *   Each plugin's types get a heading (data-bricks "sections"); the general types come first, without one.
 *   The list is a <script data-group-palette-template>, re-rendered on AJAX updates (e.g. a locale switch).
 * - Collapsed bricks show "Type: summary": the value of the type's titleFrom field, or of its first filled field.
 * - "Expand all" / "Collapse all" buttons above the bricks.
 */
+function ($) {
    'use strict';

    var SUMMARY_LENGTH = 100;

    function config($container) {
        try {
            return JSON.parse($container.attr('data-bricks') || '{}');
        } catch (e) {
            return {};
        }
    }

    // The bricks field container of a repeater element, if the repeater is a bricks field (not a nested repeater)
    function bricksContainer($repeater) {
        var $container = $repeater.closest('.form-group');

        return $container.is('[data-bricks]') ? $container : $();
    }

    //
    // "Add brick" list
    //

    function hiddenCodes($container) {
        return String($container.attr('data-bricks-hidden') || '').split(',').filter(function (code) {
            return code !== '';
        });
    }

    // The type code of a palette entry (its data-request-data is "_repeater_group: 'code'")
    function paletteCode(link) {
        var match = String($(link).attr('data-request-data') || '').match(/_repeater_group:\s*'([^']*)'/);

        return match ? match[1] : null;
    }

    // Hides the types in data-bricks-hidden and adds a heading before each plugin's section (the groups already come
    // in section order). The headings are text, never HTML.
    function processPalettes() {
        $('[data-bricks]').each(function () {
            var $container = $(this),
                hidden = hiddenCodes($container),
                sections = config($container).sections || [];

            $container.find('script[data-group-palette-template]').each(function () {
                if (this.getAttribute('data-bricks-processed') || (!hidden.length && !sections.length)) {
                    return;
                }

                var $template = $('<div>').html(this.innerHTML),
                    items = {};

                $template.find('[data-repeater-add]').each(function () {
                    var code = paletteCode(this),
                        $item = $(this).closest('.repeater-group-item');

                    if (code !== null && hidden.indexOf(code) !== -1) {
                        $item.remove();
                    } else if (code !== null) {
                        items[code] = $item;
                    }
                });

                sections.forEach(function (section) {
                    var first = null;

                    (section.codes || []).forEach(function (code) {
                        first = first || items[code] || null;
                    });

                    if (first) {
                        first.before($('<div class="bricks-palette-section">').text(section.label));
                    }
                });

                this.innerHTML = $template.html();
                this.setAttribute('data-bricks-processed', '1');
            });
        });
    }

    // The palette's search hides entries; hide the headings of sections without a visible entry too. Core's handler is
    // bound to the input itself, so this delegated one runs after it.
    $(document).on('input', '.repeater-group-search', function () {
        var $grid = $(this).closest('.repeater-group-search-container').parent().find('.repeater-group-grid');

        $grid.children('.bricks-palette-section').each(function () {
            var visible = $(this).nextUntil('.bricks-palette-section', '.repeater-group-item').filter(function () {
                return this.style.display !== 'none';
            }).length > 0;

            $(this).toggle(visible);
        });
    });

    //
    // Collapsed titles
    //

    // Text of HTML without running it (DOMParser doesn't execute scripts or load images)
    function htmlToText(html) {
        return new DOMParser().parseFromString(String(html), 'text/html').body.textContent || '';
    }

    function shorten(text) {
        text = String(text).replace(/\s+/g, ' ').trim();

        return text.length > SUMMARY_LENGTH ? text.slice(0, SUMMARY_LENGTH).trim() + '…' : text;
    }

    // The fields of an item's own form, not those of nested repeaters inside it
    function ownFields($item) {
        return $item.find('[data-field-name]').filter(function () {
            return $(this).closest('.field-repeater-item')[0] === $item[0];
        });
    }

    // The value of one field as text, or '' when it is empty or can't be summarised (checkboxes, switches)
    function fieldText($field) {
        var $repeater = $field.find('[data-control="fieldrepeater"]').first(),
            $media = $field.find('[data-control="mediafinder"]').first(),
            $code = $field.find('[data-control="codeeditor"]').first(),
            $select = $field.find('select').first(),
            $textarea = $field.find('textarea').first(),
            $input = $field.find('input[type=text], input[type=url], input[type=email], input[type=number]').first();

        if ($repeater.length) {
            // A nested repeater: the summaries of its items, e.g. the images of a gallery
            return $repeater.children('.field-repeater-items').children('.field-repeater-item').map(function () {
                return itemSummary($(this), null);
            }).get().filter(Boolean).join(', ');
        }

        if ($media.length) {
            var path = String($media.find('input').filter(function () { return this.value !== ''; }).first().val() || '');

            return path.split('/').pop();
        }

        if ($code.length) {
            // Code (e.g. an HTML brick) is shown as written; the editor keeps its value in a hidden input
            return $code.find('textarea[name], input[name]').first().val() || '';
        }

        if ($select.length) {
            return $select.val() ? $select.find('option:selected').text() : '';
        }

        if ($textarea.length) {
            return htmlToText($textarea.val());
        }

        return $input.length ? $input.val() : '';
    }

    function itemSummary($item, titleFrom) {
        // Without the anchor and CSS class ("Advanced")
        var $fields = ownFields($item).not('.bricks-advanced'),
            text = '';

        if (titleFrom) {
            text = shorten(fieldText($fields.filter('[data-field-name="' + titleFrom + '"]').first()));
        }

        $fields.each(function () {
            if (!text) {
                text = shorten(fieldText($(this)));
            }
        });

        return text;
    }

    function patchRepeater() {
        var Repeater = $.fn.fieldRepeater && $.fn.fieldRepeater.Constructor;

        if (!Repeater || Repeater.prototype.bricksPatched) {
            return;
        }

        var getCollapseTitle = Repeater.prototype.getCollapseTitle,
            collapse = Repeater.prototype.collapse;

        Repeater.prototype.bricksPatched = true;

        // Plain text, for anything else that reads the title
        Repeater.prototype.getCollapseTitle = function ($item) {
            var parts = titleParts(this, $item, getCollapseTitle);

            return parts.summary ? parts.type + ': ' + parts.summary : parts.type;
        };

        // Core writes the title as text; a brick's gets the type in bold
        Repeater.prototype.collapse = function ($item) {
            collapse.call(this, $item);

            if (bricksContainer(this.$el).length) {
                renderTitle(this, $item, getCollapseTitle);
            }
        };
    }

    // The type name (core's title: the group name) and, for bricks, the summary of the item's content
    function titleParts(repeater, $item, getCollapseTitle) {
        var type = getCollapseTitle.call(repeater, $item),
            $container = bricksContainer(repeater.$el);

        if (!$container.length) {
            return {type: type, summary: ''};
        }

        var code = $item.find('input[name$="[_group]"]').first().val();

        return {type: type, summary: itemSummary($item, (config($container).titleFrom || {})[code])};
    }

    // Built from text nodes, so content is never rendered as HTML
    function renderTitle(repeater, $item, getCollapseTitle) {
        var parts = titleParts(repeater, $item, getCollapseTitle),
            $title = $item.children('.repeater-item-collapsed-title').empty();

        $('<strong class="bricks-title-type">').text(parts.type).appendTo($title);

        if (parts.summary) {
            $title.append(document.createTextNode(': ' + parts.summary));
        }
    }

    // Repeaters that were set up before the patch got the plain type names
    function refreshTitles() {
        var Repeater = $.fn.fieldRepeater && $.fn.fieldRepeater.Constructor;

        if (!Repeater || !Repeater.prototype.bricksPatched) {
            return;
        }

        $('[data-bricks] [data-control="fieldrepeater"]').each(function () {
            var repeater = $(this).data('oc.repeater');

            if (!repeater || !bricksContainer(repeater.$el).length) {
                return;
            }

            repeater.$el.children('.field-repeater-items').children('.field-repeater-item.collapsed').each(function () {
                Repeater.prototype.collapse.call(repeater, $(this));
            });
        });
    }

    //
    // Header of an open brick: its type (hidden while collapsed, the title shows it then)
    //

    function addItemHeaders() {
        $('[data-bricks] [data-control="fieldrepeater"]').each(function () {
            var $repeater = $(this);

            if (!bricksContainer($repeater).length) {
                return;
            }

            $repeater.children('.field-repeater-items').children('.field-repeater-item').each(function () {
                var $item = $(this).addClass('bricks-item');

                if (!$item.children('.bricks-item-header').length) {
                    $('<div class="bricks-item-header">')
                        .append($('<strong class="bricks-title-type">').text(String($item.attr('data-collapse-title') || '')))
                        .insertAfter($item.children('.repeater-item-collapsed-title'));
                }

                addItemActions($item);
                updateEditLink($item);
                updateHidden($item);
                initAdvanced($item);
            });
        });
    }

    //
    // Actions of a brick, left of the collapse and remove buttons: the "Edit …" link and the buttons
    //

    function addItemActions($item) {
        if ($item.children('.bricks-item-actions').length) {
            return;
        }

        var labels = config(bricksContainer($item.closest('[data-control="fieldrepeater"]'))).labels || {},
            $actions = $('<div class="bricks-item-actions">').insertAfter($item.children('.bricks-item-header'));

        $('<button type="button" class="bricks-item-action" data-bricks-action="duplicate">')
            .attr('title', labels.duplicate || 'Duplicate')
            .append('<i class="icon-copy"></i>')
            .appendTo($actions);

        $('<button type="button" class="bricks-item-action" data-bricks-action="hide">')
            .attr('title', labels.hide || 'Hide')
            .append('<i class="icon-eye"></i>')
            .appendTo($actions);
    }

    //
    // Duplicate (the copy button): the server renders a copy of the brick's posted values (BrickDuplicator), which is
    // inserted right after the brick
    //

    $(document).on('click', '[data-bricks] .bricks-item-actions [data-bricks-action="duplicate"]', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();

        var $item = $(this).closest('.bricks-item'),
            $remove = $item.children('.repeater-item-remove').find('[data-repeater-remove]'),
            handler = String($remove.attr('data-request') || '').replace(/::onRemoveItem$/, '::onDuplicateBrick'),
            index = String($remove.attr('data-request-data') || '').match(/_repeater_index':\s*'([^']*)'/);

        if (!index || handler.indexOf('::onDuplicateBrick') === -1) {
            return;
        }

        $item.request(handler, {
            data: {_bricks_source: index[1]},
            success: function (data) {
                var $copy = $(String(data.item || '')).filter('li.field-repeater-item');

                if (!$copy.length) {
                    return;
                }

                $copy.insertAfter($item).trigger('render');
                $item.closest('[data-field-name]').trigger('change.oc.formwidget');
                $copy.find(':input').first().trigger('change');
            }
        });
    });

    //
    // "Advanced": the anchor and CSS class fields, shown with a toggle (open when one of them has a value)
    //

    function setAdvanced($item, open) {
        ownFields($item).filter('.bricks-advanced').toggleClass('bricks-advanced--open', open);
        ownFields($item).find('[data-bricks-advanced-toggle] > i').first()
            .attr('class', open ? 'icon-angle-down' : 'icon-angle-right');
        $item.data('bricks-advanced', open);
    }

    function initAdvanced($item) {
        if ($item.data('bricks-advanced') !== undefined) {
            return;
        }

        setAdvanced($item, ownFields($item).filter('.bricks-advanced').find('input').filter(function () {
            return $.trim(this.value) !== '';
        }).length > 0);
    }

    $(document).on('click', '[data-bricks] [data-bricks-advanced-toggle]', function (ev) {
        ev.preventDefault();

        var $item = $(this).closest('.bricks-item');

        setAdvanced($item, !$item.data('bricks-advanced'));
    });

    //
    // Hidden bricks (the eye button): stored with _hidden = 1, not shown on the website
    //

    // The brick's own hidden flag input (FieldBuilder::HIDDEN_FIELD), not one of a nested brick
    function hiddenFlag($item) {
        return $item.find('input[data-bricks-hidden-flag]').filter(function () {
            return $(this).closest('.field-repeater-item')[0] === $item[0];
        }).first();
    }

    function updateHidden($item) {
        var $flag = hiddenFlag($item),
            hidden = $flag.length > 0 && !$flag.prop('disabled'),
            labels = config(bricksContainer($item.closest('[data-control="fieldrepeater"]'))).labels || {};

        $item.toggleClass('bricks-item--hidden', hidden);
        $item.children('.bricks-item-actions').find('[data-bricks-action="hide"]')
            .attr('title', hidden ? (labels.show || 'Show') : (labels.hide || 'Hide'))
            .find('i').attr('class', hidden ? 'icon-eye-slash' : 'icon-eye');

        $item.children('.bricks-item-actions').children('.bricks-item-hidden-label').remove();

        if (hidden) {
            $('<span class="bricks-item-hidden-label">').text(labels.hidden || 'Hidden')
                .insertBefore($item.children('.bricks-item-actions').children('.bricks-item-action').first());
        }
    }

    $(document).on('click', '[data-bricks] .bricks-item-actions [data-bricks-action="hide"]', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();

        var $item = $(this).closest('.bricks-item'),
            $flag = hiddenFlag($item);

        // Enabled = hidden (posted); disabled = shown (not posted, nothing stored). "change" marks the form as changed.
        $flag.prop('disabled', !$flag.prop('disabled')).trigger('change');
        updateHidden($item);
    });

    //
    // "Edit …" link: where a component brick's content is edited (e.g. the selected Widgets group), in a new tab
    //

    // The URL with each {field} replaced by the brick's field value, or '' when one of them is empty
    function editUrl(pattern, $item) {
        var $fields = ownFields($item),
            missing = false,
            url = pattern.replace(/\{([A-Za-z0-9_]+)\}/g, function (match, name) {
                var value = String($fields.filter('[data-field-name="' + name + '"]').first()
                    .find('select, input:not([type=hidden]), textarea').first().val() || '').trim();

                missing = missing || value === '';

                return encodeURIComponent(value);
            });

        return missing ? '' : url;
    }

    function updateEditLink($item) {
        var $container = bricksContainer($item.closest('[data-control="fieldrepeater"]')),
            code = $item.find('input[name$="[_group]"]').first().val(),
            edit = (config($container).edit || {})[code],
            url = edit ? editUrl(edit.url, $item) : '',
            $link = $item.children('.bricks-item-actions').children('.bricks-item-edit');

        if (!url) {
            $link.remove();
            $item.removeClass('bricks-item--editable');
            return;
        }

        if (!$link.length) {
            $link = $('<a class="bricks-item-edit" target="_blank" rel="noopener">').prependTo($item.children('.bricks-item-actions'));
        }

        $link.attr('href', url).text(edit.label + ' ›');
        $item.addClass('bricks-item--editable');
    }

    $(document).on('change', '[data-bricks] .bricks-item', function (ev) {
        // The brick whose field changed (not a brick around a nested repeater)
        updateEditLink($(ev.target).closest('.bricks-item'));
    });

    // Like the collapsed bar, which opens the brick, the header closes it
    $(document).on('click', '[data-bricks] .bricks-item-header', function (ev) {
        if ($(ev.target).closest('a').length) {
            return;
        }

        var $item = $(this).closest('.field-repeater-item'),
            repeater = $item.closest('[data-control="fieldrepeater"]').data('oc.repeater');

        if (repeater) {
            repeater.collapse($item);
        }
    });

    //
    // Expand all / Collapse all
    //

    function addToggleButtons() {
        $('[data-bricks]').each(function () {
            var $container = $(this),
                settings = config($container);

            if ($container.children('.bricks-toggle-all').length || !settings.expandAll) {
                return;
            }

            // Translatable fields have a language button in the top right corner
            var $buttons = $('<div class="bricks-toggle-all">')
                .toggleClass('bricks-toggle-all--multilingual', $container.find('.field-multilingual').length > 0);

            $.each({expand: settings.expandAll, collapse: settings.collapseAll}, function (action, label) {
                $('<button type="button" class="btn btn-default btn-xs">')
                    .addClass(action === 'expand' ? 'wn-icon-angle-double-down' : 'wn-icon-angle-double-up')
                    .attr('data-bricks-toggle', action)
                    .text(label)
                    .appendTo($buttons);
            });

            $container.prepend($buttons);
        });
    }

    $(document).on('click', '[data-bricks-toggle]', function (ev) {
        ev.preventDefault();

        var $button = $(this),
            repeater = $button.closest('[data-bricks]').find('[data-control="fieldrepeater"]').filter(function () {
                return bricksContainer($(this)).is($button.closest('[data-bricks]'));
            }).first().data('oc.repeater');

        if (repeater) {
            $button.data('bricks-toggle') === 'expand' ? repeater.expandAll() : repeater.collapseAll();
        }
    });

    //
    // Winter.Translate: a hint above an empty list in another language (the website shows the default language's
    // bricks there), with a button for Translate's own "copy from" of the default language
    //

    function updateFallbackNotices() {
        $('[data-bricks]').each(function () {
            var $container = $(this),
                locales = config($container).locales,
                $ml = $container.find('[data-control="mlrepeater"]').first(),
                $notice = $ml.children('.bricks-fallback');

            if (!locales || !locales.default || !$ml.length) {
                return;
            }

            var active = String($ml.find('[data-repeater-active-locale]').first().val() || ''),
                count = $ml.find('[data-control="fieldrepeater"]').first().children('.field-repeater-items').children('.field-repeater-item').length,
                names = locales.names || {},
                name = function (code) { return names[code] || code; };

            if (!active || active === locales.default || count > 0) {
                $notice.remove();
                return;
            }

            if ($notice.length && $notice.data('locale') === active) {
                return;
            }

            $notice.remove();
            $notice = $('<div class="bricks-fallback">').data('locale', active)
                .append($('<span>').text(String(locales.notice || '').replace(':locale', name(active)).replace(':default', name(locales.default))))
                .append(' ')
                .append($('<button type="button" class="btn btn-default btn-xs" data-bricks-copy-default>')
                    .text(String(locales.copy || '').replace(':default', name(locales.default))));

            // Inside the multilingual field, so its language button (positioned above the field) stays on the label line
            $ml.prepend($notice);
        });
    }

    $(document).on('click', '[data-bricks] [data-bricks-copy-default]', function () {
        var $container = $(this).closest('[data-bricks]'),
            locales = config($container).locales || {};

        $container.find('[data-control="mlrepeater"]').first()
            .find('.ml-locale-copy[data-copy-locale="' + locales.default + '"]').first().trigger('click');
    });

    // A brick removed (the list may be empty now)
    $(document).on('change', '[data-bricks]', function () {
        window.setTimeout(updateFallbackNotices, 0);
    });

    function update() {
        patchRepeater();
        processPalettes();
        refreshTitles();
        addItemHeaders();
        addToggleButtons();
        updateFallbackNotices();
    }

    patchRepeater();
    $(document).on('render', update);
    $(update);
}(window.jQuery);
