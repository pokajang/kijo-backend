<?php

namespace App\Services\Salary;

final class SalaryNotificationType
{
    public const NEEDS_CHECK = 'needs_check';

    public const NEEDS_APPROVAL = 'needs_approval';

    public const SUBMITTED = 'submitted';

    public const CHECKED = 'checked';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const AMENDED = 'amended';

    public const CANCELLED = 'cancelled';

    public const PAYMENT_READY = 'payment_ready';

    public const PAID = 'paid';

    public const PAYMENT_REVERSED = 'payment_reversed';

    private function __construct() {}
}
