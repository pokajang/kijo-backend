<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-track-and-create-manual-debtors';

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
            'title' => 'How to Track and Create Manual Debtors',
            'slug' => $this->slug,
            'summary' => 'Use Debtors to monitor open receivables from system invoices and create manual debtor records for old or off-system debts that are not traceable to a KIJO invoice.',
            'body_html' => $this->articleBody(),
            'category' => 'Commercial',
            'tags' => json_encode(['debtors', 'manual-debtor', 'receivables', 'invoice', 'payment', 'commercial', 'aging', 'collection']),
            'related_route' => '/commercial/debtors',
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
            $remarks = 'Updated standalone debtors guide with system invoice rows, manual debtor creation, payment terms, payment status, and lifecycle controls.';
        } else {
            $articleId = (int) DB::table('knowledge_articles')->insertGetId($payload + [
                'created_by_staff_id' => null,
                'created_by_name_code' => 'SYSTEM',
                'created_at' => $now,
            ]);
            $action = 'created_published';
            $remarks = 'Production standalone debtors guide seeded by system.';
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
<h3>What the Debtors page is for</h3>
<p><strong>Debtors</strong> is the receivables register in the Commercial module. It shows money still owed by clients and helps Finance track collection status, aging, due dates, and payment updates.</p>
<ul>
<li><strong>System Invoice</strong> rows come from invoices created inside KIJO.</li>
<li><strong>Manual Entry</strong> rows are created directly from Debtors for old, migrated, direct-award, or off-system receivables that are not traceable to a KIJO invoice.</li>
<li>Use manual debtor records carefully. Do not duplicate invoices that already exist in KIJO.</li>
</ul>
<h3>Opening Debtors</h3>
<ol>
<li>Open <strong>Commercial</strong> from the navigation.</li>
<li>Choose the <strong>Debtors</strong> tab.</li>
<li>The page loads open receivables by default.</li>
<li>Use the top summary cards to review <strong>Open Receivables</strong>, <strong>More Than 30 Days</strong>, <strong>31-60 Days</strong>, and <strong>61+ Days</strong>.</li>
<li>Click <strong>Open Receivables</strong> if you want to return the list to open debtor records.</li>
</ol>
<h3>Filtering and reading debtor rows</h3>
<ol>
<li>Use <strong>Type to search...</strong> to search the debtor list.</li>
<li>Open filters when you need to change <strong>Status</strong>, <strong>Source</strong>, or <strong>As Of</strong>.</li>
<li>Use <strong>Status</strong> to view <strong>Open</strong>, <strong>Paid</strong>, <strong>Cancelled</strong>, or <strong>All</strong> debtor rows.</li>
<li>Use <strong>Source</strong> to switch between <strong>System Invoices</strong>, <strong>Manual Debtors</strong>, or all sources.</li>
<li>Use <strong>As Of</strong> to calculate aging and overdue status as at a selected date.</li>
<li>Review <strong>Age</strong>, <strong>Terms</strong>, <strong>Due</strong>, and <strong>Overdue</strong> to decide which receivables need follow-up.</li>
<li>Use <strong>Columns</strong> to show or hide optional fields like remarks, source, service, and PIC.</li>
<li>Use <strong>Export</strong> when you need a CSV copy of the current debtor register.</li>
</ol>
<h3>System invoice debtor rows</h3>
<p>System invoice debtor rows are controlled by the invoice lifecycle. Create and edit the invoice from the Invoice module or invoice detail page, then the debtor register reflects that invoice status.</p>
<ul>
<li>Click a system invoice debtor row to open the linked invoice detail page.</li>
<li>Use <strong>Open Invoice</strong> from the row action menu to open the invoice detail page.</li>
<li>Use <strong>PDF Invoice</strong> to open the invoice PDF.</li>
<li>Use <strong>Mark Paid</strong> when payment has been received for an open invoice debtor.</li>
<li>Do not create a manual debtor for the same invoice. That would duplicate receivables.</li>
</ul>
<h3>When to create a manual debtor</h3>
<p>Create a manual debtor only when the receivable should be tracked in KIJO but does not come from a KIJO invoice.</p>
<ul>
<li>Use it for old receivables that existed before the system record was created.</li>
<li>Use it for direct-award or special commercial cases where the project/invoice flow was not used.</li>
<li>Use it when Finance needs visibility of a collection item but there is no matching KIJO invoice record.</li>
<li>Do not use it as a shortcut when a normal invoice should be created from the project or commercial workflow.</li>
</ul>
<h3>Creating a manual debtor</h3>
<ol>
<li>Open <strong>Commercial</strong> and choose <strong>Debtors</strong>.</li>
<li>Click <strong>Add Debtor</strong>.</li>
<li>Use <strong>Select Client</strong> to search for the client company.</li>
<li>If the client does not exist yet, click <strong>Create one?</strong>. KIJO stores your debtor draft temporarily, sends you to client creation, then returns the new client to the debtor form.</li>
<li>If the selected client has multiple contacts, use <strong>Select all</strong>, <strong>Primary only</strong>, or tick the specific contact rows that should be attached to the debtor snapshot.</li>
<li>Fill <strong>Invoice Ref</strong>. This must be unique among manual debtor records.</li>
<li>Fill <strong>Invoice Date</strong>. This date drives age and receivable reporting.</li>
<li>Choose <strong>Payment Terms</strong>. Use the saved client terms or choose <strong>Custom</strong> when this debt follows a different arrangement.</li>
<li>Check the calculated <strong>Due Date</strong>. It is read-only and calculated from invoice date plus payment terms.</li>
<li>Fill <strong>Grand Total</strong> with the receivable amount.</li>
<li>Choose <strong>Service</strong> when the debt belongs to Training, Industrial Hygiene, Manpower Supply, Equipment Supply, or Special Service.</li>
<li>Fill <strong>Service Start Date</strong> and <strong>Service End Date</strong> if the debt is tied to a service period.</li>
<li>Add useful context in <strong>Remarks</strong>, such as old invoice references, payment arrangement, direct-award background, or legacy debt notes.</li>
<li>Set <strong>Status</strong> to <strong>Open</strong>, <strong>Paid</strong>, or <strong>Cancelled</strong>.</li>
<li>Fill <strong>Payment Method</strong> if the payment method is already known.</li>
<li>Upload an <strong>Attachment</strong> when there is supporting proof. Supported files are PDF, JPG, PNG, and WebP up to 5 MB.</li>
<li>If the status is <strong>Paid</strong>, fill <strong>Paid Date</strong>, <strong>Paid Amount</strong>, and <strong>Paid Remarks</strong>.</li>
<li>Click <strong>Save</strong>.</li>
</ol>
<h3>Editing a manual debtor</h3>
<ol>
<li>Open the <strong>Debtors</strong> list.</li>
<li>Find the debtor row marked <strong>Manual Entry</strong>.</li>
<li>Click the row or choose <strong>Edit</strong> from the row action menu.</li>
<li>Update the debtor details, contact snapshot, service details, payment terms, status, attachment, or payment fields as needed.</li>
<li>Click <strong>Save</strong> to return to the Debtors list.</li>
</ol>
<h3>Marking a debtor as paid</h3>
<ol>
<li>Find an open debtor row.</li>
<li>Open the row action menu.</li>
<li>Choose <strong>Mark Paid</strong>.</li>
<li>Confirm the payment date, paid amount, and remarks in the payment modal.</li>
<li>Submit the modal. The row is refreshed with paid status after the update.</li>
</ol>
<h3>Reopening or deleting manual debtors</h3>
<ul>
<li>Use <strong>Mark Open</strong> only for paid manual debtor rows that need to return to open status.</li>
<li>Use <strong>Delete</strong> only for manual debtor records that were created incorrectly and should be removed from the register.</li>
<li>Deleting applies to manual debtor records only. System invoice debtor rows are managed through their invoice records.</li>
<li>When in doubt, keep the debtor record and update its status or remarks rather than deleting collection history.</li>
</ul>
<h3>How debtors affect reporting</h3>
<ul>
<li>Open debtor rows contribute to receivable and aging views.</li>
<li>Manual debtors can appear in client commercial history and ROI reporting as manual debtor revenue/payment records.</li>
<li>The <strong>As Of</strong> date lets Finance review receivable aging for a point in time instead of only today.</li>
<li>Payment terms determine the due date and overdue calculation, so confirm client terms before saving old or manual debts.</li>
</ul>
HTML;
    }
};
