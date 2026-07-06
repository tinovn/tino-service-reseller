{*
  Tino module — product configuration (admin).

  HostBill injects this template as a table row of the product config form, so
  the outer element MUST be <tr><td> (a bare <div> breaks the surrounding table
  and turns the config tab links into "#", freezing tab switching).

  Category / Product / Cycle are dynamic <select> containers (.tofetch) filled
  by AJAX from the Tino API. Saved values ($default.*) render server-side; the
  catalog is fetched only when the admin clicks "Load / Reload catalog".
*}
<tr>
    <td id="onappconfig_">
        {if $test_connection_result}
            <div style="margin-bottom:10px; font-weight:bold; text-transform:capitalize; color:{if $test_connection_result.result == 'Success'}#009900{else}#990000{/if}">
                Test connection:
                {if $test_connection_result.result}{$test_connection_result.result|escape}{/if}
                {if $test_connection_result.error}: {$test_connection_result.error|escape}{/if}
            </div>
        {/if}

        <div class="tino-product-config">

            <div class="form-group">
                <label>Category</label>
                <div class="tofetch tino-fetch" data-opt="Category">
                    <select name="options[Category]" id="Category" class="form-control">
                        {if $default.Category}
                            <option value="{$default.Category|escape}" selected="selected">{$default.Category|escape}</option>
                        {else}
                            <option value="">- select category -</option>
                        {/if}
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Product</label>
                <div class="tofetch tino-fetch" data-opt="Product">
                    <select name="options[Product]" id="Product" class="form-control">
                        {if $default.Product}
                            <option value="{$default.Product|escape}" selected="selected">{$default.Product|escape}</option>
                        {else}
                            <option value="">- select product -</option>
                        {/if}
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Billing Cycle</label>
                <div class="tofetch tino-fetch" data-opt="Cycle">
                    <select name="options[Cycle]" id="Cycle" class="form-control">
                        {if $default.Cycle}
                            <option value="{$default.Cycle|escape}" selected="selected">{$default.Cycle|escape}</option>
                        {else}
                            <option value="">- select cycle -</option>
                        {/if}
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Promotion Code <small>(optional)</small></label>
                <input type="text" name="options[Promocode]" id="Promocode"
                       class="form-control" value="{$default.Promocode|default:''|escape}" />
            </div>

            <div class="form-group">
                <label>Affiliate ID <small>(optional)</small></label>
                <input type="text" name="options[Affiliate ID]" id="AffiliateId"
                       class="form-control" value="{$default.'Affiliate ID'|default:''|escape}" />
            </div>

            <div class="form-group">
                <button type="button" class="btn btn-default tino-reload-cache">
                    &#8635; Load / Reload catalog
                </button>
                <small class="tino-hint">Click to load the product list from Tino (cached; click again to refresh).</small>
            </div>

        </div>

        <script type="text/javascript">
            var TINO_SERVER_ID = '{$server_id|default:''|escape:'javascript'}';
        </script>
        <script type="text/javascript" src="../includes/modules/Hosting/tino/templates/js/product.js"></script>
    </td>
</tr>
