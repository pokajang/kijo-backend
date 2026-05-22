<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-manage-vendors';

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
            'title' => 'How to Manage Vendors',
            'slug' => $this->slug,
            'summary' => 'Find, view, edit, freeze, reactivate, or delete vendor records and understand how vendor details support projects and vendor payments.',
            'body_html' => $this->articleBody(),
            'category' => 'Vendors',
            'tags' => json_encode(['vendor', 'supplier', 'manage-vendors', 'frozen-vendor', 'reactivate', 'vendor-payment', 'project-vendor']),
            'related_route' => '/vendor/manage',
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
            $remarks = 'Expanded vendor management starter guide with the current active and frozen vendor lifecycle.';
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
<h3>Opening Manage Vendors</h3>
<ol>
<li>Open the vendor module and choose <strong>Manage Vendors</strong>.</li>
<li>The vendor module tabs show <strong>Manage Vendors</strong>, <strong>Pay Vendors</strong>, and <strong>Payment Records</strong>.</li>
<li><strong>Manage Vendors</strong> loads active vendor records by default.</li>
<li>Click <strong>Create Vendor</strong> when a new vendor master record needs to be added before it can be assigned to projects or paid.</li>
</ol>
<h3>Reading the active vendor list</h3>
<ul>
<li>The active table shows vendor records with status <strong>Active</strong>.</li>
<li>The default visible columns are <strong>Vendor Name</strong>, <strong>Contact Person</strong>, <strong>Mobile</strong>, and <strong>Category</strong>.</li>
<li>Optional columns include <strong>Email</strong>, <strong>Website</strong>, <strong>Services</strong>, <strong>Bank Name</strong>, <strong>Bank Account</strong>, and <strong>Account Holder</strong>.</li>
<li>The <strong>Services</strong> column combines training topics, competency, supplier products, consultancy, and services offered into one searchable table field.</li>
<li>Click a vendor row to open the vendor in view mode.</li>
</ul>
<h3>Searching and filtering vendors</h3>
<ul>
<li>Use <strong>Search vendor, contact, service</strong> to search vendor name, contact person, mobile number, email, website, bank details, training topics, competency, supplier products, consultancy, or services offered.</li>
<li>Open the filter controls and use <strong>Category</strong> to filter by vendor type.</li>
<li>Choose <strong>All Categories</strong> to remove the category filter.</li>
<li>Use the search and category chips to see which filters are active.</li>
<li>Use <strong>Reset</strong> to clear the current search and category filter.</li>
</ul>
<h3>Viewing a vendor</h3>
<ol>
<li>Click a vendor row or choose <strong>View</strong> from the row action menu.</li>
<li>The <strong>Vendor Details</strong> modal opens.</li>
<li>Review <strong>Vendor Information</strong>, including vendor name, SSM number, SST number, address, city, state, and zip code.</li>
<li>Review <strong>Contact Information</strong>, including contact person, mobile number, email, website, emergency contact, relationship, and emergency number.</li>
<li>Review <strong>Vendor Categories</strong> to understand what the vendor can be used for.</li>
<li>Review the category-specific sections that appear, such as <strong>Training Topics</strong>, <strong>Competencies</strong>, <strong>Products Supplied</strong>, <strong>Consultancy Fields</strong>, or <strong>Services Offered</strong>.</li>
<li>Review <strong>Banking Information</strong> before the vendor is used in payment workflows.</li>
<li>Click <strong>Close</strong> when done.</li>
</ol>
<h3>Editing a vendor</h3>
<ol>
<li>Choose <strong>Edit</strong> from the active vendor row action menu.</li>
<li>The <strong>Edit Vendor</strong> modal opens.</li>
<li>Update <strong>Vendor Details</strong>, including vendor name, SSM number, SST number, address, city, state, and zip code.</li>
<li>Update <strong>Contact Details</strong>, including contact person, mobile number, email, website, emergency contact name, relationship, and emergency contact number.</li>
<li>Update <strong>Vendor Type</strong> by ticking or unticking category checkboxes.</li>
<li>If you untick a vendor category, confirm the warning because related category data is cleared.</li>
<li>Fill category-specific details only for the selected vendor types.</li>
<li>Update <strong>Banking Details</strong>, including bank name, bank account number, and bank holder name.</li>
<li>Click <strong>Save Changes</strong>.</li>
<li>Confirm <strong>Are you sure you want to save changes to this vendor?</strong>.</li>
<li>Use <strong>Cancel</strong> to close the modal without saving.</li>
</ol>
<h3>Understanding vendor categories</h3>
<ul>
<li><strong>Trainer</strong> enables <strong>Training Topics</strong>. Enter one topic per line.</li>
<li><strong>Competent Person</strong> enables <strong>Competency Details</strong>. Tick the relevant competency records.</li>
<li><strong>Equipment Supplier</strong> enables <strong>Products Supplied</strong>. Enter one product per line.</li>
<li><strong>Consultant</strong> enables <strong>Consulting Fields</strong>. Enter one consulting field per line.</li>
<li><strong>Service Provider</strong> enables <strong>Services Offered</strong>. Enter one service per line.</li>
<li>These category details make the vendor easier to find from search, filters, project assignment, and payment context.</li>
</ul>
<h3>Deactivating a vendor</h3>
<ol>
<li>Choose <strong>Deactivate</strong> from the active vendor row action menu.</li>
<li>Confirm the message that the vendor will be moved to the <strong>Frozen Vendor</strong> list.</li>
<li>Enter an optional deactivation reason when prompted.</li>
<li>The system changes the vendor status to <strong>Inactive</strong>, records the deactivation reason, and removes the vendor from the active list.</li>
<li>Use deactivation when the vendor should no longer be selected for normal active workflows but should still remain recoverable.</li>
</ol>
<h3>Opening frozen vendors</h3>
<ol>
<li>Tick <strong>Show Frozen Vendors</strong> in the <strong>Manage Vendors</strong> header.</li>
<li>The <strong>Frozen Vendors</strong> modal opens.</li>
<li>The frozen list loads inactive vendor records.</li>
<li>Use <strong>Search frozen vendor, contact, reason</strong> to search vendor name, contact person, mobile number, email, services, or deactivation reason.</li>
<li>Use <strong>Category</strong> to filter frozen vendors by category.</li>
<li>Click <strong>Close</strong> to leave the frozen vendor modal.</li>
</ol>
<h3>Reviewing frozen vendor details</h3>
<ol>
<li>Click a frozen vendor row in the <strong>Frozen Vendors</strong> modal.</li>
<li>The system opens <strong>Frozen Vendor Details</strong>.</li>
<li>Review vendor name, contact person, mobile number, email, category, training topics, competency, supplier products, consultancy, services offered, and <strong>Deactivation Reason</strong>.</li>
<li>Use <strong>Back</strong> to return to <strong>Manage Vendors</strong>.</li>
</ol>
<h3>Reactivating a frozen vendor</h3>
<ol>
<li>Choose <strong>Reactivate</strong> from the frozen vendor row action menu or the <strong>Frozen Vendor Details</strong> action menu.</li>
<li>Confirm <strong>Reactivate vendor</strong> when prompted.</li>
<li>The system changes the vendor status back to <strong>Active</strong>.</li>
<li>The system clears the previous deleted date, deleted by value, and deactivation reason.</li>
<li>The vendor appears again in the active <strong>Manage Vendors</strong> list.</li>
</ol>
<h3>Permanently deleting a frozen vendor</h3>
<ol>
<li>Choose <strong>Delete</strong> from the frozen vendor row action menu or the <strong>Frozen Vendor Details</strong> action menu.</li>
<li>Confirm the warning that permanent deletion cannot be undone.</li>
<li>The system deletes the vendor master record and related category, training, competency, supplier, consultancy, and service records.</li>
<li>Use permanent deletion only for records that were created incorrectly and should not remain in the system.</li>
</ol>
<h3>How vendor records are used later</h3>
<ul>
<li>Active vendor records are used when assigning vendors inside project management workflows.</li>
<li>Vendor category and service information helps users select the correct trainer, consultant, competent person, service provider, or equipment supplier.</li>
<li>Banking details are used as payment reference information in vendor payment workflows.</li>
<li>The <strong>Pay Vendors</strong> tab handles vendor payment requests.</li>
<li>The <strong>Payment Records</strong> tab is used to review vendor payment history.</li>
<li>Frozen vendors should be reactivated before they are used again in normal project or payment workflows.</li>
</ul>
<h3>Recommended operating practice</h3>
<ul>
<li>Keep <strong>Vendor Name</strong>, <strong>Mobile Number</strong>, <strong>Bank Name</strong>, <strong>Bank Account Number</strong>, and <strong>Bank Holder Name</strong> accurate because they are required by the backend update flow.</li>
<li>Choose vendor categories carefully because removing a category clears its related category details.</li>
<li>Use <strong>Deactivate</strong> before using <strong>Delete</strong> so the record can be recovered if the vendor is only temporarily inactive.</li>
<li>Record a clear deactivation reason when freezing a vendor so future users understand why the vendor was removed from active use.</li>
<li>Use permanent <strong>Delete</strong> only from the frozen vendor lifecycle and only after confirming the vendor record should not be retained.</li>
</ul>
HTML;
    }
};
