<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-manage-client-vendor-registrations';

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
            'title' => 'How to Manage Client Vendor Registrations',
            'slug' => $this->slug,
            'summary' => 'Track client portal vendor registrations, expiry dates, certificates, login details, notification recipients, renewals, and reminder follow-up from the Client module.',
            'body_html' => $this->articleBody(),
            'category' => 'CRM',
            'tags' => json_encode(['client', 'vendor-registration', 'registration', 'certificate', 'portal', 'renewal', 'expiry', 'reminder', 'crm']),
            'related_route' => '/client/vendor-registration',
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
            $remarks = 'Updated client vendor registration guide with list controls, creation, renewal, certificate, portal credential, reminder, and deletion lifecycle.';
        } else {
            $articleId = (int) DB::table('knowledge_articles')->insertGetId($payload + [
                'created_by_staff_id' => null,
                'created_by_name_code' => 'SYSTEM',
                'created_at' => $now,
            ]);
            $action = 'created_published';
            $remarks = 'Production client vendor registration guide seeded by system.';
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
<h3>What this module is for</h3>
<p><strong>Vendor Registration</strong> in the Client module tracks client-side vendor registration records, especially when a client requires our company to be registered in their portal before quotations, onboarding, work orders, claims, or payments can proceed.</p>
<ul>
<li>Use it to store registration validity dates, certificate files, portal links, portal login details, internal notification recipients, and remarks.</li>
<li>Use it to monitor registrations that are active, expiring soon, expired, or missing certificates.</li>
<li>Use it as an operational reminder surface, not as the official legal source of the certificate itself.</li>
</ul>
<h3>Opening the Vendor Registration list</h3>
<ol>
<li>Open <strong>Clients</strong> from the navigation.</li>
<li>Choose the <strong>Vendor Registration</strong> tab in the client module strip.</li>
<li>Review the summary cards for <strong>Total Registrations</strong>, <strong>Active</strong>, <strong>Expiring Soon</strong>, and <strong>Expired</strong>.</li>
<li>Click <strong>Active</strong>, <strong>Expiring Soon</strong>, or <strong>Expired</strong> to apply that status filter quickly.</li>
</ol>
<h3>Understanding registration status</h3>
<ul>
<li><strong>Expired</strong> means the <strong>Valid Until</strong> date is before today.</li>
<li><strong>Expiring Soon</strong> means the registration expires within 60 days, including registrations that expire within 7 or 30 days.</li>
<li><strong>Missing Certificate</strong> means the record is not expired or expiring soon, but no certificate file is uploaded.</li>
<li><strong>Active</strong> means the registration has a certificate and the expiry date is more than 60 days away.</li>
<li><strong>Days Left</strong> is calculated from the <strong>Valid Until</strong> date.</li>
</ul>
<h3>Searching and filtering records</h3>
<ol>
<li>Use <strong>Search client, recipient, certificate, portal, remarks</strong> to search the visible vendor registration rows.</li>
<li>Open filters and use <strong>Status</strong> to show all statuses, active records, expiring records, expired records, or records missing certificates.</li>
<li>Use <strong>Recipient</strong> to show records assigned to a specific notification recipient.</li>
<li>Use <strong>Columns</strong> to show optional fields such as portal URL, username, remarks, and updated date.</li>
<li>Use <strong>Export</strong> when you need a CSV of the current register.</li>
</ol>
<h3>Creating a vendor registration</h3>
<ol>
<li>Open <strong>Vendor Registration</strong>.</li>
<li>Click <strong>Add Registration</strong>.</li>
<li>Use <strong>Select Client</strong> to choose the client company.</li>
<li>If the client does not exist, use the create-client action from the selector. KIJO marks that you came from vendor registration and can return you to the registration form after the client is created.</li>
<li>Fill <strong>Validity Start</strong> and <strong>Validity End</strong>. The end date must be on or after the start date.</li>
<li>Select at least one <strong>Notification Recipient</strong>. Only active staff with valid email addresses can be saved as recipients.</li>
<li>Upload the <strong>Registration Certificate</strong> when available. Supported files are PDF, JPG, JPEG, PNG, and WebP up to 10 MB.</li>
<li>Fill <strong>Portal URL</strong> if the client has an online vendor portal. The URL must start with <strong>http://</strong> or <strong>https://</strong>.</li>
<li>Fill <strong>Username or Email</strong> and <strong>Portal Password</strong> only when the portal credential should be stored for internal use.</li>
<li>Add concise context in <strong>Registration Remarks</strong>, such as renewal submitted, pending approval, certificate requested, or client portal notes.</li>
<li>Click <strong>Save Registration</strong>.</li>
</ol>
<h3>Important save rules</h3>
<ul>
<li>Each client can have only one active, non-deleted vendor registration record.</li>
<li>The selected client must exist in client records.</li>
<li>At least one notification recipient is required.</li>
<li>Portal credentials are shown only on the detail page, and the password is encrypted when stored.</li>
<li>Uploading a new certificate during edit replaces the previous stored certificate file.</li>
</ul>
<h3>Opening details</h3>
<ol>
<li>Click a vendor registration row to open <strong>Vendor Registration Details</strong>.</li>
<li>Review client, client status, valid dates, days left, status, notification recipients, certificate, portal details, and remarks.</li>
<li>Click the certificate link or <strong>Open Certificate</strong> to view the uploaded certificate.</li>
<li>Use <strong>Show</strong> to reveal the portal password only when needed.</li>
<li>Use <strong>Copy</strong> to copy the portal password for portal login.</li>
<li>Click <strong>Back</strong> to return to the list.</li>
</ol>
<h3>Editing or renewing a registration</h3>
<ol>
<li>Open the row action menu or the detail page actions.</li>
<li>Use <strong>Edit</strong> for active, expiring soon, or missing-certificate records.</li>
<li>Use <strong>Renew Registration</strong> for expired records. The same form opens, but the action is labelled as renewal.</li>
<li>Update validity dates, recipients, certificate, portal details, or remarks.</li>
<li>Click <strong>Save Registration</strong> or <strong>Save Renewal</strong>.</li>
<li>The list refreshes and the client vendor registration attention count is updated.</li>
</ol>
<h3>Deleting a registration</h3>
<ul>
<li>Use <strong>Delete</strong> from the row action menu or detail page only when the record should no longer be tracked.</li>
<li>The record is soft-deleted and removed from the list.</li>
<li>The uploaded certificate file is removed when the registration is deleted.</li>
<li>If the registration is still operationally relevant, renew or edit it instead of deleting it.</li>
</ul>
<h3>Reminder and notification lifecycle</h3>
<ul>
<li>The system tracks expired and expiring registrations for the <strong>Vendor Registration</strong> tab badge and the app notification summary.</li>
<li>Internal reminder emails are sent to selected notification recipients when a registration reaches reminder stages.</li>
<li>The reminder stages are <strong>Expires within 60 days</strong>, <strong>Expires within 30 days</strong>, <strong>Expires within 7 days</strong>, and <strong>Expired</strong>.</li>
<li>The scheduled reminder command runs daily at 08:45 when mail is configured.</li>
<li>Each recipient receives each reminder stage once per registration, based on the reminder log.</li>
</ul>
<h3>Recommended operating practice</h3>
<ul>
<li>Keep the certificate uploaded whenever possible so the record can be verified quickly.</li>
<li>Choose recipients who are responsible for renewal action, not just people who need visibility.</li>
<li>Use remarks to record renewal status and client portal follow-up notes.</li>
<li>Review <strong>Expiring Soon</strong> records regularly so registration renewal does not block quotations, project onboarding, delivery, invoicing, or payment.</li>
</ul>
HTML;
    }
};
