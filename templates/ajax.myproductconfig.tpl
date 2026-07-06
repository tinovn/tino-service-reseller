{*
  Tino module — AJAX fragment: renders one dynamic <select>.

  Contract (assigned by Tino_controller::product_values):
    $valx      option key being rendered (Category | Product | Cycle)
    $defval    currently saved value (to mark selected)
    $modvalues normalized [id => ['id','label','selected']]

  The <select> name/id mirror the container in myproductconfig.tpl so HostBill
  saves the value under options[<key>].
*}
<select name="options[{$valx|escape}]" id="{$valx|escape}" class="form-control">
    <option value="">- select -</option>
    {foreach from=$modvalues item=value}
        <option value="{$value.id|escape}"{if $value.selected} selected="selected"{/if}>{$value.label|escape}</option>
    {foreachelse}
        <option value="" disabled="disabled">- no options available -</option>
    {/foreach}
</select>
