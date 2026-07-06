/*
 * Tino module — product configuration JS.
 *
 * Each row has a "Load" button that fetches its list from the Tino API and
 * caches it (?cmd=tino&action=updatecache&opt=...). Event delegation on
 * document only — no work runs on load, so nothing races the HostBill tab UI.
 *
 *   Category button -> load all categories
 *   Product  button -> load products of the selected category
 */
(function ($) {
    'use strict';
    if (!$) { return; }

    // The selected App Connection is the HostBill server picker: a checked
    // checkbox in #serv_picker (same source proxmox2 uses).
    function serverId() {
        return $('#serv_picker input[type=checkbox][name]:checked:eq(0)').val() || '';
    }

    function val(id) {
        var el = $('#' + id);
        return el.length ? el.val() : '';
    }

    // Replace a select's options with the fetched list, keeping current value.
    function fill(id, items) {
        var $sel = $('#' + id);
        if (!$sel.length) { return; }
        var current = $sel.val();
        var html = '<option value="">- select ' + id.toLowerCase() + ' -</option>';
        $.each(items || [], function (i, it) {
            var sel = (String(it.id) === String(current)) ? ' selected="selected"' : '';
            html += '<option value="' + it.id + '"' + sel + '>' + it.label + '</option>';
        });
        $sel.html(html);
    }

    function loadRow(opt, $btn) {
        var sid = serverId();
        if (!sid) {
            alert('Tino: please select an App Connection (server) first');
            return;
        }
        var data = { server_id: sid, opt: opt };
        if (opt === 'Product') {
            data.category = val('Category');
        }

        var label = $btn.html();
        $btn.prop('disabled', true).html('…');

        $.post('?cmd=tino&action=updatecache', data, null, 'json')
            .done(function (res) {
                if (res && res.ok) {
                    fill(opt, res.items);
                } else {
                    alert('Tino: ' + ((res && res.error) || 'failed to load'));
                }
            })
            .fail(function () {
                alert('Tino: request failed');
            })
            .always(function () {
                $btn.prop('disabled', false).html(label);
            });
    }

    $(document).on('click.tino', '.tino-product-config .tino-load', function (e) {
        e.preventDefault();
        loadRow($(this).attr('data-opt'), $(this));
    });

})(window.jQuery);
