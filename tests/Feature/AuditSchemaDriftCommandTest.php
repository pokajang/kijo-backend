<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditSchemaDriftCommandTest extends TestCase
{
    private string $fixtureFile;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('audit_required_clients');
        Schema::dropIfExists('audit_dynamic_clients');
        Schema::dropIfExists('audit_spread_clients');
        Schema::dropIfExists('audit_complex_clients');
        Schema::dropIfExists('audit_test_clients');

        Schema::create('audit_test_clients', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->dateTime('created_at')->nullable();
        });

        DB::statement('CREATE TABLE audit_required_clients (id integer primary key autoincrement, name varchar not null, required_code varchar not null)');
        DB::statement('CREATE TABLE audit_complex_clients (id integer primary key autoincrement, name varchar not null, required_code varchar not null, nested_label varchar not null)');
        DB::statement('CREATE TABLE audit_spread_clients (id integer primary key autoincrement, name varchar not null, required_code varchar not null)');
        DB::statement('CREATE TABLE audit_dynamic_clients (id integer primary key autoincrement, name varchar not null, required_code varchar not null)');

        $fixtureDir = base_path('storage/framework/testing');
        if (! is_dir($fixtureDir)) {
            mkdir($fixtureDir, 0777, true);
        }

        $this->fixtureFile = $fixtureDir . '/schema-drift-fixture.php';
        file_put_contents($this->fixtureFile, <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

function schema_drift_fixture(): mixed
{
    DB::table('audit_test_clients')->where('id', 1)->update([
        'name' => 'Updated',
        'updated_at' => now(),
    ]);

    DB::table('audit_required_clients')->insert([
        'name' => 'Missing required code',
    ]);

    $item = [
        'name' => 'Complex',
        'required_code' => 'RC-1',
        'nested_label' => 'Nested',
    ];
    DB::table('audit_complex_clients')->insert([
        'name' => $item['name'],
        'required_code' => $item['required_code'],
        'nested_label' => [
            'value' => $item['nested_label'],
            'fallback' => ['inside' => true],
        ]['value'],
    ]);

    $payload = ['required_code' => 'SP-1'];
    DB::table('audit_spread_clients')->insert([
        ...$payload,
        'name' => 'Spread payload',
    ]);

    $insert = [
        'name' => 'Dynamic',
        'required_code' => 'DYN-1',
    ];
    DB::table('audit_dynamic_clients')->insert($insert);

    DB::table('audit_test_clients')->select(schema_select_columns('audit_test_clients', [
        'id',
        'name',
    ]));

    try {
        DB::table('audit_test_clients')->insert([
            'name' => 'Fallback',
        ]);
    } catch (\Throwable $e) {
        return response()->json(['status' => 'error', 'message' => 'Database error'], 500);
    }
}
PHP);
    }

    public function test_audit_reports_missing_columns_required_insert_columns_and_silent_database_errors(): void
    {
        $exitCode = Artisan::call('app:audit-schema-drift', [
            '--path' => [$this->fixtureFile],
            '--format' => 'json',
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload['summary']['files_scanned']);
        $this->assertFinding($payload['findings'], 'missing_column', 'audit_test_clients', 'updated_at');
        $this->assertFinding($payload['findings'], 'insert_omits_required_column', 'audit_required_clients', 'required_code');
        $this->assertFinding($payload['findings'], 'silent_database_error', '', '');
        $this->assertNoFinding($payload['findings'], 'insert_omits_required_column', 'audit_complex_clients', 'required_code');
        $this->assertNoFinding($payload['findings'], 'insert_omits_required_column', 'audit_complex_clients', 'nested_label');
        $this->assertNoFinding($payload['findings'], 'insert_omits_required_column', 'audit_spread_clients', 'required_code');
        $this->assertNoFinding($payload['findings'], 'insert_omits_required_column', 'audit_dynamic_clients', 'required_code');
        $this->assertNoFinding($payload['findings'], 'missing_column', 'audit_test_clients', 'audit_test_clients');
    }

    public function test_audit_can_fail_ci_when_threshold_is_met(): void
    {
        $exitCode = Artisan::call('app:audit-schema-drift', [
            '--path' => [$this->fixtureFile],
            '--format' => 'json',
            '--fail-on' => 'blocker',
        ]);

        $this->assertSame(1, $exitCode);
    }

    private function assertFinding(array $findings, string $category, string $table, string $column): void
    {
        foreach ($findings as $finding) {
            if (
                $finding['category'] === $category
                && $finding['table'] === $table
                && $finding['column'] === $column
            ) {
                $this->assertTrue(true);
                return;
            }
        }

        $this->fail("Expected finding {$category} for {$table}.{$column} was not reported.");
    }

    private function assertNoFinding(array $findings, string $category, string $table, string $column): void
    {
        foreach ($findings as $finding) {
            if (
                $finding['category'] === $category
                && $finding['table'] === $table
                && $finding['column'] === $column
            ) {
                $this->fail("Unexpected finding {$category} for {$table}.{$column} was reported.");
            }
        }

        $this->assertTrue(true);
    }
}
