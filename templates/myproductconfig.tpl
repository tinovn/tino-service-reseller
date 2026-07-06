{*
  Tino module — product configuration (admin).

  Category / Product / Cycle are dynamic <select> containers (.tofetch) filled
  by AJAX from the Tino API. Product depends on Category; Cycle depends on
  Product. "Reload catalog" clears the cached catalog and re-fetches.

  Saved values ($default.*) are echoed inside escaped attributes/text; the AJAX
  fragment (ajax.myproductconfig.tpl) replaces these with the full list.
*}

<div class="tino-product-config">

    <div class="form-group">
        <label>Category</label>
        <div id="Categorycontainer" class="tofetch tino-fetch" data-opt="Category">
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
        <div id="Productcontainer" class="tofetch tino-fetch" data-opt="Product" data-depends="Category">
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
        <div id="Cyclecontainer" class="tofetch tino-fetch" data-opt="Cycle" data-depends="Product">
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
