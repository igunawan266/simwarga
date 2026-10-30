<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */
    public static function tenantContext(bool $getShared = true): \App\Libraries\Tenancy\TenantContext
    {
        if ($getShared) {
            return static::getSharedInstance('tenantContext');
        }

        return new \App\Libraries\Tenancy\TenantContext();
    }

    public static function auditLogger(bool $getShared = true): \App\Services\AuditService
    {
        if ($getShared) {
            return static::getSharedInstance('auditLogger');
        }

        return new \App\Services\AuditService();
    }

    public static function currentUser(bool $getShared = true): \App\Libraries\Auth\CurrentUser
    {
        if ($getShared) {
            return static::getSharedInstance('currentUser');
        }

        return new \App\Libraries\Auth\CurrentUser();
    }

    public static function tripay(bool $getShared = true): \App\Libraries\Payment\Tripay\TripayClient
    {
        if ($getShared) {
            return static::getSharedInstance('tripay');
        }

        return new \App\Libraries\Payment\Tripay\TripayClient(config('Tripay'));
    }
}
