/*
 * Tino module — product configuration JS.
 *
 * Populates the Category / Product / Cycle selects from the Tino API
 * (?cmd=tino&action=productdetails).
 *
 * Design constraints (learned the hard way):
 *  - NEVER run AJAX or touch the DOM on load. Eager work races the HostBill
 *    tab engine and freezes tab switching.
 *  - Use event delegation bound once on `document`, so we never depend on the
 *    config markup existing at load time and never interfere with core init.
 *  - Wrap everything so a failure here can never break HostBill's own scripts.
 */
(function ($) {
    'use strict';

    if (!$) { return; }

    function serverId() {
        return (typeof window.TINO_SERVER_ID !== 'undefined' && window.TINO_SERVER_ID)
            ? window.TINO_SERVER_ID : '';
    }

    function productId() {
        var el = $('input[name="id"]').filter('[value]').eq(0);
        return el.length ? el.val() : '';
    }

    // Current values of every Tino option (so dependent selects resolve).
    function currentOptions() {
        var opts = {};
        $('.tino-product-config').find('[name^="options["]').each(function () {
            var name = $(this).attr('name') || '';
            var m = /^options\[(.+)\]$/.exec(name);
            if (m) { opts[m[1]] = $(this).val(); }
        });
        return opts;
    }

    // Fetch one dynamic select into its container. Returns a jQuery promise.
    function loadSelect(opt, make) {
        var $container = $('.tino-fetch[data-opt="' + opt + '"]');
        if (!$container.length || !productId()) {
            return $.Deferred().resolve().promise();
        }
        $container.addClass('tino-loading');
        return $.post('?cmd=tino&action=productdetails', {
            id: productId(),
            server_id: serverId(),
            opt: opt,
            make: make || 'getonappval',
            options: currentOptions()
        }).done(function (html) {
            if (typeof html === 'string' && html.indexOf('<select') !== -1) {
                $container.html(html);
            }
        }).always(function () {
            $container.removeClass('tino-loading');
        });
    }

    // Fetch a dependent chain (each step waits for the previous).
    function reloadChain(startOpt, make) {
        if (startOpt === 'Category') {
            loadSelect('Category', make).done(function () {
                loadSelect('Product', make).done(function () {
                    loadSelect('Cycle', make);
                });
            });
        } else if (startOpt === 'Product') {
            loadSelect('Product').done(function () { loadSelect('Cycle'); });
        } else if (startOpt === 'Cycle') {
            loadSelect('Cycle');
        }
    }

    // Delegated handlers bound once on document — no DOM work happens on load,
    // so nothing races the HostBill tab engine.
    $(document)
        .on('change.tino', '.tino-product-config #Category', function () {
            reloadChain('Product');
        })
        .on('change.tino', '.tino-product-config #Product', function () {
            reloadChain('Cycle');
        })
        .on('click.tino', '.tino-product-config .tino-reload-cache', function (e) {
            e.preventDefault();
            reloadChain('Category', 'reloadcache');
        });

})(window.jQuery);
