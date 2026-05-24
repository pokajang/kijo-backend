<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $slug = 'how-to-read-dashboard-statistics';

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
            'title' => 'How to Read Dashboard Statistics',
            'slug' => $this->slug,
            'summary' => 'Interpret Sales, CRM, Financial, and Monitoring dashboard statistics, understand their date logic, and use the data to guide company direction and action.',
            'body_html' => $this->articleBody(),
            'category' => 'System',
            'tags' => json_encode(['dashboard', 'statistics', 'sales', 'crm', 'financial', 'monitoring', 'pipeline', 'kpi', 'company-direction', 'management']),
            'related_route' => '/dashboard',
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
            $remarks = 'Updated dashboard interpretation guide with Sales, CRM, Financial, Monitoring, date logic, and management action guidance.';
        } else {
            $articleId = (int) DB::table('knowledge_articles')->insertGetId($payload + [
                'created_by_staff_id' => null,
                'created_by_name_code' => 'SYSTEM',
                'created_at' => $now,
            ]);
            $action = 'created_published';
            $remarks = 'Production dashboard interpretation guide seeded by system.';
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
<h3>What the dashboard is for</h3>
<p>The dashboard is a management view. Use it to understand business movement, not to replace the source modules.</p>
<ul>
<li><strong>Sales</strong> explains realized revenue movement and conversion quality.</li>
<li><strong>CRM</strong> explains quotation activity, inquiry sources, and pipeline generation before work is awarded.</li>
<li><strong>Financial</strong> explains invoiced value, received money, and outstanding receivables.</li>
<li><strong>Monitoring</strong> explains pipeline activity by month, week, staff, stage, segment, and service category.</li>
</ul>
<h3>Start with the correct period</h3>
<ol>
<li>Open <strong>Dashboard</strong> from the navigation.</li>
<li>Choose <strong>Sales</strong>, <strong>CRM</strong>, <strong>Financial</strong>, or <strong>Monitoring</strong>.</li>
<li>For Sales, CRM, and Financial, use the period selector to choose previous month, current month, current year, 3 months, 6 months, all time, or custom dates.</li>
<li>For Monitoring, choose a specific month. Monitoring then splits that month into weekly buckets.</li>
<li>When comparing numbers between tabs, check the date logic first. The same business may appear in different months depending on whether you are reading quotation date, award date, invoice date, paid date, or monitoring activity date.</li>
</ol>
<h3>The most important date rules</h3>
<ul>
<li><strong>Sales</strong> uses award date for realized projects and valid manual closed pipeline entries.</li>
<li><strong>CRM</strong> uses quotation created date.</li>
<li><strong>Financial - Invoiced</strong> uses invoice date.</li>
<li><strong>Financial - Received</strong> uses paid date.</li>
<li><strong>Financial - Outstanding</strong> uses open receivables as of the selected end date.</li>
<li><strong>Monitoring</strong> uses the selected month activity date. Revenue status uses award date or manual closed entry date.</li>
</ul>
<h3>Reading the Sales dashboard</h3>
<p>Use Sales when you want to understand what has turned into realized business.</p>
<ul>
<li><strong>Monthly Sales</strong> shows awarded or won value by month, based on award date.</li>
<li><strong>Awarded Value by Service</strong> shows which service lines are carrying revenue.</li>
<li><strong>Awarded Value by Person</strong> shows which staff are associated with realized value.</li>
<li><strong>Awarded Value by Source</strong> shows which inquiry sources are producing realized business.</li>
<li><strong>Conversion Rate by Staff</strong>, <strong>Conversion Rate by Source</strong>, and <strong>Conversion Rate by Service</strong> show how many quotes became realized projects.</li>
</ul>
<h3>How Sales data should guide action</h3>
<ul>
<li>If one service line dominates awarded value, check whether the company should protect capacity, pricing, staffing, and delivery quality for that service.</li>
<li>If a service line has weak awarded value but strong quotation activity in CRM, investigate pricing, proposal quality, follow-up speed, or market fit.</li>
<li>If a source produces high awarded value, invest more effort in that channel and document what makes it work.</li>
<li>If a source produces many quotes but weak conversion, tighten qualification before quoting or improve the sales script for that source.</li>
<li>If one staff member has high awarded value, study the behavior and replicate the process across the team.</li>
<li>If conversion rate is high but quote count is low, increase lead generation for that staff, service, or source.</li>
<li>If quote count is high but conversion rate is low, focus on lead quality, pricing discipline, follow-up cadence, or proposal positioning.</li>
</ul>
<h3>Reading the CRM dashboard</h3>
<p>Use CRM when you want to understand demand creation and quotation workload before business is awarded.</p>
<ul>
<li><strong>Monthly Quote Value</strong> shows the RM value of quotations created in each month.</li>
<li><strong>Monthly Quote Count</strong> shows quotation volume created in each month.</li>
<li><strong>Quote Value by Service</strong> shows where quotation value is concentrated by service line.</li>
<li><strong>Monthly Quote Value by Service</strong> shows whether service demand is rising, falling, or seasonal.</li>
<li><strong>Quote Activity by Staff</strong> can be read by quote count or quote value.</li>
<li><strong>Inquiry Source Mix</strong> can be read by inquiry count or quotation value from each source.</li>
</ul>
<h3>How CRM data should guide action</h3>
<ul>
<li>If quote count is falling, increase prospecting, call activity, source campaigns, or client reactivation work.</li>
<li>If quote value is rising but quote count is flat, the team may be handling larger opportunities. Check whether the team needs more proposal support or management review.</li>
<li>If one staff member has many quotes but low awarded results in Sales, review qualification, pricing, follow-up notes, and proposal closing actions.</li>
<li>If one service has growing quotation value, prepare delivery resources before the work is awarded.</li>
<li>If inquiry source count is high but value is low, use that source for volume only or adjust the targeting.</li>
<li>If inquiry source value is high but count is low, prioritize higher quality outreach for that source.</li>
<li>If CRM activity is strong but Sales is weak later, review the quotation-to-award process rather than blaming lead generation alone.</li>
</ul>
<h3>Reading the Financial dashboard</h3>
<p>Use Financial when you want to understand billing, cash received, and money still pending collection.</p>
<ul>
<li><strong>Total Invoiced</strong> is the value invoiced during the selected period, including valid manual debtor records.</li>
<li><strong>Total Received</strong> is the paid amount received during the selected period, based on paid date.</li>
<li><strong>Outstanding</strong> is unpaid receivable value as of the selected end date.</li>
<li><strong>Debtor rows</strong> show open receivables from system invoices and manual debtors.</li>
<li><strong>Age</strong> helps identify how long each receivable has been open.</li>
</ul>
<h3>How Financial data should guide action</h3>
<ul>
<li>If invoiced value is high but received value is low, prioritize collection, payment reminders, and client follow-up.</li>
<li>If outstanding value is growing, review payment terms, client credit behavior, invoice timing, and collection accountability.</li>
<li>If many invoices are old, focus on the oldest and highest-value debtors first.</li>
<li>If a client repeatedly appears as overdue, consider tighter payment terms or management approval before accepting more work.</li>
<li>If Sales is strong but Financial received is weak, the company may have revenue on paper but cash pressure in operation.</li>
<li>If received value is strong after a weak invoicing month, confirm whether cash came from old debtors rather than current-month billing.</li>
</ul>
<h3>Reading the Monitoring dashboard</h3>
<p>Use Monitoring when you want to understand live pipeline activity by month, staff, funnel stage, segment, and service category.</p>
<ul>
<li><strong>Monitoring Trends</strong> compares recent pipeline movement, proposal value, revenue value, and win rate.</li>
<li><strong>Pipeline Tools</strong> shows funnel quantity for <strong>Leads</strong>, <strong>Qualified</strong>, <strong>Meeting / Pitching</strong>, <strong>Proposal</strong>, <strong>Negotiation</strong>, and <strong>Closed</strong>.</li>
<li><strong>Weekly Pipeline Quantity</strong> shows which week of the month each activity landed in.</li>
<li><strong>Pipeline Segment Data</strong> separates individual, special project, and tender activity.</li>
<li><strong>Revenue Status</strong> groups awarded, won, and valid manual closed value by service category.</li>
<li><strong>Staff Pipeline Matrix</strong> appears for all-staff views and compares staff activity by funnel stage and segment.</li>
</ul>
<h3>What feeds Monitoring</h3>
<ul>
<li><strong>Leads</strong> can come from call records and manual lead entries.</li>
<li><strong>Qualified</strong> can come from created quotations and manual qualified entries.</li>
<li><strong>Meeting / Pitching</strong> comes from manual entries.</li>
<li><strong>Proposal</strong> can come from created quotations and manual proposal entries.</li>
<li><strong>Negotiation</strong> can come from quote negotiation requests and manual negotiation entries.</li>
<li><strong>Closed</strong> can come from awarded or won quotations and valid manual closed entries.</li>
</ul>
<h3>How Monitoring data should guide action</h3>
<ul>
<li>If leads are low, increase prospecting, call records, referrals, and campaign activity.</li>
<li>If leads are high but qualified is low, improve targeting and discovery questions.</li>
<li>If qualified is high but proposal is low, check whether staff are slow to quote, missing pricing information, or waiting for client details.</li>
<li>If proposal is high but negotiation is low, check follow-up discipline and whether proposals are being actively chased.</li>
<li>If negotiation is high but closed is low, review discount approvals, pricing objections, client budget fit, and closing authority.</li>
<li>If closed value is concentrated in one service category, plan manpower, delivery calendar, vendor support, and cash requirements around that category.</li>
<li>If special project or tender value is growing, management should review risk, resource planning, payment terms, and approval controls earlier.</li>
<li>If one week has unusually low activity, investigate holidays, staffing gaps, campaign pauses, or delayed data entry.</li>
</ul>
<h3>Reading staff filters in Monitoring</h3>
<ul>
<li>Managers, HR, admins, and super roles can view all staff or one selected staff member.</li>
<li>Normal users are limited to their own staff code.</li>
<li>Use <strong>All staff</strong> to understand company movement.</li>
<li>Use one staff member to coach individual activity, follow-up habits, and pipeline quality.</li>
<li>Do not compare staff only by quantity. Also compare stage quality, proposal value, closed value, and the kind of opportunities handled.</li>
</ul>
<h3>Connecting the tabs for company direction</h3>
<ul>
<li>Use <strong>CRM</strong> to see demand being generated.</li>
<li>Use <strong>Monitoring</strong> to see whether the team is moving opportunities through the funnel.</li>
<li>Use <strong>Sales</strong> to see which activities become realized business.</li>
<li>Use <strong>Financial</strong> to see whether realized work is turning into collectible cash.</li>
<li>If CRM and Monitoring are strong but Sales is weak, focus on conversion, pricing, proposal quality, and closing.</li>
<li>If Sales is strong but Financial is weak, focus on invoicing discipline, collection, credit control, and payment terms.</li>
<li>If Financial is strong but CRM is weak, the company may be collecting past work while future pipeline is thinning.</li>
<li>If all tabs are weak, the issue is likely not one module. Review market focus, team capacity, sales process, and management follow-up cadence.</li>
</ul>
<h3>Using dashboard data in management meetings</h3>
<ol>
<li>Start with the selected period and confirm which date logic applies.</li>
<li>Read CRM quote activity to understand new demand.</li>
<li>Read Monitoring to understand current funnel movement and staff activity.</li>
<li>Read Sales to understand realized business and conversion.</li>
<li>Read Financial to understand invoicing, collection, and debtor pressure.</li>
<li>Choose actions from the gap, not from one number alone.</li>
<li>Assign owners for follow-up actions such as calling dormant leads, chasing proposals, revising prices, collecting debtors, or improving service capacity.</li>
</ol>
<h3>Common mistakes to avoid</h3>
<ul>
<li>Do not compare Sales and CRM totals without remembering that Sales uses award date while CRM uses quote created date.</li>
<li>Do not treat high quotation value as guaranteed revenue.</li>
<li>Do not treat high invoiced value as cash received.</li>
<li>Do not manually add pipeline activity that is already captured by quotations, negotiations, or awarded records.</li>
<li>Do not judge a staff member only by one period if their deals are long-cycle, tender-based, or project-heavy.</li>
<li>Do not ignore empty or unattributed sources. They make channel decisions less reliable.</li>
<li>Do not use dashboards as final audit reports. Open the source module when a number needs investigation.</li>
</ul>
<h3>When numbers look wrong</h3>
<ul>
<li>Check the selected period or monitoring month first.</li>
<li>Check whether you are reading created date, award date, invoice date, paid date, or entry date.</li>
<li>Open the source module for the relevant record: quotation, project, invoice, debtor, call record, negotiation, or manual pipeline entry.</li>
<li>Check whether a record is missing a source, award date, paid date, owner staff code, service category, or estimated RM.</li>
<li>For manual closed entries, confirm that service category and estimated RM are complete.</li>
<li>For debtor values, confirm that status and paid date are correct.</li>
</ul>
HTML;
    }
};
