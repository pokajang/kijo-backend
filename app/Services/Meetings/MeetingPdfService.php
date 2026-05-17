<?php

namespace App\Services\Meetings;

use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingPdfService extends PdfRenderer
{
    public function __construct(private MeetingActionItemService $actionItems)
    {
    }

    public function export(Request $request, int $id)
    {
        if ($id <= 0) {
            return response('Invalid meeting id', 422);
        }

        $meeting = DB::table('meeting_minutes')
            ->select([
                'id',
                'meeting_title',
                'meeting_type',
                'meeting_datetime',
                'venue',
                'guest_attendees_text',
                'agenda',
                'minutes_text',
                'action_items',
                'created_name',
                'created_code',
                'created_at',
                'updated_name',
                'updated_code',
                'updated_at',
                'created_by',
                'record_status',
                'verification_status',
                'verified_name',
                'verified_code',
                'verified_at',
                'concurred_name',
                'concurred_code',
                'concurred_at',
            ])
            ->where('id', $id)
            ->first();

        if (! $meeting) {
            return response('Meeting record not found', 404);
        }
        if ((string) ($meeting->record_status ?? 'Complete') !== 'Complete') {
            $staffId = (int) $request->session()->get('staff_id', 0);
            if ($staffId <= 0 || (int) ($meeting->created_by ?? 0) !== $staffId) {
                return response('Meeting record not found', 404);
            }
            return response('Finalize meeting minutes before exporting PDF.', 400);
        }

        try {
            $attendees = DB::table('meeting_minute_attendees')
                ->select(['staff_name', 'staff_code'])
                ->where('meeting_id', $id)
                ->orderBy('staff_name')
                ->get();
            $actionItems = $this->actionItems->decode((string) ($meeting->action_items ?? ''));

            $attendeeRows = [];
            foreach ($attendees as $a) {
                $name = trim((string) ($a->staff_name ?? ''));
                if ($name === '') {
                    continue;
                }
                $code = trim((string) ($a->staff_code ?? ''));
                $attendeeRows[] = $code !== '' ? "{$name} ({$code})" : $name;
            }

            $guestLines = array_values(array_filter(array_map(
                static fn ($line) => trim((string) $line),
                preg_split('/\r\n|\r|\n/', (string) ($meeting->guest_attendees_text ?? '')) ?: []
            ), static fn ($line) => $line !== ''));

            $actionItemRows = [];
            foreach ($actionItems as $item) {
                $actionText = trim((string) ($item['action_text'] ?? '')) ?: '-';
                $picName = trim((string) ($item['pic_name'] ?? ''));
                $picCode = trim((string) ($item['pic_code'] ?? ''));
                $picLabel = $picName !== '' ? ($picName . ($picCode !== '' ? " ({$picCode})" : '')) : '-';
                $actionItemRows[] = [
                    'actionText' => $actionText,
                    'picLabel' => $picLabel,
                    'dueDate' => trim((string) ($item['due_date'] ?? '')) ?: '-',
                    'status' => trim((string) ($item['status'] ?? 'Pending')) ?: 'Pending',
                ];
            }

            $logoDataUri = $this->companyLogoDataUri();
            $generatedAt = now();
            $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
            $generatorCode = (string) $request->session()->get('name_code', '');

            $html = view('pdf.meeting-minute', [
                'meetingTitle' => (string) ($meeting->meeting_title ?? 'Meeting Minutes'),
                'meetingType' => (string) ($meeting->meeting_type ?? 'Ad Hoc'),
                'meetingDateTime' => $this->formatDateTimeForPdf((string) ($meeting->meeting_datetime ?? '')),
                'venue' => trim((string) ($meeting->venue ?? '')) !== '' ? (string) $meeting->venue : '-',
                'createdBy' => $this->personMeta((string) ($meeting->created_name ?? ''), (string) ($meeting->created_code ?? ''), (string) ($meeting->created_at ?? '')),
                'updatedBy' => $this->personMeta((string) ($meeting->updated_name ?? ''), (string) ($meeting->updated_code ?? ''), (string) ($meeting->updated_at ?? '')),
                'verificationStatus' => (string) ($meeting->verification_status ?? 'Pending'),
                'verifiedBy' => $this->personMeta((string) ($meeting->verified_name ?? ''), (string) ($meeting->verified_code ?? ''), (string) ($meeting->verified_at ?? ''), 'Not verified'),
                'concurredBy' => $this->personMeta((string) ($meeting->concurred_name ?? ''), (string) ($meeting->concurred_code ?? ''), (string) ($meeting->concurred_at ?? ''), 'Not concurred'),
                'attendees' => $attendeeRows,
                'guestLines' => $guestLines,
                'agendaHtml' => $this->cleanHtmlForPdf((string) ($meeting->agenda ?? '')),
                'minutesHtml' => $this->cleanHtmlForPdf((string) ($meeting->minutes_text ?? '')),
                'actionItems' => $actionItemRows,
                'generatedDate' => $generatedAt->format('d M Y, h:i A'),
                'generatedByCode' => $generatorCode,
                'generatedById' => $generatorId,
                'logoDataUri' => $logoDataUri,
            ])->render();

            $dompdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);

            $safeTitle = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($meeting->meeting_title ?? 'meeting_minutes'));
            $safeTitle = trim((string) $safeTitle, '_');
            if ($safeTitle === '') {
                $safeTitle = 'meeting_minutes';
            }

            return response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $safeTitle . '_' . $id . '.pdf"',
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response('Error generating PDF.', 500);
        }
    }

    private function formatDateTimeForPdf(?string $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '-';
        }
        $ts = strtotime($text);
        if ($ts === false) {
            return $text;
        }
        return date('d/m/Y, H:i:s', $ts);
    }

    private function cleanHtmlForPdf(?string $html): string
    {
        $text = trim((string) $html);
        if ($text === '') {
            return '<p>-</p>';
        }
        return strip_tags($text, '<p><br><ul><ol><li><strong><b><em><i><u>');
    }

    private function personMeta(string $name, string $code, string $dateTime, string $fallback = '-'): string
    {
        $name = trim($name);
        if ($name === '') {
            return $fallback;
        }
        $label = $name . ' (' . (trim($code) !== '' ? trim($code) : '-') . ')';
        $dt = $this->formatDateTimeForPdf($dateTime);
        return $label . ($dt !== '-' ? ' - ' . $dt : '');
    }
}
