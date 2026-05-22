<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-use-commercial-records';

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
            'title' => 'How to Use Commercial Records',
            'slug' => $this->slug,
            'summary' => 'Use the Commercial records area to review invoices, debtors, JD14 forms, vendor LOAs, supplier POs, and delivery orders across the project commercial lifecycle.',
            'body_html' => $this->articleBody(),
            'category' => 'Commercial',
            'tags' => json_encode(['commercial', 'invoice', 'debtor', 'jd14', 'vendor-loa', 'supplier-po', 'delivery-order', 'payment']),
            'related_route' => '/commercial/invoice',
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
            $remarks = 'Expanded commercial records guide with the current module tabs, list controls, detail actions, and payment lifecycle.';
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
<h3>Opening Commercial Records</h3>
<ol>
<li>Open the <strong>Commercial</strong> module from the navigation.</li>
<li>Use the commercial module tabs to move between <strong>Invoice</strong>, <strong>Debtors</strong>, <strong>JD 14</strong>, <strong>Vendor LOAs</strong>, <strong>Supplier POs</strong>, and <strong>Delivery Order</strong>.</li>
<li>Each tab is a record register for documents or finance records created from project, catalog, vendor, or manual commercial workflows.</li>
<li>Click any record row to open the detail page for that record.</li>
</ol>
<h3>How commercial records are created</h3>
<ul>
<li><strong>Invoices</strong> are normally generated from the project management workflow after a project is awarded or created.</li>
<li><strong>Delivery Orders</strong> are generated from project delivery workflows, especially when project items or equipment need a delivery document.</li>
<li><strong>JD 14</strong> forms are generated for training projects that need the HRD/JD14 claim document.</li>
<li><strong>Vendor LOAs</strong> are created when a vendor is assigned to a project from <strong>Vendor Details</strong> in project management.</li>
<li><strong>Supplier POs</strong> are created from catalog purchase order workflows.</li>
<li><strong>Debtors</strong> combines system invoices with manual receivable entries that are not traceable to an invoice created inside KIJO.</li>
</ul>
<h3>Using common list controls</h3>
<ul>
<li>Most commercial tabs load the current year-to-date period by default.</li>
<li>Use the period selector to change the reporting range when the tab shows date-based records.</li>
<li>Use <strong>Type to search...</strong> to search the current tab.</li>
<li>Open the filter controls to use filters such as <strong>Person In Charge</strong>, <strong>Type of Service</strong>, <strong>Status</strong>, <strong>Training Payment</strong>, <strong>Training Title</strong>, <strong>Source</strong>, or <strong>As Of</strong>.</li>
<li>Use active filter chips to see which filters are currently applied.</li>
<li>Use <strong>Reset</strong> to return the tab to its default search and filter state.</li>
<li>Use the table tools to change visible columns or export the current list when those tools are available.</li>
</ul>
<h3>Reading commercial stats</h3>
<ul>
<li><strong>Invoice</strong> shows stats such as <strong>Invoices</strong>, <strong>Total Amount</strong>, <strong>Unpaid</strong>, and <strong>Top Internal PIC</strong>.</li>
<li><strong>Debtors</strong> shows receivable aging stats such as <strong>Open Receivables</strong>, <strong>More Than 30 Days</strong>, <strong>31-60 Days</strong>, and <strong>61+ Days</strong>.</li>
<li><strong>JD 14</strong> shows <strong>JD14 Forms</strong>, <strong>Completed</strong>, <strong>Ongoing</strong>, and <strong>Upcoming</strong>.</li>
<li><strong>Vendor LOAs</strong> shows <strong>LOAs</strong>, <strong>Total Value</strong>, <strong>Pending</strong>, and <strong>Top Award By</strong>.</li>
<li><strong>Supplier POs</strong> shows <strong>POs</strong>, <strong>Total Value</strong>, <strong>Pending</strong>, and <strong>Top Creator</strong>.</li>
<li><strong>Delivery Order</strong> shows <strong>Delivery Orders</strong>, <strong>Issued</strong>, <strong>Upcoming</strong>, and <strong>Top PIC</strong>.</li>
<li>Click a clickable stat card to apply the related filter when the card supports filtering.</li>
</ul>
<h3>Managing invoices</h3>
<ol>
<li>Open the <strong>Invoice</strong> tab.</li>
<li>Use <strong>Person In Charge</strong>, <strong>Type of Service</strong>, <strong>Training Payment</strong>, and <strong>Status</strong> to filter invoices.</li>
<li>Use <strong>Training Payment</strong> only after <strong>Type of Service</strong> is set to <strong>Training</strong>.</li>
<li>Click an invoice row to open <strong>Invoice Details</strong>.</li>
<li>Use <strong>View</strong> to open the invoice view modal from the row action menu.</li>
<li>Use <strong>Edit</strong> to update an invoice while it is not paid.</li>
<li>If the invoice is already <strong>Paid</strong>, use <strong>Mark as Pending</strong> before editing.</li>
<li>Use <strong>HRD Claim Ref</strong> for Training invoices using HRD payment method.</li>
<li>Use <strong>PDF Invoice</strong> to open the invoice PDF.</li>
<li>Use <strong>Mark as Paid</strong> when the payment has been received.</li>
<li>Use <strong>PDF Receipt</strong> only after the invoice is marked as paid.</li>
<li>Use <strong>Delete</strong> only when the invoice record was created incorrectly.</li>
</ol>
<h3>Marking invoices paid or pending</h3>
<ul>
<li><strong>Mark as Paid</strong> opens a payment modal and updates the invoice payment status after confirmation.</li>
<li>Paid invoices can generate <strong>PDF Receipt</strong>.</li>
<li><strong>Mark as Pending</strong> clears the paid state and returns the invoice to an editable pending status.</li>
<li>Use paid and pending changes only when the finance status has actually changed.</li>
</ul>
<h3>Tracking debtors</h3>
<ol>
<li>Open the <strong>Debtors</strong> tab.</li>
<li>The default view shows <strong>Open</strong> receivables.</li>
<li>Use <strong>Status</strong> to switch between <strong>Open</strong>, <strong>Paid</strong>, <strong>Cancelled</strong>, and <strong>All</strong>.</li>
<li>Use <strong>Source</strong> to switch between <strong>System Invoices</strong>, <strong>Manual Debtors</strong>, and all debtor rows.</li>
<li>Use <strong>As Of</strong> to review debtor aging as at a selected date.</li>
<li>Rows from invoices are marked as <strong>System Invoice</strong>.</li>
<li>Rows created manually are marked as <strong>Manual Entry</strong>.</li>
</ol>
<h3>Managing debtor rows</h3>
<ul>
<li>For system invoice debtors, use <strong>Open Invoice</strong> to open the linked invoice detail page.</li>
<li>For system invoice debtors, use <strong>PDF Invoice</strong> to open the invoice PDF.</li>
<li>Use <strong>Mark Paid</strong> on open debtor rows when payment is received.</li>
<li>For manual debtors, use <strong>Edit</strong> to update the manual debtor record.</li>
<li>For manual debtors, use <strong>Attachment</strong> to open the uploaded supporting file when one exists.</li>
<li>For paid manual debtors, use <strong>Mark Open</strong> when the payment status needs to be reopened.</li>
<li>For manual debtors, use <strong>Delete</strong> only when the manual receivable record should be removed.</li>
</ul>
<h3>Adding a manual debtor</h3>
<ol>
<li>Open <strong>Debtors</strong>.</li>
<li>Click <strong>Add Debtor</strong>.</li>
<li>Use <strong>Select Client</strong> to search for the client company.</li>
<li>If the client does not exist, use <strong>Create one?</strong> to create the client first.</li>
<li>If the client has multiple contacts, use <strong>Select all</strong>, <strong>Primary only</strong>, or tick the specific contact rows.</li>
<li>Fill <strong>Invoice Ref</strong>, <strong>Invoice Date</strong>, <strong>Payment Terms</strong>, <strong>Grand Total</strong>, and <strong>Service</strong>.</li>
<li>Fill <strong>Service Start Date</strong> and <strong>Service End Date</strong> when the debt is tied to a service period.</li>
<li>Add useful context in <strong>Remarks</strong>.</li>
<li>Choose <strong>Status</strong>: <strong>Open</strong>, <strong>Paid</strong>, or <strong>Cancelled</strong>.</li>
<li>Fill <strong>Payment Method</strong> if known.</li>
<li>Upload an <strong>Attachment</strong> if there is supporting proof.</li>
<li>If <strong>Status</strong> is <strong>Paid</strong>, fill <strong>Paid Date</strong>, <strong>Paid Amount</strong>, and <strong>Paid Remarks</strong>.</li>
<li>Click <strong>Save</strong>.</li>
</ol>
<h3>Managing JD14 forms</h3>
<ol>
<li>Open the <strong>JD 14</strong> tab.</li>
<li>Use <strong>Person In Charge</strong>, <strong>Training Title</strong>, and <strong>Status</strong> to filter forms.</li>
<li>The list status is calculated from the training dates: <strong>Upcoming</strong>, <strong>Ongoing</strong>, or <strong>Completed</strong>.</li>
<li>Click a JD14 row to open <strong>JD14 Details</strong>.</li>
<li>Use <strong>Edit</strong> to update the JD14 form details.</li>
<li>Use <strong>Generate PDF</strong> to open the JD14 PDF.</li>
<li>Use <strong>Delete</strong> only when the JD14 record was created incorrectly.</li>
</ol>
<h3>Reviewing JD14 details</h3>
<ul>
<li><strong>JD14 Details</strong> shows approval number, employer, course, commenced date, ended date, venue, and creator.</li>
<li>Use <strong>Back</strong> to return to the JD14 list.</li>
<li>Use the detail page action menu when you need to edit, generate PDF, or delete from the full record view.</li>
</ul>
<h3>Managing Vendor LOAs</h3>
<ol>
<li>Open the <strong>Vendor LOAs</strong> tab.</li>
<li>Use <strong>Person In Charge</strong> and <strong>Status</strong> to filter vendor LOA records.</li>
<li>Use search to find LOA reference number, vendor name, project name, service description, or award by code.</li>
<li>Click a row to open <strong>Vendor LOA Details</strong>.</li>
<li>Use <strong>Edit</strong> to update the vendor assignment details.</li>
<li>Use <strong>Generate LOA</strong> to open the vendor LOA PDF.</li>
<li>Use <strong>Mark Paid</strong> only when the vendor payment is approved and ready to record as paid.</li>
<li>Use <strong>Delete</strong> only when the vendor assignment or LOA record should be removed.</li>
</ol>
<h3>Understanding Vendor LOA payment status</h3>
<ul>
<li><strong>No Request</strong> means no vendor payment request exists for the LOA.</li>
<li><strong>Payment Requested</strong> means a payment request exists but has not reached system-level approval.</li>
<li><strong>System Level Approved, Pending Bank Transfer</strong> means the payment has been approved but still needs bank transfer completion.</li>
<li><strong>Mark Paid</strong> is enabled only for manager, admin, finance, account, or bank roles when an approved payment request and approval date exist.</li>
<li>When marking paid, enter the transaction date before confirming.</li>
</ul>
<h3>Reviewing Vendor LOA details</h3>
<ul>
<li><strong>Vendor LOA Details</strong> shows LOA reference number, vendor, project, service, position, payment terms, value, award date, award by, payment request date, approval date, status, venue, fee breakdown, and remarks.</li>
<li>Use <strong>Back</strong> to return to the <strong>Vendor LOAs</strong> list.</li>
<li>Use the detail page action menu for <strong>Edit</strong>, <strong>Generate LOA</strong>, <strong>Mark Paid</strong>, or <strong>Delete</strong>.</li>
</ul>
<h3>Managing Supplier POs</h3>
<ol>
<li>Open the <strong>Supplier POs</strong> tab.</li>
<li>The page title is <strong>Equipment Supplier PO</strong>.</li>
<li>Use <strong>Person In Charge</strong>, <strong>Type of Service</strong>, and <strong>Status</strong> to filter purchase orders.</li>
<li>Use search to find PO reference number, supplier name, supplier contact, supplier phone, item details, or creator.</li>
<li>Click a PO row to open <strong>Supplier PO Details</strong>.</li>
<li>Use <strong>View</strong> to open the detail page.</li>
<li>Use <strong>Preview Modal</strong> to view the PO in a modal.</li>
<li>Use <strong>Export PDF</strong> to open the supplier PO PDF.</li>
<li>Use <strong>Mark Paid</strong> after supplier payment is completed.</li>
<li>Use <strong>Delete</strong> only when the PO record should be removed.</li>
</ol>
<h3>Reviewing Supplier PO details</h3>
<ul>
<li><strong>Supplier PO Details</strong> shows supplier name, address, contact, phone, PO number, issued date, status, remarks, grand total, and item lines.</li>
<li>The item table shows item, description, quantity, unit, unit price, and line total.</li>
<li>Use <strong>Export PDF</strong> to generate the purchase order document.</li>
<li>Use <strong>Mark Paid</strong> to record payment date and remarks.</li>
<li>Use <strong>Back</strong> to return to the <strong>Supplier POs</strong> list.</li>
</ul>
<h3>Managing Delivery Orders</h3>
<ol>
<li>Open the <strong>Delivery Order</strong> tab.</li>
<li>Use <strong>Person In Charge</strong>, <strong>Type of Service</strong>, and <strong>Status</strong> to filter delivery orders.</li>
<li>Use search to find DO number, project name, client name, client contact, issuer, project type, or item details.</li>
<li>Click a delivery order row to open <strong>Delivery Order Details</strong>.</li>
<li>Use <strong>View</strong> to open the detail page.</li>
<li>Use <strong>Preview Modal</strong> to view the delivery order in a modal.</li>
<li>Use <strong>Edit</strong> to update delivery order details and item breakdown.</li>
<li>Use <strong>Generate PDF</strong> to open the delivery order PDF.</li>
<li>Use <strong>Delete</strong> only when the delivery order record should be removed.</li>
</ol>
<h3>Reviewing Delivery Order details</h3>
<ul>
<li><strong>Delivery Order Details</strong> shows client, address, contact, position, phone, email, DO number, project, project code, project type, award date, service period, status, and description.</li>
<li><strong>Issued By</strong> shows the company contact name, email, and phone.</li>
<li><strong>Item Breakdown</strong> shows item name, description, quantity, and unit.</li>
<li>Use <strong>Edit</strong> to update client, project, issued by, or item breakdown details.</li>
<li>Use <strong>Back</strong> to return to the <strong>Delivery Order</strong> list.</li>
</ul>
<h3>Recommended commercial workflow</h3>
<ul>
<li>Generate commercial documents from project or catalog workflows whenever possible so the records stay linked to project and quotation history.</li>
<li>Use <strong>Add Debtor</strong> only for old, off-system, or manual receivables that are not already represented by a system invoice.</li>
<li>Use <strong>Mark as Paid</strong>, <strong>Mark Paid</strong>, or supplier/vendor payment paid actions only after payment details are confirmed.</li>
<li>Use PDF actions such as <strong>PDF Invoice</strong>, <strong>PDF Receipt</strong>, <strong>Generate PDF</strong>, <strong>Generate LOA</strong>, and <strong>Export PDF</strong> for client-facing, vendor-facing, or supplier-facing documents.</li>
<li>Use <strong>Delete</strong> only for records created incorrectly. If a record is part of an actual project or finance trail, confirm the correct corrective workflow before deleting it.</li>
<li>Use filters, stats, and aging views for finance follow-up, collection review, and payment status checking.</li>
</ul>
HTML;
    }
};
