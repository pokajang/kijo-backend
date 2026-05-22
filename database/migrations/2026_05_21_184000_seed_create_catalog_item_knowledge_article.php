<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-create-a-catalog-item';

    public function up(): void
    {
        if (! Schema::hasTable('knowledge_articles')) {
            return;
        }

        if (DB::table('knowledge_articles')->where('slug', $this->slug)->exists()) {
            return;
        }

        $now = now();
        $articleId = (int) DB::table('knowledge_articles')->insertGetId([
            'title' => 'How to Create a Catalog Item',
            'slug' => $this->slug,
            'summary' => 'Add equipment, materials, supplier pricing, remarks, and brochure files into the shared catalog.',
            'body_html' => $this->articleBody(),
            'category' => 'Catalog',
            'tags' => json_encode(['catalog', 'equipment', 'supplier', 'price', 'brochure']),
            'related_route' => '/catalog/create',
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

        $this->insertEditLog($articleId, $now);
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

    private function insertEditLog(int $articleId, mixed $createdAt): void
    {
        if (! Schema::hasTable('knowledge_article_edit_logs')) {
            return;
        }

        DB::table('knowledge_article_edit_logs')->insert([
            'knowledge_article_id' => $articleId,
            'action' => 'created_published',
            'remarks' => 'Production starter guide seeded by system.',
            'staff_id' => null,
            'name_code' => 'SYSTEM',
            'created_at' => $createdAt,
        ]);
    }

    private function articleBody(): string
    {
        return <<<'HTML'
<h3>Starting a catalog item</h3>
<ol>
<li>Open <strong>Catalog List</strong>.</li>
<li>Click <strong>Create Item</strong> at the top-right of <strong>Manage Catalog</strong>.</li>
<li>The system opens <strong>Create New Catalog Item</strong>.</li>
<li>Create one catalog item per entry.</li>
</ol>
<h3>Filling item details</h3>
<ul>
<li>Enter the product or equipment name in <strong>Item Name</strong>.</li>
<li>Select the correct <strong>Category</strong>.</li>
<li>Enter the <strong>Unit</strong>, such as piece, set, box, or hour.</li>
<li>Use <strong>Description</strong> for usage notes, specifications, model details, or internal reference.</li>
</ul>
<h3>Adding supplier and price details</h3>
<ul>
<li>Enter the supplier in <strong>Supplier Name</strong>.</li>
<li>Enter the latest known price in <strong>Latest Supplier Price (RM / unit)</strong>.</li>
<li>Choose the <strong>Price Date</strong> so users know when the price was last checked.</li>
<li>Use <strong>Entry Remarks</strong> for internal notes, alternative supplier info, or special purchasing details.</li>
</ul>
<h3>Attaching a product brochure</h3>
<ul>
<li>Use <strong>Product Brochure</strong> to attach a supporting PDF or product image.</li>
<li>Accepted files are PDF, JPG, JPEG, and PNG.</li>
<li>The file should be below 10 MB.</li>
<li>If you leave the form before saving, you may need to select the file again.</li>
</ul>
<h3>Saving the item</h3>
<ol>
<li>Click <strong>Create Catalog Item</strong>.</li>
<li>Confirm that you want to add the item.</li>
<li>After saving, choose <strong>Go to list</strong> to return to <strong>Manage Catalog</strong>, or choose <strong>Create another</strong> to add another item.</li>
</ol>
<h3>Recovering unfinished drafts</h3>
<ul>
<li>The form auto-saves unfinished text fields in the browser.</li>
<li>Use <strong>Reset</strong> to clear the draft and start again.</li>
<li>Uploaded brochure files are not safely restored by browser draft recovery, so reselect the file before saving.</li>
</ul>
<h3>Managing saved catalog items</h3>
<ul>
<li>Open <strong>Manage Catalog</strong> to search and filter catalog items.</li>
<li>Use the row action menu to <strong>View</strong>, <strong>Edit</strong>, or <strong>Delete</strong> an item.</li>
<li>Open the detail page to review item details, supplier pricing, remarks, and attached brochure.</li>
<li>Use <strong>Edit</strong> to replace or remove the brochure and update item information.</li>
</ul>
HTML;
    }
};
