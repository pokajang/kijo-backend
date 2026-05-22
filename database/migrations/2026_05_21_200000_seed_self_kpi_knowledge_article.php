<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-set-up-and-update-your-kpi';

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
            'title' => 'How to Set Up and Update Your KPI',
            'slug' => $this->slug,
            'summary' => 'Create yearly KPI parameters, update monthly achieved values, review your live KPI score, and understand how staff KPI review uses the same data.',
            'body_html' => $this->articleBody(),
            'category' => 'Leave & HR',
            'tags' => json_encode(['kpi', 'self-kpi', 'monthly-tracker', 'performance', 'staff-review', 'hr']),
            'related_route' => '/my/kpi',
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
            $remarks = 'Expanded self KPI guide with the parameter setup, monthly tracker, overview scoring, and staff review lifecycle.';
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
<h3>Opening My KPI</h3>
<ol>
<li>Open <strong>My KPI</strong> from the staff or personal navigation.</li>
<li>The default page is <strong>KPI Overview</strong>.</li>
<li>Use the <strong>Actions</strong> dropdown from the top-right of the KPI card.</li>
<li>Choose <strong>Parameters</strong> when you need to create or manage KPI definitions.</li>
<li>Choose <strong>Update</strong> when you need to enter monthly achieved values.</li>
<li>Use <strong>Back</strong> from the Parameters or Update page to return to <strong>KPI Overview</strong>.</li>
</ol>
<h3>How the KPI lifecycle works</h3>
<ul>
<li><strong>Parameters</strong> are the yearly KPI definitions, targets, units, and weightages.</li>
<li><strong>Update</strong> is where you enter the achieved value and monthly remarks for each KPI.</li>
<li><strong>Overview</strong> reads the same parameters and monthly updates to calculate your annual progress and live score.</li>
<li>The staff KPI review page reads the same records for the selected staff member.</li>
<li>Create KPI parameters first. Monthly updates cannot be entered until parameters exist for that year.</li>
</ul>
<h3>Creating KPI parameters</h3>
<ol>
<li>Open <strong>My KPI</strong>.</li>
<li>Open <strong>Actions</strong> and choose <strong>Parameters</strong>.</li>
<li>Select the <strong>KPI Year</strong> card for the year you want to manage.</li>
<li>Click <strong>Add KPI</strong>.</li>
<li>Fill <strong>Parameter Name</strong>.</li>
<li>Fill <strong>Description</strong> with a clear explanation of what this KPI measures.</li>
<li>Fill <strong>Annual Target</strong>.</li>
<li>Fill <strong>Unit</strong>, such as cases, tasks, RM, projects, reports, calls, or percentage.</li>
<li>Fill <strong>Weightage (%)</strong>.</li>
<li>Click <strong>Save</strong>.</li>
</ol>
<h3>Choosing good KPI parameter values</h3>
<ul>
<li><strong>Parameter Name</strong> should be short and readable, such as Completed Reports or New Client Calls.</li>
<li><strong>Description</strong> should explain the counting rule so future updates are consistent.</li>
<li><strong>Annual Target</strong> is the full-year target used by the Overview score.</li>
<li><strong>Unit</strong> is shown beside achieved and target values throughout the KPI module.</li>
<li><strong>Weightage (%)</strong> controls how much that KPI contributes to the total live score.</li>
<li>Use weightage carefully. A high-weight KPI has more impact on the final score than a low-weight KPI.</li>
<li>The UI expects weightage between 1 and 100 for each KPI row.</li>
</ul>
<h3>Editing KPI parameters</h3>
<ol>
<li>Open <strong>My KPI</strong>.</li>
<li>Open <strong>Actions</strong> and choose <strong>Parameters</strong>.</li>
<li>Select the KPI year.</li>
<li>Open the action menu beside the KPI parameter.</li>
<li>Click <strong>Edit</strong>.</li>
<li>Update the parameter name, description, annual target, unit, or weightage.</li>
<li>Click <strong>Save</strong>.</li>
<li>Use <strong>Cancel</strong> if you do not want to keep the edit.</li>
</ol>
<h3>Deleting KPI parameters</h3>
<ol>
<li>Open <strong>Parameters</strong>.</li>
<li>Select the KPI year.</li>
<li>Open the action menu beside the KPI parameter.</li>
<li>Click <strong>Delete</strong>.</li>
<li>Confirm only when the KPI parameter should be removed.</li>
<li>Deleting a parameter removes it from your KPI setup and affects the Overview for that year.</li>
</ol>
<h3>Copying past KPI parameters</h3>
<ol>
<li>Open <strong>Parameters</strong>.</li>
<li>Select the current year.</li>
<li>Click <strong>Add KPI</strong>.</li>
<li>Use <strong>Search past KPI</strong> to find a KPI parameter from an earlier year.</li>
<li>Click the matching past KPI option to copy its name, description, target, unit, and weightage into the new row.</li>
<li>Adjust the copied values for the current year if needed.</li>
<li>Click <strong>Save</strong>.</li>
</ol>
<h3>Copying a past KPI directly to the current year</h3>
<ol>
<li>Open <strong>Parameters</strong>.</li>
<li>Select a past KPI year.</li>
<li>Open the action menu beside the past KPI parameter.</li>
<li>Click <strong>Copy to current year</strong>.</li>
<li>The system creates a new parameter for the current year using the past KPI details.</li>
<li>Review the new current-year KPI and edit it if the target or weightage changed.</li>
</ol>
<h3>Updating monthly KPI achievements</h3>
<ol>
<li>Open <strong>My KPI</strong>.</li>
<li>Open <strong>Actions</strong> and choose <strong>Update</strong>.</li>
<li>Use <strong>For Month</strong> to select the month you are updating.</li>
<li>The default month is normally the previous month in the current year.</li>
<li>Only months up to the current month are selectable.</li>
<li>Find the KPI row you want to update.</li>
<li>Click <strong>Add</strong> when the month has not been updated before.</li>
<li>Click <strong>Update</strong> when the month already has a saved value.</li>
<li>Enter <strong>Achieved</strong>.</li>
<li>Enter <strong>Remarks</strong> if you need to explain the result, evidence, or context.</li>
<li>Click <strong>Save</strong>.</li>
</ol>
<h3>Understanding monthly row statuses</h3>
<ul>
<li><strong>Not updated</strong> means the selected month has no achieved value saved for that KPI.</li>
<li><strong>Saved</strong> means the selected month already has an achieved value.</li>
<li><strong>Unsaved changes</strong> means you changed the value or remarks but have not saved yet.</li>
<li><strong>Saving...</strong> means the selected KPI row is being submitted.</li>
<li>Each KPI row saves independently, so save every row that you change.</li>
</ul>
<h3>Using Achieved and Remarks correctly</h3>
<ul>
<li><strong>Achieved</strong> is the actual monthly result for the selected KPI.</li>
<li>The value entered in <strong>Achieved</strong> is stored as the monthly actual value.</li>
<li><strong>Remarks</strong> are optional, but they are useful for explaining unusually high, low, or delayed results.</li>
<li>Use remarks to record evidence, blockers, partial completion, or important notes that a reviewer should know.</li>
<li>If nothing changed, clicking <strong>Save</strong> closes the row edit without submitting a new value.</li>
<li>Use <strong>Cancel</strong> to revert an edited row back to the last saved achieved value and remarks.</li>
</ul>
<h3>Reviewing KPI Overview</h3>
<ol>
<li>Open <strong>My KPI</strong>.</li>
<li>The page opens on <strong>KPI Overview</strong>.</li>
<li>Select the year card you want to review.</li>
<li>The Overview loads your KPI parameters for that year.</li>
<li>The Overview loads all monthly tracker rows for each KPI.</li>
<li>Review <strong>Total Live Score</strong> at the top of the page.</li>
<li>Review each KPI card under <strong>Annual Overview</strong>.</li>
<li>Turn on <strong>Show monthly remarks</strong> when you want to review remarks by month.</li>
</ol>
<h3>Reading the annual KPI cards</h3>
<ul>
<li>Each KPI card shows current achieved total against the annual target.</li>
<li>The current value is the sum of monthly achieved values for that KPI in the selected year.</li>
<li>The card shows the KPI unit beside the current and target values.</li>
<li>The card shows monthly cells from January up to the current month for the current year.</li>
<li>Past years show all twelve months.</li>
<li>Blank months appear as empty values.</li>
<li>The card badge shows the status: <strong>Exceeded</strong>, <strong>On Track</strong>, <strong>Watch</strong>, or <strong>At Risk</strong>.</li>
</ul>
<h3>Understanding status badges</h3>
<ul>
<li><strong>Exceeded</strong> means the achieved value is above 100% of the annual target.</li>
<li><strong>On Track</strong> means achievement is at least 80%.</li>
<li><strong>Watch</strong> means achievement is at least 50% but below 80%.</li>
<li><strong>At Risk</strong> means achievement is below 50%.</li>
<li>Status is calculated from achieved value compared against annual target.</li>
</ul>
<h3>Understanding Total Live Score</h3>
<ul>
<li><strong>Total Live Score</strong> combines KPI achievement with each KPI weightage.</li>
<li>Each KPI earns score using its achieved value divided by its annual target.</li>
<li>The earned score for each KPI is capped at that KPI weightage.</li>
<li>A KPI can show <strong>Exceeded</strong>, but it does not earn more than its configured weightage.</li>
<li>The score popover shows the calculation for each KPI.</li>
<li>The percentage beside the score shows earned weight divided by total configured weight.</li>
</ul>
<h3>Reviewing monthly remarks</h3>
<ol>
<li>Open <strong>KPI Overview</strong>.</li>
<li>Scroll to <strong>Monthly Remarks</strong>.</li>
<li>Turn on <strong>Show monthly remarks</strong>.</li>
<li>Review each month card.</li>
<li>Each month groups remarks from all KPI parameters for that month.</li>
<li>Use <strong>Show more</strong> when a month has more remarks than can fit in the preview.</li>
<li>Use <strong>Show less</strong> to collapse the month again.</li>
</ol>
<h3>How self KPI relates to staff KPI review</h3>
<ul>
<li>The self KPI pages write and read your own KPI data.</li>
<li>The staff KPI review page reads KPI parameters and monthly tracker rows for a selected staff member.</li>
<li>Managers or HR reviewers use <strong>Staff KPI</strong> to select a staff member and review the same annual overview and monthly remarks.</li>
<li>The staff review page uses the same <strong>Total Live Score</strong> and <strong>Annual Overview</strong> display logic.</li>
<li>When you update your monthly KPI achievements, the staff review page can reflect those values after it reloads.</li>
</ul>
<h3>Backend data lifecycle</h3>
<ul>
<li>KPI definitions are saved in the KPI parameters records for your staff account and year.</li>
<li>Monthly achieved values are saved in the KPI tracker records for your staff account, KPI parameter, and month.</li>
<li>Saving a monthly KPI update uses an upsert, so saving the same KPI and month again updates the existing tracker row.</li>
<li>Each monthly tracker row stores the achieved value and remarks.</li>
<li>A scheduled reminder is sent on the first day of every month to remind active staff to fill their KPI tracker.</li>
</ul>
<h3>Recommended monthly practice</h3>
<ul>
<li>Review your <strong>Parameters</strong> at the start of each year before entering monthly achievements.</li>
<li>Keep KPI names and units consistent across years when the measurement is the same.</li>
<li>Use <strong>Search past KPI</strong> or <strong>Copy to current year</strong> for yearly rollover instead of retyping old KPI definitions.</li>
<li>Update KPI achievements monthly, preferably after the month closes.</li>
<li>Write useful <strong>Remarks</strong> when the achieved value needs explanation.</li>
<li>Check <strong>KPI Overview</strong> after saving monthly values to confirm the annual total and score changed as expected.</li>
</ul>
<h3>Common blockers</h3>
<ul>
<li>If <strong>Update</strong> shows no KPI rows, create KPI parameters for that year first.</li>
<li>If a year does not show expected KPI data, confirm the KPI parameter was created for that same year.</li>
<li>If <strong>Save</strong> fails on a KPI parameter, confirm <strong>Parameter Name</strong>, <strong>Description</strong>, <strong>Annual Target</strong>, <strong>Unit</strong>, and <strong>Weightage (%)</strong> are filled correctly.</li>
<li>If monthly <strong>Save</strong> fails, confirm <strong>Achieved</strong> is filled with a valid number.</li>
<li>If the Overview score looks low, check whether the KPI has missing monthly achieved values.</li>
<li>If the staff review page does not show your latest update, reload the staff KPI review after saving your monthly KPI row.</li>
</ul>
HTML;
    }
};
