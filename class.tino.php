<?php

/**
 * Tino Reseller Module for HostBill
 *
 * Lets a HostBill instance resell Tino services (https://api.tino.vn) by
 * placing orders through the Tino REST API. The server connection stores the
 * reseller's Tino account (username/password); the module logs in, caches the
 * JWT access/refresh tokens, and provisions services via the order API.
 *
 * @see https://docs.tino.vn
 */

require_once __DIR__ . '/lib/include.php';

use Hosting\Tino\lib\TinoAPI;
use Hosting\Tino\lib\TokenCache;
use Hosting\Tino\lib\Constants;

class Tino
    extends HostingModule
    implements Constants
{
    protected $modname     = 'Tino';
    protected $description = 'Resell Tino services (hosting, VPS, domains) via the Tino REST API.';
    protected $version     = '1.0.0';

    /** Tino API base URLs per environment. */
    const API_URL_LIVE = 'https://api.tino.vn';
    const API_URL_OTE  = 'https://ote.tino.vn/api/';

    /** Environment labels shown in the server config (order matters: default first). */
    const ENV_LIVE = 'Live';
    const ENV_OTE  = 'OTE';

    /** @var TinoAPI|null */
    protected $api = null;

    // -------------------------------------------------------------------------
    // Server connection fields
    //   username / password  -> Tino account credentials (standard HB fields)
    //   custom "Environment"  -> Live (api.tino.vn) or OTE (ote.tino.vn/api)
    // -------------------------------------------------------------------------

    protected $serverFields = [
        self::CONNECTION_FIELD_CHECKBOX    => false,
        self::CONNECTION_FIELD_MAXACCOUNTS => false,
        self::CONNECTION_FIELD_STATUSURL   => false,
        self::CONNECTION_FIELD_INPUT1      => false,
        self::CONNECTION_FIELD_INPUT2      => false,
        self::CONNECTION_FIELD_CUSTOM      => [
            'Environment' => [
                'type'        => 'select',
                'default'     => [self::ENV_LIVE, self::ENV_OTE],
                'description' => 'Live = ' . self::API_URL_LIVE . ' · OTE = ' . self::API_URL_OTE,
            ],
        ],
    ];

    // -------------------------------------------------------------------------
    // Product configuration options (HostBill product setup)
    //   Category / Product are dynamic selects populated from the Tino API and
    //   cached; see admin/class.tino_controller.php.
    // -------------------------------------------------------------------------

    protected $options = [
        self::O_CATEGORY_ID => [
            'type'     => 'select',
            'default'  => [''],
            'value'    => [''],
            'loadable' => 'getCategoryOptions',
            'variable' => 'tino_category',
        ],
        self::O_PRODUCT_ID => [
            'type'     => 'select',
            'default'  => [''],
            'value'    => [''],
            'loadable' => 'getProductOptions',
            'variable' => 'tino_product',
        ],
        self::O_CYCLE => [
            'type'     => 'select',
            'default'  => [''],
            'value'    => [''],
            'loadable' => 'getCycleOptions',
            'variable' => 'tino_cycle',
        ],
        self::O_PROMOCODE => [
            'type'    => 'input',
            'default' => '',
        ],
        self::O_AFF_ID => [
            'type'    => 'input',
            'default' => '',
        ],
    ];

    // -------------------------------------------------------------------------
    // Per-account details (stored by HostBill against each service)
    // -------------------------------------------------------------------------

    protected $details = [
        self::D_SERVICE_ID => [
            'name'    => 'Service ID',
            'value'   => false,
            'type'    => 'input',
            'default' => false,
        ],
        self::D_DOMAIN => [
            'name'    => 'Domain',
            'value'   => false,
            'type'    => 'input',
            'default' => false,
        ],
        self::D_ORDER_ID => [
            'name'    => 'Order ID',
            'value'   => false,
            'type'    => 'input',
            'default' => false,
        ],
    ];

    // -------------------------------------------------------------------------
    // Connection / API helpers
    // -------------------------------------------------------------------------

    /**
     * Called by HostBill when the server connection is established.
     *
     * @param array $connect Server connection data from HostBill
     */
    public function connect($connect)
    {
        $env = isset($connect['custom']['Environment']) ? $connect['custom']['Environment'] : self::ENV_LIVE;

        $server = [];
        $server['id']       = isset($connect['id']) ? (int) $connect['id'] : 0;
        $server['env']      = $env;
        $server['api_url']  = $this->apiUrlForEnv($env);
        $server['username'] = isset($connect['username']) ? $connect['username'] : '';
        $server['password'] = isset($connect['password']) ? $connect['password'] : '';

        $this->connection = $server;
        $this->api = null; // rebuilt lazily by getApi()
    }

    /**
     * Map an environment label to its API base URL (defaults to Live).
     *
     * @param string $env
     * @return string
     */
    protected function apiUrlForEnv($env)
    {
        return ($env === self::ENV_OTE) ? self::API_URL_OTE : self::API_URL_LIVE;
    }

    /**
     * Get or build the API client (with token cache scoped to this server).
     *
     * @return TinoAPI
     * @throws \RuntimeException when credentials are missing
     */
    protected function getApi()
    {
        if ($this->api !== null) {
            return $this->api;
        }

        if (empty($this->connection['username']) || empty($this->connection['password'])) {
            throw new \RuntimeException('Tino API credentials are not configured on the server');
        }

        $cache = new TokenCache(isset($this->connection['id']) ? $this->connection['id'] : 0);

        $this->api = new TinoAPI(
            !empty($this->connection['api_url']) ? $this->connection['api_url'] : self::API_URL_LIVE,
            $this->connection['username'],
            $this->connection['password'],
            $cache
        );

        return $this->api;
    }

    /**
     * Verify the reseller credentials by logging in.
     *
     * @param callable|null $log
     * @return bool
     */
    public function testConnection($log = null)
    {
        if ($log) {
            $log(\Monolog\Logger::INFO, 'Connecting to Tino API: ' . ($this->connection['api_url'] ?? self::API_URL_LIVE));
        }

        try {
            $res = $this->getApi()->login(true);
            if ($log && !empty($res['client']['email'])) {
                $log(\Monolog\Logger::INFO, 'Authenticated as ' . $res['client']['email']);
            }
            return true;
        } catch (\Exception $ex) {
            $this->addError('Connection test failed: ' . $ex->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Dynamic config data (fetched from Tino API, cached; used by the admin
    // controller to populate Category / Product / Cycle selects).
    // Products change rarely, so results are cached via HBCache until the admin
    // clicks "Reload cache" (see admin/class.tino_controller.php).
    // -------------------------------------------------------------------------

    /** Cache time-to-live for catalog data (seconds). */
    const CACHE_TTL = 86400;

    /**
     * Build the HBCache key for a piece of catalog data, scoped to the server
     * so different Tino accounts don't share cached catalogs.
     *
     * @param string $suffix
     * @return string
     */
    protected function cacheKey($suffix)
    {
        $serverId = isset($this->connection['id']) ? (int) $this->connection['id'] : 0;
        return 'tino.catalog.' . $serverId . '.' . $suffix;
    }

    /**
     * Read a cached value, or compute+store it via $producer on a miss.
     * Uses HostBill's static cache (HBCache::get/set), matching core modules.
     *
     * @param string   $suffix
     * @param callable $producer Returns the value to cache
     * @param bool     $force    Bypass cache (force refresh)
     * @return mixed
     */
    protected function cached($suffix, callable $producer, $force = false)
    {
        $key = $this->cacheKey($suffix);

        if (!$force && class_exists('\\HBCache')) {
            try {
                $hit = \HBCache::get($key);
                if ($hit !== null) {
                    return $hit;
                }
            } catch (\Exception $ex) {
                // Cache unavailable — fall through and fetch live.
            }
        }

        // Remember the key so Reload cache can clear it precisely.
        $this->rememberCacheKey($suffix);

        $value = $producer();

        if (class_exists('\\HBCache')) {
            try {
                \HBCache::set($key, $value, self::CACHE_TTL);
            } catch (\Exception $ex) {
                // Ignore cache write failures.
            }
        }

        return $value;
    }

    /**
     * Track a cache-key suffix under an index so Reload can clear every entry
     * (product lists are keyed per category, so the set is not fixed).
     *
     * @param string $suffix
     */
    protected function rememberCacheKey($suffix)
    {
        if (!class_exists('\\HBCache')) {
            return;
        }
        try {
            $indexKey = $this->cacheKey('_index');
            $index    = \HBCache::get($indexKey);
            $index    = is_array($index) ? $index : [];
            if (!in_array($suffix, $index, true)) {
                $index[] = $suffix;
                \HBCache::set($indexKey, $index, self::CACHE_TTL);
            }
        } catch (\Exception $ex) {
            // Ignore.
        }
    }

    /**
     * Drop all cached catalog data for this server (used by Reload cache).
     */
    public function clearCatalogCache()
    {
        if (!class_exists('\\HBCache')) {
            return;
        }
        try {
            $indexKey = $this->cacheKey('_index');
            $index    = \HBCache::get($indexKey);
            if (is_array($index)) {
                foreach ($index as $suffix) {
                    \HBCache::delete($this->cacheKey($suffix));
                }
            }
            \HBCache::delete($indexKey);
        } catch (\Exception $ex) {
            // Ignore cache errors.
        }
    }

    /**
     * Categories as a flat select list [['id','label'], ...], including nested
     * subcategories (indented). Cached.
     *
     * @param bool $force Force refresh from API
     * @return array
     */
    public function getCategoryOptions($force = false)
    {
        return $this->cached('categories', function () {
            $res  = $this->getApi()->getCategories();
            $list = isset($res['categories']) ? $res['categories'] : (is_array($res) ? $res : []);
            return $this->flattenCategories($list);
        }, $force);
    }

    /**
     * Flatten a (possibly nested) category tree into select rows.
     *
     * @param array  $cats
     * @param string $prefix Indentation prefix for subcategories
     * @return array
     */
    protected function flattenCategories(array $cats, $prefix = '')
    {
        $out = [];
        foreach ($cats as $cat) {
            if (empty($cat['id'])) {
                continue;
            }
            $out[] = [
                'id'    => (int) $cat['id'],
                'label' => $prefix . (isset($cat['name']) ? $cat['name'] : ('#' . $cat['id'])),
            ];
            if (!empty($cat['subcategories']) && is_array($cat['subcategories'])) {
                $out = array_merge($out, $this->flattenCategories($cat['subcategories'], $prefix . '— '));
            }
        }
        return $out;
    }

    /**
     * Products in a category as select rows [['id','label'], ...]. Cached per
     * category id.
     *
     * @param int  $categoryId
     * @param bool $force
     * @return array
     */
    public function getProductOptions($categoryId, $force = false)
    {
        $categoryId = (int) $categoryId;
        if ($categoryId <= 0) {
            return [];
        }

        return $this->cached('products.' . $categoryId, function () use ($categoryId) {
            $res  = $this->getApi()->getCategoryProducts($categoryId);
            $list = isset($res['products']) ? $res['products'] : (is_array($res) ? $res : []);
            $out  = [];
            foreach ($list as $p) {
                if (empty($p['id'])) {
                    continue;
                }
                $label = isset($p['name']) ? $p['name'] : ('#' . $p['id']);
                if (!empty($p['out_of_stock'])) {
                    $label .= ' (out of stock)';
                }
                $out[] = ['id' => (int) $p['id'], 'label' => $label];
            }
            return $out;
        }, $force);
    }

    /**
     * Billing cycles for a product as select rows [['id','label'], ...].
     * Sourced from GET /order/{id}; not cached long-term (per-product, cheap).
     *
     * @param int $productId
     * @return array
     */
    public function getCycleOptions($productId)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return [];
        }

        try {
            $cfg    = $this->getApi()->getProductConfig($productId);
            $fields = isset($cfg['product']['config']['product'])
                ? $cfg['product']['config']['product']
                : [];
            foreach ($fields as $field) {
                if (($field['id'] ?? '') === 'cycle' && !empty($field['items'])) {
                    $out = [];
                    foreach ($field['items'] as $item) {
                        if (!isset($item['value'])) {
                            continue;
                        }
                        $title = isset($item['formatted']) && $item['formatted']
                            ? trim($item['formatted'])
                            : (isset($item['title']) ? $item['title'] : $item['value']);
                        $price = isset($item['price']) ? number_format((float) $item['price'], 0, '.', ',') : '';
                        $out[] = [
                            'id'    => $item['value'],
                            'label' => $title . ($price !== '' ? ' - ' . $price : ''),
                        ];
                    }
                    return $out;
                }
            }
        } catch (\Exception $ex) {
            $this->addError('Failed to load billing cycles: ' . $ex->getMessage());
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // Helpers to read config/details
    // -------------------------------------------------------------------------

    /**
     * Read a saved product option value.
     *
     * @param string $key
     * @return mixed
     */
    protected function getOption($key)
    {
        if (isset($this->options[$key]['value'])) {
            $val = $this->options[$key]['value'];
            return is_array($val) ? reset($val) : $val;
        }
        if (isset($this->options[$key]['default'])) {
            $def = $this->options[$key]['default'];
            return is_array($def) ? reset($def) : $def;
        }
        return '';
    }

    /**
     * Read a per-account detail value.
     *
     * @param string $key
     * @return mixed
     */
    protected function getDetail($key)
    {
        if (!empty($this->details[$key]['value'])) {
            return $this->details[$key]['value'];
        }
        // Fallback to raw account_details (option1..optionN)
        if (isset($this->account_details[$key])) {
            return $this->account_details[$key];
        }
        return '';
    }

    /**
     * Get the Tino service id for the current account.
     *
     * @return int
     */
    protected function getServiceId()
    {
        return (int) $this->getDetail(self::D_SERVICE_ID);
    }

    // -------------------------------------------------------------------------
    // Provisioning lifecycle
    // -------------------------------------------------------------------------

    /**
     * Create (order) a new Tino service.
     *
     * pay_method is intentionally not sent so HostBill/Tino settle from credit.
     *
     * @return bool
     */
    public function Create()
    {
        $status = isset($this->account_details['status']) ? $this->account_details['status'] : '';
        if (in_array($status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true) && $this->getServiceId()) {
            $this->addError('Service already provisioned; terminate it before re-provisioning');
            return false;
        }

        $productId = (int) $this->getOption(self::O_PRODUCT_ID);
        if ($productId <= 0) {
            $this->addError('No Tino product selected in the product configuration');
            return false;
        }

        $cycle = $this->getOption(self::O_CYCLE);
        if (empty($cycle)) {
            $this->addError('No billing cycle selected in the product configuration');
            return false;
        }

        try {
            $api    = $this->getApi();
            $domain = $this->getDetail(self::D_DOMAIN);
            if (empty($domain) && !empty($this->account_details['domain'])) {
                $domain = $this->account_details['domain'];
            }

            // Idempotency guard: if this service already has an order/service on
            // Tino (e.g. a previous Create timed out after the order went
            // through), reuse it instead of ordering again.
            if ($this->reconcileExistingOrder($domain)) {
                return true;
            }

            $params = [
                'cycle'     => $cycle,
                'promocode' => $this->getOption(self::O_PROMOCODE),
                'aff_id'    => $this->getOption(self::O_AFF_ID),
            ];
            if (!empty($domain)) {
                $params['domain'] = $domain;
            }

            $res = $api->order($productId, $params);

            // The order API is asynchronous: it returns an order id immediately;
            // the service is provisioned shortly after. Persist whatever ids we
            // get and resolve the service id (now or on a later lifecycle call).
            $orderId = $this->extractOrderId($res);
            if ($orderId) {
                $this->details[self::D_ORDER_ID]['value'] = $orderId;
            }
            if (!empty($domain)) {
                $this->details[self::D_DOMAIN]['value'] = $domain;
            }

            $serviceId = $this->extractServiceId($res);
            if (!$serviceId && !empty($domain)) {
                // Not in the response yet (async) — try to look it up by domain.
                $serviceId = $this->resolveServiceId($domain);
            }
            if ($serviceId) {
                $this->details[self::D_SERVICE_ID]['value'] = $serviceId;
            }

            // Fail closed if we captured NOTHING to identify the order later,
            // otherwise the service can never be suspended/terminated and a
            // retry would place a duplicate order.
            if (!$serviceId && !$orderId) {
                $this->addError(
                    'Order placed but Tino returned no order/service id. '
                    . 'Response: ' . json_encode($res)
                );
                return false;
            }

            return true;
        } catch (\Exception $ex) {
            $this->addError('Failed to create Tino service: ' . $ex->getMessage());
            return false;
        }
    }

    /**
     * If this account already has an order/service id stored, or a matching
     * service exists on Tino for its domain, adopt it (no re-order).
     *
     * @param string $domain
     * @return bool True when an existing order/service was found and adopted.
     */
    protected function reconcileExistingOrder($domain)
    {
        // Already have an id persisted — nothing to order.
        if ($this->getServiceId() || (int) $this->getDetail(self::D_ORDER_ID)) {
            return true;
        }

        if (empty($domain)) {
            return false;
        }

        try {
            $serviceId = $this->findServiceIdByDomain($domain);
        } catch (\Exception $ex) {
            return false; // Look-up failed; proceed to order normally.
        }

        if ($serviceId) {
            $this->details[self::D_SERVICE_ID]['value'] = $serviceId;
            $this->details[self::D_DOMAIN]['value']     = $domain;
            return true;
        }

        return false;
    }

    /**
     * Resolve a service id by domain (the order API is async, so the service
     * may not be listed immediately).
     *
     * @param string $domain
     * @return int
     */
    protected function resolveServiceId($domain)
    {
        try {
            return $this->findServiceIdByDomain($domain);
        } catch (\Exception $ex) {
            return 0; // service may still be provisioning
        }
    }

    /**
     * Find a Tino service id by its domain via GET /service.
     *
     * @param string $domain
     * @return int 0 when not found
     */
    protected function findServiceIdByDomain($domain)
    {
        $res      = $this->getApi()->listServices();
        $services = isset($res['services']) ? $res['services'] : (is_array($res) ? $res : []);

        foreach ($services as $svc) {
            if (!empty($svc['domain']) && strcasecmp($svc['domain'], $domain) === 0 && !empty($svc['id'])) {
                return (int) $svc['id'];
            }
        }
        return 0;
    }

    /**
     * Suspend the service.
     *
     * NOTE: the reseller suspend endpoint (POST /service/{id}/suspend) is being
     * added by Tino separately. Until it exists the API call will fail and the
     * error is surfaced to the admin.
     *
     * @return bool
     */
    public function Suspend()
    {
        $serviceId = $this->getServiceId();
        if (!$serviceId) {
            $this->addError('Cannot suspend: Tino service id not found');
            return false;
        }

        try {
            $this->getApi()->suspendService($serviceId, 'Suspended by HostBill');
            return true;
        } catch (\Exception $ex) {
            $this->addError('Failed to suspend Tino service: ' . $ex->getMessage());
            return false;
        }
    }

    /**
     * Unsuspend the service (POST /service/{id}/unsuspend).
     *
     * @return bool
     */
    public function Unsuspend()
    {
        $serviceId = $this->getServiceId();
        if (!$serviceId) {
            $this->addError('Cannot unsuspend: Tino service id not found');
            return false;
        }

        try {
            $this->getApi()->unsuspendService($serviceId);
            return true;
        } catch (\Exception $ex) {
            $this->addError('Failed to unsuspend Tino service: ' . $ex->getMessage());
            return false;
        }
    }

    /**
     * Terminate the service (POST /service/{id}/cancel, immediate).
     *
     * @return bool
     */
    public function Terminate()
    {
        $serviceId = $this->getServiceId();
        if (!$serviceId) {
            $this->addError('Cannot terminate: Tino service id not found');
            return false;
        }

        try {
            $this->getApi()->cancelService($serviceId, true, 'Terminated by HostBill');
            return true;
        } catch (\Exception $ex) {
            $this->addError('Failed to terminate Tino service: ' . $ex->getMessage());
            return false;
        }
    }

    /**
     * Change package (POST /service/{id}/upgrade).
     *
     * @return bool
     */
    public function ChangePackage()
    {
        $serviceId = $this->getServiceId();
        if (!$serviceId) {
            $this->addError('Cannot change package: Tino service id not found');
            return false;
        }

        $newProductId = (int) $this->getOption(self::O_PRODUCT_ID);
        $newCycle     = $this->getOption(self::O_CYCLE);

        try {
            $this->getApi()->upgradeService(
                $serviceId,
                $newProductId > 0 ? $newProductId : null,
                !empty($newCycle) ? $newCycle : null
            );
            return true;
        } catch (\Exception $ex) {
            $this->addError('Failed to change Tino package: ' . $ex->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Response parsing helpers.
    //
    // Verified shape of POST /order/{id} on success:
    //   {
    //     "order_num": 1132559230,
    //     "invoice_id": "1199323",
    //     "total": 0,
    //     "items": [ { "type": "Hosting", "id": "356472",
    //                  "name": "<domain>", "product_id": "1" } ]
    //   }
    // -> service id is items[].id ; order id is order_num / invoice_id.
    // -------------------------------------------------------------------------

    /**
     * Extract the service id from an order response (items[].id).
     * Falls back to a few legacy shapes for safety.
     *
     * @param mixed $res
     * @return int 0 when not present
     */
    protected function extractServiceId($res)
    {
        if (!is_array($res)) {
            return 0;
        }

        if (!empty($res['items']) && is_array($res['items'])) {
            foreach ($res['items'] as $item) {
                if (!empty($item['id']) && is_numeric($item['id'])) {
                    return (int) $item['id'];
                }
            }
        }

        // Fallbacks (other product types / future shapes).
        foreach (['service_id', 'serviceid'] as $k) {
            if (!empty($res[$k]) && is_numeric($res[$k])) {
                return (int) $res[$k];
            }
        }
        if (!empty($res['service']['id']) && is_numeric($res['service']['id'])) {
            return (int) $res['service']['id'];
        }

        return 0;
    }

    /**
     * Extract the order id from an order response (order_num, then invoice_id).
     *
     * @param mixed $res
     * @return int 0 when not present
     */
    protected function extractOrderId($res)
    {
        if (!is_array($res)) {
            return 0;
        }
        foreach (['order_num', 'invoice_id', 'order_id', 'orderid'] as $k) {
            if (!empty($res[$k]) && is_numeric($res[$k])) {
                return (int) $res[$k];
            }
        }
        return 0;
    }
}
