<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-create-legal-compliance-templates-and-manage-assessment-reports';

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
            'title' => 'How to Create Legal Compliance Templates and Manage Assessment Reports',
            'slug' => $this->slug,
            'summary' => 'Create legal compliance templates through legislation groups and clauses, publish usable versions, run assessments, submit reports, export PDFs, and manage report revisions.',
            'body_html' => $this->articleBody(),
            'category' => 'System',
            'tags' => json_encode([
                'legal-compliance',
                'assessment',
                'legal-assessment',
                'template',
                'legislation',
                'clause',
                'osh',
                'report',
                'revision',
                'pdf',
                'internal-tools',
            ]),
            'related_route' => '/internal-tools/legal-compliance/select-template',
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
            $remarks = 'Updated Legal Compliance guide with template creation, publishing, assessment, records, PDF export, and revision lifecycle.';
        } else {
            $articleId = (int) DB::table('knowledge_articles')->insertGetId($payload + [
                'created_by_staff_id' => null,
                'created_by_name_code' => 'SYSTEM',
                'created_at' => $now,
            ]);
            $action = 'created_published';
            $remarks = 'Production Legal Compliance guide seeded by system.';
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
<h3>What this module is for</h3>
<p>Use <strong>Legal Compliance Assessment</strong> to create structured legal assessment templates, run client or project assessments, review findings, submit locked reports, and export submitted reports as PDF.</p>
<ul>
<li><strong>Start</strong> is for creating a new legal compliance assessment from a published template.</li>
<li><strong>Records</strong> is for continuing drafts, reviewing saved reports, exporting submitted PDFs, creating revisions, and deleting records from the active list.</li>
<li><strong>Manage Templates</strong> is for Manager and System Admin users who maintain the reusable legal assessment template library.</li>
</ul>
<h3>Template permissions</h3>
<ul>
<li>Only <strong>Manager</strong> and <strong>System Admin</strong> users can create, edit, publish, set default, or delete legal compliance templates.</li>
<li>Normal users can use published templates when starting assessments, but they cannot manage template structure.</li>
<li>Draft templates and unpublished template changes are hidden from normal assessment selection.</li>
</ul>
<h3>Assessment record permissions</h3>
<ul>
<li>Normal users can view, update, revise, export, and delete their own assessment records where the action is allowed by the record stage.</li>
<li>Manager and System Admin users can view and manage assessment records created by other users.</li>
<li>Submitted reports are locked. To change a submitted report, create a revision instead of editing the submitted record directly.</li>
</ul>
<h3>Opening the Legal Compliance tools</h3>
<ol>
<li>Open <strong>Internal Tools</strong>.</li>
<li>Find <strong>Legal Compliance Assessment</strong>.</li>
<li>Click <strong>Start</strong> to begin a new assessment.</li>
<li>Click <strong>Records</strong> to manage saved assessment reports.</li>
<li>Open the card action menu and choose <strong>Manage Templates</strong> when you need to create or maintain assessment templates.</li>
</ol>
<h3>Creating a new template</h3>
<ol>
<li>Open <strong>Internal Tools</strong>.</li>
<li>Open <strong>Manage Templates</strong> for Legal Compliance Assessment.</li>
<li>Click <strong>New Template</strong>.</li>
<li>Enter the <strong>Template Name</strong>.</li>
<li>Add a short <strong>Description</strong> so users know when to use the template.</li>
<li>Choose the <strong>Assessment Tier</strong>: <strong>Free Assessment</strong> or <strong>Paid Assessment</strong>.</li>
<li>Confirm the <strong>Report Title</strong>. If left blank, the system uses the default title for the selected tier.</li>
<li>Confirm the <strong>Disclaimer Text</strong>. The system provides different defaults for free and paid assessments.</li>
<li>Save the template. The system creates it as a draft and opens the template editor.</li>
</ol>
<h3>Understanding free and paid templates</h3>
<ul>
<li><strong>Free Assessment</strong> templates are intended for preliminary compliance reviews.</li>
<li><strong>Paid Assessment</strong> templates are intended for formal paid assessment reports and can optionally be linked to an existing project when the assessment is started.</li>
<li>The tier controls which template list the user sees when starting an assessment.</li>
<li>The tier also controls the default report title and disclaimer text.</li>
</ul>
<h3>Adding legal groups</h3>
<p>The template creation flow is <strong>legal group</strong> to <strong>clause</strong> to <strong>publish template</strong>. In the UI, a legal group is shown as <strong>Legislation</strong>.</p>
<ol>
<li>Open the template in edit mode.</li>
<li>Click <strong>Add Legislation</strong>.</li>
<li>Enter the legislation name, for example <strong>Occupational Safety and Health Act 1994</strong>.</li>
<li>Click <strong>Save</strong>.</li>
<li>The legislation appears as a group tile with its clause count.</li>
<li>Click the legislation tile to open the clause editor.</li>
</ol>
<h3>Managing legal groups</h3>
<ul>
<li>Click the legislation tile to manage the clauses inside that group.</li>
<li>Use the pencil icon to rename the legislation.</li>
<li>Use the trash icon to delete the legislation and all clauses inside it.</li>
<li>If the template has unsaved changes, the system warns before leaving or moving into a group editor.</li>
<li>Use <strong>Save Template Draft</strong> regularly while building the structure.</li>
</ul>
<h3>Adding clauses inside a legal group</h3>
<ol>
<li>Open a legislation group.</li>
<li>Click <strong>Add Clause</strong>.</li>
<li>Enter the <strong>Clause Number and Title</strong>. This should include the legal reference and a readable title.</li>
<li>Enter the <strong>Description or Legal Text</strong>. This should be the excerpt or helper context the assessor needs while assessing.</li>
<li>Click <strong>Save Clause</strong>.</li>
<li>Repeat until the legislation group contains all required clauses.</li>
<li>Click <strong>Save Template Draft</strong> after clause changes.</li>
</ol>
<h3>What every clause asks during assessment</h3>
<p>Each clause automatically includes the required assessment response fields used later by assessors.</p>
<ul>
<li><strong>Compliance Status</strong> asks the assessor to choose <strong>Comply</strong> or <strong>Not comply</strong>.</li>
<li><strong>Assessment Finding</strong> asks the assessor to write the finding for that clause.</li>
<li>Both fields are required before the assessment can move into report review.</li>
</ul>
<h3>Editing or removing clauses</h3>
<ul>
<li>Click a clause tile or its pencil icon to edit the clause title and legal text.</li>
<li>Click <strong>Save Clause</strong> after editing.</li>
<li>Use the trash icon to remove an unwanted clause.</li>
<li>If a clause form is open, save or cancel it before saving the template draft.</li>
<li>Deleting a clause from a draft affects only the draft until the template is published.</li>
</ul>
<h3>Saving draft versus publishing</h3>
<ul>
<li><strong>Save Template Draft</strong> saves the working draft only.</li>
<li><strong>Publish Template</strong> creates a new published version that users can select when starting assessments.</li>
<li>New assessments keep using the last published version until the new draft is published.</li>
<li>Existing assessments keep their own template snapshot and are not rewritten by later template changes.</li>
</ul>
<h3>Publishing a template</h3>
<ol>
<li>Open the template in edit mode.</li>
<li>Confirm all legislation groups and clauses are complete.</li>
<li>Click <strong>Publish Template</strong>.</li>
<li>If validation issues appear, fix them before publishing.</li>
<li>Enter <strong>What changed in this version</strong> so the change history explains the update.</li>
<li>Click <strong>Confirm</strong>.</li>
<li>The system creates the next template version and makes it the active version.</li>
</ol>
<h3>Publish validation rules</h3>
<ul>
<li>The template must have at least one legislation.</li>
<li>Every legislation must have a name.</li>
<li>Every legislation must have at least one clause.</li>
<li>Every clause must have a title.</li>
<li>Every clause must have description or legal text.</li>
<li>Duplicate clause titles inside the same legislation are not allowed.</li>
</ul>
<h3>Template lifecycle management</h3>
<ul>
<li><strong>Edit Template</strong> opens the template structure for draft changes.</li>
<li><strong>Rename</strong> changes the template name and keeps the template content aligned with the new name.</li>
<li><strong>Duplicate</strong> copies an existing template into a new draft template. Use this when a new template is similar to an existing one.</li>
<li><strong>Delete</strong> removes a template only when it is not the default and no assessment records already use it.</li>
<li><strong>Change History</strong> shows published versions, changed by, published time, and the publish change note.</li>
</ul>
<h3>Important template rules</h3>
<ul>
<li>A draft template is not available in <strong>Start</strong> until it is published.</li>
<li>A template with draft changes can still have an older active published version.</li>
<li>Assessments are linked to the template version and a saved snapshot, so the report stays stable even if the template changes later.</li>
<li>The default template is used when the system needs a fallback published template.</li>
<li>The backend supports setting another published template as default, but the current template list does not expose a visible default-switch action.</li>
</ul>
<h3>Starting a new assessment</h3>
<ol>
<li>Open <strong>Internal Tools</strong>.</li>
<li>Click <strong>Start</strong> under Legal Compliance Assessment.</li>
<li>Choose <strong>Free Assessment</strong> or <strong>Paid Assessment</strong>.</li>
<li>Choose a published template from the matching tier list.</li>
<li>For a paid assessment, choose either <strong>Continue Without Project</strong> or <strong>Connect Existing Project</strong>.</li>
<li>If connecting a project, select the project before starting.</li>
<li>The system opens the assessment form using the selected template version.</li>
</ol>
<h3>Using a paid assessment with a project</h3>
<ul>
<li>Use <strong>Connect Existing Project</strong> when the legal compliance assessment belongs to an existing project record.</li>
<li>The system pulls project/client details where available, including client name, project name, address, and PIC information.</li>
<li>Projects with unavailable statuses such as terminated, deleted, or cancelled cannot be used for paid assessments.</li>
<li>If no project is selected, a paid assessment can still continue without project linkage.</li>
</ul>
<h3>Saving assessment details</h3>
<ol>
<li>If the assessment is not linked to a project, select an <strong>Assessment Client</strong> from CRM.</li>
<li>If the client does not exist yet, use the create-client action from the client selector and return to the assessment afterwards.</li>
<li>Review the populated company, address, client PIC name, and client PIC email.</li>
<li>Enter the <strong>Assessment Date</strong>.</li>
<li>Enter the <strong>Nature of Company</strong>.</li>
<li>Click <strong>Save Assessment Draft</strong>.</li>
<li>The assessment moves from initial details entry into the clause response stage.</li>
</ol>
<h3>Draft recovery and autosave</h3>
<ul>
<li>Before the first server save, the page keeps a local draft in the browser.</li>
<li>After the assessment has a saved record id, the page autosaves later changes after a short delay.</li>
<li>If a save fails, the latest changes are still backed up locally where possible.</li>
<li>Local draft data is cleared when a report is submitted or when a fresh assessment is started from the template selector.</li>
</ul>
<h3>Completing clause responses</h3>
<ol>
<li>Work through each legislation accordion section.</li>
<li>Read the clause title and legal text.</li>
<li>Choose <strong>Comply</strong> or <strong>Not comply</strong>.</li>
<li>Write the <strong>Assessment Finding</strong> for the clause.</li>
<li>Use the progress line to monitor total clauses, completed clauses, comply count, and not comply count.</li>
<li>Click <strong>Save Assessment Draft</strong> to persist progress manually.</li>
<li>Click <strong>Review Report</strong> when all required clause fields are complete.</li>
</ol>
<h3>Reviewing the report</h3>
<ul>
<li><strong>Review Report</strong> saves the assessment as review-ready and opens the report preview.</li>
<li>If required fields are missing, the system opens the first incomplete legislation section and asks you to complete all required clause fields.</li>
<li>The review page shows assessment details, project details where applicable, compliance status badges, and findings.</li>
<li>Use <strong>Edit Form</strong> if the details or clause responses need correction before submission.</li>
<li>Use <strong>Save Assessment Draft</strong> to keep the report in review-ready state without submitting.</li>
</ul>
<h3>Submitting a report</h3>
<ol>
<li>Open the report review page.</li>
<li>Confirm that assessment details and findings are correct.</li>
<li>Click <strong>Submit Report</strong>.</li>
<li>Confirm submission in the modal.</li>
<li>The system saves the record as submitted, clears local draft backup, and returns to <strong>Records</strong>.</li>
<li>Submitted reports are locked from direct editing.</li>
</ol>
<h3>Opening assessment records</h3>
<ol>
<li>Open <strong>Internal Tools</strong>.</li>
<li>Click <strong>Records</strong> under Legal Compliance Assessment.</li>
<li>Use search to find records by company, address, client PIC, assessment date, assessor, stage, template, project, revision, creator, or updated date.</li>
<li>Use column controls to show or hide fields.</li>
<li>Use CSV export when you need a spreadsheet of visible records.</li>
</ol>
<h3>Reading assessment record stages</h3>
<ul>
<li><strong>Details Saved</strong> means the assessment details are saved and clause responses can continue.</li>
<li><strong>Review Ready</strong> means the required clause responses were completed and the report preview was saved.</li>
<li><strong>Submitted</strong> means the report was finalized and locked.</li>
<li>The records list hides superseded records when a newer active revision exists.</li>
</ul>
<h3>Record actions</h3>
<ul>
<li><strong>Continue Assessment</strong> opens a details-saved record for editing.</li>
<li><strong>Review Report</strong> opens a review-ready record in report preview mode.</li>
<li><strong>View Submitted Report</strong> opens a submitted report in preview mode.</li>
<li><strong>Edit Assessment</strong> is available for unsubmitted records.</li>
<li><strong>Create Revision</strong> is available for submitted reports.</li>
<li><strong>Export Report PDF</strong> is available only for submitted reports.</li>
<li><strong>Delete</strong> hides the record from the active records list while keeping audit history.</li>
</ul>
<h3>Creating a revision</h3>
<ol>
<li>Open <strong>Records</strong>.</li>
<li>Find a submitted report.</li>
<li>Open the row action menu and choose <strong>Create Revision</strong>.</li>
<li>The system creates a new editable assessment record using the same template snapshot and previous responses.</li>
<li>Edit the revised record as needed.</li>
<li>Review and submit the revision when complete.</li>
<li>After the revision is submitted, the older submitted report is superseded by the new one.</li>
</ol>
<h3>Exporting PDF reports</h3>
<ul>
<li>Only submitted reports can be exported to PDF.</li>
<li>Use <strong>Export PDF</strong> from the submitted report view or <strong>Export Report PDF</strong> from the records action menu.</li>
<li>The PDF uses the submitted assessment record, the saved template snapshot, selected assessors, findings, report title, and disclaimer text.</li>
<li>The PDF export is permission-checked. You must own the record or have Manager/System Admin access.</li>
</ul>
<h3>Deleting records</h3>
<ul>
<li>Deleting an assessment record performs a soft delete.</li>
<li>The record is hidden from the records list but kept for audit history.</li>
<li>Normal users can delete their own records where permitted.</li>
<li>Manager and System Admin users can delete records created by other users.</li>
<li>Do not delete a report if the history is still operationally important. Use revisions for corrections.</li>
</ul>
<h3>Common mistakes to avoid</h3>
<ul>
<li>Do not expect a draft template to appear in assessment selection. Publish it first.</li>
<li>Do not assume saving a template draft updates active assessments. Only publishing creates a usable version.</li>
<li>Do not rename a clause into a duplicate title within the same legislation.</li>
<li>Do not submit a report before reviewing the client, date, nature of company, and findings.</li>
<li>Do not try to directly edit a submitted report. Create a revision instead.</li>
<li>Do not delete a template that assessment records already use; the system blocks this to protect report history.</li>
<li>Do not treat a free assessment report as a full statutory audit unless the template and business process explicitly support that scope.</li>
</ul>
<h3>Troubleshooting</h3>
<ul>
<li>If no template appears when starting an assessment, confirm that the template has been published and matches the selected tier.</li>
<li>If publishing fails, read the validation messages and check missing legislation names, missing clauses, missing clause descriptions, or duplicate clause titles.</li>
<li>If assessment details cannot be saved, select a CRM client unless the assessment is already linked to a project.</li>
<li>If <strong>Review Report</strong> fails, complete every required compliance status and assessment finding field.</li>
<li>If PDF export is unavailable, confirm that the report has been submitted.</li>
<li>If you cannot open or edit another person's record, check whether your account has Manager or System Admin access.</li>
<li>If a submitted report needs correction, create a revision and submit the revised report.</li>
</ul>
HTML;
    }
};
