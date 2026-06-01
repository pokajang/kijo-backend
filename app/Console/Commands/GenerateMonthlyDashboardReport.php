<?php

namespace App\Console\Commands;

use App\Services\Stats\MonthlyDashboardReportService;
use Illuminate\Console\Command;

class GenerateMonthlyDashboardReport extends Command
{
    protected $signature = 'dashboard:monthly-report
        {--month= : Report month in YYYY-MM format. Defaults to previous month.}
        {--send : Email the signed report link to configured recipients.}
        {--scheduled : Send only when the configured dashboard report email schedule is due.}
        {--force : Regenerate the PDF even if one already exists.}
        {--dry-run : Build the payload and report intended actions without storing or sending.}';

    protected $description = 'Generate the year-to-date dashboard management report PDF.';

    public function handle(MonthlyDashboardReportService $reports): int
    {
        $month = trim((string) ($this->option('month') ?: $reports->previousReportMonth()));
        $force = (bool) $this->option('force');
        $send = (bool) $this->option('send');
        $scheduled = (bool) $this->option('scheduled');
        $dryRun = (bool) $this->option('dry-run');

        try {
            if ($scheduled) {
                $result = $reports->runScheduledSend();
                if (! ($result['due'] ?? false)) {
                    $this->info('Year-to-date dashboard report schedule checked. Not due.');

                    return self::SUCCESS;
                }

                if ($result['skipped']) {
                    $this->info("Year-to-date dashboard report schedule ran for {$result['reportMonth']}. Email skipped: no configured recipients.");
                } else {
                    $this->info("Year-to-date dashboard report schedule ran for {$result['reportMonth']}. Sent={$result['sent']}");
                }

                return self::SUCCESS;
            }

            if ($dryRun) {
                $payload = $reports->buildPayloadForDryRun($month);
                $this->info("Year-to-date dashboard report dry run for {$payload['reportMonth']} ({$payload['periodLabel']}).");
                $this->line('Configured recipients: '.count($reports->configuredRecipients()));
                $this->line('Sections: Sales, CRM, Financial, Monitoring, Workload.');

                return self::SUCCESS;
            }

            if ($send) {
                $result = $reports->sendForMonth($month, $force);
                if ($result['skipped']) {
                    $this->info("Year-to-date dashboard report generated for {$month}. Email skipped: no configured recipients.");
                } else {
                    $this->info("Year-to-date dashboard report generated and emailed for {$month}. Sent={$result['sent']}");
                }

                return self::SUCCESS;
            }

            $record = $reports->generateForMonth($month, $force);
            $this->info("Year-to-date dashboard report generated for {$record->report_month}: {$record->stored_path}");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
