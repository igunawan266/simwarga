<?php

if (! function_exists('app_path')) {
    /**
     * Root-relative URL ("/sub/dir/assets/x.js"). Keeps module scripts and fetch() same-origin
     * even when the site is reached through a hostname other than app.baseURL.
     */
    function asset_path(string $path = ''): string
    {
        $base = rtrim((string) parse_url(base_url(), PHP_URL_PATH), '/');

        return $base . '/' . ltrim($path, '/');
    }
}
