<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * The `Host` values the API accepts (outside local development).
 *
 * The host of APP_URL, plus the names that reach the API without going through the
 * public address: `api` (the web server's calls), `proxy` (the browser tests), and the
 * loopback names (health checks). A fixed list: a visitor cannot make the application
 * build a link to a host of his choice.
 */
final class TrustedHosts
{
    private const INTERNAL = ['api', 'proxy', 'localhost', '127.0.0.1'];

    /** @return list<string> Patterns for `Request::setTrustedHosts()`. */
    public static function patterns(): array
    {
        $public = parse_url(Config::string('app.url'), PHP_URL_HOST);

        $hosts = array_values(array_unique([...(is_string($public) ? [$public] : []), ...self::INTERNAL]));

        return array_map(fn (string $host) => '^'.preg_quote($host, '#').'$', $hosts);
    }
}
