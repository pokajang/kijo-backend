<?php

namespace Tests\Unit;

use App\Support\PdfText;
use Tests\TestCase;

class PdfTextTest extends TestCase
{
    public function test_ordinary_item_wording_stays_in_one_cell_segment(): void
    {
        $segments = PdfText::itemCellSegments(
            "First catalogue line\nSecond catalogue line",
            'Client requested blue equipment.',
        );

        $this->assertCount(1, $segments);
        $this->assertSame('First catalogue line; Second catalogue line', $segments[0]['description']);
        $this->assertSame('Client requested blue equipment.', $segments[0]['remarks']);
        $this->assertTrue($segments[0]['show_description_label']);
        $this->assertTrue($segments[0]['show_remarks_label']);
    }

    public function test_oversized_item_wording_uses_page_safe_segments_without_repeating_labels(): void
    {
        $descriptionEnd = 'DESCRIPTION-END-SENTINEL';
        $remarksEnd = 'REMARKS-END-SENTINEL';
        $segments = PdfText::itemCellSegments(
            str_repeat("Complete catalogue wording on its own line.\n", 30).$descriptionEnd,
            str_repeat('Client-specific requirement ', 80).$remarksEnd,
        );

        $this->assertGreaterThan(1, count($segments));
        $this->assertSame(1, collect($segments)->where('show_description_label', true)->count());
        $this->assertSame(1, collect($segments)->where('show_remarks_label', true)->count());
        $this->assertStringContainsString(
            $descriptionEnd,
            collect($segments)->pluck('description')->implode("\n"),
        );
        $this->assertStringContainsString(
            $remarksEnd,
            collect($segments)->pluck('remarks')->implode("\n"),
        );
    }

    public function test_invalid_segment_limits_are_clamped_to_safe_values(): void
    {
        $segments = PdfText::itemCellSegments(
            str_repeat('Long catalogue wording ', 20),
            'Client remarks',
            0,
            0,
        );

        $this->assertNotEmpty($segments);
        $this->assertSame(1, collect($segments)->where('show_description_label', true)->count());
        $this->assertSame(1, collect($segments)->where('show_remarks_label', true)->count());
    }

    public function test_compact_inline_normalizes_pasted_bullets_and_line_endings(): void
    {
        $description = "Personal Air Sampling Pump\r\nIncludes:\r\n"
            ."• 1 air sampling pump\r\n"
            ."◦ standard charging dock\r\n"
            .'3) filter cassette holder';

        $this->assertSame(
            'Personal Air Sampling Pump; Includes: 1 air sampling pump; standard charging dock; 3) filter cassette holder',
            PdfText::compactInline($description),
        );
        $this->assertStringNotContainsString('•', PdfText::compactInline($description));
    }
}
