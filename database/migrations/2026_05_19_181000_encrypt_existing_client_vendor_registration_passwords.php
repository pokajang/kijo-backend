<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('client_vendor_registrations') || !Schema::hasColumn('client_vendor_registrations', 'portal_password')) {
            return;
        }

        DB::table('client_vendor_registrations')
            ->whereNotNull('portal_password')
            ->where('portal_password', '<>', '')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $password = trim((string) $row->portal_password);
                    if ($password === '' || $this->isEncrypted($password)) {
                        continue;
                    }

                    DB::table('client_vendor_registrations')
                        ->where('id', $row->id)
                        ->update(['portal_password' => Crypt::encryptString($password)]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally irreversible. Decrypting stored portal passwords during rollback would expose secrets.
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
};
