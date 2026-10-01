<?php
namespace WpSync;

/**
 * „Password Protected“ sperrt sonst auch die REST-API (Spike B11). Der Bypass gilt nur für
 * exakt bekannte wpsync-Routen und nie anhand des Query-Strings (SEC-01). Ohne formal gültige
 * Signatur-Header sind nur der Namespace-Index (wpsync pair → Discover) und /pair offen; die
 * Signatur selbst prüft danach Rest::auth(). Frontend-Seiten bleiben geschützt (AC-25).
 */
final class Protection
{
    public const ROUTE_PREFIX = '/wpsync/v1';

    public static function register(): void
    {
        add_filter('password_protected_is_active', [self::class, 'passwordProtected'], PHP_INT_MAX);
    }

    public static function isAgentRequest(): bool
    {
        // phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        $getRoute = isset($_GET['rest_route']) ? (is_string($_GET['rest_route']) ? wp_unslash($_GET['rest_route']) : "\n") : null;
        return self::matches(
            $getRoute,
            isset($_POST['rest_route']),
            isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '',
            (string) parse_url(home_url(), PHP_URL_PATH),
            rest_get_url_prefix(),
            isset($_SERVER['HTTP_X_WPSYNC_KEY']) ? (string) $_SERVER['HTTP_X_WPSYNC_KEY'] : '',
            isset($_SERVER['HTTP_X_WPSYNC_SIGNATURE']) ? (string) $_SERVER['HTTP_X_WPSYNC_SIGNATURE'] : ''
        );
        // phpcs:enable
    }

    /**
     * @param string|null $getRoute     $_GET['rest_route'] oder null, wenn nicht gesetzt
     * @param bool        $hasPostRoute rest_route im POST-Body – gewinnt in WP::parse_request gegen den Pfad
     * @param string      $uri          REQUEST_URI inklusive Query-String
     * @param string      $homePath     Pfadteil von home_url(), z. B. '' oder '/blog'
     * @param string      $restPrefix   rest_get_url_prefix(), üblicherweise 'wp-json'
     */
    public static function matches(
        ?string $getRoute,
        bool $hasPostRoute,
        string $uri,
        string $homePath,
        string $restPrefix,
        string $keyId,
        string $signature
    ): bool {
        if ($hasPostRoute) {
            return false;
        }
        if ($getRoute !== null) {
            $route = $getRoute;
        } else {
            $path = explode('?', $uri, 2)[0];
            $base = rtrim($homePath, '/') . '/' . trim($restPrefix, '/');
            if (strpos($path, $base . '/') !== 0) {
                return false;
            }
            $route = substr($path, strlen($base));
        }
        $route = rtrim($route, '/');
        if (preg_match('#^/wpsync/v1(/[a-z-]+){0,2}\z#', $route) !== 1) {
            return false;
        }
        if ($route === self::ROUTE_PREFIX || $route === self::ROUTE_PREFIX . '/pair') {
            return true;
        }
        return preg_match('/^[a-f0-9]{16}\z/', $keyId) === 1 && preg_match('/^[a-f0-9]{64}\z/', $signature) === 1;
    }

    /**
     * @param mixed $active
     * @return mixed
     */
    public static function passwordProtected($active)
    {
        return self::isAgentRequest() ? false : $active;
    }
}
