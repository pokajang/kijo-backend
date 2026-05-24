<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-create-and-manage-meeting-minutes';

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
            'title' => 'How to Create and Manage Meeting Minutes',
            'slug' => $this->slug,
            'summary' => 'Create meeting minute drafts, complete meeting notes, assign and complete action items, verify or concur minutes, export PDFs, and manage the meeting record lifecycle.',
            'body_html' => $this->articleBody(),
            'category' => 'System',
            'tags' => json_encode([
                'meetings',
                'meeting-minutes',
                'minute',
                'administration',
                'action-items',
                'pic',
                'verification',
                'concurrence',
                'pdf',
                'draft',
                'attendees',
                'agenda',
                'minutes',
                'approval',
            ]),
            'related_route' => '/administration/meetings',
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
            $remarks = 'Updated Meeting Minutes guide with list, draft, notes, action item, verification, concurrence, PDF, and delete lifecycle details.';
        } else {
            $articleId = (int) DB::table('knowledge_articles')->insertGetId($payload + [
                'created_by_staff_id' => null,
                'created_by_name_code' => 'SYSTEM',
                'created_at' => $now,
            ]);
            $action = 'created_published';
            $remarks = 'Production Meeting Minutes guide seeded by system.';
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
<h3>What Meetings is for</h3>
<p>Use <strong>Meetings</strong> to record formal meeting minutes, keep attendee and guest attendance clear, assign follow-up action items, track completion, and produce a PDF copy when the minutes are finalized.</p>
<ul>
<li>Use it for weekly, monthly, or ad hoc meeting minutes that need a searchable company record.</li>
<li>Use action items to assign follow-up work to a PIC and track pending items after the meeting.</li>
<li>Use verification and concurrence when management, HR, admin, or supervisor roles need to approve the meeting minutes.</li>
</ul>
<h3>Opening Meetings</h3>
<ol>
<li>Open <strong>Administration</strong> from the sidebar or module navigation.</li>
<li>Click <strong>Meetings</strong>.</li>
<li>The page opens the meeting minute records list.</li>
<li>Click <strong>Add Minute</strong> to create a new meeting minute record.</li>
</ol>
<h3>Reading the meeting records list</h3>
<ul>
<li>The main table shows <strong>Title</strong>, <strong>Meeting Date</strong>, <strong>Meeting Type</strong>, and <strong>Pending Items</strong>.</li>
<li>Draft records show a <strong>Draft</strong> badge and are visible only to the draft creator.</li>
<li>Complete records open in view mode when you click the row.</li>
<li>Draft records open in edit mode when you click the row, so you can continue the draft.</li>
<li>The <strong>Pending Items</strong> column shows the number of incomplete action items. When action items are assigned, the count can be grouped by PIC code.</li>
</ul>
<h3>Searching and filtering meeting records</h3>
<ul>
<li>Use the search bar to find records by title, meeting type, venue, agenda, minutes, action item text, staff attendee, or guest attendee.</li>
<li>Use <strong>Meeting Type</strong> to filter by <strong>Monthly</strong>, <strong>Weekly</strong>, or <strong>Ad Hoc</strong>.</li>
<li>Use <strong>Status</strong> to filter <strong>Drafts</strong> or <strong>Complete</strong> records.</li>
<li>The period filter defaults to year-to-date. Change it when older or future meeting records are not visible.</li>
<li>Use <strong>Columns</strong> to adjust visible table columns.</li>
<li>Use <strong>Export</strong> to download the current visible records as CSV.</li>
</ul>
<h3>Creating a meeting minute record</h3>
<ol>
<li>Open <strong>Administration</strong> then <strong>Meetings</strong>.</li>
<li>Click <strong>Add Minute</strong>.</li>
<li>The form starts at <strong>1. Meeting Details</strong>.</li>
<li>Complete the required details first.</li>
<li>Click <strong>Save &amp; Continue</strong> to create the draft record and move to meeting notes.</li>
</ol>
<h3>Step 1: Meeting Details</h3>
<ol>
<li>Enter a clear <strong>Meeting Title</strong>.</li>
<li>Select <strong>Meeting Type</strong>: <strong>Monthly</strong>, <strong>Weekly</strong>, or <strong>Ad Hoc</strong>.</li>
<li>Choose <strong>Meeting Date &amp; Time</strong>.</li>
<li>Enter <strong>Venue</strong> if the meeting location should be recorded.</li>
<li>Use <strong>Tick Attendees</strong> to select internal staff attendees.</li>
<li>Use <strong>Search staff</strong> to find attendees faster.</li>
<li>Use <strong>Selected only</strong> to review who has already been selected.</li>
<li>Use <strong>Select Me</strong> when you are part of the meeting and want to add yourself quickly.</li>
<li>Enter <strong>Guest Attendees</strong> when external attendees joined the meeting. Put one guest per line, for example <strong>Name - Company</strong>.</li>
</ol>
<h3>Required detail rules</h3>
<ul>
<li><strong>Meeting Title</strong> is required.</li>
<li><strong>Meeting Type</strong> is required and must be one of the available type options.</li>
<li><strong>Meeting Date &amp; Time</strong> is required.</li>
<li>At least one internal staff attendee is required.</li>
<li>The staff list must finish loading before attendee validation can pass.</li>
</ul>
<h3>Saving details and moving to notes</h3>
<ul>
<li>Click <strong>Save &amp; Continue</strong> after completing the detail step.</li>
<li>The system saves a server-side draft and moves the form to <strong>2. Meeting Notes</strong>.</li>
<li>The draft remains unfinished until the notes step is saved.</li>
<li>If you leave the page after saving details, open the draft from the meeting records list and choose <strong>Continue Draft</strong>.</li>
</ul>
<h3>Draft recovery</h3>
<ul>
<li>Before the first server save, the browser keeps a local draft for the add form.</li>
<li>After a draft has a record id, the browser keeps a draft backup for that meeting record.</li>
<li>Use <strong>Save Draft</strong> to keep progress without finalizing minutes.</li>
<li>Use <strong>Discard Draft</strong> only when the draft should be abandoned. This action cannot be undone.</li>
<li>A discarded draft is removed from normal active use and its content is cleared from the record.</li>
</ul>
<h3>Step 2: Meeting Notes</h3>
<ol>
<li>Use <strong>Agenda</strong> for meeting agenda, topics, or planned discussion points.</li>
<li>Use <strong>Minutes</strong> for the actual meeting notes. This field is required before the record can be finalized.</li>
<li>Use <strong>Add Action</strong> in the action item section if follow-up work is needed.</li>
<li>For each action item, enter the <strong>Action</strong>.</li>
<li>Choose <strong>Assign PIC</strong> when a staff member owns that action.</li>
<li>Choose <strong>Due Date</strong> when the action has a target completion date.</li>
<li>Use the trash icon to remove an action row that is not needed.</li>
<li>Click <strong>Save Minutes</strong> to finalize a draft, or <strong>Update Minutes</strong> when editing an existing complete record.</li>
</ol>
<h3>Action item rules</h3>
<ul>
<li>An action row can be left blank and ignored.</li>
<li>If <strong>Assign PIC</strong> or <strong>Due Date</strong> is filled, the <strong>Action</strong> text is required.</li>
<li>Action status defaults to <strong>Pending</strong>.</li>
<li>Supported statuses are <strong>Pending</strong>, <strong>In Progress</strong>, and <strong>Done</strong>.</li>
<li>Action item updates are recorded in the meeting change history.</li>
</ul>
<h3>Finalizing minutes</h3>
<ol>
<li>Complete the <strong>Minutes</strong> field.</li>
<li>Confirm attendees, guests, agenda, and action items.</li>
<li>Click <strong>Save Minutes</strong>.</li>
<li>The record status changes from <strong>Draft</strong> to <strong>Complete</strong>.</li>
<li>The system returns you to the meeting records list.</li>
</ol>
<h3>Opening and viewing a meeting record</h3>
<ul>
<li>Click a complete meeting row to open the detail page.</li>
<li>The detail page shows meeting title, meeting type, meeting date and time, venue, creator, updater, approval status, staff attendees, guest attendees, agenda, minutes, and action items.</li>
<li>Use <strong>Show Change History</strong> to view audit entries such as draft creation, details update, notes update, action item changes, verification, and concurrence changes.</li>
</ul>
<h3>Editing meeting minutes</h3>
<ul>
<li>Use the record action menu and choose <strong>Edit</strong>, or open the detail page and choose <strong>Edit</strong>.</li>
<li>Only the meeting creator can edit the meeting content.</li>
<li>Editing opens the same two-step form used during creation.</li>
<li>When a complete meeting is edited, the save button on the notes step becomes <strong>Update Minutes</strong>.</li>
<li>Verified or concurred minutes are locked from content changes. Revoke approval first if a correction is required.</li>
</ul>
<h3>Adding action items after minutes are finalized</h3>
<ol>
<li>Open <strong>Administration</strong> then <strong>Meetings</strong>.</li>
<li>Find the complete meeting record.</li>
<li>Open the row action menu.</li>
<li>Choose <strong>Add Action</strong>.</li>
<li>Enter the <strong>Action</strong>.</li>
<li>Select <strong>Assign PIC</strong> if a staff member owns the follow-up.</li>
<li>Select <strong>Due Date</strong> if needed.</li>
<li>Click <strong>Add Action</strong> in the modal.</li>
</ol>
<h3>Who can add or update action items</h3>
<ul>
<li>Only the meeting creator can add new action items to a finalized meeting.</li>
<li>Only the meeting creator or assigned PIC can update an action item's status.</li>
<li>Action item changes are blocked when the meeting minutes are verified or concurred.</li>
<li>Draft meetings must be finalized before action items can be added or updated from the record list.</li>
</ul>
<h3>Completing action items from the records list</h3>
<ol>
<li>Find a meeting with pending action items.</li>
<li>Open the row action menu.</li>
<li>Choose <strong>Complete Action</strong>.</li>
<li>Select one pending action item assigned to you or created by you.</li>
<li>Click <strong>Mark Done</strong>.</li>
<li>The pending count updates after the save succeeds.</li>
</ol>
<h3>Completing action items from the detail page</h3>
<ul>
<li>Open the meeting detail page.</li>
<li>Use the action item row menu to choose <strong>Mark Done</strong> or <strong>Mark Pending</strong>.</li>
<li>Use the page-level <strong>Complete Action</strong> action when you want to mark one eligible pending action as done from a modal.</li>
<li>If the modal says you are not eligible, the pending actions belong to another PIC and were not created by you.</li>
</ul>
<h3>Verification and concurrence lifecycle</h3>
<ul>
<li>Users with manager, HR, admin, or supervisor-style roles can manage meeting verification.</li>
<li><strong>Verify</strong> marks the minutes as verified.</li>
<li><strong>Concur</strong> is available only after the minutes are verified.</li>
<li>The verifier and concurer must be different users.</li>
<li><strong>Unverify</strong> is available only when the meeting is verified and not yet concurred.</li>
<li><strong>Unconcur</strong> is available after concurrence.</li>
<li><strong>Unverify</strong> and <strong>Unconcur</strong> require a reason.</li>
<li>Verification and concurrence actions are written into the meeting audit history.</li>
</ul>
<h3>Approval locking behavior</h3>
<ul>
<li>When minutes are <strong>Verified</strong> or <strong>Concurred</strong>, the meeting content is locked.</li>
<li>Locked content cannot be edited.</li>
<li>Locked content cannot receive new action items.</li>
<li>Locked action items cannot have their status updated.</li>
<li>To correct locked minutes, an authorized approver must use <strong>Unconcur</strong> or <strong>Unverify</strong> first, depending on the current approval state.</li>
</ul>
<h3>Exporting PDF</h3>
<ul>
<li>Use <strong>Export PDF</strong> from the records list action menu or the meeting detail page.</li>
<li>PDF export is available for finalized complete meeting records.</li>
<li>The PDF includes meeting title, type, date, venue, creator, updater, approval status, attendees, guests, agenda, minutes, action items, and generated-by details.</li>
<li>Draft minutes must be finalized before PDF export can be used.</li>
</ul>
<h3>Deleting and discarding records</h3>
<ul>
<li>For a draft, the action label is <strong>Discard Draft</strong>.</li>
<li>For a complete record, the action label is <strong>Delete</strong>.</li>
<li>The meeting creator can delete their own meeting record.</li>
<li><strong>System Admin</strong> can delete meeting records created by other users.</li>
<li>Normal users cannot delete another user's meeting record.</li>
<li>Always export or confirm operational use before deleting a finalized record.</li>
</ul>
<h3>Common mistakes to avoid</h3>
<ul>
<li>Do not expect a draft to appear for all users. Drafts are visible only to the creator.</li>
<li>Do not leave the <strong>Minutes</strong> field blank when trying to finalize a meeting.</li>
<li>Do not add a PIC or due date to an empty action row without entering the action text.</li>
<li>Do not try to edit verified or concurred minutes before approval is revoked.</li>
<li>Do not use <strong>Discard Draft</strong> unless the draft should be permanently abandoned.</li>
<li>Do not assume <strong>Complete Action</strong> can be used by everyone. Only the meeting creator or assigned PIC can update status.</li>
</ul>
<h3>Troubleshooting</h3>
<ul>
<li>If the notes step is disabled, save the meeting details first.</li>
<li>If attendees cannot be selected, wait for the staff list to finish loading and try the staff search again.</li>
<li>If an old meeting is missing from the list, check the period filter and clear search or status filters.</li>
<li>If PDF export fails, confirm the meeting is finalized and not still a draft.</li>
<li>If action updates fail, check whether the meeting is verified or concurred, and whether you are the meeting creator or assigned PIC.</li>
<li>If verification fails, confirm the meeting is complete and that your account has a manager, HR, admin, or supervisor-style role.</li>
<li>If concurrence fails, confirm the meeting has already been verified and that you are not the same user who verified it.</li>
</ul>
HTML;
    }
};
