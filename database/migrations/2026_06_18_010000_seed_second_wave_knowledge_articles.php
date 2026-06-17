<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCE_MARKDOWN = 'database/knowledge/SECOND_WAVE_KNOWLEDGE_MIGRATION.md';

    private const EXPECTED_COUNT = 20;

    public function up(): void
    {
        if (! Schema::hasTable('knowledge_articles')) {
            return;
        }

        $articles = $this->articlesFromMarkdown();
        $now = now();

        foreach ($articles as $article) {
            $existing = DB::table('knowledge_articles')->where('slug', $article['slug'])->first();

            if ($existing && ! $this->isSystemManaged($existing)) {
                continue;
            }

            $payload = [
                'title' => $article['title'],
                'slug' => $article['slug'],
                'summary' => $article['summary'],
                'body_html' => $article['body_html'],
                'category' => $article['category'],
                'tags' => json_encode($article['tags']),
                'related_route' => $article['related_route'],
                'contributor_note' => null,
                'status' => 'published',
                'published_at' => $existing->published_at ?? $now,
                'updated_by_staff_id' => null,
                'updated_by_name_code' => 'SYSTEM',
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('knowledge_articles')->where('id', $existing->id)->update($payload);
                $articleId = (int) $existing->id;
                $action = 'updated';
                $remarks = 'Second-wave operational knowledge article refreshed from audited markdown source.';
            } else {
                $articleId = (int) DB::table('knowledge_articles')->insertGetId($payload + [
                    'created_by_staff_id' => null,
                    'created_by_name_code' => 'SYSTEM',
                    'created_at' => $now,
                ]);
                $action = 'created_published';
                $remarks = 'Second-wave operational knowledge article seeded by system from audited markdown source.';
            }

            $this->insertEditLog($articleId, $action, $remarks, $now);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('knowledge_articles')) {
            return;
        }

        foreach ($this->expectedSlugs() as $slug) {
            $article = DB::table('knowledge_articles')
                ->where('slug', $slug)
                ->where('created_by_name_code', 'SYSTEM')
                ->where('updated_by_name_code', 'SYSTEM')
                ->first();

            if (! $article) {
                continue;
            }

            DB::table('knowledge_articles')->where('id', $article->id)->delete();
        }
    }

    private function articlesFromMarkdown(): array
    {
        $path = base_path(self::SOURCE_MARKDOWN);
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Second-wave knowledge markdown source is missing: '.self::SOURCE_MARKDOWN);
        }

        $markdown = file_get_contents($path);
        if ($markdown === false || trim($markdown) === '') {
            throw new RuntimeException('Second-wave knowledge markdown source is empty or unreadable.');
        }

        preg_match_all('/^## Article\s+\d+\s+-\s+(.+?)\R(.*?)(?=^## Article\s+\d+\s+-\s+|\z)/ms', $markdown, $matches, PREG_SET_ORDER);

        if (count($matches) !== self::EXPECTED_COUNT) {
            throw new RuntimeException('Expected '.self::EXPECTED_COUNT.' second-wave knowledge articles, found '.count($matches).'.');
        }

        $articles = [];
        foreach ($matches as $match) {
            $blockTitle = trim($match[1]);
            $block = $match[2];
            $article = [
                'title' => $this->requiredField($block, 'Title'),
                'slug' => $this->requiredCodeField($block, 'Slug'),
                'category' => $this->requiredCodeField($block, 'Category'),
                'related_route' => $this->requiredCodeField($block, 'Related route'),
                'tags' => $this->tags($block),
                'summary' => $this->requiredField($block, 'Summary'),
                'body_html' => $this->bodyHtml($block, $blockTitle),
            ];

            $this->validateArticle($article);
            $articles[] = $article;
        }

        $slugs = array_column($articles, 'slug');
        if (count($slugs) !== count(array_unique($slugs))) {
            throw new RuntimeException('Second-wave knowledge markdown contains duplicate slugs.');
        }

        $expectedSlugs = $this->expectedSlugs();
        sort($slugs);
        sort($expectedSlugs);
        if ($slugs !== $expectedSlugs) {
            throw new RuntimeException('Second-wave knowledge markdown slugs do not match the expected migration slug list.');
        }

        return $articles;
    }

    private function requiredField(string $block, string $field): string
    {
        $pattern = '/^-\s+'.preg_quote($field, '/').':\s+(.+?)\s*$/m';
        if (! preg_match($pattern, $block, $match)) {
            throw new RuntimeException("Missing {$field} field in second-wave markdown article.");
        }

        return trim($match[1]);
    }

    private function requiredCodeField(string $block, string $field): string
    {
        $pattern = '/^-\s+'.preg_quote($field, '/').':\s+`([^`]+)`\s*$/m';
        if (! preg_match($pattern, $block, $match)) {
            throw new RuntimeException("Missing {$field} code field in second-wave markdown article.");
        }

        return trim($match[1]);
    }

    private function tags(string $block): array
    {
        if (! preg_match('/^-\s+Tags:\s+(.+?)\s*$/m', $block, $match)) {
            throw new RuntimeException('Missing Tags field in second-wave markdown article.');
        }

        preg_match_all('/`([^`]+)`/', $match[1], $tagMatches);
        $tags = array_values(array_unique(array_filter(array_map(
            static fn (string $tag): string => trim($tag),
            $tagMatches[1] ?? []
        ))));

        if ($tags === []) {
            throw new RuntimeException('Second-wave markdown article has no tags.');
        }

        return $tags;
    }

    private function bodyHtml(string $block, string $title): string
    {
        if (! preg_match('/```html\R(.*?)\R```/ms', $block, $match)) {
            throw new RuntimeException("Missing body_html block for {$title}.");
        }

        $body = trim($match[1]);
        if ($body === '') {
            throw new RuntimeException("Empty body_html block for {$title}.");
        }

        return $body;
    }

    private function validateArticle(array $article): void
    {
        $categories = [
            'Getting Started',
            'Leave & HR',
            'CRM',
            'Proposals',
            'Projects',
            'Commercial',
            'Vendors',
            'Catalog',
            'Support',
            'System',
        ];

        if (! in_array($article['category'], $categories, true)) {
            throw new RuntimeException("Invalid knowledge category for {$article['slug']}: {$article['category']}.");
        }

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $article['slug'])) {
            throw new RuntimeException("Invalid knowledge slug: {$article['slug']}.");
        }

        if (! preg_match('/^\/[A-Za-z0-9_\-\/?=&%.#]*$/', $article['related_route'])) {
            throw new RuntimeException("Invalid related route for {$article['slug']}: {$article['related_route']}.");
        }

        foreach ($article['tags'] as $tag) {
            if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $tag)) {
                throw new RuntimeException("Invalid tag '{$tag}' for {$article['slug']}.");
            }
        }
    }

    private function expectedSlugs(): array
    {
        return [
            'how-to-apply-salary-and-submit-other-claims',
            'how-salary-and-other-claim-approvals-work',
            'how-to-use-the-salary-payment-queue',
            'how-to-configure-approval-workflows',
            'how-vendor-payment-requests-and-approvals-work',
            'how-handbook-publishing-and-acknowledgements-work',
            'how-to-manage-leave-approvals-and-entitlements',
            'how-to-use-ai-assistant-governance',
            'how-to-manage-performance-appraisals-and-final-appraisals',
            'how-to-create-and-publish-whats-new-notices',
            'how-to-use-monthly-dashboard-report-scheduling',
            'how-to-use-mail-diagnostics',
            'how-to-manage-invoice-payment-status-and-receipt-pdfs',
            'how-to-manage-pipeline-inquiries-and-bulk-pipeline-entries',
            'how-to-review-client-roi-and-past-pics',
            'how-knowledge-hub-authoring-publishing-archiving-and-assistant-use-work',
            'how-to-use-staff-activity-logs-and-exported-activity-reports',
            'how-digital-signature-and-password-self-service-work',
            'how-to-troubleshoot-auth-session-and-role-access-issues',
            'how-to-read-the-system-admin-schema-and-migration-status-page',
        ];
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
};
