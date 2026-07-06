<tr>
    <td id="onappconfig_">
        <div class="tino-product-config" data-server-id="{$server_id|default:''|escape}">

            <div class="form-group">
                <label>Category</label>
                <div style="display:flex; gap:8px;">
                    <select name="options[Category]" id="Category" class="form-control">
                        <option value="">- select category -</option>
                        {if $tino_categories}
                            {foreach from=$tino_categories item=cat}
                                <option value="{$cat.id|escape}" {if $default.Category == $cat.id}selected="selected"{/if}>{$cat.label|escape}</option>
                            {/foreach}
                        {elseif $default.Category}
                            <option value="{$default.Category|escape}" selected="selected">{$default.Category|escape}</option>
                        {/if}
                    </select>
                    <button type="button" class="btn btn-default tino-load" data-opt="Category">&#8635; Load</button>
                </div>
            </div>

            <div class="form-group">
                <label>Product</label>
                <div style="display:flex; gap:8px;">
                    <select name="options[Product]" id="Product" class="form-control">
                        <option value="">- select product -</option>
                        {if $tino_products}
                            {foreach from=$tino_products item=prod}
                                <option value="{$prod.id|escape}" {if $default.Product == $prod.id}selected="selected"{/if}>{$prod.label|escape}</option>
                            {/foreach}
                        {elseif $default.Product}
                            <option value="{$default.Product|escape}" selected="selected">{$default.Product|escape}</option>
                        {/if}
                    </select>
                    <button type="button" class="btn btn-default tino-load" data-opt="Product">&#8635; Load</button>
                </div>
            </div>

            <div class="form-group">
                <label>Promotion Code</label>
                <input type="text" name="options[Promocode]" id="Promocode" class="form-control" value="{$default.Promocode|default:''|escape}" />
            </div>

            {if $tino_forms}
                <hr/>
                <h4>Product options (from Tino)</h4>
                <p class="tino-hint">Tick a field to let your clients choose it during checkout.</p>
                {foreach from=$tino_forms item=form}
                    <div class="form-group tino-form-row" data-form-id="{$form.form_id|escape}" data-variable="{$form.variable|escape}">
                        <label>
                            {$form.title|escape}
                            {if $form.required} <span style="color:#990000">*</span>{/if}
                            <small style="color:#888">({$form.type|escape}{if $form.items} · {$form.items|@count} options{/if})</small>
                        </label>
                        <div>
                            <label style="font-weight:normal">
                                <input type="checkbox" class="tino-formchecker" rel="{$form.variable|escape}" />
                                Allow client to choose during order
                            </label>
                            <span class="tino-form-link"></span>
                        </div>
                    </div>
                {/foreach}
            {/if}

        </div>
{literal}
        <script type="text/javascript" src="../includes/modules/Hosting/tino/templates/js/product.js"></script>
{/literal}
    </td>
</tr>
