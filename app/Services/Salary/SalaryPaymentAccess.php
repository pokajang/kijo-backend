<?php

namespace App\Services\Salary;

final class SalaryPaymentAccess
{
    public const ROLES = ['HR', 'Manager', 'Finance', 'Account', 'Bank'];

    private function __construct() {}
}
