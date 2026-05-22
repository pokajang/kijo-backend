<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-apply-leave';

    public function up(): void
    {
        if (! Schema::hasTable('knowledge_articles')) {
            return;
        }

        $now = now();
        $article = DB::table('knowledge_articles')->where('slug', $this->slug)->first();

        if ($article && ($article->created_by_name_code ?? null) !== 'SYSTEM') {
            return;
        }

        $payload = [
            'title' => 'How to Apply Leave',
            'slug' => $this->slug,
            'summary' => 'Apply leave from My Leaves, understand balances and half-day timing, then track review, approval, cancellation, and balance updates.',
            'body_html' => $this->articleBody(),
            'category' => 'Leave & HR',
            'tags' => json_encode(['leave', 'apply-leave', 'my-leaves', 'entitlement', 'approval', 'hr']),
            'related_route' => '/my/leaves/apply',
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
            $remarks = 'Expanded leave application guide with balances, date/time duration rules, submission, workflow, records, and cancellation lifecycle.';
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
            'title' => 'How to Apply Leave',
            'summary' => 'Submit a leave application from My Leaves and track the request status.',
            'body_html' => '<ol><li>Open Account and choose My Leaves.</li><li>Select Apply Leave.</li><li>Choose the leave type and date range.</li><li>Add a reason or attachment when required.</li><li>Submit the request and monitor the status in Leave Records.</li></ol>',
            'category' => 'Leave & HR',
            'tags' => json_encode(['leave', 'hr', 'getting-started']),
            'related_route' => '/my/leaves/apply',
            'status' => 'published',
            'updated_at' => now(),
        ]);
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
<h3>Opening Apply Leave</h3>
<ol>
<li>Open <strong>My Leaves</strong> from the personal or staff navigation.</li>
<li>The default page is <strong>Leave Records</strong>.</li>
<li>Click <strong>Apply Leave</strong> at the top-right of the leave card.</li>
<li>The page changes to the <strong>Apply Leave</strong> form.</li>
<li>Use <strong>Help</strong> if you need to open this guide again.</li>
<li>Use <strong>Back</strong> to return to <strong>Leave Records</strong>.</li>
</ol>
<h3>Reading Leave Balance</h3>
<ul>
<li>The form first loads your current-year leave balances.</li>
<li>Each balance card shows the leave type, remaining days, and entitlement year.</li>
<li>The <strong>Type of Leave</strong> dropdown is built from your current-year leave entitlements.</li>
<li>Allocated leave types show their balance directly in the dropdown.</li>
<li><strong>Unpaid Leave</strong> is also available even though it does not come from an entitlement balance.</li>
<li>If no current-year entitlement exists, the application form is hidden and the page shows that no leave is allocated for the year.</li>
</ul>
<h3>Selecting Type of Leave</h3>
<ol>
<li>Open the <strong>Type of Leave</strong> dropdown.</li>
<li>Select the leave type you want to apply for.</li>
<li>Use a paid leave type when you have a valid balance for that type.</li>
<li>Use <strong>Unpaid Leave</strong> when the leave should not consume a paid entitlement balance.</li>
<li>If the selected leave type becomes unavailable after balances reload, the form automatically falls back to the first available option.</li>
</ol>
<h3>Filling the Reason</h3>
<ol>
<li>Fill <strong>Reason</strong> with a short explanation for the leave request.</li>
<li>Use practical wording such as medical appointment, rest, family matter, emergency, or planned personal leave.</li>
<li>The reason is included in the leave application record.</li>
<li>The reason is also visible to reviewers and approvers.</li>
</ol>
<h3>Selecting Start and End Dates</h3>
<ol>
<li>Choose <strong>Start Date</strong>.</li>
<li>Choose <strong>End Date</strong>.</li>
<li>The end date cannot be earlier than the start date.</li>
<li>Dates are handled using Malaysia date formatting to avoid timezone date shifts.</li>
<li>For a single-day leave, keep start and end date the same.</li>
<li>For multi-day leave, choose the first and last calendar day of the leave period.</li>
</ol>
<h3>Selecting Start and End Time</h3>
<ol>
<li>Use <strong>Start Time</strong> to choose whether the leave starts in the morning or afternoon.</li>
<li>Select <strong>8:30 AM</strong> when the leave starts from the beginning of the workday.</li>
<li>Select <strong>2:00 PM</strong> when the leave starts after the first half of the day.</li>
<li>Use <strong>End Time</strong> to choose whether the leave ends at mid-day or the end of the workday.</li>
<li>Select <strong>1:00 PM</strong> when the leave ends after the first half of the day.</li>
<li>Select <strong>5:30 PM</strong> when the leave ends at the end of the workday.</li>
</ol>
<h3>Understanding Duration Calculation</h3>
<ul>
<li>The form calculates duration automatically after you select dates and times.</li>
<li>Same-day <strong>8:30 AM</strong> to <strong>5:30 PM</strong> counts as <strong>1 day</strong>.</li>
<li>Same-day leave starting at <strong>2:00 PM</strong> counts as <strong>0.5 day</strong>.</li>
<li>Same-day leave ending at <strong>1:00 PM</strong> counts as <strong>0.5 day</strong>.</li>
<li>Multi-day leave counts all calendar days between start date and end date.</li>
<li>For multi-day leave, starting at <strong>2:00 PM</strong> subtracts <strong>0.5 day</strong>.</li>
<li>For multi-day leave, ending at <strong>1:00 PM</strong> subtracts <strong>0.5 day</strong>.</li>
<li>The form shows <strong>Applying leave for X days</strong> before submission.</li>
</ul>
<h3>Submitting the Leave Application</h3>
<ol>
<li>Check the selected leave type, reason, dates, times, and calculated duration.</li>
<li>Click <strong>Submit</strong>.</li>
<li>The page shows <strong>Submitting leave application...</strong> while the request is being saved.</li>
<li>The system creates the leave application with <strong>Pending</strong> status.</li>
<li>The system notifies configured leave workflow recipients.</li>
<li>If email notification succeeds, the page confirms that recipients were notified.</li>
<li>If the leave was submitted but email could not be confirmed, the page shows a warning while keeping the submitted request.</li>
</ol>
<h3>What Happens After Submission</h3>
<ul>
<li>The leave application is saved in your personal leave records.</li>
<li>The application starts as <strong>Pending</strong>.</li>
<li>Recommendation recipients receive a notification that the leave needs recommendation.</li>
<li>Configured recipients are used first.</li>
<li>If no custom recipients are configured, active HR staff are used as the fallback recommendation recipients.</li>
<li>After submission, the form refreshes your leave balances and clears the form fields.</li>
</ul>
<h3>Choosing the Next Action After Submit</h3>
<ul>
<li>Click <strong>Apply Another Leave</strong> to return to a fresh application form.</li>
<li>Click <strong>View Records</strong> to return to your leave record list.</li>
<li>If submission failed, click <strong>Back to Form</strong> to fix the form and submit again.</li>
</ul>
<h3>Viewing Leave Records</h3>
<ol>
<li>Open <strong>My Leaves</strong>.</li>
<li>The <strong>Leave Records</strong> page loads your personal leave records.</li>
<li>The stats strip shows <strong>Days Balance</strong>, <strong>Days Used</strong>, <strong>Days Pending Approval</strong>, and <strong>Days Cancelled</strong>.</li>
<li>Use search to find records by leave type, reason, or status.</li>
<li>Use the period selector to change the record date range.</li>
<li>Use the <strong>Leave Type</strong> filter to show only one type of leave.</li>
<li>Click a leave row to open <strong>Leave Record Details</strong>.</li>
</ol>
<h3>Reading Workflow Status</h3>
<ul>
<li><strong>Pending review</strong> means the application is waiting for recommendation.</li>
<li><strong>Review: Recommended</strong> means the leave was recommended and is waiting for approval.</li>
<li><strong>Review: Rejected</strong> means the leave was rejected at the review stage.</li>
<li><strong>Approval: Approved</strong> means the leave was approved.</li>
<li><strong>Approval: Rejected</strong> means the leave was rejected at the approval stage.</li>
<li><strong>Cancellation: Cancelled</strong> means the leave request was cancelled.</li>
<li>Workflow remarks appear when the reviewer or approver entered remarks.</li>
</ul>
<h3>How Recommendation and Approval Work</h3>
<ol>
<li>After submission, the application waits for a reviewer to choose <strong>Recommend</strong> or <strong>Reject</strong>.</li>
<li>If the reviewer chooses <strong>Recommend</strong>, the leave waits for an approver.</li>
<li>The approver then chooses <strong>Approve</strong> or <strong>Reject</strong>.</li>
<li>Approval recipients are configured in leave workflow settings.</li>
<li>If no custom approval recipients are configured, active Manager or System Admin staff are used as fallback approvers.</li>
<li>The applicant is notified when leave is recommended, approved, rejected, cancelled, or revoked.</li>
</ol>
<h3>When Leave Balance Is Used</h3>
<ul>
<li>Submitting a leave application does not immediately consume used leave days.</li>
<li>Leave allocation is updated when the leave is <strong>Approved</strong>.</li>
<li>Approved leave increases <strong>used_days</strong> for the matching staff, leave type, and leave year.</li>
<li>The remaining balance is calculated from entitlement total days minus used days.</li>
<li>If an approved leave is later cancelled or revoked, the used days are reversed.</li>
<li>If no matching allocation exists for the leave type and year, the system does not adjust an allocation row.</li>
</ul>
<h3>Cancelling a Leave Request</h3>
<ol>
<li>Open <strong>My Leaves</strong>.</li>
<li>Find the leave record.</li>
<li>Open the row action menu and choose <strong>Cancel</strong>.</li>
<li>Confirm the cancellation prompt.</li>
<li>The leave status changes to <strong>Cancelled</strong>.</li>
<li>Pending workflow notifications are resolved where applicable.</li>
<li>If the leave had already been approved, the approved used days are reversed.</li>
</ol>
<h3>Opening Leave Record Details</h3>
<ol>
<li>Open <strong>My Leaves</strong>.</li>
<li>Click a leave row from the records table.</li>
<li>The <strong>Leave Record Details</strong> page opens.</li>
<li>Review leave type, applied date, start date, end date, duration, status, reason, reviewed status, reviewed remarks, approved status, and approved remarks.</li>
<li>Use the page action menu to <strong>Cancel</strong> the leave when cancellation is still allowed.</li>
<li>Use <strong>Back</strong> to return to <strong>Leave Records</strong>.</li>
</ol>
<h3>Common Validation Messages</h3>
<ul>
<li><strong>Please select a leave type.</strong> Select a value from <strong>Type of Leave</strong>.</li>
<li><strong>End date cannot be earlier than start date.</strong> Choose an end date on or after the start date.</li>
<li><strong>Invalid leave time range.</strong> Check that the selected start and end time create at least half a day.</li>
<li><strong>For same-day leave, start_time must be earlier than end_time.</strong> Check same-day time choices before submitting.</li>
<li><strong>Could not load your leave balances.</strong> Refresh the page or contact HR if entitlements are missing.</li>
</ul>
<h3>Recommended Practice</h3>
<ul>
<li>Check <strong>Leave Balance</strong> before applying.</li>
<li>Use the correct leave type so the correct entitlement is adjusted after approval.</li>
<li>Apply leave early when the leave is planned.</li>
<li>Use a clear <strong>Reason</strong> so reviewers and approvers understand the request.</li>
<li>Use half-day timing only when the leave is actually for half a workday.</li>
<li>After submitting, monitor <strong>Leave Records</strong> until the status changes from <strong>Pending</strong>.</li>
<li>Cancel incorrect leave requests as soon as possible so reviewers do not act on the wrong record.</li>
</ul>
HTML;
    }
};
