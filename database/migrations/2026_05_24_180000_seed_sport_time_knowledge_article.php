<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-record-and-track-sport-time-events';

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
            'title' => 'How to Record and Track Sport Time Events',
            'slug' => $this->slug,
            'summary' => 'Create sport event records with photos and staff attendees, search event history, filter by year, view participation stats, and manage owner-only edits and deletes.',
            'body_html' => $this->articleBody(),
            'category' => 'System',
            'tags' => json_encode([
                'sport-time',
                'sport',
                'sports',
                'event',
                'events',
                'administration',
                'attendees',
                'staff',
                'participation',
                'stats',
                'photo',
                'image',
                'gallery',
                'team',
                'wellness',
            ]),
            'related_route' => '/administration/sport-time',
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
            $remarks = 'Updated Sport Time guide with event creation, image upload, attendee tracking, search, year filters, stats, edit, delete, and owner permission lifecycle.';
        } else {
            $articleId = (int) DB::table('knowledge_articles')->insertGetId($payload + [
                'created_by_staff_id' => null,
                'created_by_name_code' => 'SYSTEM',
                'created_at' => $now,
            ]);
            $action = 'created_published';
            $remarks = 'Production Sport Time guide seeded by system.';
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
<h3>What Sport Time is for</h3>
<p>Use <strong>Sport Time</strong> to record company sport or wellness events with a photo, event date, and staff attendance. The records create a simple event gallery and allow participation counts to be reviewed by year.</p>
<ul>
<li>Use it for sport activities, team wellness sessions, badminton nights, futsal sessions, runs, fitness activities, or similar staff events.</li>
<li>Use attendees to record who joined the event.</li>
<li>Use photos to keep visual proof or memories of the event.</li>
<li>Use participation stats to understand staff involvement across events.</li>
</ul>
<h3>Opening Sport Time</h3>
<ol>
<li>Open <strong>Administration</strong> from the sidebar or module navigation.</li>
<li>Click <strong>Sport Time</strong>.</li>
<li>The <strong>Sport Event Records</strong> page opens.</li>
<li>Existing events appear as image cards.</li>
</ol>
<h3>Reading the Sport Time page</h3>
<ul>
<li>The page shows sport events as cards, not as a table.</li>
<li>Each card shows the event image, event name, event date, attendee names, and the user who created or last updated the record.</li>
<li>Click the event image to open the image in a new browser tab.</li>
<li>Use <strong>Edit</strong> to update an event you created.</li>
<li>Use <strong>Delete</strong> to remove an event you created.</li>
</ul>
<h3>Loading event records</h3>
<ul>
<li>The backend returns sport events in pages of 20 records.</li>
<li>The Sport Time page automatically loads all available pages before showing the complete set.</li>
<li>Events are sorted by event date descending, then by newest record id.</li>
<li>If the page is loading, wait for <strong>Loading sporting events...</strong> to finish.</li>
</ul>
<h3>Creating a sport event</h3>
<ol>
<li>Open <strong>Administration</strong> then <strong>Sport Time</strong>.</li>
<li>Click <strong>Add Event</strong>.</li>
<li>The <strong>Add Sport Event</strong> modal opens.</li>
<li>Enter <strong>Event Name</strong>.</li>
<li>Choose <strong>Event Date &amp; Time</strong>.</li>
<li>Use <strong>Tick Attendees</strong> to select staff who joined the event.</li>
<li>Use <strong>Upload Image</strong> to attach the event photo.</li>
<li>Click <strong>Save Event</strong>.</li>
</ol>
<h3>Required fields for a new event</h3>
<ul>
<li><strong>Event Name</strong> is required.</li>
<li><strong>Event Date &amp; Time</strong> is required.</li>
<li>At least one attendee is required.</li>
<li>An event image is required when creating a new sport event.</li>
<li>The image must be JPG, PNG, WebP, or GIF.</li>
<li>The image must be smaller than 5 MB.</li>
</ul>
<h3>Selecting attendees</h3>
<ul>
<li>Attendees are selected from the active staff list.</li>
<li>Use the attendee search field to search by staff name, name code, or department.</li>
<li>Tick each staff member who joined the event.</li>
<li>The selected attendee count appears below the staff checklist.</li>
<li>The backend validates that selected staff ids still exist and are active.</li>
</ul>
<h3>Image upload behavior</h3>
<ul>
<li>Sport Time stores images under the sport-time upload area for the current year.</li>
<li>The saved file extension is based on the image MIME type.</li>
<li>Supported image types are <strong>JPG</strong>, <strong>PNG</strong>, <strong>WebP</strong>, and <strong>GIF</strong>.</li>
<li>Images appear as cropped card covers using the center of the uploaded image.</li>
<li>Choose clear landscape photos where possible so the card preview looks useful.</li>
</ul>
<h3>Searching event records</h3>
<ul>
<li>Use the <strong>Search</strong> field to find an event by event name.</li>
<li>The search also matches participant names and staff codes already loaded with each event.</li>
<li>Clear the search field if expected events disappear.</li>
</ul>
<h3>Filtering by year</h3>
<ul>
<li>Use <strong>Year</strong> to filter event cards by event date year.</li>
<li>The year list is built from the years available in existing event records.</li>
<li>Choose <strong>All Years</strong> to show every loaded event.</li>
<li>The same year filter is also used by the participation stats panel.</li>
</ul>
<h3>Using participation stats</h3>
<ol>
<li>Turn on <strong>Show Stats</strong>.</li>
<li>Choose a year if you only want stats for one year.</li>
<li>Read <strong>Total events</strong> to see how many events are included in the selected scope.</li>
<li>Review the staff list to see how many times each staff member appears as an attendee.</li>
<li>Use the progress bars to quickly compare participation counts.</li>
</ol>
<h3>How stats are calculated</h3>
<ul>
<li>Stats count attendance per staff member across the current year-filtered event set.</li>
<li>If <strong>All Years</strong> is selected, stats count attendance across all loaded sport events.</li>
<li>Each event can add one count for each selected attendee.</li>
<li>Staff with zero participation are still shown when staff data is available.</li>
<li>The highest participation count is highlighted with a stronger progress color.</li>
</ul>
<h3>Editing a sport event</h3>
<ol>
<li>Find the event card.</li>
<li>Click <strong>Edit</strong>.</li>
<li>The <strong>Edit Sport Event</strong> modal opens.</li>
<li>Update <strong>Event Name</strong>, <strong>Event Date &amp; Time</strong>, or selected attendees.</li>
<li>If the current photo should remain, leave <strong>Upload Image</strong> empty.</li>
<li>If the photo should change, choose a new image file.</li>
<li>Click <strong>Update Event</strong>.</li>
</ol>
<h3>Editing rules</h3>
<ul>
<li>Only the event creator can edit the event.</li>
<li>The page may show the edit action, but the backend rejects edits from non-owners.</li>
<li>The same event name, date/time, and attendee rules apply during edit.</li>
<li>Replacing the image is optional during edit.</li>
<li>If a new image is uploaded successfully, the old image file is deleted by the backend.</li>
</ul>
<h3>Using Reset, Cancel, and modal close</h3>
<ul>
<li>Use <strong>Reset</strong> to clear the create form or return the edit form to the loaded event values.</li>
<li>Use <strong>Cancel</strong> to close the modal and clear the form.</li>
<li>The modal uses a static backdrop, so clicking outside does not accidentally close it.</li>
<li>The close button is disabled while the form is saving.</li>
</ul>
<h3>Deleting a sport event</h3>
<ol>
<li>Find the event card.</li>
<li>Click <strong>Delete</strong>.</li>
<li>Confirm the deletion prompt.</li>
<li>The backend deletes the event record and removes the stored image file.</li>
<li>The page reloads the event list after deletion.</li>
</ol>
<h3>Delete permissions</h3>
<ul>
<li>Only the event creator can delete the event.</li>
<li>If another user tries to delete it, the backend returns a permission error.</li>
<li>Delete is permanent for this module.</li>
<li>Use delete only for duplicate, wrong, or no-longer-needed sport event records.</li>
</ul>
<h3>Common mistakes to avoid</h3>
<ul>
<li>Do not forget to select attendees. At least one attendee is required.</li>
<li>Do not upload unsupported image formats such as PDF or HEIC.</li>
<li>Do not upload images above 5 MB.</li>
<li>Do not assume the stats panel changes event records. It only reads attendance data.</li>
<li>Do not delete an event just to correct its attendee list. Use <strong>Edit</strong> instead.</li>
<li>Do not expect to edit events created by another user.</li>
</ul>
<h3>Troubleshooting</h3>
<ul>
<li>If no events appear, clear the search field and choose <strong>All Years</strong>.</li>
<li>If the staff checklist is empty, wait for the staff list to finish loading or clear the attendee search field.</li>
<li>If save fails with an attendee error, one of the selected staff records may no longer be active.</li>
<li>If image upload fails, confirm the file is JPG, PNG, WebP, or GIF and below 5 MB.</li>
<li>If edit or delete fails with a permission message, the event was created by another user.</li>
<li>If the image preview looks cropped, open the image in a new tab to see the full image.</li>
<li>If stats show no events for a year, check whether any event records actually have an event date in that year.</li>
</ul>
HTML;
    }
};
