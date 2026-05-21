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

        $article = DB::table('knowledge_articles')
            ->where('slug', 'how-to-create-and-manage-daily-tasks')
            ->where('created_by_name_code', 'SYSTEM')
            ->where('updated_by_name_code', 'SYSTEM')
            ->first();

        if (! $article || str_contains((string) $article->body_html, '<strong>Create Task</strong>')) {
            return;
        }

        DB::table('knowledge_articles')->where('id', $article->id)->update([
            'body_html' => $this->boldenUiTerms((string) $article->body_html),
            'updated_at' => now(),
        ]);
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

        DB::table('knowledge_articles')->where('id', $article->id)->update([
            'body_html' => str_replace(
                [
                    '<strong>Five Minutes Meeting</strong>',
                    '<strong>Create Task</strong>',
                    '<strong>Task List</strong>',
                    '<strong>Task</strong>',
                    '<strong>Due Date</strong>',
                    '<strong>Add Row</strong>',
                    '<strong>Save Task</strong>',
                    '<strong>Save Tasks</strong>',
                    '<strong>Ongoing</strong>',
                    '<strong>Overdue</strong>',
                    '<strong>Completed</strong>',
                    '<strong>Add Comment</strong>',
                    '<strong>Mark Completed</strong>',
                ],
                [
                    'Five Minutes Meeting',
                    'Create Task',
                    'Task List',
                    'Task',
                    'Due Date',
                    'Add Row',
                    'Save Task',
                    'Save Tasks',
                    'Ongoing',
                    'Overdue',
                    'Completed',
                    'Add Comment',
                    'Mark Completed',
                ],
                (string) $article->body_html,
            ),
            'updated_at' => now(),
        ]);
    }

    private function boldenUiTerms(string $html): string
    {
        return str_replace(
            [
                'Open Five Minutes Meeting from the navigation.',
                'Click Create Task at the top-right of the Task List card.',
                'in the Task field.',
                'Choose the Due Date for the task.',
                'Click Add Row if you need',
                'Click Save Task, or Save Tasks',
                'with Ongoing status.',
                'Use Add Comment from the task action menu',
                'Use Mark Completed when the work is done.',
                'Ongoing tasks become Overdue automatically',
                'Completed tasks show whether',
            ],
            [
                'Open <strong>Five Minutes Meeting</strong> from the navigation.',
                'Click <strong>Create Task</strong> at the top-right of the <strong>Task List</strong> card.',
                'in the <strong>Task</strong> field.',
                'Choose the <strong>Due Date</strong> for the task.',
                'Click <strong>Add Row</strong> if you need',
                'Click <strong>Save Task</strong>, or <strong>Save Tasks</strong>',
                'with <strong>Ongoing</strong> status.',
                'Use <strong>Add Comment</strong> from the task action menu',
                'Use <strong>Mark Completed</strong> when the work is done.',
                '<strong>Ongoing</strong> tasks become <strong>Overdue</strong> automatically',
                '<strong>Completed</strong> tasks show whether',
            ],
            $html,
        );
    }
};
