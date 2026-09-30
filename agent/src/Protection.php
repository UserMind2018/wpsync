<?php
namespace WpSync;

/**
 * „Password Protected“ sperrt sonst auch die REST-API (Spike B11). Der Bypass gilt nur für
 * wpsync-Routen; die Signatur prüft danach Rest::auth(). Frontend-Seiten bleiben geschützt (AC-25).
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
        $route = isset($_GET['rest_route']) ? (string) wp_unslash($_GET['rest_route']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $uri   = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        return strpos($route, self::ROUTE_PREFIX) === 0 || strpos($uri, '/wp-json' . self::ROUTE_PREFIX) !== false;
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
