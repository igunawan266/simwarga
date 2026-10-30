<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'PortalController::index');

$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1', 'filter' => 'throttle:api,120,60'], static function (RouteCollection $routes) {
    // Public
    $routes->get('building', 'BuildingController::index');
    $routes->get('session', 'SessionController::show');
    $routes->post('session/login', 'SessionController::login', ['filter' => 'throttle:login,5,60']);
    $routes->post('session/logout', 'SessionController::logout');

    // Tenant-scoped (auth -> tenant context -> controller)
    $routes->group('office', ['filter' => ['auth', 'tenant']], static function (RouteCollection $routes) {
        $routes->get('/', 'OfficeController::show');
        $routes->get('rooms/(:num)', 'OfficeController::room/$1');
    });
});

// Reserved for Phase 4 (Tripay callback). CSRF is skipped for webhooks/*; the signature check replaces it.
// $routes->post('webhooks/tripay', 'Webhooks\TripayController::handle');
