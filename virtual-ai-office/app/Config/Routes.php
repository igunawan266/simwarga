<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'PortalController::index');
$routes->get('invite/(:segment)', 'InvitationController::show/$1', ['filter' => 'throttle:invite,30,60']);
$routes->post('invite/(:segment)', 'InvitationController::accept/$1', ['filter' => 'throttle:invite_post,10,60']);

$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1', 'filter' => 'throttle:api,120,60'], static function (RouteCollection $routes) {
    // Public
    $routes->get('building', 'BuildingController::index');
    $routes->get('session', 'SessionController::show');
    $routes->post('session/login', 'SessionController::login', ['filter' => 'throttle:login,5,60']);
    $routes->post('session/logout', 'SessionController::logout');
    $routes->get('plans', 'RegistrationController::plans');
    $routes->post('register', 'RegistrationController::register', ['filter' => 'throttle:register,5,600']);

    // Tenant-scoped (auth -> tenant context -> controller)
    $routes->group('office', ['filter' => ['auth', 'tenant']], static function (RouteCollection $routes) {
        $routes->get('/', 'OfficeController::show');
        $routes->get('rooms/(:num)', 'OfficeController::room/$1');

        $routes->group('', ['filter' => 'role:tenant_owner,tenant_admin'], static function (RouteCollection $routes) {
            $routes->get('billing', 'OfficeController::billing');
            $routes->get('invoices/(:num)/channels', 'PaymentController::channels/$1');
            $routes->post('invoices/(:num)/pay', 'PaymentController::pay/$1');
            $routes->get('invoices/(:num)/payments', 'PaymentController::status/$1');
            $routes->get('invoices/(:num)/pdf', 'PaymentController::pdf/$1');
            $routes->post('subscription/renew', 'PaymentController::renew');
        });
        $routes->get('notifications', 'NotificationsController::index');
        $routes->post('notifications/read', 'NotificationsController::markRead');
        $routes->get('members', 'MembersController::index');
        $routes->group('members', ['filter' => 'role:tenant_owner,tenant_admin'], static function (RouteCollection $routes) {
            $routes->post('/', 'MembersController::invite');
            $routes->patch('(:num)', 'MembersController::update/$1');
            $routes->delete('(:num)', 'MembersController::remove/$1');
            $routes->post('(:num)/reinvite', 'MembersController::reinvite/$1');
        });
    });

    // Super Admin (platform role, cross-tenant by design)
    $routes->group('admin', ['namespace' => 'App\Controllers\Api\V1\Admin', 'filter' => ['auth', 'role:super_admin']], static function (RouteCollection $routes) {
        $routes->get('floors', 'BuildingController::floors');
        $routes->patch('offices/(:num)', 'BuildingController::updateOffice/$1');
        $routes->post('offices/(:num)/assign', 'BuildingController::assign/$1');
        $routes->post('offices/(:num)/release', 'BuildingController::release/$1');

        $routes->get('tenants', 'TenantsController::index');
        $routes->post('tenants', 'TenantsController::create');
        $routes->get('tenants/(:num)', 'TenantsController::show/$1');
        $routes->patch('tenants/(:num)', 'TenantsController::update/$1');
        $routes->post('tenants/(:num)/members', 'TenantMembersController::inviteFor/$1');
        $routes->patch('tenants/(:num)/members/(:num)', 'TenantMembersController::updateFor/$1/$2');
        $routes->delete('tenants/(:num)/members/(:num)', 'TenantMembersController::removeFor/$1/$2');
        $routes->post('tenants/(:num)/members/(:num)/reinvite', 'TenantMembersController::reinviteFor/$1/$2');

        $routes->get('invoices/(:num)/pdf', 'PaymentChannelsController::invoicePdf/$1');
        $routes->get('payment-channels', 'PaymentChannelsController::index');
        $routes->post('payment-channels/sync', 'PaymentChannelsController::sync');

        $routes->get('plans', 'PlansController::index');
        $routes->post('plans', 'PlansController::create');
        $routes->patch('plans/(:num)', 'PlansController::update/$1');
    });
});

// Payment gateway callback. CSRF is skipped for webhooks/*; the HMAC signature check replaces it.
$routes->post('webhooks/tripay', 'Webhooks\TripayController::handle', ['filter' => 'throttle:webhook,240,60']);
