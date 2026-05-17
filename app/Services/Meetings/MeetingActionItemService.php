<?php

namespace App\Services\Meetings;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingActionItemService
{
    private function meetingActionItemMutationService(): MeetingActionItemMutationService
    {
        return app(MeetingActionItemMutationService::class);
    }

    private function meetingActionItemCodecService(): MeetingActionItemCodecService
    {
        return app(MeetingActionItemCodecService::class);
    }

    public function add(Request $request)
    {
        return $this->meetingActionItemMutationService()->add($request);
    }

    public function updateStatus(Request $request)
    {
        return $this->meetingActionItemMutationService()->updateStatus($request);
    }

    public function normalizeStatus(?string $value): string
    {
        return $this->meetingActionItemCodecService()->normalizeStatus($value);
    }

    public function decode(string $raw): array
    {
        return $this->meetingActionItemCodecService()->decode($raw);
    }

    public function normalizeForStorage(string $raw, int $actorId, string $actorName, string $actorCode): string
    {
        return $this->meetingActionItemCodecService()->normalizeForStorage($raw, $actorId, $actorName, $actorCode);
    }

}
