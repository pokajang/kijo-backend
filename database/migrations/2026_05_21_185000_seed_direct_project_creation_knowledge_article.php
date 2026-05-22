<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-create-a-project-directly';

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
            'title' => 'How to Create a Project Directly',
            'slug' => $this->slug,
            'summary' => 'Create a project record directly when it does not come from Quotation Records through the Awarded workflow.',
            'body_html' => $this->articleBody(),
            'category' => 'Projects',
            'tags' => json_encode(['project', 'direct-project', 'quotation', 'awarded', 'project-management']),
            'related_route' => '/project/create',
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
            $remarks = 'Expanded direct project creation guide with the current module flow.';
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
<h3>When to use direct project creation</h3>
<ul>
<li>Use this page only when the project does not come through the normal <strong>Quotation &gt; Records &gt; Awarded</strong> workflow.</li>
<li>Use it for complex projects, special proposal stages, or operational work where no official quotation record was issued in the system.</li>
<li>If an official quotation exists, create the project from <strong>Quotation Records</strong> by using the <strong>Awarded</strong> function instead.</li>
<li>Direct-created projects are not linked to quotation records, so <strong>CRM Trails</strong> may show that official quotation data is missing.</li>
</ul>
<h3>Starting the project record</h3>
<ol>
<li>Open <strong>Project Overview</strong>.</li>
<li>Click <strong>Create Project</strong> at the top-right of the project list.</li>
<li>Read the warning banner before continuing.</li>
<li>Click <strong>Back</strong> if the project should be created from an awarded quotation instead.</li>
</ol>
<h3>Filling project details</h3>
<ul>
<li>Select the customer in <strong>Client</strong>. If the client is not available, create or update the client from the client records first.</li>
<li>Select the correct <strong>Project Type</strong>: <strong>Manpower Supply</strong>, <strong>Equipment Supply</strong>, <strong>Special</strong>, <strong>Training</strong>, or <strong>Industrial Hygiene</strong>.</li>
<li>Enter a clear name in <strong>Project Name</strong>.</li>
<li>Enter the customer reference in <strong>Purchase Order / LOA Number</strong> when available.</li>
<li>Enter the expected amount in <strong>Project Value (RM)</strong>.</li>
<li>Choose the <strong>Award Date</strong>. This is important because the project list defaults to the current award year.</li>
<li>Choose <strong>Service Start Date</strong> and <strong>Service End Date</strong> if the project period is known.</li>
<li>Use <strong>Project Description</strong> to capture scope, proposal context, or why the project was created directly.</li>
</ul>
<h3>Saving the project</h3>
<ol>
<li>Click <strong>Create Project</strong>.</li>
<li>Confirm <strong>Are you sure you want to create this project?</strong>.</li>
<li>After saving, choose <strong>Go to list</strong> to return to <strong>Project Overview</strong>.</li>
<li>Choose <strong>Create another</strong> only when you need to add another direct project immediately.</li>
</ol>
<h3>What happens after saving</h3>
<ul>
<li>The project is created with <strong>Active</strong> status.</li>
<li>The system records the current staff member as the creator.</li>
<li>The system adds an automatic progress note: <strong>Project started without linking to a quotation record.</strong></li>
<li>The project appears in <strong>Project Overview</strong> when its <strong>Award Date</strong> matches the selected list period.</li>
<li>Because the project has no quotation link, <strong>CRM Trails</strong> may display a missing official quotation message.</li>
</ul>
<h3>Opening and updating the project</h3>
<ol>
<li>Open <strong>Project Overview</strong>.</li>
<li>Search or filter the list by client, project name, project type, status, project leader, vendor, update, or value.</li>
<li>Click the project row to open the project detail page.</li>
<li>Use <strong>Project Details</strong> &gt; <strong>Edit</strong> to update project name, LOA/PO number, type, award date, service dates, or description.</li>
<li>Click <strong>Save</strong> to keep the updated project details.</li>
</ol>
<h3>Setting up the project team</h3>
<ul>
<li>Use <strong>Collaborators</strong> &gt; <strong>Add Collaborator</strong> to assign staff to the project.</li>
<li>Select the staff member in <strong>Assign Staff</strong>.</li>
<li>Choose a <strong>Role</strong>: <strong>Leader</strong>, <strong>Assistant</strong>, or <strong>Collaborator</strong>.</li>
<li>Use <strong>Role Description</strong> to describe the staff member's responsibility.</li>
<li>Only one <strong>Leader</strong> can be assigned to a project.</li>
<li>Click <strong>Add Staff</strong> to save the collaborator.</li>
</ul>
<h3>Tracking project progress</h3>
<ul>
<li>Use <strong>Project Progress Tracking</strong> &gt; <strong>Update</strong> to add a progress note.</li>
<li>Choose the actual <strong>Event Date</strong>.</li>
<li>Write the update in <strong>Update Details</strong>.</li>
<li>Click <strong>Add Update</strong>.</li>
<li>Use the progress row action menu to <strong>Edit</strong> or <strong>Delete</strong> a progress update.</li>
<li>The progress table calculates day gaps between updates and the cumulative project timeline.</li>
</ul>
<h3>Assigning vendors</h3>
<ol>
<li>Open <strong>Vendor Details</strong>.</li>
<li>Click <strong>Assign Vendor</strong>.</li>
<li>Select the vendor in <strong>Assign Vendor</strong>. If no vendor exists, create the vendor first.</li>
<li>Enter <strong>Sum Professional Fee (RM)</strong>.</li>
<li>Select <strong>Payment Terms</strong>.</li>
<li>Fill <strong>Position</strong>, <strong>Services Description</strong>, <strong>Venue Details</strong>, <strong>Fee Breakdown</strong>, and <strong>Remarks (If Any)</strong> when needed for LOA preparation.</li>
<li>Click <strong>Confirm Award</strong>.</li>
<li>Use the vendor row action menu to <strong>Edit LOA</strong>, <strong>Generate LOA</strong>, or <strong>Remove Vendor</strong>.</li>
</ol>
<h3>Using commercial records</h3>
<ul>
<li>Use <strong>Generate Invoice</strong> from the project actions when an invoice needs to be prepared.</li>
<li>Use <strong>Generate DO</strong> when a delivery order is needed.</li>
<li>Use <strong>Generate JD14</strong> for <strong>Training</strong> projects only.</li>
<li>Use <strong>Commercial Trails</strong> to review commercial documents already linked to the project.</li>
<li>Use <strong>Payment Requests</strong> and <strong>Profit / Loss</strong> to review project finance information.</li>
</ul>
<h3>Closing the project lifecycle</h3>
<ul>
<li>Use <strong>Complete Project</strong> when the project is finished.</li>
<li>Before completing, tick <strong>All claims received</strong>, <strong>All vendors paid</strong>, and <strong>All due services completed</strong>.</li>
<li>Enter <strong>Closure Remarks</strong>, then click <strong>Complete Project</strong>.</li>
<li>Use <strong>Terminate Project</strong> when the project should be stopped instead of completed.</li>
<li>Enter the <strong>Termination Cause</strong>, then click <strong>Terminate Project</strong>.</li>
<li>Closed projects cannot be completed or terminated again from the action menu.</li>
</ul>
<h3>Deleting a project</h3>
<ul>
<li>Use <strong>Delete Project</strong> only when the record was created incorrectly and should be permanently removed.</li>
<li>Deletion is blocked if the project is already referenced by invoices or delivery orders.</li>
<li>Deleting a quotation-linked project can reset the related quotation status, but direct-created projects usually have no quotation to reset.</li>
</ul>
HTML;
    }
};
