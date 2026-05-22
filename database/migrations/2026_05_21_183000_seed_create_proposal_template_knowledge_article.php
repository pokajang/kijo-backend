<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-create-a-proposal';

    public function up(): void
    {
        if (! Schema::hasTable('knowledge_articles')) {
            return;
        }

        $now = now();
        $article = DB::table('knowledge_articles')->where('slug', $this->slug)->first();

        if ($article && ! $this->isSystemManaged($article)) {
            return;
        }

        $payload = [
            'title' => 'How to Create a Proposal Template',
            'slug' => $this->slug,
            'summary' => 'Create reusable proposal templates, choose the right service type, save changes with remarks, and manage BM versions.',
            'body_html' => $this->articleBody(),
            'category' => 'Proposals',
            'tags' => json_encode(['proposal', 'template', 'training', 'ih', 'manpower', 'special', 'bm']),
            'related_route' => '/templates/create',
            'contributor_note' => null,
            'status' => 'published',
            'published_at' => $article->published_at ?? $now,
            'updated_by_staff_id' => null,
            'updated_by_name_code' => 'SYSTEM',
            'updated_at' => $now,
        ];

        if ($article) {
            DB::table('knowledge_articles')->where('id', $article->id)->update($payload);
            $articleId = (int) $article->id;
            $action = 'updated';
            $remarks = 'Expanded proposal template starter guide with the current module flow.';
        } else {
            $articleId = (int) DB::table('knowledge_articles')->insertGetId($payload + [
                'created_by_staff_id' => null,
                'created_by_name_code' => 'SYSTEM',
                'created_at' => $now,
            ]);
            $action = 'created_published';
            $remarks = 'Production starter guide seeded by system.';
        }

        $this->insertEditLog($articleId, $action, $remarks, $now);
    }

    public function down(): void
    {
        if (! Schema::hasTable('knowledge_articles')) {
            return;
        }

        $article = DB::table('knowledge_articles')
            ->where('slug', $this->slug)
            ->where('created_by_name_code', 'SYSTEM')
            ->where('updated_by_name_code', 'SYSTEM')
            ->first();

        if (! $article) {
            return;
        }

        DB::table('knowledge_articles')->where('id', $article->id)->update([
            'title' => 'How to Create a Proposal',
            'summary' => 'Create a reusable proposal template for sales documents.',
            'body_html' => '<ol><li>Open Proposals and select Create.</li><li>Choose the service type.</li><li>Fill in the proposal details, scope, and pricing content.</li><li>Review the content before saving.</li><li>Use Proposal Records to find the saved proposal.</li></ol>',
            'category' => 'Proposals',
            'tags' => json_encode(['proposal', 'template']),
            'related_route' => '/templates/create',
            'contributor_note' => 'Starter guide seeded by system.',
            'status' => 'published',
            'updated_at' => now(),
        ]);
    }

    private function isSystemManaged(object $article): bool
    {
        return ($article->created_by_name_code ?? null) === 'SYSTEM'
            && ($article->updated_by_name_code ?? null) === 'SYSTEM';
    }

    private function insertEditLog(int $articleId, string $action, string $remarks, mixed $createdAt): void
    {
        if (! Schema::hasTable('knowledge_article_edit_logs')) {
            return;
        }

        DB::table('knowledge_article_edit_logs')->insert([
            'knowledge_article_id' => $articleId,
            'action' => $action,
            'remarks' => $remarks,
            'staff_id' => null,
            'name_code' => 'SYSTEM',
            'created_at' => $createdAt,
        ]);
    }

    private function articleBody(): string
    {
        return <<<'HTML'
<h3>Starting a proposal template</h3>
<ol>
<li>Open <strong>Proposal Records</strong>.</li>
<li>Click <strong>Create Proposal</strong> at the top-right of the proposal list.</li>
<li>Check the existing proposal records first to avoid creating duplicates.</li>
<li>Under <strong>Select Service</strong>, choose the proposal type you need.</li>
</ol>
<h3>Choosing the service type</h3>
<ul>
<li>Use <strong>Training Proposal</strong> for training courses with duration and agenda details.</li>
<li>Use <strong>Industrial Hygiene Proposal</strong> for IH services such as CHRA or hygiene monitoring scopes.</li>
<li>Use <strong>Manpower Supply Proposal</strong> for manpower services and deliverables.</li>
<li>Use <strong>Special Service Proposal</strong> for custom work that does not fit the standard proposal types.</li>
</ul>
<h3>Filling the proposal content</h3>
<ul>
<li>Enter a clear title in <strong>Training Title</strong> or <strong>Service Title</strong>.</li>
<li>Enter a short uppercase code in <strong>Training Code</strong> or <strong>Service Code</strong>.</li>
<li>Complete the required rich-text sections such as <strong>Introduction</strong>, <strong>Objectives</strong>, or <strong>Service Deliverables</strong>.</li>
<li>For <strong>Training Proposal</strong>, choose the <strong>Training Duration</strong> and complete at least one agenda row with start time, end time, and topic.</li>
<li>For <strong>Special Service Proposal</strong>, choose either <strong>Upload Full Proposal</strong> or <strong>Write Proposal</strong>.</li>
</ul>
<h3>Using special proposal mode</h3>
<ul>
<li>Choose <strong>Upload Full Proposal</strong> when the full customer-facing proposal is already prepared as an attachment.</li>
<li>Fill <strong>Service Summary</strong> for internal reference when using upload mode.</li>
<li>Attach at least one proposal file before saving in upload mode.</li>
<li>Choose <strong>Write Proposal</strong> when the proposal content should be written directly in the editor.</li>
<li>Fill <strong>Proposal Contents</strong> when using write mode.</li>
</ul>
<h3>Saving the proposal template</h3>
<ol>
<li>Write a useful note in <strong>Remarks</strong>. This is required and appears in the proposal history.</li>
<li>Click <strong>Save Template</strong> or <strong>Create</strong>, depending on the proposal type.</li>
<li>Confirm the save when the confirmation dialog appears.</li>
<li>After saving, the system redirects you back to <strong>Proposal Records</strong>.</li>
</ol>
<h3>Recovering unfinished drafts</h3>
<ul>
<li>New proposal forms are auto-saved in the browser while you type.</li>
<li>If you leave the page before saving, reopen the same proposal type to recover the draft.</li>
<li>Click <strong>Reset</strong> if you want to clear the current draft and start again.</li>
</ul>
<h3>Editing an existing proposal</h3>
<ol>
<li>Open <strong>Proposal Records</strong>.</li>
<li>Find the proposal and choose <strong>Edit</strong> from the action menu or detail page.</li>
<li>Update the required fields.</li>
<li>Enter new <strong>Remarks</strong> explaining what changed.</li>
<li>Click <strong>Update Changes</strong>.</li>
</ol>
<h3>Creating a BM proposal</h3>
<ul>
<li>Open an English proposal record.</li>
<li>Choose <strong>Create BM Proposal</strong>.</li>
<li>The system creates a machine-translated Bahasa Melayu copy and opens it for review.</li>
<li>Review the translated content carefully before using it in quotations.</li>
<li>For <strong>Special Service Proposal</strong>, review copied attachments manually because uploaded files are copied as-is.</li>
<li>Click <strong>Save BM Proposal</strong> when the BM proposal is ready.</li>
</ul>
<h3>Managing saved proposals</h3>
<ul>
<li>Use <strong>Export Brochure</strong> or <strong>Export Proposal</strong> to generate the proposal PDF.</li>
<li>Use <strong>Open BM Proposal</strong> when an English proposal already has a BM version.</li>
<li>Use <strong>Delete</strong> only when the proposal should no longer be available.</li>
<li>Open the detail page to review metadata, history, remarks, and proposal content.</li>
</ul>
HTML;
    }
};
