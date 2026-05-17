<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffGeneral extends Model
{
    protected $table      = 'staff_general';
    protected $primaryKey = 'staff_id';
    public $incrementing  = false;
    protected $keyType    = 'string';
}
