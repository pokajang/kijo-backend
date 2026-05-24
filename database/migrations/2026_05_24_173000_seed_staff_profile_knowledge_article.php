<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-update-your-staff-profile';

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
            'title' => 'How to Update Your Staff Profile',
            'slug' => $this->slug,
            'summary' => 'Update your personal staff profile from the Account menu, including contact details, CRM sales position, emergency contacts, and health information used in staff records.',
            'body_html' => $this->articleBody(),
            'category' => 'Leave & HR',
            'tags' => json_encode([
                'staff',
                'profile',
                'account',
                'personal-information',
                'emergency-contact',
                'health',
                'medical',
                'crm-position',
                'hr',
                'staff-records',
            ]),
            'related_route' => null,
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
            $remarks = 'Updated Staff Profile guide with account menu access, editable profile sections, save behavior, and HR record usage.';
        } else {
            $articleId = (int) DB::table('knowledge_articles')->insertGetId($payload + [
                'created_by_staff_id' => null,
                'created_by_name_code' => 'SYSTEM',
                'created_at' => $now,
            ]);
            $action = 'created_published';
            $remarks = 'Production Staff Profile guide seeded by system.';
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
<h3>What Staff Profile is for</h3>
<p>Use <strong>Staff Profile</strong> to keep your personal staff information accurate in KIJO. The information can appear in HR and staff management records, so update it carefully.</p>
<ul>
<li>Use it to update contact details, current address, CRM sales position, emergency contacts, and health or medical notes.</li>
<li>Use it when HR or management asks you to complete missing staff profile information.</li>
<li>Do not use it to change system access, roles, department, position, or employment status. Those are managed by authorized HR, Manager, or System Admin users.</li>
</ul>
<h3>Opening your profile</h3>
<ol>
<li>Open the <strong>Account</strong> menu from the app header or bottom navigation area.</li>
<li>Go to the <strong>Settings</strong> section.</li>
<li>Click <strong>Profile</strong>.</li>
<li>The <strong>My Profile</strong> modal opens.</li>
</ol>
<h3>Reading the profile notice</h3>
<ul>
<li>The blue notice reminds you that profile information should be accurate and true.</li>
<li>The warning notice appears when one or more profile fields are still blank.</li>
<li>The warning does not block saving, but it means the profile may still be incomplete for HR or official record purposes.</li>
</ul>
<h3>Updating general information</h3>
<ol>
<li>Review <strong>Full Name</strong>.</li>
<li>Review <strong>Email</strong>. This field is shown as read-only in the profile modal.</li>
<li>Update <strong>Phone Number</strong> if your mobile number has changed.</li>
<li>Update <strong>Date of Birth</strong>.</li>
<li>Update <strong>IC Number</strong>.</li>
<li>Update <strong>Current Address</strong>.</li>
</ol>
<h3>Updating sales identity</h3>
<ul>
<li><strong>Name Code</strong> is shown as read-only in the profile modal.</li>
<li><strong>CRM Sales Position</strong> can be updated when you have a sales or CRM-facing identity that should appear in staff records.</li>
<li>If your name code is wrong, ask HR or System Admin to correct the staff record instead of trying to change it from this modal.</li>
</ul>
<h3>Updating emergency contacts</h3>
<ol>
<li>Fill <strong>Full Name</strong> for emergency contact person 1.</li>
<li>Fill <strong>Relationship</strong> for person 1.</li>
<li>Fill <strong>Phone Number</strong> for person 1.</li>
<li>Fill <strong>Address</strong> for person 1.</li>
<li>Repeat the same details for person 2 when you have a second emergency contact.</li>
<li>Keep at least one reliable emergency contact accurate at all times.</li>
</ol>
<h3>Updating health and medical concerns</h3>
<ul>
<li>Use <strong>Chronic Illness</strong> for long-term medical conditions that the company should be aware of.</li>
<li>Use <strong>Known Allergies</strong> for allergies that may matter during work, travel, events, site visits, or emergencies.</li>
<li>Use <strong>Disabilities/Impairments</strong> for accessibility or safety-related information.</li>
<li>Use <strong>Current Medications</strong> when medication information may be relevant in an emergency.</li>
<li>Use <strong>Other Concerns / Notes</strong> for additional context that does not fit the other fields.</li>
</ul>
<h3>Saving your profile</h3>
<ol>
<li>Review each section before saving.</li>
<li>Click <strong>Update Profile</strong>.</li>
<li>If the save succeeds, the system shows a success message and closes the modal.</li>
<li>If the save fails, read the error message and correct the highlighted issue where applicable.</li>
</ol>
<h3>Cancelling changes</h3>
<ul>
<li>Click <strong>Cancel</strong> if you do not want to save the current edits.</li>
<li>The modal closes without submitting the current form values.</li>
<li>Open <strong>Profile</strong> again to reload the latest saved information from the server.</li>
</ul>
<h3>Where the information is used</h3>
<ul>
<li>General identity fields are stored in the main staff record.</li>
<li>NRIC, birth date, current address, emergency contacts, and medical notes are stored in the staff profile record.</li>
<li>HR, Manager, and System Admin users can view these details from staff management detail pages when they have permission.</li>
<li>Accurate information helps avoid issues with staff records, emergency handling, and official documentation.</li>
</ul>
<h3>What normal users cannot update here</h3>
<ul>
<li>You cannot update your system role from this modal.</li>
<li>You cannot update your department, employment position, staff type, access status, or employment status from this modal.</li>
<li>You cannot update your password here. Use <strong>Account</strong> then <strong>Password</strong>.</li>
<li>You cannot update your digital signature here. Use <strong>Account</strong> then <strong>Signature</strong>.</li>
</ul>
<h3>Common mistakes to avoid</h3>
<ul>
<li>Do not leave emergency contact phone numbers outdated.</li>
<li>Do not put temporary or unclear information in fields used for staff records.</li>
<li>Do not use the medical notes fields for unrelated personal notes.</li>
<li>Do not assume the warning means saving is blocked. It is a reminder that some information is still blank.</li>
<li>Do not expect read-only fields such as email or name code to be changed from the profile modal.</li>
</ul>
<h3>Troubleshooting</h3>
<ul>
<li>If your profile does not load, refresh the page and open <strong>Account</strong> then <strong>Profile</strong> again.</li>
<li>If saving fails, check that date fields use a valid date and text fields are not unusually long.</li>
<li>If your email or name code is wrong, contact HR or System Admin.</li>
<li>If updated details do not appear in staff management immediately, close and reopen the staff detail view.</li>
<li>If you are signed out, sign in again before opening the profile modal.</li>
</ul>
HTML;
    }
};
