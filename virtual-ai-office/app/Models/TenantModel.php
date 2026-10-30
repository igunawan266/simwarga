<?php

namespace App\Models;

use CodeIgniter\Model;

/** The tenants table itself is the isolation root; access is gated by services/filters. */
class TenantModel extends Model
{
    protected $table          = 'tenants';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $allowedFields  = ['uuid', 'slug', 'company_name', 'logo_url', 'brand_color', 'industry', 'business_profile', 'contact_email', 'contact_phone', 'status', 'is_listed'];
}
