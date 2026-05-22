<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-add-manual-pipeline-entries-and-read-monitoring';

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
            'title' => 'How to Add Manual Pipeline Entries and Read Monitoring',
            'slug' => $this->slug,
            'summary' => 'Record off-system pipeline activity, maintain it from Pipeline Entries, and understand how those records feed the Monitoring dashboard.',
            'body_html' => $this->articleBody(),
            'category' => 'CRM',
            'tags' => json_encode(['pipeline', 'monitoring', 'manual-entry', 'crm', 'dashboard', 'lead', 'proposal', 'negotiation', 'closed']),
            'related_route' => '/dashboard/monitoring',
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
            $remarks = 'Expanded pipeline monitoring guide with the dashboard quick-add flow, Pipeline Entries maintenance flow, and reporting aggregation rules.';
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
<h3>When to use manual pipeline entries</h3>
<ul>
<li>Use manual pipeline entries when pipeline activity happened outside KIJO and there is no system record for it.</li>
<li>Good examples are WhatsApp follow-ups, referrals, offline pitching, informal meetings, external prospect discussions, or missing prospect activity.</li>
<li>Use the normal quotation, proposal, negotiation, and award workflows whenever the activity already exists inside KIJO.</li>
<li>Do not use manual entries to duplicate a proposal, negotiation, or closed deal that already exists as a quotation record.</li>
</ul>
<h3>How Pipeline Entries and Monitoring are connected</h3>
<ul>
<li><strong>Pipeline Entries</strong> is the record maintenance area for manual pipeline activity.</li>
<li><strong>Monitoring</strong> is the reporting dashboard that reads manual pipeline entries together with system activity.</li>
<li>When a manual entry is saved, the same record can appear in <strong>Pipeline Entries</strong> and also affect <strong>Monitoring</strong> counts.</li>
<li>Monitoring also reads system sources such as call records, quotation/proposal records, quote negotiation requests, and awarded or won quotation records.</li>
<li>This means manual entries should fill gaps, not replace the normal CRM and quotation lifecycle.</li>
</ul>
<h3>Opening the Monitoring dashboard</h3>
<ol>
<li>Open <strong>Dashboard</strong> from the navigation.</li>
<li>Click the <strong>Monitoring</strong> dashboard tab.</li>
<li>Choose the monitoring month if you need a different reporting period.</li>
<li>If you have permission to view other staff, choose the staff filter or keep the dashboard on all staff.</li>
<li>Click <strong>Add Manual Entry</strong> when you need to capture off-system activity quickly.</li>
</ol>
<h3>Adding a quick manual entry from Monitoring</h3>
<ol>
<li>Click <strong>Add Manual Entry</strong>.</li>
<li>The <strong>Add Manual Pipeline Entry</strong> modal opens.</li>
<li>Select <strong>Entry type</strong>.</li>
<li>Select <strong>Entry date</strong>. The date should match when the activity happened.</li>
<li>Select <strong>Source</strong>. Examples include WhatsApp, call, email, LinkedIn, Facebook, Instagram, referral, or other listed sources.</li>
<li>Select <strong>Classification</strong> if the activity belongs to <strong>Special Project</strong> or <strong>Tender</strong>.</li>
<li>Leave <strong>Classification</strong> blank when the activity is normal individual pipeline activity.</li>
</ol>
<h3>Filling prospect details</h3>
<ol>
<li>In <strong>Prospects</strong>, enter the company or prospect name.</li>
<li>Select <strong>Service category</strong> when the entry should be tied to a service line.</li>
<li>Enter <strong>Estimated RM</strong> only when the selected type allows value tracking.</li>
<li><strong>Estimated RM</strong> appears for <strong>Proposal</strong> and <strong>Closed</strong> entry types.</li>
<li>Add useful context in <strong>Notes</strong>, such as what was discussed, next action, or why the activity is recorded manually.</li>
<li>Attach <strong>Screenshot Proof</strong> when there is supporting proof such as a WhatsApp screenshot, email excerpt, or referral message.</li>
</ol>
<h3>Adding entries to the quick batch</h3>
<ol>
<li>Click <strong>Add to Batch</strong> after filling one prospect row.</li>
<li>The row moves into the pending batch inside the modal.</li>
<li>Repeat the same steps to add more rows.</li>
<li>The Monitoring quick add modal supports up to five entries per save.</li>
<li>Use the edit icon beside a pending row when you need to change it before saving.</li>
<li>After editing a pending row, click <strong>Update Batch</strong>.</li>
<li>Use <strong>Cancel Edit</strong> if you started editing a pending row but do not want to keep the change.</li>
<li>Use the delete icon beside a pending row to remove it from the batch before saving.</li>
</ol>
<h3>Saving quick entries</h3>
<ol>
<li>Review the pending rows in the modal.</li>
<li>Click <strong>Save Entries</strong>.</li>
<li>The system validates required fields before saving.</li>
<li>The modal closes after a successful save.</li>
<li>Monitoring reloads the affected reporting sections so the new activity can appear in the dashboard.</li>
<li>The saved records are also available from <strong>Pipeline Entries</strong>.</li>
</ol>
<h3>Using Bulk Entries for larger entry work</h3>
<ol>
<li>From the <strong>Add Manual Pipeline Entry</strong> modal, click <strong>Bulk Entries</strong> when you need to add more than a few records.</li>
<li>The system opens the bulk entry page at <strong>/pipeline/entries/bulk-add</strong>.</li>
<li>You can also open <strong>Pipeline CRM</strong> and go to <strong>Pipeline Entries</strong>.</li>
<li>Click <strong>Add Entries</strong> from the Pipeline Entries page.</li>
<li>Use the bulk page when you need to prepare many manual records before saving.</li>
<li>The bulk page supports larger batches than the Monitoring quick-add modal.</li>
</ol>
<h3>Adding records from the bulk page</h3>
<ol>
<li>Select the entry type, date, source, classification, and service information.</li>
<li>Enter the company or prospect name.</li>
<li>Enter <strong>Estimated RM</strong> when it applies to the selected entry type.</li>
<li>Add <strong>Notes</strong> for context.</li>
<li>Attach <strong>Screenshot Proof</strong> if there is proof.</li>
<li>Click <strong>Add to Batch</strong>.</li>
<li>Repeat until the batch contains all records you want to save.</li>
<li>Click <strong>Save Entries</strong>.</li>
<li>Use <strong>Cancel</strong> or <strong>Back to Records</strong> only after confirming you do not need unsaved rows.</li>
</ol>
<h3>Opening Pipeline Entries records</h3>
<ol>
<li>Open <strong>Pipeline CRM</strong>.</li>
<li>Click <strong>Pipeline Entries</strong>.</li>
<li>The page lists manual pipeline entries for the selected period.</li>
<li>Click a row to open the entry detail page.</li>
<li>Use the row action menu to edit, view screenshot proof, or delete when those actions are available to you.</li>
</ol>
<h3>Searching and filtering Pipeline Entries</h3>
<ul>
<li>Use the search field to find a prospect, source, owner, notes, or related entry text.</li>
<li>Use <strong>Type</strong> to filter by <strong>Lead</strong>, <strong>Qualified</strong>, <strong>Meeting/ Pitching</strong>, <strong>Proposal</strong>, <strong>Negotiation</strong>, or <strong>Closed</strong>.</li>
<li>Use <strong>Owner</strong> to filter by the staff member who owns the manual entry.</li>
<li>Use <strong>Source</strong> to filter by where the activity came from.</li>
<li>Use <strong>Classification</strong> to filter individual, special project, or tender activity.</li>
<li>Use <strong>Service</strong> to filter by service category.</li>
<li>Use the period selector to control which entry dates are shown.</li>
<li>Use <strong>Reset</strong> to clear filters and return to the default view.</li>
</ul>
<h3>Editing a manual entry</h3>
<ol>
<li>Open <strong>Pipeline Entries</strong>.</li>
<li>Find the manual entry you want to update.</li>
<li>Open the row action menu and click <strong>Edit</strong>.</li>
<li>Update <strong>Entry type</strong>, <strong>Entry date</strong>, <strong>Source</strong>, <strong>Classification</strong>, <strong>Service category</strong>, <strong>Estimated RM</strong>, prospect name, notes, or proof image as needed.</li>
<li>Use <strong>Replace Screenshot Proof</strong> only when a new screenshot should replace the current proof file.</li>
<li>Save the modal after checking the changes.</li>
<li>Only the entry creator or owner can update the manual entry.</li>
</ol>
<h3>Deleting a manual entry</h3>
<ol>
<li>Open <strong>Pipeline Entries</strong>.</li>
<li>Find the manual entry that was created incorrectly.</li>
<li>Open the row action menu and click <strong>Delete</strong>.</li>
<li>Confirm only when the record should be removed from both the record list and Monitoring calculations.</li>
<li>Only the entry creator or owner can delete the manual entry.</li>
</ol>
<h3>Viewing screenshot proof</h3>
<ul>
<li>Use <strong>View Screenshot</strong> from the Pipeline Entries row action menu when a proof image exists.</li>
<li>Monitoring detail popovers can also show proof links for manual contributors.</li>
<li>Screenshot files are compressed before upload when possible.</li>
<li>The upload limit is 500 KB per proof image.</li>
<li>Draft recovery can restore text fields, but selected proof files normally need to be attached again after a browser refresh.</li>
</ul>
<h3>How entry types affect Monitoring</h3>
<ul>
<li><strong>Lead</strong> contributes to the manual side of <strong>LEADS</strong>.</li>
<li><strong>Qualified</strong> contributes to the manual side of <strong>QUALIFIED</strong>.</li>
<li><strong>Meeting/ Pitching</strong> contributes to <strong>MEETING/ PITCHING</strong>.</li>
<li><strong>Proposal</strong> contributes to the manual side of <strong>PROPOSAL</strong>.</li>
<li><strong>Negotiation</strong> contributes to the manual side of <strong>NEGOTIATION</strong>.</li>
<li><strong>Closed</strong> contributes to the manual side of <strong>CLOSED</strong>.</li>
<li>The selected <strong>Entry date</strong> controls which monitoring month or period receives the count.</li>
<li>The selected owner controls which staff view receives the count when Monitoring is filtered by staff.</li>
</ul>
<h3>What Monitoring adds from system records</h3>
<ul>
<li><strong>LEADS</strong> also includes recorded call activity.</li>
<li><strong>QUALIFIED</strong> also includes issued quotation or proposal activity.</li>
<li><strong>PROPOSAL</strong> also includes issued quotation or proposal activity.</li>
<li><strong>NEGOTIATION</strong> also includes quote negotiation requests from the quotation negotiation workflow.</li>
<li><strong>CLOSED</strong> also includes awarded or won quotation records.</li>
<li>Because these sources are already counted, do not manually add the same activity again.</li>
</ul>
<h3>Using Closed entries correctly</h3>
<ul>
<li>Use a manual <strong>Closed</strong> entry only when the closed activity is not already represented by an awarded or won quotation.</li>
<li><strong>Closed</strong> entries require a valid <strong>Service category</strong>.</li>
<li><strong>Closed</strong> entries require <strong>Estimated RM</strong> greater than zero.</li>
<li>Manual closed entries can affect revenue-style Monitoring views because the estimated value is treated as manual closed value.</li>
<li>If a quotation can be marked awarded or won, use the quotation workflow instead of a manual closed entry.</li>
</ul>
<h3>Reading Pipeline Tools</h3>
<ul>
<li><strong>Pipeline Tools</strong> shows funnel movement by stage: <strong>Leads</strong>, <strong>Qualified</strong>, <strong>Meeting / Pitching</strong>, <strong>Proposal</strong>, <strong>Negotiation</strong>, and <strong>Closed</strong>.</li>
<li><strong>Weekly Pipeline Quantity</strong> shows how many records landed in each week of the selected monitoring period.</li>
<li><strong>Pipeline Segment Data</strong> splits the same stages by <strong>Individual</strong>, <strong>Special Project</strong>, and <strong>Tender</strong>.</li>
<li>Click available dashboard cells or detail controls to inspect which records contributed to the count.</li>
<li>Manual records appear as manual contributors, while quotation, call, and negotiation records appear with their own source labels.</li>
</ul>
<h3>Reading revenue and service views</h3>
<ul>
<li><strong>Revenue Status</strong> groups awarded, won, and complete manual closed value by service category.</li>
<li><strong>Weekly Status Value</strong> shows quantity and RM by service for each week.</li>
<li>System RM normally comes from awarded or won quotation value.</li>
<li>Manual RM comes from valid manual <strong>Closed</strong> entries with service category and estimated value.</li>
<li><strong>Service Segment Data</strong> splits service totals by individual, special project, and tender classification.</li>
</ul>
<h3>Choosing between quick add and Pipeline Entries</h3>
<ul>
<li>Use <strong>Add Manual Entry</strong> on Monitoring when you are reviewing the dashboard and need to add a small number of missing activity records.</li>
<li>Use <strong>Bulk Entries</strong> when you have several records to key in at once.</li>
<li>Use <strong>Pipeline Entries</strong> when you need to search, audit, edit, delete, or view proof for existing manual entries.</li>
<li>Use the normal CRM and quotation modules for activity that belongs to the formal quotation, negotiation, award, or project flow.</li>
</ul>
<h3>Common mistakes to avoid</h3>
<ul>
<li>Do not manually add a <strong>Proposal</strong> entry if a quotation or proposal was already issued in KIJO.</li>
<li>Do not manually add a <strong>Closed</strong> entry if the quotation is already awarded or won.</li>
<li>Do not use <strong>Lead</strong> for every conversation if the activity is already captured as a call record and does not need manual correction.</li>
<li>Do not leave <strong>Source</strong> vague. Choose the closest real source so reporting remains useful.</li>
<li>Do not use <strong>Estimated RM</strong> as confirmed revenue unless the entry is a valid off-system closed record.</li>
<li>Do not delete records just to hide dashboard counts. Correct the entry type, date, owner, or value if the record is valid but keyed wrongly.</li>
</ul>
<h3>Troubleshooting</h3>
<ul>
<li>If an entry does not appear in Monitoring, confirm the <strong>Entry date</strong> falls inside the selected monitoring month.</li>
<li>If an entry does not appear for a staff member, confirm the owner staff code matches the selected staff filter.</li>
<li>If <strong>Save Entries</strong> fails, check that every row has an entry type, entry date, source, and prospect name.</li>
<li>If a <strong>Closed</strong> entry fails, fill <strong>Service category</strong> and enter <strong>Estimated RM</strong> greater than zero.</li>
<li>If a proof image fails to upload, compress it below 500 KB or use a JPG, PNG, or WebP image.</li>
<li>If filters look wrong in <strong>Pipeline Entries</strong>, click <strong>Reset</strong> and reapply the period, type, owner, source, classification, or service filters.</li>
</ul>
HTML;
    }
};
