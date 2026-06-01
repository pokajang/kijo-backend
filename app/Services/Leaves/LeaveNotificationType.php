<?php

namespace App\Services\Leaves;

/**
 * Phase D4: single source of truth for the leave-notification clearing taxonomy
 * (remediation finding H2). Every stored leave notification type is exactly one
 * of two classes, which determines HOW its badge is cleared:
 *
 *  - ACTION — appears on a recommender/approver worklist. Cleared when the
 *             workflow stage is RESOLVED ({@see AppNotificationService::resolveActive()}),
 *             i.e. when the work is done. NOT cleared by merely viewing a page.
 *  - FYI    — informational (applicant outcomes + participant copies). Cleared
 *             when the recipient VIEWS the record ({@see AppNotificationService::consumeEntity()}
 *             / consumeRouteGroup), i.e. on read.
 *
 * Centralizing this keeps the staff.leaves and my.leaves badges on one coherent
 * clearing model instead of two divergent ad-hoc rules.
 */
final class LeaveNotificationType
{
    /** Worklist items cleared on workflow resolution. */
    public const ACTION = [
        'leave.needs_recommendation',
        'leave.needs_approval',
    ];

    /** Informational items cleared on view. */
    public const FYI = [
        'leave.approved',
        'leave.approved.copy',
        'leave.rejected',
        'leave.rejected.copy',
        'leave.revoked',
        'leave.revoked.copy',
        'leave.cancelled',
        'leave.cancelled.applicant',
    ];

    public static function isAction(string $type): bool
    {
        return in_array($type, self::ACTION, true);
    }

    public static function isFyi(string $type): bool
    {
        return in_array($type, self::FYI, true);
    }
}
