<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-find-prospects-and-manage-call-records';

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
            'title' => 'How to Find Prospects and Manage Call Records',
            'slug' => $this->slug,
            'summary' => 'Use Factory Directory to find Google-sourced prospects, register useful contacts into Call Records, then log call outcomes, follow-ups, and contact history.',
            'body_html' => $this->articleBody(),
            'category' => 'CRM',
            'tags' => json_encode(['pipeline', 'call-list', 'call-records', 'factory-directory', 'google-places', 'prospecting', 'calls', 'follow-up', 'crm']),
            'related_route' => '/pipeline/find',
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
            $remarks = 'Updated combined Call List and Call Records guide with prospect generation, registration, call logging, filtering, detail, and deletion lifecycle.';
        } else {
            $articleId = (int) DB::table('knowledge_articles')->insertGetId($payload + [
                'created_by_staff_id' => null,
                'created_by_name_code' => 'SYSTEM',
                'created_at' => $now,
            ]);
            $action = 'created_published';
            $remarks = 'Production Call List and Call Records guide seeded by system.';
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
<h3>How the two pages work together</h3>
<p><strong>Factory Directory</strong> and <strong>Call Records</strong> are one prospecting flow.</p>
<ul>
<li>Use <strong>Factory Directory</strong> to discover possible companies from Google Places and choose which prospects are worth saving.</li>
<li>Use <strong>Call Records</strong> to manage saved contacts and log every marketing call against each contact.</li>
<li>Once a Factory Directory record is registered, it is removed from the unregistered factory list and appears in Call Records.</li>
</ul>
<h3>Opening Factory Directory</h3>
<ol>
<li>Open <strong>Pipeline CRM</strong> from the navigation.</li>
<li>Choose <strong>Find Clients</strong>. This opens <strong>Factory Directory (Google On-Demand)</strong>.</li>
<li>The page loads unregistered Google place records, meaning places that have not yet been saved into Call Records.</li>
<li>Use the <strong>About</strong> button to confirm the behavior: phone details are fetched live from Google and are not stored automatically until you register the contact.</li>
</ol>
<h3>Generating prospect rows</h3>
<ol>
<li>In Factory Directory, search using keywords such as <strong>factory</strong>, <strong>kilang</strong>, <strong>manufacturer</strong>, industry names, or service targets.</li>
<li>Open filters and choose <strong>Region (bias)</strong> when you want to bias the Google search to a Malaysian state or all Malaysia.</li>
<li>Choose <strong>Generate count</strong> to control how many Google Places results to request, from 5 to 50.</li>
<li>Click <strong>Generate</strong>.</li>
<li>KIJO calls Google Places Text Search, inserts new place records, and refreshes the table.</li>
<li>If Google rejects or cannot complete the request, read the error message for the Google status and message.</li>
</ol>
<h3>Filtering Factory Directory</h3>
<ol>
<li>Use the search box to filter by company name or address.</li>
<li>Use <strong>Region (bias)</strong> to narrow visible rows by address text.</li>
<li>Use the period selector to filter place rows by created or updated date.</li>
<li>Use <strong>Columns</strong> to adjust the visible columns.</li>
<li>Use <strong>Export</strong> if you need a CSV of the current unregistered factory list.</li>
</ol>
<h3>Fetching phone details</h3>
<ol>
<li>Find a prospect row in Factory Directory.</li>
<li>Click <strong>Show phone</strong> in the phone column or row action menu.</li>
<li>KIJO calls Google Place Details for that place and shows the live phone number when available.</li>
<li>If Google cannot return a phone, the row shows <strong>Unavailable</strong> and the action becomes disabled for that place.</li>
<li>Phone and website details are held in the page state until the contact is registered.</li>
</ol>
<h3>Registering a prospect to Call Records</h3>
<ol>
<li>On a Factory Directory row, choose <strong>Register to Call Records</strong>.</li>
<li>If phone details have not been loaded yet, KIJO tries to fetch them before opening the registration modal.</li>
<li>Review <strong>Business Name</strong>, <strong>Phone</strong>, <strong>Address</strong>, and <strong>Website</strong>.</li>
<li>Edit the fields if the Google result needs cleanup.</li>
<li>Click <strong>Register to Call Records</strong>.</li>
<li>After registration, the modal confirms that the contact has been registered and you can start phone call marketing.</li>
</ol>
<h3>Duplicate handling during registration</h3>
<ul>
<li>If the same Google place was already registered, KIJO reuses the existing contact record.</li>
<li>If the same normalized phone number already exists, KIJO reuses the existing contact record.</li>
<li>This prevents the same prospect from being registered repeatedly from Factory Directory.</li>
</ul>
<h3>Opening Call Records</h3>
<ol>
<li>Open <strong>Pipeline CRM</strong>.</li>
<li>Choose <strong>Call Records</strong>.</li>
<li>The page loads saved contacts together with their call logs.</li>
<li>The default quick period is year-to-date, so stats and visible call logs initially focus on current-year activity.</li>
<li>Use <strong>Add My Contact</strong> when you already have a prospect that did not come from Factory Directory.</li>
</ol>
<h3>Adding a contact manually</h3>
<ol>
<li>Click <strong>Add My Contact</strong>.</li>
<li>Fill <strong>Company Name</strong>. This is required.</li>
<li>Fill <strong>Phone</strong>, <strong>Address</strong>, and <strong>Web URL</strong> when available.</li>
<li>Click <strong>Save</strong>.</li>
<li>After saving, KIJO refreshes Call Records and immediately opens <strong>Add Call Log</strong> for the new contact.</li>
</ol>
<h3>Reading Call Records stats</h3>
<ul>
<li><strong>Contacts</strong> counts visible contacts after filters.</li>
<li><strong>Total Calls</strong> counts visible call logs after period, year, caller, and outcome filters.</li>
<li><strong>Follow-up Needed</strong> counts contacts where the latest visible call outcome or note suggests callback or follow-up.</li>
<li><strong>Top Caller</strong> shows the caller code with the most visible call logs.</li>
</ul>
<h3>Searching and filtering Call Records</h3>
<ol>
<li>Use <strong>Type to search...</strong> to search contact name, phone, address, and visible call notes.</li>
<li>Use the period selector to filter call logs by date range.</li>
<li>Use <strong>Year</strong> to restrict call logs to a specific call year or all years.</li>
<li>Use <strong>Caller Code</strong> to show calls made by a specific staff code.</li>
<li>Use <strong>Outcome</strong> to filter by <strong>No Answer</strong>, <strong>Callback Later</strong>, <strong>Interested</strong>, or <strong>Not Interested</strong>.</li>
<li>When call-level filters are active, contacts with no matching visible call logs are hidden.</li>
<li>Use <strong>Columns</strong> to show optional fields such as website, address, latest note, and call count.</li>
</ol>
<h3>Adding a call log</h3>
<ol>
<li>Find the contact in <strong>Call Records</strong>.</li>
<li>Open the row action menu and choose <strong>Add Log</strong>.</li>
<li>Confirm or adjust <strong>Call Date &amp; Time</strong>.</li>
<li>Select an <strong>Outcome</strong>. The available outcomes are <strong>No Answer</strong>, <strong>Callback Later</strong>, <strong>Interested</strong>, and <strong>Not Interested</strong>.</li>
<li>Add useful conversation context in <strong>Notes</strong>.</li>
<li>If the outcome is <strong>Callback Later</strong>, fill <strong>Next Follow-up</strong>.</li>
<li>Click <strong>Save</strong>.</li>
<li>KIJO records the call under your staff ID and caller code, then refreshes the list.</li>
</ol>
<h3>Opening a contact detail page</h3>
<ol>
<li>Click a Call Records row to open <strong>Call Record Details</strong>.</li>
<li>Review company name, phone, address, website, created date, creator code, and call logs.</li>
<li>Use <strong>Add Log</strong> from the detail page when you want to add a new call while reviewing full history.</li>
<li>Use <strong>Edit Contact</strong> when the company name, phone, address, or website needs correction.</li>
<li>Use <strong>Back</strong> to return to Call Records.</li>
</ol>
<h3>Viewing and editing contacts</h3>
<ul>
<li>Use <strong>View Contact</strong> to inspect the saved contact from the list without leaving the page.</li>
<li>Use <strong>Edit Contact</strong> to update company name, phone, address, or website.</li>
<li>Only the contact owner or privileged roles can update a contact.</li>
<li>If the phone number duplicates another saved contact, the update is rejected.</li>
</ul>
<h3>Deleting call logs or contacts</h3>
<ul>
<li>Use <strong>Delete Latest Call Log</strong> from the row action menu when you need to remove the latest call log.</li>
<li>Use the trash icon in the detail call-log stack to delete a specific call log.</li>
<li>Only the call-log owner or privileged roles can delete a call log.</li>
<li>A contact can be deleted only when it has no call logs.</li>
<li>If a contact has call logs, delete the call logs first or keep the contact as history.</li>
<li>Only the contact owner or privileged roles can delete a contact.</li>
</ul>
<h3>How this feeds the pipeline</h3>
<ul>
<li>Factory Directory is for finding and qualifying raw prospect records.</li>
<li>Call Records is for tracking actual outreach activity.</li>
<li>Call outcomes and call counts feed operational visibility, including Monitoring views that summarize pipeline activity.</li>
<li>Use <strong>Interested</strong> or strong callback notes as a signal to create or update downstream inquiry, quotation, or pipeline records when the prospect becomes real work.</li>
</ul>
HTML;
    }
};
