/*
 * Tino module — product configuration JS.
 *
 * Populates the Category / Product / Cycle selects from the Tino API via AJAX
 * (?cmd=tino&action=productdetails). Dependencies:
 *   Category change  -> reload Product, then Cycle
 *   Product change   -> reload Cycle
 * "Reload catalog" clears the server-side cache and re-fetches everything.
 */
(function ($) {
    'use strict';

    function serverId() {
        return (typeof TINO_SERVER_ID !== 'undefined' && TINO_SERVER_ID) ? TINO_SERVER_ID : '';
    }

    function productId() {
        // HostBill exposes the product id being edited as a hidden input `id`.
        var el = $('input[name="id"], #id').filter('[value]').eq(0);
        return el.length ? el.val() : ($('#tino-product-id').val() || '');
    }

    // Collect current values of every Tino option so dependent selects resolve.
    function currentOptions() {
        var opts = {};
        $('.tino-product-config').find('select[name^="options["], input[name^="options["]').each(function () {
            var m = /^options\[(.+)\]$/.exec($(this).attr('name'));
            if (m) {
                opts[m[1]] = $(this).val();
            }
        });
        return opts;
    }

    function requestData(opt, extra) {
        return $.extend({
            id: productId(),
            server_id: serverId(),
            opt: opt,
            options: currentOptions()
        }, extra || {});
    }

    // Load one dynamic select into its .tofetch container.
    function loadSelect(opt, make) {
        var $container = $('.tino-fetch[data-opt="' + opt + '"]');
        if (!$container.length) {
            return $.Deferred().resolve().promise();
        }

        $container.addClass('tino-loading');

        return $.post(
            '?cmd=tino&action=productdetails',
            requestData(opt, { make: make || 'getonappval' })
        ).done(function (html) {
            if (typeof html === 'string' && html.indexOf('<select') !== -1) {
                $container.html(html);
                bindChange($container.find('select'));
            }
        }).always(function () {
            $container.removeClass('tino-loading');
        });
    }

    // Reload the whole dependent chain starting at a given option.
    function reloadChain(startOpt, force) {
        var make = force ? 'reloadcache' : 'getonappval';
        if (startOpt === 'Category') {
            loadSelect('Category', make).done(function () {
                loadSelect('Product', make).done(function () {
                    loadSelect('Cycle', make);
                });
            });
        } else if (startOpt === 'Product') {
            loadSelect('Product').done(function () {
                loadSelect('Cycle');
            });
        } else if (startOpt === 'Cycle') {
            loadSelect('Cycle');
        }
    }

    function bindChange($select) {
        $select.off('change.tino').on('change.tino', function () {
            var opt = $(this).attr('id');
            if (opt === 'Category') {
                reloadChain('Product');
            } else if (opt === 'Product') {
                reloadChain('Cycle');
            }
        });
    }

    function init() {
        var $cfg = $('.tino-product-config');
        if (!$cfg.length) {
            return;
        }

        // Initial population (respects saved values via the server response).
        loadSelect('Category').done(function () {
            loadSelect('Product').done(function () {
                loadSelect('Cycle');
            });
        });

        // Reload catalog button — force refresh from API.
        $cfg.on('click', '.tino-reload-cache', function (e) {
            e.preventDefault();
            reloadChain('Category', true);
        });
    }

    $(init);
})(jQuery);
