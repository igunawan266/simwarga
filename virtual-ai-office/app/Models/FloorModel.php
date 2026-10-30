<?php

namespace App\Models;

use CodeIgniter\Model;

class FloorModel extends Model
{
    protected $table         = 'floors';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['building_id', 'floor_number', 'name', 'floor_type', 'tenant_capacity', 'base_monthly_price', 'status'];
}
