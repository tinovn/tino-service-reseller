<?php

namespace Hosting\Tino\lib;

/**
 * Tino REST API client (https://api.tino.vn).
 *
 * Auth flow:
 *   1. POST /login {username,password}         -> access token (JWT, ~7d) + refresh token
 *   2. POST /token {refresh_token}             -> new access token
 *   3. Authenticated calls send `Authorization: Bearer <access token>`
 *
 * Tokens are cached (per HostBill server) through TokenCache so we avoid
 * logging in on every request. When a cached access token is expired we try
 * the refresh token first and fall back to a full login.
 */
class TinoAPI
{
    /** @var string Base URL, e.g. https://api.tino.vn */
    protected $baseUrl;

    /** @var string Account email / username */
    protected $username;

    /** @var string Account password */
    protected $password;

    /** @var TokenCache|null Optional token persistence */
    protected $tokenCache;

    /** @var string|null In-memory access token for this request */
    protected $accessToken = null;

    /** @var int Connection timeout (seconds) */
    protected $connectTimeout = 15;

    /** @var int Request timeout (seconds) */
    protected $timeout = 45;

    /** @var array Last decoded response ['http_code'=>int,'body'=>mixed] */
    protected $lastResponse = [];

    /**
     * @param string          $baseUrl    e.g. https://api.tino.vn
     * @param string          $username   Account email
     * @param string          $password   Account password
     * @param TokenCache|null $tokenCache Optional persistence for tokens
     */
    public function __construct($baseUrl, $username, $password, ?TokenCache $tokenCache = null)
    {
        if (empty($baseUrl)) {
            throw new \InvalidArgumentException('Tino API base URL is required');
        }

        $this->baseUrl    = rtrim($baseUrl, '/');
        $this->username   = (string) $username;
        $this->password   = (string) $password;
        $this->tokenCache = $tokenCache;
    }

    /**
     * @return array Last response ['http_code'=>int,'body'=>mixed]
     */
    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    /**
     * Log in with username/password and cache the resulting tokens.
     *
     * @param bool $remember Request a long-lived (360 day) session
     * @return array Raw login response (client info + token + refresh)
     * @throws \RuntimeException on failure
     */
    public function login($remember = true)
    {
        if (empty($this->username) || empty($this->password)) {
            throw new \RuntimeException('Tino API username and password are required');
        }

        $res = $this->rawRequest('POST', '/login', [
            'username' => $this->username,
            'password' => $this->password,
            'remember' => (bool) $remember,
        ], false);

        if (empty($res['token'])) {
            throw new \RuntimeException('Login succeeded but no token was returned');
        }

        $this->accessToken = $res['token'];
        if ($this->tokenCache) {
            $this->tokenCache->store($res['token'], isset($res['refresh']) ? $res['refresh'] : '');
        }

        return $res;
    }

    /**
     * Exchange a refresh token for a new access token.
     *
     * @param string $refreshToken
     * @return string New access token
     * @throws \RuntimeException when refresh fails
     */
    public function refresh($refreshToken)
    {
        $res = $this->rawRequest('POST', '/token', [
            'refresh_token' => $refreshToken,
        ], false);

        if (empty($res['token'])) {
            throw new \RuntimeException('Refresh did not return a new token');
        }

        $this->accessToken = $res['token'];
        if ($this->tokenCache) {
            $this->tokenCache->store(
                $res['token'],
                isset($res['refresh']) ? $res['refresh'] : $refreshToken
            );
        }

        return $res['token'];
    }

    /**
     * Ensure a usable access token is available, using cache → refresh → login.
     *
     * @return string
     * @throws \RuntimeException when no token can be obtained
     */
    public function getAccessToken()
    {
        if (!empty($this->accessToken)) {
            return $this->accessToken;
        }

        if ($this->tokenCache) {
            $cached = $this->tokenCache->getValidAccessToken();
            if (!empty($cached)) {
                return $this->accessToken = $cached;
            }

            $refresh = $this->tokenCache->getRefreshToken();
            if (!empty($refresh)) {
                try {
                    return $this->refresh($refresh);
                } catch (\RuntimeException $ex) {
                    // Refresh token expired/invalid — fall through to full login.
                    $this->tokenCache->clear();
                }
            }
        }

        $this->login();
        return $this->accessToken;
    }

    // -------------------------------------------------------------------------
    // Core HTTP
    // -------------------------------------------------------------------------

    /**
     * Perform an authenticated request. Retries once (after refresh/login) on 401.
     *
     * @param string $method
     * @param string $endpoint
     * @param array  $data
     * @return array Decoded response body
     * @throws \RuntimeException
     */
    public function request($method, $endpoint, $data = [])
    {
        $token = $this->getAccessToken();

        try {
            return $this->rawRequest($method, $endpoint, $data, true, $token);
        } catch (UnauthorizedException $ex) {
            // Token rejected — invalidate and retry once with a fresh one.
            $this->accessToken = null;
            if ($this->tokenCache) {
                $this->tokenCache->clear();
            }
            $token = $this->getAccessToken();
            return $this->rawRequest($method, $endpoint, $data, true, $token);
        }
    }

    /**
     * Low-level cURL call.
     *
     * @param string      $method
     * @param string      $endpoint
     * @param array       $data
     * @param bool        $auth   Send Authorization header
     * @param string|null $token  Bearer token when $auth is true
     * @return array Decoded body
     * @throws \RuntimeException|UnauthorizedException
     */
    protected function rawRequest($method, $endpoint, $data, $auth, $token = null)
    {
        $url    = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $method = strtoupper($method);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($auth && $token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            // Enforce TLS verification explicitly (Tino API is public HTTPS) so a
            // future edit cannot silently disable it.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Only allow HTTP(S); block file://, gopher://, etc. on redirects.
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $body   = curl_exec($ch);
        $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno  = curl_errno($ch);
        $errmsg = curl_error($ch);
        // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5; guard it.
        if (\PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($errno) {
            throw new \RuntimeException("Tino API connection error ({$errno}): {$errmsg}");
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "Invalid JSON from Tino API (HTTP {$code}): " . substr((string) $body, 0, 300)
            );
        }

        $this->lastResponse = ['http_code' => $code, 'body' => $decoded];

        // The Tino API returns errors as {"error":[...]} even with HTTP 200.
        $apiError = $this->extractError($decoded);

        if ($code === 401 || $apiError === 'unauthorized') {
            throw new UnauthorizedException($apiError ?: 'unauthorized');
        }

        if ($code >= 400 || $apiError !== null) {
            $msg = $apiError !== null ? $apiError : ('HTTP ' . $code);
            throw new \RuntimeException("Tino API error: {$msg}");
        }

        return $decoded;
    }

    /**
     * Extract an error string from a Tino response, or null when there is none.
     *
     * Handles the shapes the API is known/likely to use:
     *   {"error": ["code", ...]}          (observed)
     *   {"error": "message"}
     *   {"error": {"code": "...", ...}}
     *   {"errors": [...] | {...}}
     *   {"message": "..."}                (only when no data payload present)
     *
     * @param mixed $decoded
     * @return string|null
     */
    protected function extractError($decoded)
    {
        if (!is_array($decoded)) {
            return null;
        }

        foreach (['error', 'errors'] as $key) {
            if (isset($decoded[$key]) && !empty($decoded[$key])) {
                return $this->stringifyError($decoded[$key]);
            }
        }

        // A bare {"message": "..."} with no data is treated as an error.
        if (isset($decoded['message']) && !isset($decoded['data']) && count($decoded) <= 2) {
            return (string) $decoded['message'];
        }

        return null;
    }

    /**
     * Flatten an error value (string | list | assoc) into a readable string.
     *
     * @param mixed $err
     * @return string
     */
    protected function stringifyError($err)
    {
        if (is_string($err)) {
            return $err;
        }
        if (is_array($err)) {
            $parts = [];
            foreach ($err as $item) {
                $parts[] = is_scalar($item) ? (string) $item : json_encode($item);
            }
            return implode(', ', $parts);
        }
        return json_encode($err);
    }

    // -------------------------------------------------------------------------
    // Domain-specific endpoints
    // -------------------------------------------------------------------------

    /** @return array List of product categories (GET /category) */
    public function getCategories()
    {
        return $this->request('GET', '/category');
    }

    /**
     * @param int $categoryId
     * @return array Products in the category (GET /category/{id}/product)
     */
    public function getCategoryProducts($categoryId)
    {
        return $this->request('GET', '/category/' . (int) $categoryId . '/product');
    }

    /**
     * Product configuration details (GET /order/{id}). This endpoint is public,
     * but we still send auth for a consistent client.
     *
     * @param int $productId
     * @return array
     */
    public function getProductConfig($productId)
    {
        return $this->request('GET', '/order/' . (int) $productId);
    }

    /**
     * Place an order (POST /order/{id}). pay_method is intentionally omitted so
     * HostBill/Tino settles the order from account credit.
     *
     * @param int   $productId
     * @param array $params ['domain','cycle','custom','promocode','aff_id']
     * @return array
     */
    public function order($productId, array $params)
    {
        $body = array_filter([
            'domain'    => isset($params['domain']) ? $params['domain'] : null,
            'cycle'     => isset($params['cycle']) ? $params['cycle'] : null,
            'custom'    => isset($params['custom']) ? $params['custom'] : null,
            'promocode' => isset($params['promocode']) ? $params['promocode'] : null,
            'aff_id'    => isset($params['aff_id']) ? $params['aff_id'] : null,
        ], function ($v) {
            return $v !== null && $v !== '';
        });

        return $this->request('POST', '/order/' . (int) $productId, $body);
    }

    /**
     * @param int $serviceId
     * @return array Service details (GET /service/{id})
     */
    public function getService($serviceId)
    {
        return $this->request('GET', '/service/' . (int) $serviceId);
    }

    /**
     * @return array List of the account's services (GET /service)
     */
    public function listServices()
    {
        return $this->request('GET', '/service');
    }

    /**
     * Cancel (terminate) a service (POST /service/{id}/cancel).
     *
     * @param int    $serviceId
     * @param bool   $immediate true = terminate now, false = at end of term
     * @param string $reason
     * @return array
     */
    public function cancelService($serviceId, $immediate = true, $reason = 'Terminated by HostBill')
    {
        return $this->request('POST', '/service/' . (int) $serviceId . '/cancel', [
            'immediate' => $immediate ? 'true' : 'false',
            'reason'    => $reason,
        ]);
    }

    /**
     * Request an upgrade/downgrade (POST /service/{id}/upgrade).
     *
     * @param int         $serviceId
     * @param int|null    $packageId New Tino product id (optional)
     * @param string|null $cycle     New billing cycle (optional)
     * @param array       $resources Resource values (optional)
     * @return array
     */
    public function upgradeService($serviceId, $packageId = null, $cycle = null, array $resources = [])
    {
        $body = ['send' => true];
        if ($packageId !== null) {
            $body['package'] = (int) $packageId;
        }
        if ($cycle !== null && $cycle !== '') {
            $body['cycle'] = $cycle;
        }
        if (!empty($resources)) {
            $body['resources'] = $resources;
        }

        return $this->request('POST', '/service/' . (int) $serviceId . '/upgrade', $body);
    }

    /**
     * Suspend a service. NOTE: this reseller endpoint is provided by Tino
     * separately (POST /service/{id}/suspend) — see module README.
     *
     * @param int    $serviceId
     * @param string $reason
     * @return array
     */
    public function suspendService($serviceId, $reason = 'Suspended by HostBill')
    {
        return $this->request('POST', '/service/' . (int) $serviceId . '/suspend', [
            'reason' => $reason,
        ]);
    }

    /**
     * Unsuspend a service (POST /service/{id}/unsuspend).
     *
     * @param int $serviceId
     * @return array
     */
    public function unsuspendService($serviceId)
    {
        return $this->request('POST', '/service/' . (int) $serviceId . '/unsuspend');
    }
}
