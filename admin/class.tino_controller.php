<?php

/**
 * Admin controller for the Tino module.
 *
 * HostBill routes admin AJAX to `?cmd=tino&action=<publicMethod>`.
 *
 *   - productdetails            renders the product-config page (customconfig)
 *   - updatecache               (AJAX) refresh the catalog and return it as JSON
 */
class Tino_controller extends HBController
{
    /** @var Tino */
    var $module;

    /**
     * Render the product-config page. HostBill includes the template assigned
     * to `customconfig` into the surrounding config form.
     *
     * @param array $params
     */
    public function productdetails($params)
    {
        $tplDir = APPDIR_MODULES . 'Hosting' . DS . 'tino' . DS . 'templates' . DS;

        $default = $this->savedOptionValues($params);

        // Populate the selects from cache (no API call) so saved ids map to
        // their labels on page load. Requires connecting to the server for the
        // correct cache scope.
        $categories = [];
        $products   = [];
        $forms      = [];
        if (!empty($params['server_id'])) {
            try {
                $servers = HBLoader::LoadModel('Servers');
                $this->module->connect($servers->getServerDetails($params['server_id']));
                $categories = $this->module->getCachedCategoryOptions();
                $catId      = (int) (isset($default['Category']) ? $default['Category'] : 0);
                if ($catId > 0) {
                    $products = $this->module->getCachedProductOptions($catId);
                }
                // Render the product's form fields from cache so saved state
                // (defaults + config fields) shows without clicking Load.
                $productId = (int) (isset($default['Product']) ? $default['Product'] : 0);
                if ($productId > 0) {
                    $forms = $this->module->getCachedProductForms($productId);
                }
            } catch (\Exception $ex) {
                // ignore — selects fall back to the raw saved id + Load button
            }
        }

        $this->template->assign('customconfig', $tplDir . 'myproductconfig.tpl');
        $this->template->assign('default', $default);
        $this->template->assign('tino_categories', $categories);
        $this->template->assign('tino_products', $products);
        $this->template->assign('tino_forms', $forms);
        $this->template->assign('server_id', isset($params['server_id']) ? $params['server_id'] : '');
    }

    /**
     * AJAX: load one list from the Tino API (force refresh) and return it as
     * JSON. One button per row:
     *   opt=Category -> all categories
     *   opt=Product  -> products of the selected category (param `category`)
     *
     * The billing cycle is chosen by the end client at order time, so it is not
     * configured here.
     *
     * @param array $params
     */
    public function updatecache($params)
    {
        header('Content-Type: application/json');

        $result = ['ok' => false, 'error' => '', 'items' => [], 'forms' => []];

        if (empty($params['server_id'])) {
            $result['error'] = 'No server selected';
            echo json_encode($result);
            die();
        }

        try {
            $servers = HBLoader::LoadModel('Servers');
            $this->module->connect($servers->getServerDetails($params['server_id']));

            $opt = isset($params['opt']) ? $params['opt'] : '';
            switch ($opt) {
                case 'Category':
                    $result['items'] = $this->module->getCategoryOptions(true);
                    break;

                case 'Product':
                    $categoryId = (int) ($params['category'] ?? 0);
                    if ($categoryId <= 0) {
                        throw new \RuntimeException('Please select a category first');
                    }
                    $result['items'] = $this->module->getProductOptions($categoryId, true);
                    break;

                case 'Forms':
                    $productId = (int) ($params['product'] ?? 0);
                    if ($productId <= 0) {
                        throw new \RuntimeException('Please select a product first');
                    }
                    // Reload button forces a fresh fetch and refreshes the cache.
                    $result['forms'] = $this->module->getProductForms($productId, true);
                    break;

                default:
                    throw new \RuntimeException('Unknown option: ' . $opt);
            }

            $result['ok'] = true;
        } catch (\Exception $ex) {
            $result['error'] = $ex->getMessage();
        }

        echo json_encode($result);
        die();
    }

    /**
     * AJAX: create a HostBill config field (form element) for one product form
     * so the client can choose it during order. Mirrors proxmox2's
     * ConfigFields->addFieldCat() flow.
     *
     * Params: id (product id), server_id, product (Tino product id),
     *         variable (tino_form_<id>).
     *
     * Returns JSON { ok, error, fid }.
     *
     * @param array $params
     */
    public function importformel($params)
    {
        header('Content-Type: application/json');
        $result = ['ok' => false, 'error' => '', 'fid' => 0];

        if (($params['id'] ?? '') === 'new' || empty($params['id'])) {
            $result['error'] = 'Please save your product first';
            echo json_encode($result);
            die();
        }
        if (empty($params['server_id']) || empty($params['product']) || empty($params['variable'])) {
            $result['error'] = 'Missing server, product or field';
            echo json_encode($result);
            die();
        }

        try {
            $servers = HBLoader::LoadModel('Servers');
            $this->module->connect($servers->getServerDetails($params['server_id']));

            // Find the form definition by its variable.
            $forms = $this->module->getProductForms((int) $params['product']);
            $form  = null;
            foreach ($forms as $f) {
                if ($f['variable'] === $params['variable']) {
                    $form = $f;
                    break;
                }
            }
            if (!$form) {
                throw new \RuntimeException('Form field not found: ' . $params['variable']);
            }

            $var = [
                'type'       => (!empty($form['items'])) ? 'select' : 'input',
                'name'       => $form['title'],
                'variable'   => $form['variable'],
                'category'   => 'software',
                'premade'    => '1',
                'product_id' => (int) $params['id'],
                'options'    => $form['required'] ? 0 : 2,
                'items'      => [],
            ];
            foreach ($form['items'] as $it) {
                $var['items'][] = ['variable_id' => $it['id'], 'name' => $it['label']];
            }

            $f   = HBLoader::LoadModel('ConfigFields');
            $fid = $f->addFieldCat($var);

            $result['fid'] = is_array($fid) ? reset($fid) : $fid;
            $result['ok']  = true;
        } catch (\Exception $ex) {
            $result['error'] = $ex->getMessage();
        }

        echo json_encode($result);
        die();
    }

    /**
     * Collect saved option values for the page render, keyed by option key.
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
