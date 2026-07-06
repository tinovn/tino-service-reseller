<?php

namespace Hosting\Tino\lib;

/**
 * Persists Tino JWT access/refresh tokens per HostBill server so we do not
 * re-login on every request.
 *
 * Storage uses HostBill's built-in cache (\HBCache::get/set/delete) — no custom
 * table required. Access token TTL is ~7 days, the refresh token lives longer;
 * we store both plus the access-token expiry timestamp.
 *
 * A small skew is subtracted from the real expiry so we refresh slightly early
 * and never send a token that expires mid-request.
 */
class TokenCache
{
    /** @var int HostBill server id this cache is scoped to */
    protected $serverId;

    /** @var int Seconds subtracted from expiry so we refresh a bit early */
    const EXPIRY_SKEW = 300;

    /** @var int Cache entry TTL (seconds) — longer than a refresh token life */
    const CACHE_TTL = 2592000; // 30 days

    /**
     * @param int $serverId HostBill server id (0 if none, e.g. during testConnection)
     */
    public function __construct($serverId)
    {
        $this->serverId = (int) $serverId;
    }

    /**
     * @return string Cache key scoped to this server.
     */
    protected function key()
    {
        return 'tino.token.' . $this->serverId;
    }

    /**
     * Read the cached record for this server.
     *
     * @return array|null ['access_token','refresh_token','token_expire'] or null
     */
    public function read()
    {
        if (!class_exists('\\HBCache')) {
            return null;
        }
        try {
            $row = \HBCache::get($this->key());
        } catch (\Exception $ex) {
            return null;
        }
        return is_array($row) ? $row : null;
    }

    /**
     * Get a still-valid access token, or null if missing/expired.
     *
     * @return string|null
     */
    public function getValidAccessToken()
    {
        $row = $this->read();
        if (!$row || empty($row['access_token'])) {
            return null;
        }

        $expire = isset($row['token_expire']) ? (int) $row['token_expire'] : 0;
        if ($expire > 0 && $expire - self::EXPIRY_SKEW <= time()) {
            return null; // expired or about to expire
        }

        return $row['access_token'];
    }

    /**
     * Get the stored refresh token (may still be valid even when access expired).
     *
     * @return string|null
     */
    public function getRefreshToken()
    {
        $row = $this->read();
        return ($row && !empty($row['refresh_token'])) ? $row['refresh_token'] : null;
    }

    /**
     * Store tokens for this server.
     *
     * @param string   $accessToken
     * @param string   $refreshToken May be empty (some refresh responses omit it)
     * @param int|null $expire       Unix ts of access-token expiry; auto-decoded from JWT if null
     */
    public function store($accessToken, $refreshToken, $expire = null)
    {
        if (!class_exists('\\HBCache')) {
            return;
        }

        if ($expire === null) {
            $expire = self::decodeJwtExpiry($accessToken);
        }

        // Keep the previous refresh token when the new payload omits it.
        if (empty($refreshToken)) {
            $existing     = $this->getRefreshToken();
            $refreshToken = $existing ?: '';
        }

        try {
            \HBCache::set($this->key(), [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expire'  => (int) $expire,
            ], self::CACHE_TTL);
        } catch (\Exception $ex) {
            // Ignore cache write failures — worst case we log in again next time.
        }
    }

    /**
     * Remove cached tokens for this server (e.g. after an unrecoverable auth error).
     */
    public function clear()
    {
        if (!class_exists('\\HBCache')) {
            return;
        }
        try {
            \HBCache::delete($this->key());
        } catch (\Exception $ex) {
            // Ignore.
        }
    }

    /**
     * Decode the `exp` claim from a JWT without verifying its signature.
     *
     * @param string $jwt
     * @return int Unix timestamp, or 0 when it cannot be parsed
     */
    public static function decodeJwtExpiry($jwt)
    {
        $parts = explode('.', (string) $jwt);
        if (count($parts) < 2) {
            return 0;
        }

        $payload = self::base64UrlDecode($parts[1]);
        $data    = json_decode($payload, true);

        return (isset($data['exp']) && is_numeric($data['exp'])) ? (int) $data['exp'] : 0;
    }

    /**
     * @param string $data
     * @return string
     */
    protected static function base64UrlDecode($data)
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
