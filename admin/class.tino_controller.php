<?php

/**
 * Admin controller for the Tino module.
 *
 * Serves the dynamic product-configuration UI. HostBill routes admin AJAX to
 * `?cmd=tino&action=<publicMethod>`; the config UI uses `action=productdetails`.
 *
 * Follows the Proxmox2 convention:
 *   - $this->module    : the Tino module instance (auto-injected by core)
 *   - $this->template  : Smarty template engine (from HBController)
 */
class Tino_controller extends HBController
{
    /** @var Tino */
    var $module;

    /**
     * Entry point for the product-config UI (AJAX).
     *
     * Dispatches on $params['make']:
     *   - (default)     render the full product-config page
     *   - getonappval   render one dynamic <select> (category/product/cycle)
     *   - reloadcache   clear cached catalog data, then re-render the select
     *
     * @param array $params
     */
    public function productdetails($params)
    {
        // Connect to the selected server so the module can call the Tino API.
        if (!empty($params['server_id'])) {
            $servers = HBLoader::LoadModel('Servers');
            $this->module->connect($servers->getServerDetails($params['server_id']));
        }

        $tplDir = APPDIR_MODULES . 'Hosting' . DS . 'tino' . DS . 'templates' . DS;
        $this->template->assign('product_tpl_dir', $tplDir);

        $make = isset($params['make']) ? $params['make'] : '';

        switch ($make) {
            case 'reloadcache':
                $this->module->clearCatalogCache();
                $this->product_values($params, true);
                break;

            case 'getonappval':
                $this->product_values($params);
                break;

            default:
                // Full config page. Saved option values are exposed as $default.
                $this->template->assign('customconfig', $tplDir . 'myproductconfig.tpl');
                $this->template->assign('default', $this->savedOptionValues($params));
                $this->template->assign('server_id', isset($params['server_id']) ? $params['server_id'] : '');
                return;
        }

        // Dynamic select fragment.
        $this->template->assign('make', $make);
        $this->template->render($tplDir . 'ajax.myproductconfig.tpl');
    }

    /**
     * Render one dynamic select based on $params['opt'].
     *
     * Assigns the AJAX template contract:
     *   - valx      : the option key being rendered
     *   - defval    : currently saved value (to mark selected)
     *   - modvalues : normalized [id => ['id','label','selected']]
     *
     * @param array $params
     * @param bool  $force Force refresh from API (bypass cache)
     */
    protected function product_values($params, $force = false)
    {
        if (($params['id'] ?? '') === 'new') {
            Engine::addError('Please save your product first');
            return;
        }
        if (empty($params['opt'])) {
            return;
        }

        $opt    = $params['opt'];
        $defVal = $this->currentOptionValue($params, $opt);

        // Sibling option values needed for dependent selects (product depends
        // on category, cycle depends on product).
        $siblings   = isset($params['options']) && is_array($params['options']) ? $params['options'] : [];
        $categoryId = (int) ($siblings[Tino::O_CATEGORY_ID] ?? 0);
        $productId  = (int) ($siblings[Tino::O_PRODUCT_ID] ?? 0);

        $this->template->assign('valx', $opt);
        $this->template->assign('defval', $defVal);

        // Fetch fails soft: an API/credential problem renders an empty select
        // (with the error surfaced) instead of breaking the whole config page.
        $rows = [];
        try {
            switch ($opt) {
                case Tino::O_CATEGORY_ID:
                    $rows = $this->module->getCategoryOptions($force);
                    break;

                case Tino::O_PRODUCT_ID:
                    $rows = $this->module->getProductOptions($categoryId, $force);
                    break;

                case Tino::O_CYCLE:
                    $rows = $this->module->getCycleOptions($productId);
                    break;
            }
        } catch (\Exception $ex) {
            Engine::addError('Tino: ' . $ex->getMessage());
        }

        $this->template->assign('modvalues', $this->normalize($rows, $defVal));
    }

    /**
     * Normalize select rows into the template contract, marking the saved value
     * as selected. Preserves saved-but-missing values (prefixed with '*').
     *
     * @param array $rows   [['id','label'], ...]
     * @param mixed $defVal Currently saved value
     * @return array [id => ['id','label','selected']]
     */
    protected function normalize(array $rows, $defVal)
    {
        $list = [];
        foreach ($rows as $row) {
            if (!isset($row['id'])) {
                continue;
            }
            $id        = (string) $row['id'];
            $list[$id] = [
                'id'       => $row['id'],
                'label'    => isset($row['label']) ? $row['label'] : $row['id'],
                'selected' => ((string) $defVal === $id),
            ];
        }

        // Keep a saved value that no longer exists in the catalog.
        if ($defVal !== '' && $defVal !== null && !isset($list[(string) $defVal])) {
            $list[(string) $defVal] = [
                'id'       => $defVal,
                'label'    => '*' . $defVal,
                'selected' => true,
            ];
        }

        return $list;
    }

    /**
     * Resolve the currently saved value for a single option.
     *
     * @param array  $params
     * @param string $opt
     * @return string
     */
    protected function currentOptionValue($params, $opt)
    {
        if (isset($params['options'][$opt])) {
            $val = $params['options'][$opt];
            return is_array($val) ? (string) reset($val) : (string) $val;
        }
        if (isset($params[$opt])) {
            $val = $params[$opt];
            return is_array($val) ? (string) reset($val) : (string) $val;
        }
        return '';
    }

    /**
     * Collect saved option values for the full-page render, keyed by option key.
     *
     * @param array $params
     * @return array
     */
    protected function savedOptionValues($params)
    {
        $opts = $this->module->getOptions();
        $out  = [];
        if (isset($opts['simple']) && is_array($opts['simple'])) {
            foreach ($opts['simple'] as $key => $op) {
                $out[$key] = isset($op['value']) ? $op['value'] : (isset($op['default']) ? $op['default'] : '');
            }
        }
        return $out;
    }
}
