<?php

namespace App\Services\Meetings;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingActionItemCodecService extends MeetingActionItemBaseService
{

    public function normalizeStatus(?string $value): string
    {
        $raw = strtolower(trim((string) $value));
        if (in_array($raw, ['in progress', 'in_progress', 'progress'], true)) {
            return 'In Progress';
        }
        if (in_array($raw, ['done', 'completed', 'complete'], true)) {
            return 'Done';
        }
        return 'Pending';
    }

    public function decode(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
            $items = [];
            foreach ($lines as $line) {
                $text = trim((string) $line);
                if ($text === '') {
                    continue;
                }
                $items[] = [
                    'item_id' => '',
                    'action_text' => $text,
                    'pic_staff_id' => null,
                    'pic_name' => '',
                    'pic_code' => '',
                    'due_date' => '',
                    'status' => 'Pending',
                    'created_by' => null,
                    'created_name' => '',
                    'created_code' => '',
                    'created_at' => '',
                    'updated_by' => null,
                    'updated_name' => '',
                    'updated_code' => '',
                    'updated_at' => '',
                    'completed_by' => null,
                    'completed_name' => '',
                    'completed_code' => '',
                    'completed_at' => '',
                ];
            }
            return $items;
        }

        $items = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $actionText = trim((string) ($entry['action_text'] ?? $entry['actionText'] ?? $entry['action'] ?? ''));
            $picStaffIdRaw = $entry['pic_staff_id'] ?? $entry['picStaffId'] ?? null;
            $picStaffId = is_numeric($picStaffIdRaw) ? (int) $picStaffIdRaw : 0;
            $dueDate = $this->normalizeDueDate((string) ($entry['due_date'] ?? $entry['dueDate'] ?? ''));
            $status = $this->normalizeStatus((string) ($entry['status'] ?? 'Pending'));
            if ($actionText === '' && $picStaffId <= 0 && $dueDate === '') {
                continue;
            }

            $createdByRaw = $entry['created_by'] ?? $entry['createdBy'] ?? null;
            $updatedByRaw = $entry['updated_by'] ?? $entry['updatedBy'] ?? null;
            $completedByRaw = $entry['completed_by'] ?? $entry['completedBy'] ?? null;
            $items[] = [
                'item_id' => trim((string) ($entry['item_id'] ?? $entry['itemId'] ?? '')),
                'action_text' => $actionText,
                'pic_staff_id' => $picStaffId > 0 ? $picStaffId : null,
                'pic_name' => trim((string) ($entry['pic_name'] ?? $entry['picName'] ?? '')),
                'pic_code' => trim((string) ($entry['pic_code'] ?? $entry['picCode'] ?? '')),
                'due_date' => $dueDate,
                'status' => $status,
                'created_by' => is_numeric($createdByRaw) && (int) $createdByRaw > 0 ? (int) $createdByRaw : null,
                'created_name' => trim((string) ($entry['created_name'] ?? $entry['createdName'] ?? '')),
                'created_code' => trim((string) ($entry['created_code'] ?? $entry['createdCode'] ?? '')),
                'created_at' => trim((string) ($entry['created_at'] ?? $entry['createdAt'] ?? '')),
                'updated_by' => is_numeric($updatedByRaw) && (int) $updatedByRaw > 0 ? (int) $updatedByRaw : null,
                'updated_name' => trim((string) ($entry['updated_name'] ?? $entry['updatedName'] ?? '')),
                'updated_code' => trim((string) ($entry['updated_code'] ?? $entry['updatedCode'] ?? '')),
                'updated_at' => trim((string) ($entry['updated_at'] ?? $entry['updatedAt'] ?? '')),
                'completed_by' => is_numeric($completedByRaw) && (int) $completedByRaw > 0 ? (int) $completedByRaw : null,
                'completed_name' => trim((string) ($entry['completed_name'] ?? $entry['completedName'] ?? '')),
                'completed_code' => trim((string) ($entry['completed_code'] ?? $entry['completedCode'] ?? '')),
                'completed_at' => trim((string) ($entry['completed_at'] ?? $entry['completedAt'] ?? '')),
            ];
        }

        return $items;
    }

    public function normalizeForStorage(string $raw, int $actorId, string $actorName, string $actorCode): string
    {
        $items = $this->decode($raw);
        if (count($items) === 0) {
            return '';
        }

        $now = now()->format('Y-m-d H:i:s');
        $normalized = [];
        foreach ($items as $item) {
            $actionText = trim((string) ($item['action_text'] ?? ''));
            $picStaffId = isset($item['pic_staff_id']) && is_numeric($item['pic_staff_id']) ? (int) $item['pic_staff_id'] : 0;
            $dueDate = $this->normalizeDueDate((string) ($item['due_date'] ?? ''));
            if ($actionText === '' && $picStaffId <= 0 && $dueDate === '') {
                continue;
            }

            $status = $this->normalizeStatus((string) ($item['status'] ?? 'Pending'));
            $createdBy = isset($item['created_by']) && is_numeric($item['created_by']) && (int) $item['created_by'] > 0
                ? (int) $item['created_by']
                : $actorId;
            $updatedBy = isset($item['updated_by']) && is_numeric($item['updated_by']) && (int) $item['updated_by'] > 0
                ? (int) $item['updated_by']
                : $actorId;

            $createdAt = trim((string) ($item['created_at'] ?? ''));
            $updatedAt = trim((string) ($item['updated_at'] ?? ''));
            $completedAt = trim((string) ($item['completed_at'] ?? ''));
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $createdAt)) {
                $createdAt = $now;
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $updatedAt)) {
                $updatedAt = $createdAt;
            }

            $completedBy = null;
            $completedName = '';
            $completedCode = '';
            if ($status === 'Done') {
                $completedBy = isset($item['completed_by']) && is_numeric($item['completed_by']) && (int) $item['completed_by'] > 0
                    ? (int) $item['completed_by']
                    : $updatedBy;
                $completedName = trim((string) ($item['completed_name'] ?? '')) !== ''
                    ? trim((string) ($item['completed_name'] ?? ''))
                    : trim((string) ($item['updated_name'] ?? $actorName));
                $completedCode = trim((string) ($item['completed_code'] ?? '')) !== ''
                    ? trim((string) ($item['completed_code'] ?? ''))
                    : trim((string) ($item['updated_code'] ?? $actorCode));
                if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $completedAt)) {
                    $completedAt = $updatedAt;
                }
            } else {
                $completedAt = '';
            }

            $normalized[] = [
                'item_id' => trim((string) ($item['item_id'] ?? '')) !== '' ? (string) $item['item_id'] : $this->generateId(),
                'action_text' => $actionText,
                'pic_staff_id' => $picStaffId > 0 ? $picStaffId : null,
                'pic_name' => trim((string) ($item['pic_name'] ?? '')),
                'pic_code' => trim((string) ($item['pic_code'] ?? '')),
                'due_date' => $dueDate,
                'status' => $status,
                'created_by' => $createdBy,
                'created_name' => trim((string) ($item['created_name'] ?? '')) !== '' ? trim((string) ($item['created_name'] ?? '')) : $actorName,
                'created_code' => trim((string) ($item['created_code'] ?? '')) !== '' ? trim((string) ($item['created_code'] ?? '')) : $actorCode,
                'created_at' => $createdAt,
                'updated_by' => $updatedBy,
                'updated_name' => trim((string) ($item['updated_name'] ?? '')) !== '' ? trim((string) ($item['updated_name'] ?? '')) : $actorName,
                'updated_code' => trim((string) ($item['updated_code'] ?? '')) !== '' ? trim((string) ($item['updated_code'] ?? '')) : $actorCode,
                'updated_at' => $updatedAt,
                'completed_by' => $completedBy,
                'completed_name' => $completedName,
                'completed_code' => $completedCode,
                'completed_at' => $completedAt,
            ];
        }

        return count($normalized) > 0 ? $this->encode($normalized) : '';
    }
}
