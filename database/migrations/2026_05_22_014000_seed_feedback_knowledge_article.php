<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-submit-and-track-system-feedback-tickets';

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
            'title' => 'How to Submit and Track System Feedback Tickets',
            'slug' => $this->slug,
            'summary' => 'Submit system feedback from the global Ticket button, track feedback records, edit your own tickets, and understand the System Admin fix workflow.',
            'body_html' => $this->articleBody(),
            'category' => 'Support',
            'tags' => json_encode(['support', 'feedback', 'ticket', 'bug-report', 'system-feedback', 'admin-fix', 'request', 'issue', 'improvement']),
            'related_route' => '/support/feedback',
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
            $remarks = 'Updated System Feedback guide with ticket submission, list filtering, owner edits, deletion, detail page, and admin fix workflow.';
        } else {
            $articleId = (int) DB::table('knowledge_articles')->insertGetId($payload + [
                'created_by_staff_id' => null,
                'created_by_name_code' => 'SYSTEM',
                'created_at' => $now,
            ]);
            $action = 'created_published';
            $remarks = 'Production System Feedback guide seeded by system.';
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

        DB::table('knowledge_articles')->where('id', $article->id)->delete();
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
<h3>When to use System Feedback</h3>
<p>Use <strong>System Feedback</strong> when you need to report a KIJO platform issue, workflow friction, or improvement idea.</p>
<ul>
<li>Use it for bugs, confusing screens, broken actions, missing fields, slow workflows, or suggestions to improve how the system works.</li>
<li>Use <strong>Request Tool</strong> instead when you need to submit an operational request that belongs to a structured support/request process.</li>
<li>Do not use System Feedback as a replacement for CRM, project, commercial, HR, or admin records.</li>
</ul>
<h3>Submitting a ticket from the global header</h3>
<ol>
<li>Click the <strong>Ticket</strong> button in the global header or bottom navigation area.</li>
<li>The <strong>Submit Support Ticket</strong> modal opens.</li>
<li>Describe the issue you are facing or the improvement you would like to request.</li>
<li>Click <strong>Submit</strong>.</li>
<li>KIJO creates a feedback ticket under your staff account.</li>
<li>The system sends a notification email to the System Admin.</li>
</ol>
<h3>Writing a useful ticket</h3>
<ul>
<li>State which module or page you were using.</li>
<li>Describe what you clicked or tried to do.</li>
<li>Describe what happened.</li>
<li>Describe what you expected to happen.</li>
<li>Add record references when relevant, such as quotation number, project name, invoice number, client, or date.</li>
<li>If the issue is intermittent, mention when it happened and whether refreshing helped.</li>
</ul>
<h3>Opening System Feedback records</h3>
<ol>
<li>Open <strong>Support</strong> from the navigation.</li>
<li>Choose <strong>System Feedback</strong>.</li>
<li>The page opens all visible feedback records.</li>
<li>Use this page to check whether your ticket is still pending, being worked on, or already fixed.</li>
</ol>
<h3>Reading the feedback list</h3>
<ul>
<li><strong>Feedback</strong> is the ticket text submitted by the user.</li>
<li><strong>Reported by</strong> shows the staff code of the reporter.</li>
<li><strong>Date Reported</strong> shows when the ticket was submitted.</li>
<li><strong>Status</strong> shows the current admin handling status.</li>
<li><strong>Action Date</strong> shows the date the admin recorded an action.</li>
<li><strong>Remarks</strong> shows admin notes about the fix, follow-up, or handling decision.</li>
</ul>
<h3>Using the summary cards</h3>
<ul>
<li><strong>Feedback</strong> counts visible records after the current filters.</li>
<li><strong>Pending</strong> counts tickets whose status is still pending or pending-like.</li>
<li><strong>Fixed</strong> counts tickets whose status contains fixed.</li>
<li><strong>Top Reporter</strong> shows the staff code with the most visible reports.</li>
<li>Clicking relevant summary cards applies the matching filter.</li>
</ul>
<h3>Searching and filtering feedback</h3>
<ol>
<li>Use the search box to search feedback text, reporter, date reported, status, action date, or remarks.</li>
<li>Use <strong>Status</strong> to show all, pending, or fixed records.</li>
<li>Use <strong>Reported by</strong> to focus on one reporter.</li>
<li>Use the period selector to filter by reported date.</li>
<li>Use <strong>Reset</strong> to clear filters and return to the default view.</li>
<li>Use <strong>Columns</strong> to show or hide optional fields such as remarks.</li>
<li>Use <strong>Export</strong> if you need a CSV of the currently filtered feedback records.</li>
</ol>
<h3>Opening a feedback detail page</h3>
<ol>
<li>Click a feedback row.</li>
<li>The system opens the <strong>Feedback Details</strong> page.</li>
<li>Review the full feedback text, reporter, reported date, status, action date, and remarks.</li>
<li>Use <strong>Back</strong> to return to the feedback list.</li>
</ol>
<h3>Editing your own ticket</h3>
<ol>
<li>Find your ticket in <strong>System Feedback</strong>.</li>
<li>Open the row action menu and click <strong>Edit</strong>, or open the detail page and choose <strong>Edit</strong>.</li>
<li>Update the ticket text in the <strong>Submit Support Ticket</strong> modal.</li>
<li>Click <strong>Submit</strong>.</li>
<li>Only the ticket owner or System Admin can edit the ticket text.</li>
</ol>
<h3>Deleting your own ticket</h3>
<ol>
<li>Find your ticket in <strong>System Feedback</strong>.</li>
<li>Open the row action menu and click <strong>Delete</strong>, or open the detail page and choose <strong>Delete</strong>.</li>
<li>Confirm only if the feedback should be permanently removed.</li>
<li>Only the ticket owner or System Admin can delete a feedback ticket.</li>
<li>If the ticket is still valid but needs more detail, edit it instead of deleting it.</li>
</ol>
<h3>What System Admin can update</h3>
<ul>
<li>System Admin can update the ticket text.</li>
<li>System Admin can update <strong>Status</strong>.</li>
<li>System Admin can set or clear <strong>Action Date</strong>.</li>
<li>System Admin can add <strong>Remarks</strong>.</li>
<li>System Admin can delete any ticket when cleanup is required.</li>
</ul>
<h3>Using admin fix statuses</h3>
<ul>
<li><strong>Pending</strong> means the ticket has been received but no fix is recorded yet.</li>
<li><strong>In Progress</strong> means the issue is being checked or worked on.</li>
<li><strong>Fixed Pending Pushed</strong> means a fix is prepared but not yet pushed or released to users.</li>
<li><strong>Fixed Completed</strong> means the fix has been completed and is available for normal use.</li>
<li>Use <strong>Remarks</strong> to explain what changed, why the ticket is pending, or what the reporter should check next.</li>
</ul>
<h3>How this helps system improvement</h3>
<ul>
<li>Repeated tickets show where users are struggling.</li>
<li>Pending counts help System Admin prioritize unresolved issues.</li>
<li>Fixed counts show how much system cleanup or enhancement work has been completed.</li>
<li>Top reporter can identify power users who are actively testing workflows or teams that need more support.</li>
<li>Clear remarks create a lightweight support history for future debugging.</li>
</ul>
<h3>Common mistakes to avoid</h3>
<ul>
<li>Do not submit vague tickets such as "system problem" without page and action details.</li>
<li>Do not submit duplicate tickets if an existing ticket already describes the same problem. Edit the original ticket if you own it.</li>
<li>Do not delete a ticket just because the status has changed. Keep it when the history is still useful.</li>
<li>Do not use System Feedback for formal business requests that belong in <strong>Request Tool</strong>.</li>
<li>Do not use admin remarks for private or sensitive information unless it is appropriate for users who can view the feedback record.</li>
</ul>
<h3>Troubleshooting</h3>
<ul>
<li>If the submit modal refuses to save, make sure the feedback text is not blank.</li>
<li>If you cannot edit a ticket, confirm that you are the reporter or a System Admin.</li>
<li>If you cannot delete a ticket, confirm that you are the reporter or a System Admin.</li>
<li>If a ticket is missing from the list, check the period filter and reported-by filter.</li>
<li>If admin status cannot be saved, check that the selected status is one of the supported status options.</li>
</ul>
HTML;
    }
};
