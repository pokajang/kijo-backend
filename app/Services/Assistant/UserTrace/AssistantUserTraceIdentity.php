<?php

namespace App\Services\Assistant\UserTrace;

class AssistantUserTraceIdentity
{
    public function __construct(
        public readonly int $staffId,
        public readonly int $userId,
        public readonly array $roles,
        public readonly ?string $nameCode,
        public readonly ?string $email,
        public readonly ?string $fullName,
        public readonly ?string $department,
        public readonly ?string $position,
        public readonly bool $profileFound,
        public readonly array $warnings = [],
    ) {}
}
