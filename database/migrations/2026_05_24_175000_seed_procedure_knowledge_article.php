<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-upload-and-manage-standard-operating-procedures';

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
            'title' => 'How to Upload and Manage Standard Operating Procedures',
            'slug' => $this->slug,
            'summary' => 'Upload SOP PDF files, classify them by category, search and filter the procedure library, preview PDFs, replace files, and manage owner-only edits and deletes.',
            'body_html' => $this->articleBody(),
            'category' => 'System',
            'tags' => json_encode([
                'procedure',
                'procedures',
                'sop',
                'standard-operating-procedure',
                'administration',
                'pdf',
                'upload',
                'category',
                'it',
                'osh',
                'hr',
                'finance',
                'operation',
                'sales',
                'marketing',
                'owner',
            ]),
            'related_route' => '/administration/procedures',
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
            $remarks = 'Updated Procedure guide with SOP upload, list search, PDF preview, edit, replacement, delete, and owner permission lifecycle.';
        } else {
            $articleId = (int) DB::table('knowledge_articles')->insertGetId($payload + [
                'created_by_staff_id' => null,
                'created_by_name_code' => 'SYSTEM',
                'created_at' => $now,
            ]);
            $action = 'created_published';
            $remarks = 'Production Procedure guide seeded by system.';
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
<h3>What Procedures is for</h3>
<p>Use <strong>Procedures</strong> to keep company standard operating procedures, work instructions, and process documents in a searchable PDF library.</p>
<ul>
<li>Use it when the official procedure already exists as a PDF and should be available inside KIJO.</li>
<li>Use categories to separate SOPs by department or operating area.</li>
<li>Use the PDF preview page to read the document without downloading it first.</li>
<li>This module stores the procedure document itself. It does not create a rich-text SOP from scratch.</li>
</ul>
<h3>Opening Procedures</h3>
<ol>
<li>Open <strong>Administration</strong> from the sidebar or module navigation.</li>
<li>Click <strong>Procedures</strong>.</li>
<li>The <strong>Standard Operating Procedures</strong> list opens.</li>
<li>Click <strong>Create New</strong> to upload a new procedure PDF.</li>
</ol>
<h3>Understanding the procedure lifecycle</h3>
<ul>
<li><strong>Create New</strong> uploads a new SOP record with title, category, description, and PDF file.</li>
<li><strong>View</strong> opens the procedure detail page and previews the attached PDF.</li>
<li><strong>Edit</strong> lets the owner update the title, description, category, and optionally replace the PDF.</li>
<li><strong>Delete</strong> removes the procedure record and its uploaded file.</li>
<li>Only the creator of a procedure can edit or delete it.</li>
</ul>
<h3>Creating a procedure</h3>
<ol>
<li>Open <strong>Administration</strong> then <strong>Procedures</strong>.</li>
<li>Click <strong>Create New</strong>.</li>
<li>Enter <strong>Title of Procedure</strong>. Use a clear title that people will search for later.</li>
<li>Choose the correct <strong>Category</strong>.</li>
<li>Enter <strong>Brief description of this procedure</strong>. Summarize the purpose, scope, or when the SOP should be used.</li>
<li>Click <strong>Upload PDF file</strong> and choose the procedure PDF.</li>
<li>Review the selected file name and file size shown below the file input.</li>
<li>Click <strong>Upload Procedure</strong>.</li>
</ol>
<h3>Required fields when uploading</h3>
<ul>
<li><strong>Title of Procedure</strong> is required.</li>
<li><strong>Brief description</strong> is required.</li>
<li><strong>Category</strong> is required.</li>
<li><strong>Upload PDF file</strong> is required when creating a new procedure.</li>
<li>The file must be a PDF.</li>
<li>The PDF must be smaller than 10 MB.</li>
</ul>
<h3>Choosing the right category</h3>
<ul>
<li><strong>IT</strong> is for system, device, account, security, or technology procedures.</li>
<li><strong>OSH</strong> is for occupational safety and health procedures.</li>
<li><strong>HR</strong> is for people, staff, leave, onboarding, or HR process procedures.</li>
<li><strong>Finance</strong> is for finance, payment, claim, invoice, or accounting procedures.</li>
<li><strong>Operation</strong> is for operational work instructions or delivery processes.</li>
<li><strong>Sales</strong> is for quotation, proposal, negotiation, or sales workflow procedures.</li>
<li><strong>Marketing</strong> is for lead generation, call, pipeline, campaign, or marketing procedures.</li>
<li><strong>Others</strong> is for procedures that do not fit the listed categories.</li>
</ul>
<h3>After upload succeeds</h3>
<ul>
<li>The system stores the PDF under the procedure upload area for the current year.</li>
<li>The original file name is saved and shown in the procedure detail page.</li>
<li>The creator name and creator code are saved with the record.</li>
<li>The create page shows a success message after the backend accepts the upload.</li>
<li>Return to <strong>Procedures</strong> to confirm the new record appears in the list.</li>
</ul>
<h3>Using the procedure list</h3>
<ul>
<li>The list shows <strong>Title</strong>, <strong>Category</strong>, <strong>Brief Description</strong>, <strong>Date</strong>, and <strong>Created By</strong>.</li>
<li>Click a row to open the procedure detail page.</li>
<li>Use <strong>Columns</strong> to choose which list fields are visible.</li>
<li>Use <strong>Export</strong> to download the currently visible list as CSV.</li>
<li>The list sorts by newest procedure first by default.</li>
</ul>
<h3>Searching and filtering procedures</h3>
<ul>
<li>Use the main search bar to search by title, description, creator name, or creator code.</li>
<li>Use <strong>Filter By Created By</strong> to narrow records to a staff name or name code.</li>
<li>Use <strong>Filter By Category</strong> to show one SOP category.</li>
<li>Use the period filter to show procedures created within a specific date range.</li>
<li>Use <strong>Reset</strong> if the list looks empty because filters are too narrow.</li>
</ul>
<h3>Viewing a procedure</h3>
<ol>
<li>Open <strong>Administration</strong> then <strong>Procedures</strong>.</li>
<li>Click the procedure row.</li>
<li>The detail page shows procedure title, type/category, creation date, creator, file name, and description.</li>
<li>The attached PDF appears in the preview panel when a file exists.</li>
<li>Use the browser PDF toolbar to zoom, search inside the PDF, print, or download if your browser supports those controls.</li>
<li>Click <strong>Back</strong> to return to the list.</li>
</ol>
<h3>Editing a procedure</h3>
<ol>
<li>Open the procedure from the list.</li>
<li>Click <strong>Edit</strong> from the detail page actions, or use the row action menu from the list.</li>
<li>Update <strong>Title of Procedure</strong>, <strong>Category</strong>, or <strong>Brief description</strong>.</li>
<li>Use <strong>Replace PDF file</strong> only when the attached PDF must be replaced.</li>
<li>If you do not choose a new PDF, the current file remains attached.</li>
<li>Click <strong>Save Changes</strong>.</li>
</ol>
<h3>Editing rules</h3>
<ul>
<li>Only the owner who created the procedure can edit it.</li>
<li>The title, description, and category are still required during edit.</li>
<li>Replacing the PDF is optional during edit.</li>
<li>If a replacement file is selected, it must be a PDF below 10 MB.</li>
<li>After replacement succeeds, the old uploaded file is deleted by the backend.</li>
</ul>
<h3>Using Reset and Back while editing</h3>
<ul>
<li>Click <strong>Reset</strong> to return the form to the values loaded from the server.</li>
<li>Reset also clears a newly selected replacement PDF.</li>
<li>Click <strong>Back</strong> to return to the procedure detail page when an id is available.</li>
<li>If the procedure id is missing, <strong>Back</strong> returns to the procedure list.</li>
</ul>
<h3>Deleting a procedure</h3>
<ol>
<li>Find the procedure in the list or open its detail page.</li>
<li>Open the action menu or detail actions.</li>
<li>Click <strong>Delete</strong>.</li>
<li>Confirm the deletion prompt.</li>
<li>The backend deletes the procedure record and removes the stored PDF file.</li>
</ol>
<h3>Delete permissions</h3>
<ul>
<li>Only the owner who created the procedure can delete it.</li>
<li>Other users can view the procedure but their <strong>Edit</strong> and <strong>Delete</strong> actions are disabled.</li>
<li>There is no approval workflow or archive state in the current procedure lifecycle.</li>
<li>Delete is permanent for this module, so confirm the SOP is obsolete or uploaded wrongly before deleting.</li>
</ul>
<h3>What to include in the PDF</h3>
<ul>
<li>Include the SOP title, owner or department, purpose, scope, process steps, responsibilities, forms used, and revision date where possible.</li>
<li>Use a readable PDF file name before upload because the original file name is shown in the detail page.</li>
<li>Keep the PDF below 10 MB. Compress the PDF externally if it is too large.</li>
<li>When updating an SOP version, replace the PDF and update the description to explain the new scope or revision.</li>
</ul>
<h3>Common mistakes to avoid</h3>
<ul>
<li>Do not upload Word, image, spreadsheet, or scanned files that are not saved as PDF.</li>
<li>Do not leave the description too generic. It should help users know whether the SOP is relevant.</li>
<li>Do not choose <strong>Others</strong> when a specific department category fits.</li>
<li>Do not expect another user to edit or delete your procedure; owner permissions block that.</li>
<li>Do not delete an SOP just to upload a new version if the same record should remain searchable. Use <strong>Edit</strong> and replace the PDF instead.</li>
</ul>
<h3>Troubleshooting</h3>
<ul>
<li>If upload fails, confirm all required fields are filled and the file is a PDF below 10 MB.</li>
<li>If a procedure does not appear in the list, clear search, category, creator, and period filters.</li>
<li>If <strong>Edit</strong> or <strong>Delete</strong> is disabled, you are not the procedure owner.</li>
<li>If the PDF preview is blank, open the file link in a new tab or refresh the page. Some browsers block inline PDF preview controls.</li>
<li>If replacement fails, confirm the new file is a valid PDF below 10 MB.</li>
<li>If a deleted procedure still appears briefly, refresh the procedure list.</li>
</ul>
HTML;
    }
};
