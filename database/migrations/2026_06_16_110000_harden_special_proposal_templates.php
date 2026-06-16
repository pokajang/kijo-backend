<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('proposal_template_special')) {
            Schema::table('proposal_template_special', function (Blueprint $table): void {
                if (! Schema::hasColumn('proposal_template_special', 'proposal_mode')) {
                    $table->string('proposal_mode', 20)->default('upload');
                }
                if (! Schema::hasColumn('proposal_template_special', 'service_summary')) {
                    $table->longText('service_summary')->nullable();
                }
                if (! Schema::hasColumn('proposal_template_special', 'proposal_content')) {
                    $table->longText('proposal_content')->nullable();
                }
            });

            $this->backfillSpecialProposalModeFields();
        }

        if (! Schema::hasTable('proposal_template_special_items')) {
            Schema::create('proposal_template_special_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('template_id')->index();
                $table->string('line_item_title');
                $table->text('description')->nullable();
                $table->string('unit')->nullable();
                $table->decimal('default_quantity', 12, 2)->default(1);
                $table->decimal('default_unit_price', 12, 2)->default(0);
                $table->decimal('default_line_total', 12, 2)->default(0);
                $table->integer('sort_order')->default(0);
                $table->integer('created_by')->nullable();
                $table->timestamps();
            });
        }

        $this->backfillSpecialTemplateItems();

        if (! Schema::hasTable('quotes_special_proposal_snapshots')) {
            Schema::create('quotes_special_proposal_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('quote_id')->unique();
                $table->unsignedBigInteger('template_id')->nullable()->index();
                $table->string('proposal_language', 10)->default('en');
                $table->string('proposal_mode', 20)->default('upload');
                $table->string('service_title')->nullable();
                $table->string('service_code')->nullable();
                $table->longText('service_summary')->nullable();
                $table->longText('proposal_content')->nullable();
                $table->json('attachments_json')->nullable();
                $table->timestamp('template_updated_at')->nullable();
                $table->timestamp('captured_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes_special_proposal_snapshots');
        Schema::dropIfExists('proposal_template_special_items');

        if (Schema::hasTable('proposal_template_special')) {
            Schema::table('proposal_template_special', function (Blueprint $table): void {
                foreach (['proposal_content', 'service_summary', 'proposal_mode'] as $column) {
                    if (Schema::hasColumn('proposal_template_special', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function backfillSpecialProposalModeFields(): void
    {
        if (! Schema::hasColumn('proposal_template_special', 'content')) {
            return;
        }

        $attachmentFk = $this->specialAttachmentForeignKey();

        DB::table('proposal_template_special')
            ->select(['id', 'content'])
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($attachmentFk): void {
                foreach ($rows as $row) {
                    $hasAttachments = $attachmentFk !== null
                        && Schema::hasTable('proposal_special_attachments')
                        && DB::table('proposal_special_attachments')
                            ->where($attachmentFk, (int) $row->id)
                            ->exists();

                    DB::table('proposal_template_special')->where('id', (int) $row->id)->update([
                        'proposal_mode' => $hasAttachments ? 'upload' : 'write',
                        'service_summary' => $hasAttachments ? $row->content : null,
                        'proposal_content' => $hasAttachments ? null : $row->content,
                    ]);
                }
            });
    }

    private function backfillSpecialTemplateItems(): void
    {
        if (
            ! Schema::hasTable('proposal_template_special')
            || ! Schema::hasTable('proposal_template_special_items')
            || ! Schema::hasTable('quotes_special')
            || ! Schema::hasTable('quotes_special_items')
        ) {
            return;
        }

        DB::table('proposal_template_special')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(100, function ($templates): void {
                foreach ($templates as $template) {
                    $templateId = (int) $template->id;
                    $hasDefaults = DB::table('proposal_template_special_items')
                        ->where('template_id', $templateId)
                        ->exists();
                    if ($hasDefaults) {
                        continue;
                    }

                    $quoteSelect = ['id'];
                    if (Schema::hasColumn('quotes_special', 'created_by_id')) {
                        $quoteSelect[] = 'created_by_id';
                    }

                    $quoteQuery = DB::table('quotes_special')
                        ->where('sp_id', $templateId);
                    if (Schema::hasColumn('quotes_special', 'updated_at')) {
                        $quoteQuery->orderByDesc('updated_at');
                    }
                    $quoteQuery->orderByDesc('id');
                    $quote = $quoteQuery->first($quoteSelect);
                    if (! $quote) {
                        continue;
                    }

                    $items = DB::table('quotes_special_items')
                        ->where('quote_id', (int) $quote->id)
                        ->orderBy('id')
                        ->get();

                    $now = now();
                    $inserts = [];
                    foreach ($items as $index => $item) {
                        $quantity = (float) ($item->quantity ?? 1);
                        $unitPrice = (float) ($item->unit_price ?? 0);
                        $lineTotal = round($quantity * $unitPrice, 2);
                        $title = trim((string) ($item->line_item_title ?? ''));
                        if ($title === '') {
                            continue;
                        }

                        $inserts[] = [
                            'template_id' => $templateId,
                            'line_item_title' => $title,
                            'description' => $item->description ?? null,
                            'unit' => $item->unit ?? null,
                            'default_quantity' => $quantity,
                            'default_unit_price' => $unitPrice,
                            'default_line_total' => $lineTotal,
                            'sort_order' => $index,
                            'created_by' => $quote->created_by_id ?? null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if (! empty($inserts)) {
                        DB::table('proposal_template_special_items')->insert($inserts);
                    }
                }
            });
    }

    private function specialAttachmentForeignKey(): ?string
    {
        if (! Schema::hasTable('proposal_special_attachments')) {
            return null;
        }

        if (Schema::hasColumn('proposal_special_attachments', 'template_id')) {
            return 'template_id';
        }

        if (Schema::hasColumn('proposal_special_attachments', 'proposal_id')) {
            return 'proposal_id';
        }

        return null;
    }
};
