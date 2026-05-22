<?php

namespace App\Services\Knowledge;

use App\Support\AppFilePaths;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KnowledgeService
{
    private const CATEGORIES = [
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

    private const ALLOWED_HTML_TAGS = [
        'p',
        'br',
        'strong',
        'b',
        'em',
        'i',
        'u',
        'ol',
        'ul',
        'li',
        'table',
        'thead',
        'tbody',
        'tr',
        'th',
        'td',
        'h2',
        'h3',
        'h4',
        'blockquote',
        'pre',
        'code',
        'a',
    ];

    private const ALLOWED_HTML_ATTRIBUTES = ['href', 'target', 'rel'];

    private const RESERVED_SLUGS = [
        'my',
        'create',
        'edit',
        'articles',
        'article',
        'new',
        'draft',
        'drafts',
        'published',
        'archive',
        'archived',
    ];

    private const SEARCH_TEXT_MAX_LENGTH = 12000;

    private array $pendingImagePaths = [];
    private array $deferredDeletePaths = [];

    public function index(Request $request): JsonResponse
    {
        $articles = DB::table('knowledge_articles as a')
            ->where('a.status', 'published')
            ->whereNotNull('a.published_at')
            ->select('a.*')
            ->orderByDesc(DB::raw('COALESCE(a.published_at, a.updated_at, a.created_at)'))
            ->orderByDesc('a.id')
            ->limit(200)
            ->get();
        $imagesByArticle = $this->imagesForArticles($articles->pluck('id')->map(fn ($id) => (int) $id)->all());

        return response()->json([
            'status' => 'success',
            'data' => $articles
                ->map(fn ($article) => $this->formatArticle($article, $imagesByArticle[(int) $article->id] ?? []))
                ->values(),
            'meta' => $this->meta($request),
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $staffId = $this->staffId($request);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $query = DB::table('knowledge_articles as a')
            ->select('a.*')
            ->orderByDesc(DB::raw('COALESCE(a.published_at, a.updated_at, a.created_at)'))
            ->orderByDesc('a.id')
            ->limit(200);

        $articles = $query->get();
        $imagesByArticle = $this->imagesForArticles($articles->pluck('id')->map(fn ($id) => (int) $id)->all());

        return response()->json([
            'status' => 'success',
            'data' => $articles
                ->map(fn ($article) => $this->formatArticle($article, $imagesByArticle[(int) $article->id] ?? [], true))
                ->values(),
            'meta' => $this->meta($request),
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $query = DB::table('knowledge_articles as a')->select('a.*');
        ctype_digit($slug) ? $query->where('a.id', (int) $slug) : $query->where('a.slug', $slug);

        $article = $query->first();
        if (!$article) {
            return response()->json(['status' => 'error', 'message' => 'Article not found.'], 404);
        }

        $isPublished = $article->status === 'published' && $article->published_at !== null;
        if (!$isPublished && !$this->canManageArticle($request, $article)) {
            return response()->json(['status' => 'error', 'message' => 'Article not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->formatArticle($article, $this->imagesForArticle((int) $article->id), true),
            'meta' => $this->meta($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request);
        if ($validated instanceof JsonResponse) return $validated;
        $validated['body_html'] = $this->sanitizeHtml($validated['body_html']);
        if ($response = $this->validateBodyPresence($validated)) return $response;
        if ($response = $this->validateImageLimit($request)) return $response;
        if ($response = $this->validateImageDescriptions($request)) return $response;

        $now = now();
        $status = ($validated['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $staffId = $this->staffId($request) ?: null;
        $nameCode = $this->nameCode($request);

        DB::beginTransaction();
        try {
            $articleId = $this->insertArticle([
                'title' => trim($validated['title']),
                'slug' => $this->uniqueSlug($validated['title']),
                'summary' => $this->nullableTrim($validated['summary'] ?? null),
                'body_html' => $validated['body_html'],
                'category' => $this->resolveCategory($validated),
                'tags' => json_encode($this->normalizeTags($validated['tags'] ?? [])),
                'related_route' => $this->nullableTrim($validated['related_route'] ?? null),
                'contributor_note' => $this->nullableTrim($validated['contributor_note'] ?? null),
                'status' => $status,
                'published_at' => $status === 'published' ? $now : null,
                'created_by_staff_id' => $staffId,
                'created_by_name_code' => $nameCode,
                'updated_by_staff_id' => $staffId,
                'updated_by_name_code' => $nameCode,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->storeImages($request, $articleId);
            $this->insertEditLog($articleId, $request, $status === 'published' ? 'created_published' : 'created', $this->nullableTrim($validated['edit_remarks'] ?? null));
            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->deleteStoredPaths($this->pendingImagePaths);
            throw $exception;
        } finally {
            $this->pendingImagePaths = [];
        }

        return response()->json([
            'status' => 'success',
            'message' => $status === 'published' ? 'Knowledge article published.' : 'Knowledge article saved as draft.',
            'data' => $this->findArticle($articleId, true),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $article = DB::table('knowledge_articles')->where('id', $id)->first();
        if (!$article) {
            return response()->json(['status' => 'error', 'message' => 'Article not found.'], 404);
        }
        if (!$this->canManageArticle($request, $article)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }
        if ($article->status === 'archived') {
            return response()->json(['status' => 'error', 'message' => 'Archived articles cannot be edited.'], 422);
        }

        $validated = $this->validatedPayload($request, true);
        if ($validated instanceof JsonResponse) return $validated;
        if ($response = $this->validateEditRemarks($validated)) return $response;
        if ($response = $this->validateUpdateDoesNotChangeStatus($validated, $article)) return $response;
        $validated['body_html'] = $this->sanitizeHtml($validated['body_html']);
        if ($response = $this->validateBodyPresence($validated)) return $response;
        if ($response = $this->validateImageLimit($request, $id)) return $response;
        if ($response = $this->validateImageDescriptions($request)) return $response;

        $now = now();
        DB::beginTransaction();
        try {
            DB::table('knowledge_articles')->where('id', $id)->update([
                'title' => trim($validated['title']),
                'slug' => $this->uniqueSlug($validated['title'], $id),
                'summary' => $this->nullableTrim($validated['summary'] ?? null),
                'body_html' => $validated['body_html'],
                'category' => $this->resolveCategory($validated, $article->category),
                'tags' => json_encode($this->normalizeTags($validated['tags'] ?? [])),
                'related_route' => $this->nullableTrim($validated['related_route'] ?? null),
                'contributor_note' => $this->nullableTrim($validated['contributor_note'] ?? null),
                'status' => $article->status,
                'published_at' => $article->published_at,
                'updated_by_staff_id' => $this->staffId($request) ?: null,
                'updated_by_name_code' => $this->nameCode($request),
                'updated_at' => $now,
            ]);
            $this->syncImages($request, $id);
            $this->insertEditLog($id, $request, 'updated', $this->nullableTrim($validated['edit_remarks'] ?? null));
            DB::commit();
            $this->deleteStoredPaths($this->deferredDeletePaths);
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->deleteStoredPaths($this->pendingImagePaths);
            throw $exception;
        } finally {
            $this->pendingImagePaths = [];
            $this->deferredDeletePaths = [];
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Knowledge article updated.',
            'data' => $this->findArticle($id, true),
        ]);
    }

    public function setStatus(Request $request, int $id, string $status): JsonResponse
    {
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported article status.'], 422);
        }

        $article = DB::table('knowledge_articles')->where('id', $id)->first();
        if (!$article) {
            return response()->json(['status' => 'error', 'message' => 'Article not found.'], 404);
        }
        if (!$this->canManageArticle($request, $article)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }
        if ($article->status === 'archived' && $status !== 'archived') {
            return response()->json(['status' => 'error', 'message' => 'Archived articles cannot be restored from this action.'], 422);
        }

        DB::table('knowledge_articles')->where('id', $id)->update([
            'status' => $status,
            'published_at' => $status === 'published' ? ($article->published_at ?: now()) : null,
            'updated_by_staff_id' => $this->staffId($request) ?: null,
            'updated_by_name_code' => $this->nameCode($request),
            'updated_at' => now(),
        ]);
        $remarks = $this->nullableTrim($request->input('edit_remarks')) ?: match ($status) {
            'published' => 'Published article.',
            'archived' => 'Archived article.',
            default => 'Unpublished article.',
        };
        $this->insertEditLog($id, $request, $status, $remarks);

        return response()->json([
            'status' => 'success',
            'message' => match ($status) {
                'published' => 'Knowledge article published.',
                'archived' => 'Knowledge article archived.',
                default => 'Knowledge article unpublished.',
            },
            'data' => $this->findArticle($id, true),
        ]);
    }

    private function validatedPayload(Request $request, bool $isUpdate = false): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:191'],
            'summary' => ['required', 'string', 'max:2000'],
            'body_html' => ['required', 'string', 'max:50000'],
            'category' => ['nullable', 'string', 'max:80', Rule::in(self::CATEGORIES)],
            'tags' => ['nullable'],
            'related_route' => ['nullable', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9_\-\/?=&%.#]*$/'],
            'contributor_note' => ['nullable', 'string', 'max:2000'],
            'edit_remarks' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'published'])],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_descriptions' => ['nullable', 'array'],
            'image_descriptions.*' => ['nullable', 'string', 'max:500'],
            'existing_image_ids' => ['nullable', 'array'],
            'existing_image_ids.*' => ['nullable', 'integer'],
            'existing_image_descriptions' => ['nullable', 'array'],
            'existing_image_descriptions.*' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first() ?: 'Invalid knowledge article payload.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        return $validator->validated();
    }

    private function validateBodyPresence(array $validated): ?JsonResponse
    {
        $bodyText = trim((string) preg_replace(
            '/[\s\x{00A0}]+/u',
            ' ',
            html_entity_decode(strip_tags((string) ($validated['body_html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));

        return $bodyText === ''
            ? response()->json(['status' => 'error', 'message' => 'Article content is required.'], 422)
            : null;
    }

    private function validateEditRemarks(array $validated): ?JsonResponse
    {
        return $this->nullableTrim($validated['edit_remarks'] ?? null) === null
            ? response()->json(['status' => 'error', 'message' => 'Add edit remarks before saving changes.'], 422)
            : null;
    }

    private function validateUpdateDoesNotChangeStatus(array $validated, object $article): ?JsonResponse
    {
        $requestedStatus = $this->nullableTrim($validated['status'] ?? null);
        if ($requestedStatus !== null && $requestedStatus !== (string) $article->status) {
            return response()->json([
                'status' => 'error',
                'message' => 'Use publish, unpublish, or archive actions to change article status.',
            ], 422);
        }

        return null;
    }

    private function validateImageLimit(Request $request, ?int $articleId = null): ?JsonResponse
    {
        $newCount = count((array) $request->file('images', []));
        $retainedCount = 0;
        if ($articleId !== null) {
            $retainedIds = $this->retainedImageIds($request);
            if (!empty($retainedIds)) {
                $retainedCount = DB::table('knowledge_article_images')
                    ->where('knowledge_article_id', $articleId)
                    ->whereIn('id', $retainedIds)
                    ->count();
            }
        }

        return $newCount + $retainedCount > 10
            ? response()->json(['status' => 'error', 'message' => 'Attach up to 10 images per article.'], 422)
            : null;
    }

    private function validateImageDescriptions(Request $request): ?JsonResponse
    {
        $descriptions = (array) $request->input('image_descriptions', []);
        foreach (array_values((array) $request->file('images', [])) as $index => $file) {
            if ($file && $this->nullableTrim($descriptions[$index] ?? null) === null) {
                return response()->json(['status' => 'error', 'message' => 'Add a description for each image.'], 422);
            }
        }

        $existingDescriptions = (array) $request->input('existing_image_descriptions', []);
        foreach ($this->retainedImageIds($request) as $imageId) {
            if ($this->nullableTrim($existingDescriptions[$imageId] ?? null) === null) {
                return response()->json(['status' => 'error', 'message' => 'Add a description for each image.'], 422);
            }
        }

        return null;
    }

    private function storeImages(Request $request, int $articleId, int $startOrder = 0): void
    {
        $files = array_values((array) $request->file('images', []));
        $descriptions = (array) $request->input('image_descriptions', []);
        $now = now();
        foreach ($files as $index => $file) {
            if (!$file) continue;

            $extension = $this->imageExtension($file->getMimeType() ?: '');
            $filename = uniqid('knowledge-', true) . '.' . $extension;
            $storedPath = $file->storeAs('knowledge/' . now()->format('Y/m'), $filename, 'public');
            $this->pendingImagePaths[] = $storedPath;

            DB::table('knowledge_article_images')->insert([
                'knowledge_article_id' => $articleId,
                'disk_path' => $storedPath,
                'original_name' => preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->getClientOriginalName()) ?: $filename,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => (int) $file->getSize(),
                'description' => $this->nullableTrim($descriptions[$index] ?? null) ?: 'Knowledge article image',
                'sort_order' => $startOrder + $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function syncImages(Request $request, int $articleId): void
    {
        $retainedIds = $this->retainedImageIds($request);
        $descriptions = (array) $request->input('existing_image_descriptions', []);
        $existing = DB::table('knowledge_article_images')
            ->where('knowledge_article_id', $articleId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $sortOrder = 0;
        foreach ($existing as $image) {
            if (!in_array((int) $image->id, $retainedIds, true)) {
                $this->deferredDeletePaths[] = $image->disk_path;
                DB::table('knowledge_article_images')->where('id', $image->id)->delete();
                continue;
            }

            DB::table('knowledge_article_images')->where('id', $image->id)->update([
                'description' => $this->nullableTrim($descriptions[$image->id] ?? null) ?: 'Knowledge article image',
                'sort_order' => $sortOrder,
                'updated_at' => now(),
            ]);
            $sortOrder++;
        }

        $this->storeImages($request, $articleId, $sortOrder);
    }

    private function retainedImageIds(Request $request): array
    {
        return array_values(array_filter(array_map(
            fn ($id) => (int) $id,
            (array) $request->input('existing_image_ids', []),
        ), fn ($id) => $id > 0));
    }

    private function imagesForArticles(array $articleIds): array
    {
        if (empty($articleIds)) return [];

        $grouped = [];
        $rows = DB::table('knowledge_article_images')
            ->whereIn('knowledge_article_id', $articleIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $grouped[(int) $row->knowledge_article_id][] = $this->formatImage($row);
        }

        return $grouped;
    }

    private function imagesForArticle(int $articleId): array
    {
        return $this->imagesForArticles([$articleId])[$articleId] ?? [];
    }

    private function formatImage(object $image): array
    {
        return [
            'id' => (int) $image->id,
            'url' => AppFilePaths::publicUrlForStoredPath($image->disk_path),
            'description' => $image->description,
            'original_name' => $image->original_name,
            'mime_type' => $image->mime_type,
            'size_bytes' => (int) $image->size_bytes,
            'sort_order' => (int) $image->sort_order,
        ];
    }

    private function imageExtension(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function findArticle(int $id, bool $includeBody = false): ?array
    {
        $article = DB::table('knowledge_articles')->where('id', $id)->first();
        return $article ? $this->formatArticle($article, $this->imagesForArticle($id), $includeBody) : null;
    }

    private function formatArticle(object $article, array $images = [], bool $includeBody = false): array
    {
        $decodedTags = $article->tags !== null ? json_decode((string) $article->tags, true) : [];
        $tags = is_array($decodedTags) ? array_values(array_filter($decodedTags, 'is_string')) : [];

        return [
            'id' => (int) $article->id,
            'title' => (string) $article->title,
            'slug' => (string) $article->slug,
            'summary' => $article->summary,
            'body_html' => $includeBody ? $article->body_html : null,
            'category' => (string) $article->category,
            'tags' => $tags,
            'related_route' => $article->related_route,
            'contributor_note' => $article->contributor_note,
            'status' => (string) $article->status,
            'published_at' => $article->published_at,
            'created_at' => $article->created_at,
            'updated_at' => $article->updated_at,
            'created_by_staff_id' => $article->created_by_staff_id ? (int) $article->created_by_staff_id : null,
            'created_by_name_code' => $article->created_by_name_code,
            'updated_by_name_code' => $article->updated_by_name_code,
            'search_text' => $this->searchTextForArticle($article, $tags),
            'images' => $images,
            'latest_edit_log' => $includeBody ? $this->latestEditLogForArticle((int) $article->id) : null,
            'edit_logs' => $includeBody ? $this->editLogsForArticle((int) $article->id) : [],
        ];
    }

    private function searchTextForArticle(object $article, array $tags): string
    {
        $text = $this->normalizePlainText(implode(' ', array_filter([
            $article->title ?? '',
            $article->summary ?? '',
            $article->category ?? '',
            implode(' ', $tags),
            $article->related_route ?? '',
            $article->created_by_name_code ?? '',
            $article->updated_by_name_code ?? '',
            $this->plainTextFromHtml((string) ($article->body_html ?? '')),
        ], fn ($value) => trim((string) $value) !== '')));

        return Str::limit($text, self::SEARCH_TEXT_MAX_LENGTH, '');
    }

    private function plainTextFromHtml(string $html): string
    {
        $withoutUnsafeBlocks = preg_replace(
            '/<(script|style)\b[^>]*>.*?<\/\1>/is',
            '',
            $html,
        ) ?? '';

        return $this->normalizePlainText(html_entity_decode(
            strip_tags($withoutUnsafeBlocks),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));
    }

    private function normalizePlainText(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    private function insertEditLog(int $articleId, Request $request, string $action, ?string $remarks): void
    {
        DB::table('knowledge_article_edit_logs')->insert([
            'knowledge_article_id' => $articleId,
            'action' => $action,
            'remarks' => $remarks,
            'staff_id' => $this->staffId($request) ?: null,
            'name_code' => $this->nameCode($request),
            'created_at' => now(),
        ]);
    }

    private function latestEditLogForArticle(int $articleId): ?array
    {
        $row = DB::table('knowledge_article_edit_logs')
            ->where('knowledge_article_id', $articleId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $row ? $this->formatEditLog($row) : null;
    }

    private function editLogsForArticle(int $articleId): array
    {
        return DB::table('knowledge_article_edit_logs')
            ->where('knowledge_article_id', $articleId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($row) => $this->formatEditLog($row))
            ->values()
            ->all();
    }

    private function formatEditLog(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'action' => (string) $row->action,
            'remarks' => $row->remarks,
            'staff_id' => $row->staff_id ? (int) $row->staff_id : null,
            'name_code' => $row->name_code,
            'created_at' => $row->created_at,
        ];
    }

    private function insertArticle(array $data): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::table('knowledge_articles')->insertGetId($data);
            } catch (QueryException $exception) {
                if ((string) ($exception->errorInfo[0] ?? '') !== '23000') throw $exception;
                $data['slug'] .= '-' . ($attempt + 2);
            }
        }

        $data['slug'] .= '-' . uniqid();
        return DB::table('knowledge_articles')->insertGetId($data);
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = $this->safeSlugBase($title);
        $slug = $base;
        $suffix = 2;
        while ($this->slugExists($slug, $ignoreId)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function safeSlugBase(string $title): string
    {
        $base = Str::slug($title) ?: 'knowledge-article';
        if (ctype_digit($base) || in_array($base, self::RESERVED_SLUGS, true)) {
            return 'knowledge-' . $base;
        }

        return $base;
    }

    private function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        $query = DB::table('knowledge_articles')->where('slug', $slug);
        if ($ignoreId !== null) $query->where('id', '<>', $ignoreId);
        return $query->exists();
    }

    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            $tags = is_array($decoded) ? $decoded : explode(',', $tags);
        }
        if (!is_array($tags)) return [];

        return array_values(array_unique(array_filter(array_map(
            fn ($tag) => trim((string) $tag),
            $tags,
        ), fn ($tag) => $tag !== '')));
    }

    private function resolveCategory(array $validated, ?string $fallback = null): string
    {
        $category = $this->nullableTrim($validated['category'] ?? null);
        if ($category !== null && in_array($category, self::CATEGORIES, true)) {
            return $category;
        }

        $route = strtolower((string) ($validated['related_route'] ?? ''));
        $tags = implode(' ', $this->normalizeTags($validated['tags'] ?? []));
        $text = strtolower(trim(implode(' ', [
            $validated['title'] ?? '',
            $validated['summary'] ?? '',
            $route,
            $tags,
        ])));

        $matches = [
            'Leave & HR' => ['leave', 'hr', 'staff', 'appraisal', 'kpi'],
            'CRM' => ['crm', 'quote', 'quotation', 'client', 'pipeline', 'inquiry', 'record'],
            'Proposals' => ['proposal', 'template'],
            'Projects' => ['project', 'task-manager', 'task'],
            'Commercial' => ['invoice', 'delivery', 'debtor', 'commercial', 'po', 'purchase'],
            'Vendors' => ['vendor', 'supplier'],
            'Catalog' => ['catalog', 'equipment'],
            'Support' => ['support', 'request', 'feedback'],
            'System' => ['system', 'admin', 'settings'],
        ];

        foreach ($matches as $candidate => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $candidate;
                }
            }
        }

        return in_array($fallback, self::CATEGORIES, true) ? $fallback : 'Getting Started';
    }

    private function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        if (!class_exists(\DOMDocument::class)) {
            return $this->sanitizeHtmlWithoutDom($html);
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementsByTagName('div')->item(0);
        if (!$root) {
            return '';
        }

        $this->sanitizeDomChildren($root);

        $output = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    private function sanitizeHtmlWithoutDom(string $html): string
    {
        $withoutUnsafeBlocks = preg_replace(
            '/<(script|style)\b[^>]*>.*?<\/\1>/is',
            '',
            $html,
        ) ?? '';
        $text = trim(html_entity_decode(strip_tags($withoutUnsafeBlocks), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? '' : nl2br(e($text), false);
    }

    private function sanitizeDomChildren(\DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style'], true)) {
                    $parent->removeChild($child);
                    continue;
                }

                $this->sanitizeDomChildren($child);

                if (!in_array($tag, self::ALLOWED_HTML_TAGS, true)) {
                    while ($child->firstChild) {
                        $parent->insertBefore($child->firstChild, $child);
                    }
                    $parent->removeChild($child);
                    continue;
                }

                $this->sanitizeHtmlAttributes($child);
            }
        }
    }

    private function sanitizeHtmlAttributes(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (!in_array($name, self::ALLOWED_HTML_ATTRIBUTES, true) || $element->tagName !== 'a') {
                $element->removeAttribute($attribute->name);
                continue;
            }

            if ($name === 'href' && !$this->isAllowedHref($attribute->value)) {
                $element->removeAttribute('href');
            }
            if ($name === 'target' && !in_array($attribute->value, ['_blank', '_self'], true)) {
                $element->removeAttribute('target');
            }
        }

        if ($element->tagName === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function isAllowedHref(string $href): bool
    {
        $value = trim($href);
        return $value === '' ||
            str_starts_with($value, '/') ||
            str_starts_with($value, '#') ||
            preg_match('/^(https?:|mailto:|tel:)/i', $value) === 1;
    }

    private function canManageArticle(Request $request, object $article): bool
    {
        return $this->staffId($request) > 0 || $this->canModerate($request);
    }

    private function canModerate(Request $request): bool
    {
        $roles = (array) $request->session()->get('roles', []);
        return in_array('System Admin', $roles, true) || in_array('HR', $roles, true);
    }

    private function meta(Request $request): array
    {
        return [
            'categories' => self::CATEGORIES,
            'can_moderate' => $this->canModerate($request),
            'staff_id' => $this->staffId($request) ?: null,
        ];
    }

    private function staffId(Request $request): int
    {
        return (int) $request->session()->get('staff_id', 0);
    }

    private function nameCode(Request $request): ?string
    {
        return $this->nullableTrim($request->session()->get('name_code')) ?: null;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function deleteStoredPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                Storage::disk('public')->delete($path);
            }
        }
    }
}
