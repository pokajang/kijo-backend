<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-manage-projects';

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
            'title' => 'How to Manage Projects',
            'slug' => $this->slug,
            'summary' => 'Use Project Overview to find projects, update details, track progress, assign vendors, manage commercial records, review finance, and close the project lifecycle.',
            'body_html' => $this->articleBody(),
            'category' => 'Projects',
            'tags' => json_encode(['project', 'project-management', 'progress', 'vendor', 'invoice', 'delivery-order', 'jd14', 'finance']),
            'related_route' => '/project/manage',
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
            $remarks = 'Expanded project management guide with the current lifecycle and endpoint-backed module flow.';
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
<h3>Opening Project Overview</h3>
<ol>
<li>Open <strong>Project Overview</strong> from the navigation.</li>
<li>The page loads project records for the current award year.</li>
<li>Use the period selector if you need to view a different date range inside the loaded year.</li>
<li>Click <strong>Create Project</strong> only when a project must be created directly instead of from <strong>Quotation Records</strong> &gt; <strong>Awarded</strong>.</li>
</ol>
<h3>Reading the project list</h3>
<ul>
<li><strong>Total Value</strong> shows the total project value for the currently filtered rows. Terminated project value is excluded from the main total.</li>
<li><strong>Active</strong> shows the number of active projects in the filtered rows.</li>
<li><strong>Needs Update</strong> highlights active projects with no progress update or no recent update for more than 14 days.</li>
<li><strong>Top Leader</strong> shows the project leader with the highest filtered project value.</li>
</ul>
<h3>Finding a project</h3>
<ul>
<li>Use the search box to search by client, project name, project type, status, PO/LOA number, staff, vendor, or progress update.</li>
<li>Use the <strong>Project Type</strong> filter to narrow the list by service type.</li>
<li>Use the <strong>Project Leader</strong> filter to find projects assigned to a specific leader.</li>
<li>Use the <strong>Status</strong> filter to focus on <strong>Active</strong>, <strong>Completed</strong>, or <strong>Terminated</strong> projects.</li>
<li>Use <strong>Has Progress Update</strong> to find projects with or without update logs.</li>
<li>Use <strong>Has Vendors</strong> to find projects with or without vendor assignments.</li>
<li>Use <strong>Min Value (RM)</strong> and <strong>Max Value (RM)</strong> to filter by project value.</li>
<li>Use <strong>Reset</strong> to clear active filters.</li>
</ul>
<h3>Opening a project detail page</h3>
<ol>
<li>Click a project row in <strong>Project Overview</strong>.</li>
<li>The system opens the project detail page.</li>
<li>Use <strong>Back</strong> to return to <strong>Project Overview</strong>.</li>
</ol>
<h3>Reviewing client and quotation context</h3>
<ul>
<li><strong>Client Details</strong> shows the client name, address, and assigned company contact.</li>
<li><strong>CRM Trails</strong> shows quotation reference, quotation status, issuer, award date, and days to award when the project is linked to a quotation.</li>
<li>If the project was created directly without a quotation link, <strong>CRM Trails</strong> may show that official quotation data is missing.</li>
<li><strong>Commercial Trails</strong> shows linked commercial records such as invoices, delivery orders, JD14 forms, vendor LOAs, vendor payments, and supplier POs.</li>
</ul>
<h3>Updating project details</h3>
<ol>
<li>Open <strong>Project Details</strong>.</li>
<li>Click <strong>Edit</strong>.</li>
<li>Update <strong>Project Name</strong>, <strong>LOA/PO Number</strong>, <strong>Type</strong>, <strong>Award Date</strong>, <strong>Service Start Date</strong>, <strong>Service End Date</strong>, or <strong>Description</strong>.</li>
<li>If the project is linked to a quotation and the LOA/PO number is empty, use <strong>Reload PO Number</strong> to pull the number from the quotation.</li>
<li>Click <strong>Save</strong>.</li>
<li>Confirm <strong>Save changes to project details?</strong>.</li>
<li>Use <strong>Cancel</strong> to discard unsaved edits.</li>
</ol>
<h3>Tracking progress updates</h3>
<ol>
<li>Open <strong>Project Progress Tracking</strong>.</li>
<li>Click <strong>Update</strong>.</li>
<li>Choose the actual <strong>Event Date</strong>.</li>
<li>Write the progress note in <strong>Update Details</strong>.</li>
<li>Click <strong>Add Update</strong>.</li>
<li>Use the row action menu to <strong>Edit</strong> or <strong>Delete</strong> an existing progress update.</li>
<li>Turn on <strong>Show more rows</strong> when more than five updates exist.</li>
</ol>
<h3>Understanding progress timing</h3>
<ul>
<li><strong>Logged</strong> shows when the update was saved.</li>
<li><strong>Event</strong> shows the event date entered by the user.</li>
<li><strong>Delta</strong> shows the day gap from the previous progress update.</li>
<li><strong>Cum.</strong> shows the cumulative days from the first progress update.</li>
<li><strong>By</strong> shows the staff code that saved the update.</li>
</ul>
<h3>Assigning vendors</h3>
<ol>
<li>Open <strong>Vendor Details</strong>.</li>
<li>Click <strong>Assign Vendor</strong>.</li>
<li>Select the vendor from <strong>Assign Vendor</strong>.</li>
<li>If the vendor does not exist, use <strong>Create one?</strong> to create the vendor record first.</li>
<li>Enter <strong>Sum Professional Fee (RM)</strong>.</li>
<li>Select <strong>Payment Terms</strong>.</li>
<li>Fill <strong>Position</strong>, <strong>Services Description</strong>, <strong>Venue Details</strong>, <strong>Fee Breakdown</strong>, and <strong>Remarks (If Any)</strong> when the LOA needs those details.</li>
<li>Click <strong>Confirm Award</strong>.</li>
</ol>
<h3>Managing vendor LOAs</h3>
<ul>
<li>Use <strong>Edit LOA</strong> from the vendor row action menu to update vendor assignment details.</li>
<li>Click <strong>Save Changes</strong> after editing a vendor assignment.</li>
<li>Use <strong>Generate LOA</strong> to open the vendor LOA PDF.</li>
<li>Use <strong>Remove Vendor</strong> only when the vendor assignment should be removed from the project.</li>
<li>Vendor assignment, edit, and removal actions add project progress notes automatically.</li>
</ul>
<h3>Generating invoices</h3>
<ol>
<li>Click <strong>Generate Invoice</strong> from the project action menu or action section.</li>
<li>Review any <strong>Existing Commercial Records</strong> warning before continuing.</li>
<li>Review the invoice client details, project details, payment method, payment terms, LOA number, pricing, and breakdown.</li>
<li>For <strong>Training</strong> projects using <strong>HRD Grant</strong>, enter the HRD grant approval number when required.</li>
<li>Click <strong>Create Invoice</strong>.</li>
<li>After creation, choose whether to go to the invoice list.</li>
</ol>
<h3>Invoice limitations for direct-created projects</h3>
<ul>
<li>Most project types need linked quotation data before an invoice can be generated.</li>
<li>Projects created directly without a quotation link may not have quote data for invoice pricing.</li>
<li><strong>Manpower Supply</strong> supports manual invoice creation without a quote reference.</li>
<li>For other project types, confirm whether the project should have been created from <strong>Quotation Records</strong> &gt; <strong>Awarded</strong> before attempting invoice generation.</li>
</ul>
<h3>Generating delivery orders</h3>
<ol>
<li>Click <strong>Generate DO</strong>.</li>
<li>Review any warning for existing commercial records.</li>
<li>Review <strong>Delivery Details</strong>, <strong>Project Details</strong>, and <strong>Items Details</strong>.</li>
<li>For <strong>Equipment Supply</strong> projects, item lines may be loaded from quotation equipment items or the latest invoice breakdown.</li>
<li>Add or edit item rows if needed.</li>
<li>Click <strong>Generate DO</strong>.</li>
<li>If a delivery order already exists, confirm only when another delivery order is really needed.</li>
</ol>
<h3>Generating JD14 forms</h3>
<ul>
<li><strong>Generate JD14</strong> appears for <strong>Training</strong> projects.</li>
<li>Review <strong>Employer Details</strong>, including employer name, address, approval number, approved group, and claimed group.</li>
<li>Review <strong>Training Details</strong>, including topic, commenced date, end date, venue, number of pax, amount approved, and amount claimed.</li>
<li>Click <strong>Generate JD14</strong>.</li>
<li>After creation, choose whether to go to the JD14 list.</li>
</ul>
<h3>Reviewing vendor payments</h3>
<ul>
<li>Open <strong>Vendor Payments</strong> to review payment requests linked to the project.</li>
<li>The table shows vendor, request date, approval date, payment context, payment type, method, status, and amount.</li>
<li>Use <strong>Pay Vendor</strong> to open the vendor payment workflow.</li>
<li>The payment grand total is shown at the bottom of the table.</li>
</ul>
<h3>Reviewing profit and loss</h3>
<ul>
<li>Open <strong>Profit &amp; Loss Summary</strong>.</li>
<li>Project revenue comes from the project value.</li>
<li>Approved, paid, completed, and transferred vendor payments are treated as confirmed cost.</li>
<li>Pending vendor payments are used for projected cost.</li>
<li>Manual project expenses are added to the cost total.</li>
<li><strong>Confirmed Net Profit</strong> uses confirmed vendor payments and manual expenses.</li>
<li><strong>Projected Net Profit</strong> also includes pending vendor payments.</li>
</ul>
<h3>Adding project expenses</h3>
<ol>
<li>Open <strong>Profit &amp; Loss Summary</strong>.</li>
<li>Click <strong>Add Expense</strong>.</li>
<li>Choose the expense date.</li>
<li>Enter the amount.</li>
<li>Add remarks if needed.</li>
<li>Attach a PDF, JPG, JPEG, or PNG receipt if available.</li>
<li>Click <strong>Save Expense</strong>.</li>
<li>Use the expense action to view the receipt or delete the expense.</li>
</ol>
<h3>Adding collaborators</h3>
<ol>
<li>Open <strong>Collaborators</strong>.</li>
<li>Click <strong>Add Collaborator</strong>.</li>
<li>Select a staff member in <strong>Assign Staff</strong>.</li>
<li>Choose a <strong>Role</strong>: <strong>Leader</strong>, <strong>Assistant</strong>, or <strong>Collaborator</strong>.</li>
<li>Use <strong>Role Description</strong> to describe the staff member's responsibility.</li>
<li>Click <strong>Add Staff</strong>.</li>
<li>Use <strong>Remove Collaborator</strong> from the row action menu when a staff member should no longer be assigned.</li>
</ol>
<h3>Collaborator rules</h3>
<ul>
<li>A project can have only one <strong>Leader</strong>.</li>
<li>Once a leader is assigned, the <strong>Leader</strong> option is hidden for new collaborator assignments.</li>
<li>Adding or removing collaborators adds project progress notes automatically.</li>
<li>The <strong>My</strong> tab in <strong>Project Overview</strong> shows projects assigned to the signed-in user.</li>
</ul>
<h3>Completing a project</h3>
<ol>
<li>Click <strong>Complete Project</strong>.</li>
<li>Choose the <strong>Closing Date</strong>.</li>
<li>Confirm all closure checks: <strong>All claims received</strong>, <strong>All vendors paid</strong>, and <strong>All due services completed</strong>.</li>
<li>Enter <strong>Closure Remarks</strong>.</li>
<li>Click <strong>Complete Project</strong>.</li>
<li>The project status changes to <strong>Completed</strong>, and the system records a closure entry and progress note.</li>
</ol>
<h3>Terminating a project</h3>
<ol>
<li>Click <strong>Terminate Project</strong>.</li>
<li>Choose the <strong>Closing Date</strong>.</li>
<li>Enter the <strong>Termination Cause</strong>.</li>
<li>Click <strong>Terminate Project</strong>.</li>
<li>The project status changes to <strong>Terminated</strong>, and the system records a closure entry and progress note.</li>
</ol>
<h3>Deleting a project</h3>
<ul>
<li>Use <strong>Delete Project</strong> only when the project record was created incorrectly and should be permanently removed.</li>
<li>Deletion is blocked when the project is referenced by invoices.</li>
<li>Deletion is blocked when the project is referenced by delivery orders.</li>
<li>When deletion is allowed, the system removes related project closing details, collaborators, progress updates, and vendor assignments.</li>
<li>If the project is linked to a quotation, deleting it can reset the linked quotation status to <strong>Open</strong>.</li>
</ul>
HTML;
    }
};
