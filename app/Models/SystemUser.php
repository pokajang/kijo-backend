<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemUser extends Model
{
    protected $table = 'system_users';

    protected $hidden = ['password_hash'];

    protected $casts = [
        'role'                 => 'array',
        'total_lock'           => 'boolean',
        'is_active'            => 'boolean',
        'account_locked_until' => 'datetime',
        'last_failed_login'    => 'datetime',
    ];

    public function staffProfile()
    {
        return $this->hasOne(StaffGeneral::class, 'staff_id', 'staff_id');
    }
}
