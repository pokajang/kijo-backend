<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-request-and-apply-quote-negotiations';

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
            'title' => 'How to Request and Apply Quote Negotiations',
            'slug' => $this->slug,
            'summary' => 'Request a negotiated discount from quotation records, get manager approval, then apply the approved negotiation through a quote revision.',
            'body_html' => $this->articleBody(),
            'category' => 'CRM',
            'tags' => json_encode(['quotation', 'negotiation', 'price-exception', 'discount', 'approval', 'training', 'manpower']),
            'related_route' => '/crm/records',
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
            $remarks = 'Expanded quote negotiation guide with the quotation-record origin, approval flow, and apply-through-revision lifecycle.';
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
<h3>Starting from quotation records</h3>
<ol>
<li>Open <strong>Quotations</strong> from the CRM navigation.</li>
<li>Go to <strong>Quotation Records</strong>.</li>
<li>Find the quotation under <strong>Training</strong>, <strong>Manpower Supply</strong>, <strong>My Quotes</strong>, or <strong>All</strong>.</li>
<li>Use this flow only after the quotation has already been saved.</li>
<li>Use the quotation row action menu and choose <strong>Negotiate</strong>.</li>
</ol>
<h3>When negotiation is available</h3>
<ul>
<li><strong>Negotiate</strong> is available only for <strong>Training</strong> and <strong>Manpower Supply</strong> quotes.</li>
<li>The quotation status must be <strong>Open</strong> or <strong>Pending</strong>.</li>
<li>Only the quotation creator or owner can request negotiation.</li>
<li>The quote must not already have an applied negotiation request.</li>
<li>The quote must not already have an active <strong>Pending</strong> or <strong>Approved</strong> negotiation request.</li>
<li>Pre-quote negotiation is disabled. Save the quote first, then request negotiation from quotation records.</li>
</ul>
<h3>Submitting a negotiation request</h3>
<ol>
<li>Click <strong>Negotiate</strong> from the quotation row action menu.</li>
<li>The <strong>Request Negotiation</strong> modal opens.</li>
<li>Review the quotation reference, client, and current amount shown at the top of the modal.</li>
<li>Enter either <strong>Requested Discount</strong> or <strong>Requested Final Total</strong>.</li>
<li>Do not enter both amount fields. The system accepts one requested amount method only.</li>
<li>If using <strong>Requested Discount</strong>, the value cannot exceed the current quote amount.</li>
<li>If using <strong>Requested Final Total</strong>, the final total must be lower than the current quote amount.</li>
<li>Fill <strong>Reason</strong>. This field is required.</li>
<li>Use <strong>Internal Remarks</strong> for extra context that helps the approver understand the request.</li>
<li>Click <strong>Submit Request</strong>.</li>
</ol>
<h3>After submitting</h3>
<ul>
<li>The system creates a negotiation request linked to the quotation.</li>
<li>The request starts with <strong>Pending</strong> status.</li>
<li>Approvers are notified when the request is submitted.</li>
<li>The quote row cannot submit another active negotiation while the first request is pending or approved.</li>
<li>The requester sees a confirmation message that the negotiation request was submitted for approval.</li>
</ul>
<h3>Opening the Negotiations page</h3>
<ol>
<li>Open <strong>Negotiations</strong> from the CRM navigation.</li>
<li>The page lists quote negotiation requests.</li>
<li>Managers and System Admins can see approval work across requesters.</li>
<li>Normal users see their own negotiation requests.</li>
<li>Click <strong>Refresh</strong> to reload the request list.</li>
</ol>
<h3>Reading the negotiation dashboard</h3>
<ul>
<li><strong>Applied</strong> shows requests already applied to a quotation revision.</li>
<li><strong>Pending</strong> shows requests waiting for approval.</li>
<li><strong>Approved</strong> shows requests approved but not yet applied.</li>
<li><strong>Rejected</strong> shows declined requests.</li>
<li>The table shows <strong>Request</strong>, <strong>Reason</strong>, <strong>Service</strong>, <strong>Requester</strong>, <strong>Current</strong>, <strong>Requested Discount</strong>, <strong>Status</strong>, and <strong>Trace</strong>.</li>
<li><strong>Trace</strong> shows the approver name, or the quote revision where the request was applied.</li>
</ul>
<h3>Finding requests</h3>
<ul>
<li>Use <strong>Search request, quote, requester, reason, or trace</strong> to search across negotiation details.</li>
<li>Use <strong>Status</strong> to filter by <strong>All statuses</strong>, <strong>Pending</strong>, <strong>Approved</strong>, <strong>Rejected</strong>, or <strong>Applied</strong>.</li>
<li>Use <strong>Service</strong> to filter by <strong>Training</strong>, <strong>IH</strong>, <strong>Manpower</strong>, <strong>Equipment</strong>, or <strong>Special</strong>.</li>
<li>Use <strong>Requester</strong> to filter by the staff member who submitted the request.</li>
<li>Use <strong>Reset</strong> to clear search and filters.</li>
<li>Click a stats card such as <strong>Pending</strong> or <strong>Approved</strong> to apply the matching status filter.</li>
</ul>
<h3>Opening negotiation details</h3>
<ol>
<li>Click a negotiation row.</li>
<li>The system opens <strong>Negotiation Details</strong>.</li>
<li>Review request, service, requester, status, current total, requested discount, requested final total, reason, requester remarks, approved discount, approved final total, approver, approved date, applied quote, applied date, and approval remarks.</li>
<li>Use <strong>Back</strong> to return to the negotiation list.</li>
<li>Use <strong>Open Quotation</strong> when the request is linked to a quotation.</li>
<li>Use <strong>Apply</strong> only when an approved request is ready to be applied by the requester.</li>
</ol>
<h3>Approving a request</h3>
<ol>
<li>Open <strong>Negotiations</strong>.</li>
<li>Find a <strong>Pending</strong> Training or Manpower request.</li>
<li>Choose <strong>Approve</strong> from the request row action menu.</li>
<li>The <strong>Approve Request</strong> modal opens.</li>
<li>Review the quote, requester, current quote total, requested discount, and requested final total.</li>
<li>Confirm or adjust <strong>Approved Discount</strong>.</li>
<li>The approved discount must be greater than zero and cannot exceed the current quote amount.</li>
<li>Add <strong>Remarks</strong> if needed.</li>
<li>Tick the acknowledgement checkbox.</li>
<li>Click <strong>Confirm</strong>.</li>
</ol>
<h3>Rejecting a request</h3>
<ol>
<li>Open <strong>Negotiations</strong>.</li>
<li>Find a <strong>Pending</strong> Training or Manpower request.</li>
<li>Choose <strong>Reject</strong> from the request row action menu.</li>
<li>The <strong>Reject Request</strong> modal opens.</li>
<li>Enter <strong>Remarks</strong>. Rejection remarks are required.</li>
<li>Tick the acknowledgement checkbox.</li>
<li>Click <strong>Confirm</strong>.</li>
<li>The request changes to <strong>Rejected</strong> and cannot be applied to the quote.</li>
</ol>
<h3>Approval permissions</h3>
<ul>
<li>Only <strong>Manager</strong> and <strong>System Admin</strong> roles can approve or reject negotiation requests.</li>
<li>Approvers can approve or reject only pending quote-based <strong>Training</strong> and <strong>Manpower</strong> negotiations.</li>
<li>Only <strong>Open</strong> or <strong>Pending</strong> quotes can be approved for negotiation.</li>
<li>Approval does not change the quotation immediately. The approved request still needs to be applied by the requester.</li>
</ul>
<h3>Applying an approved negotiation</h3>
<ol>
<li>Open <strong>Negotiations</strong>.</li>
<li>Find the request with <strong>Approved</strong> status.</li>
<li>The original requester chooses <strong>Apply</strong> from the row action menu or from <strong>Negotiation Details</strong>.</li>
<li>The system opens the quotation page with the original quote in edit/revision mode.</li>
<li>The URL includes the approved negotiation request and revision flags.</li>
<li>Review the quote revision carefully before saving.</li>
<li>Save the quotation revision.</li>
<li>After saving, the negotiation request changes from <strong>Approved</strong> to <strong>Applied</strong>.</li>
</ol>
<h3>What happens in Training quotes</h3>
<ul>
<li>The Training quotation form loads the approved negotiation request.</li>
<li>The form sets the discount type to <strong>Negotiated</strong>.</li>
<li>The approved discount is applied as the quote discount value.</li>
<li>Training base rates remain locked. The negotiated amount is applied through discount instead of changing the base rate.</li>
<li>The quote saves the negotiation request ID so the request can be marked as applied.</li>
</ul>
<h3>What happens in Manpower quotes</h3>
<ul>
<li>The Manpower quotation form loads the approved negotiation request.</li>
<li>The approved discount is applied to the quote discount field.</li>
<li>Special Manpower Supply rates still require approved negotiation before a below-rate quote can be saved.</li>
<li>Manpower base rates remain locked. The negotiated amount is applied through discount instead of changing the base rate.</li>
<li>The quote saves the negotiation request ID so the request can be marked as applied.</li>
</ul>
<h3>Common blockers</h3>
<ul>
<li>If <strong>Negotiate</strong> does not appear, confirm the quote is <strong>Training</strong> or <strong>Manpower Supply</strong>.</li>
<li>If <strong>Negotiate</strong> does not appear, confirm the quote is <strong>Open</strong> or <strong>Pending</strong>.</li>
<li>If <strong>Negotiate</strong> does not appear, confirm you are the quote owner.</li>
<li>If the quote already has an active pending or approved negotiation, wait for the current request to be rejected or applied.</li>
<li>If <strong>Apply</strong> does not appear, confirm the request is approved and you are the original requester.</li>
<li>If applying fails, confirm the original quote is still <strong>Open</strong> or <strong>Pending</strong>.</li>
<li>If applying fails, confirm you are saving through a quote revision, not a normal quote edit.</li>
</ul>
<h3>Recommended operating practice</h3>
<ul>
<li>Use negotiation only when the client negotiation requires an approved discount from a locked-rate Training or Manpower quote.</li>
<li>Write a clear <strong>Reason</strong> so the approver can decide without asking for extra context.</li>
<li>Use <strong>Internal Remarks</strong> for private sales context, competitor pricing, or scope discussion.</li>
<li>Approvers should use <strong>Remarks</strong> to explain approvals or rejections clearly.</li>
<li>After approval, the requester should apply the negotiation promptly and save the revised quote so the request does not remain stuck in <strong>Approved</strong> status.</li>
<li>Use <strong>Open Quotation</strong> to inspect the quote before approving or applying a negotiation.</li>
</ul>
HTML;
    }
};
