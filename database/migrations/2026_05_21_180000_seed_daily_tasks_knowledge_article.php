<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knowledge_articles')) {
            return;
        }

        $slug = 'how-to-create-and-manage-daily-tasks';
        if (DB::table('knowledge_articles')->where('slug', $slug)->exists()) {
            return;
        }

        $now = now();
        $articleId = DB::table('knowledge_articles')->insertGetId([
            'title' => 'How to Create and Manage Daily Tasks',
            'slug' => $slug,
            'summary' => 'Create daily work tasks, track due dates, add progress comments, and mark tasks completed.',
            'body_html' => $this->articleBody(),
            'category' => 'Projects',
            'tags' => json_encode(['tasks', 'daily-task', 'follow-up', 'five-minutes-meeting']),
            'related_route' => '/task-manager',
            'contributor_note' => null,
            'status' => 'published',
            'published_at' => $now,
            'created_by_staff_id' => null,
            'created_by_name_code' => 'SYSTEM',
            'updated_by_staff_id' => null,
            'updated_by_name_code' => 'SYSTEM',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (Schema::hasTable('knowledge_article_edit_logs')) {
            DB::table('knowledge_article_edit_logs')->insert([
                'knowledge_article_id' => $articleId,
                'action' => 'created_published',
                'remarks' => 'Production starter guide seeded by system.',
                'staff_id' => null,
                'name_code' => 'SYSTEM',
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('knowledge_articles')) {
            return;
        }

        $article = DB::table('knowledge_articles')
            ->where('slug', 'how-to-create-and-manage-daily-tasks')
            ->where('created_by_name_code', 'SYSTEM')
            ->where('updated_by_name_code', 'SYSTEM')
            ->first();

        if (! $article) {
            return;
        }

        DB::table('knowledge_articles')->where('id', $article->id)->delete();
    }

    private function articleBody(): string
    {
        return <<<'HTML'
<h3>Creating daily tasks</h3>
<ol>
<li>Open <strong>Five Minutes Meeting</strong> from the navigation.</li>
<li>Click <strong>Create Task</strong> at the top-right of the <strong>Task List</strong> card.</li>
<li>Enter a clear task description in the <strong>Task</strong> field.</li>
<li>Choose the <strong>Due Date</strong> for the task.</li>
<li>Click <strong>Add Row</strong> if you need to create more than one task in the same save.</li>
<li>Use the trash icon to remove an unused row. At least one row must remain.</li>
<li>Click <strong>Save Task</strong>, or <strong>Save Tasks</strong> when multiple valid rows are entered.</li>
</ol>
<h3>What happens after saving</h3>
<ul>
<li>The task is saved to your personal task list with <strong>Ongoing</strong> status.</li>
<li>The task list reloads so filters, sorting, and the stats strip reflect the new task.</li>
<li>Unsubmitted task rows are temporarily recovered by the browser if the modal or page is closed before saving.</li>
</ul>
<h3>Managing the task lifecycle</h3>
<ul>
<li>Use <strong>Add Comment</strong> from the task action menu to record progress or follow-up notes.</li>
<li>Use <strong>Mark Completed</strong> when the work is done. The system records the completion date.</li>
<li><strong>Ongoing</strong> tasks become <strong>Overdue</strong> automatically when the due date has passed beyond the one-day grace period.</li>
<li><strong>Completed</strong> tasks show whether they were completed on time or late.</li>
<li>Only ongoing tasks can be deleted from the task list action menu.</li>
</ul>
<h3>Opening task details</h3>
<p>Click a task row to open the task detail page. The detail page shows the task title, status, created date, due date, completed date, days lapsed, and comment logs.</p>
HTML;
    }
};
