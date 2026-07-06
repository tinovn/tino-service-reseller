<?php

namespace Hosting\Tino\lib;

/**
 * Thrown when the Tino API rejects the access token (HTTP 401 or
 * {"error":["unauthorized"]}). Signals the client to refresh/re-login and retry.
 */
class UnauthorizedException extends \RuntimeException
{
}
