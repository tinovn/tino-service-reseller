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

    // HostBill product id being edited (hidden input `id` on the config form).
    function productId() {
        var el = $('input[name="id"]').filter('[value]').eq(0);
        return el.length ? el.val() : '';
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

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Build the admin's default-value control for one form field.
    //   - selectable types with items -> a <select> the admin picks a default from
    //   - otherwise -> a free text input
    function fieldControl(f) {
        var name = 'options[' + f.variable + ']';
        if (f.items && f.items.length) {
            var s = '<select name="' + esc(name) + '" class="form-control tino-form-default">'
                + '<option value="">- default (none) -</option>';
            $.each(f.items, function (i, it) {
                s += '<option value="' + esc(it.id) + '">' + esc(it.label) + '</option>';
            });
            return s + '</select>';
        }
        return '<input type="text" name="' + esc(name) + '" class="form-control tino-form-default" '
            + 'placeholder="default value" />';
    }

    // Render the product's custom form fields into #tino-forms.
    function renderForms(forms) {
        var $box = $('#tino-forms');
        if (!$box.length) { return; }
        if (!forms || !forms.length) {
            $box.html('<p class="tino-hint">This product has no configurable options.</p>');
            return;
        }
        var html = '<hr/><h4>Product options (from Tino)</h4>'
            + '<p class="tino-hint">Pick a default value, or tick "Allow client to choose" so the client selects it during checkout.</p>';
        $.each(forms, function (i, f) {
            var meta = f.type + (f.items && f.items.length ? ' · ' + f.items.length + ' options' : '');
            html += '<div class="form-group tino-form-row" data-form-id="' + esc(f.form_id)
                + '" data-variable="' + esc(f.variable) + '">'
                + '<label>' + esc(f.title) + (f.required ? ' <span style="color:#990000">*</span>' : '')
                + ' <small style="color:#888">(' + esc(meta) + ')</small></label>'
                + fieldControl(f)
                + '<label style="font-weight:normal; display:block; margin-top:4px">'
                + '<input type="checkbox" class="tino-formchecker" rel="' + esc(f.variable) + '" /> '
                + 'Allow client to choose during order</label>'
                + '<span class="tino-form-link"></span>'
                + '</div>';
        });
        $box.html(html);
    }

    // Tick "Allow client to choose" -> create a HostBill config field for this
    // form (via importformel), then show the "Edit related form element" link
    // (same UX as proxmox2). The admin default is disabled while ticked.
    $(document).on('change.tino', '.tino-product-config .tino-formchecker', function () {
        var $cb  = $(this);
        var $row = $cb.closest('.tino-form-row');
        $row.find('.tino-form-default').prop('disabled', this.checked);

        if (!this.checked) {
            $row.find('.tino-form-link').empty();
            return;
        }

        var sid = serverId(), pid = val('Product'), hbPid = productId();
        if (!sid || !pid) {
            alert('Tino: select an App Connection and product first');
            $cb.prop('checked', false);
            $row.find('.tino-form-default').prop('disabled', false);
            return;
        }

        var $link = $row.find('.tino-form-link').text(' creating…');
        $.post('?cmd=tino&action=importformel', {
            id: hbPid,
            server_id: sid,
            product: pid,
            variable: $row.attr('data-variable')
        }, null, 'json').done(function (res) {
            if (res && res.ok && res.fid) {
                $link.html(' <a href="#" class="editbtn" onclick="return editCustomFieldForm(\''
                    + res.fid + '\',\'' + hbPid + '\')">Edit related form element</a>');
                if (typeof editCustomFieldForm === 'function') {
                    editCustomFieldForm(res.fid, hbPid);
                }
            } else {
                alert('Tino: ' + ((res && res.error) || 'failed to create form element'));
                $cb.prop('checked', false);
                $row.find('.tino-form-default').prop('disabled', false);
                $link.empty();
            }
        }).fail(function () {
            alert('Tino: request failed');
            $cb.prop('checked', false);
            $link.empty();
        });
    });

    $(document).on('click.tino', '.tino-product-config .tino-load-forms', function (e) {
        e.preventDefault();
        var sid = serverId();
        if (!sid) { alert('Tino: please select an App Connection (server) first'); return; }
        var pid = val('Product');
        if (!pid) { alert('Tino: please select a product first'); return; }

        var $btn = $(this), label = $btn.html();
        $btn.prop('disabled', true).html('…');
        $.post('?cmd=tino&action=updatecache', { server_id: sid, opt: 'Forms', product: pid }, null, 'json')
            .done(function (res) {
                if (res && res.ok) {
                    renderForms(res.forms);
                } else {
                    alert('Tino: ' + ((res && res.error) || 'failed to load options'));
                }
            })
            .fail(function () { alert('Tino: request failed'); })
            .always(function () { $btn.prop('disabled', false).html(label); });
    });

})(window.jQuery);
