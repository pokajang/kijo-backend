<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-create-a-client';

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
            'title' => 'How to Create a Client',
            'slug' => $this->slug,
            'summary' => 'Create a client company profile with payment terms, address, optional branches, and one or more client PICs.',
            'body_html' => $this->articleBody(),
            'category' => 'CRM',
            'tags' => json_encode(['client', 'company', 'pic', 'branch', 'payment-terms', 'quotation', 'inquiry']),
            'related_route' => '/client/create',
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
            $remarks = 'Expanded client creation starter guide with the current module flow.';
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
<h3>Starting a client record</h3>
<ol>
<li>Open <strong>Client List</strong>.</li>
<li>Click <strong>Create Client</strong>.</li>
<li>The system opens <strong>Company Details</strong>.</li>
<li>Use <strong>Back</strong> if you need to return to the client list or to the workflow that sent you here.</li>
</ol>
<h3>When this page is used</h3>
<ul>
<li>Use this page to create a new company profile before creating quotations, projects, debtor records, or vendor registration records.</li>
<li>If you are sent here from an inquiry, the form may be prefilled from the inquiry details.</li>
<li>If you are sent here from quotation, debtor, vendor registration, or inquiry workflows, the system can return you to that workflow after saving.</li>
</ul>
<h3>Filling company details</h3>
<ul>
<li>Enter the company name in <strong>Company Name</strong>. This field is required.</li>
<li>Enter <strong>SSM Number</strong> if applicable.</li>
<li>Enter <strong>Tax Id. No. (TIN)</strong> if available.</li>
<li>Choose <strong>Client Status</strong>: <strong>New</strong> or <strong>Old</strong>.</li>
<li>Choose <strong>Payment Terms</strong>. Use <strong>Default (30 days)</strong> unless the client has approved custom terms.</li>
<li>If custom terms are needed, choose <strong>Custom</strong> and enter the number of days.</li>
</ul>
<h3>Handling duplicate warnings</h3>
<ul>
<li>The form checks existing client companies while you type.</li>
<li>If an exact company match is found, the warning says the company already exists.</li>
<li>If a similar company name is found, confirm whether you are creating a duplicate.</li>
<li>If the record is a branch of an existing company but should be a separate client record, add a clear branch remark to <strong>Company Name</strong>, such as <strong>XYZ Sdn Bhd - KL Branch</strong>.</li>
<li>Duplicate warnings are guidance. Always check the existing client list before saving uncertain records.</li>
</ul>
<h3>Filling address details</h3>
<ul>
<li>Choose <strong>Country</strong>. Malaysia is selected by default.</li>
<li>For Malaysian clients, choose the Malaysian <strong>State</strong> from the list.</li>
<li>For international clients, choose <strong>Other (specify)</strong>, then enter <strong>Country Name</strong>.</li>
<li>Enter <strong>Address</strong>, <strong>City</strong>, <strong>State / Province / Region</strong>, and <strong>Postal Code</strong> or <strong>Zip Code</strong>.</li>
<li>Trailing punctuation is cleaned when you leave several text fields.</li>
</ul>
<h3>Adding branches</h3>
<ol>
<li>Turn on <strong>Add Branch</strong> only when the branch shares the same SSM and TIN as the main company.</li>
<li>If the branch has a different SSM or TIN, create a separate client record instead.</li>
<li>Enter <strong>Branch Name</strong>. If left blank, the system names it as the next branch number.</li>
<li>Enter the branch <strong>Address</strong>. This field is required before a branch can be added.</li>
<li>Choose the branch <strong>Country</strong>.</li>
<li>For international branches, choose <strong>Other (specify)</strong>, then fill <strong>Country Name</strong>.</li>
<li>Fill <strong>City</strong>, <strong>State</strong> or <strong>State / Province / Region</strong>, and <strong>Zip Code</strong> or <strong>Postal Code</strong>.</li>
<li>Click <strong>Add Branch</strong>.</li>
<li>Use <strong>Remove</strong> if an added branch should not be saved.</li>
</ol>
<h3>Adding client PICs</h3>
<ol>
<li>Open <strong>Client In Charge Details</strong>.</li>
<li>Enter the PIC <strong>Full Name</strong>. This field is required.</li>
<li>Enter the PIC <strong>Email</strong>. This field is required.</li>
<li>Enter <strong>Mobile</strong>. The form starts with <strong>601</strong> as the default prefix.</li>
<li>Enter <strong>Position</strong> if known.</li>
<li>Click <strong>Add PIC</strong>.</li>
<li>Repeat the same steps if the client has multiple PICs.</li>
<li>Use <strong>Remove</strong> if a PIC was added by mistake.</li>
</ol>
<h3>Handling PIC duplicate warnings</h3>
<ul>
<li>The form checks existing PIC names and emails.</li>
<li>If the full name already exists, confirm whether it is the same person before saving.</li>
<li>If a similar full name appears, verify that you are not duplicating a contact.</li>
<li>If the email is already used by another contact, confirm before adding the PIC.</li>
</ul>
<h3>Saving the client</h3>
<ol>
<li>Confirm that <strong>Company Name</strong> is filled.</li>
<li>Confirm that at least one PIC has both <strong>Full Name</strong> and <strong>Email</strong>.</li>
<li>Click <strong>Create Client</strong>.</li>
<li>The system creates the company, assigns the PICs, creates active branches, and stores the payment terms.</li>
<li>The company status is saved as active.</li>
</ol>
<h3>After saving</h3>
<ul>
<li>If you came from <strong>Client List</strong>, choose <strong>Go to list</strong> to open <strong>Client List</strong>.</li>
<li>If you came from an inquiry, choose <strong>Go to inquiry</strong>. The created client is linked back to the inquiry when possible.</li>
<li>If you came from debtor creation, choose <strong>Go to debtor</strong>.</li>
<li>If you came from vendor registration, choose <strong>Go to vendor registration</strong>.</li>
<li>If you came from quotation, choose <strong>Go to quotation</strong>.</li>
<li>Choose <strong>Create another</strong> to stay on the creation flow and enter another client.</li>
</ul>
<h3>Resetting the form</h3>
<ul>
<li>Click <strong>Reset</strong> to clear company details, PICs, branches, and the current branch form.</li>
<li>Confirm <strong>Reset the form? This will clear all fields.</strong>.</li>
<li>Reset returns the form to default payment terms, country Malaysia, status New, and mobile prefix 601.</li>
</ul>
<h3>Finding the saved client</h3>
<ul>
<li>Open <strong>Client List</strong> after saving.</li>
<li>Search by company name, SSM, TIN, client status, PIC details, address, branch details, city, state, or zip.</li>
<li>Use the status filter to show <strong>New</strong>, <strong>Old</strong>, or records without status.</li>
<li>Use the branch filter to find clients with or without branches.</li>
<li>Open the client detail page to review company information, payment terms, branches, and PICs.</li>
</ul>
<h3>Editing or deleting later</h3>
<ul>
<li>Use <strong>Edit</strong> on the client detail page or client list action menu to update company details, PICs, and branches.</li>
<li>Use <strong>Delete</strong> only when the company record should be deactivated.</li>
<li>Deleting a client company soft-deletes the company and branches, and unassigns its PICs.</li>
</ul>
HTML;
    }
};
