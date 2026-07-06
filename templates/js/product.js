/*
 * Tino module — product configuration JS.
 *
 * Populates the Category / Product / Cycle selects from the Tino API via AJAX
 * (?cmd=tino&action=productdetails).
 *
 * IMPORTANT: fetching is LAZY / event-driven — we never run AJAX on DOM ready.
 * Firing XHRs eagerly on load races the outer HostBill tab navigation and can
 * leave a pending request during the unload phase, which freezes tab switching.
 * The selects render their saved values server-side; the catalog is only
 * fetched when the admin explicitly loads it (button) or changes a parent
 * select.
 */
(function ($) {
    'use strict';

    function serverId() {
        return (typeof TINO_SERVER_ID !== 'undefined' && TINO_SERVER_ID) ? TINO_SERVER_ID : '';
    }

    function productId() {
        var el = $('input[name="id"], #id').filter('[value]').eq(0);
        return el.length ? el.val() : '';
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

    function requestData(opt, make) {
        return {
            id: productId(),
            server_id: serverId(),
            opt: opt,
            make: make || 'getonappval',
            options: currentOptions()
        };
    }

    // Fetch one dynamic select into its .tofetch container. Returns a promise.
    function loadSelect(opt, make) {
        var $container = $('.tino-fetch[data-opt="' + opt + '"]');
        if (!$container.length || !productId()) {
            return $.Deferred().resolve().promise();
        }

        $container.addClass('tino-loading');

        return $.post('?cmd=tino&action=productdetails', requestData(opt, make))
            .done(function (html) {
                if (typeof html === 'string' && html.indexOf('<select') !== -1) {
                    $container.html(html);
                    bindChange($container.find('select'));
                }
            })
            .always(function () {
                $container.removeClass('tino-loading');
            });
    }

    // Reload the dependent chain starting at a given option.
    function reloadChain(startOpt, make) {
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

    // Bind change handlers to the (server-rendered or freshly loaded) selects.
    function bindChange($scope) {
        $scope.filter('select, :has(select)').find('select').addBack('select')
            .off('change.tino').on('change.tino', function () {
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

        // Bind change on the server-rendered selects. NO eager AJAX on load —
        // the saved values are already shown; the admin loads the catalog on demand.
        bindChange($cfg.find('select'));

        // Load / Reload catalog button — fetch (force refresh) on demand.
        $cfg.on('click', '.tino-reload-cache', function (e) {
            e.preventDefault();
            reloadChain('Category', 'reloadcache');
        });
    }

    $(init);
})(jQuery);
