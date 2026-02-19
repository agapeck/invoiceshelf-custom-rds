<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Blueprint and Schema are kept for the non-MySQL/MariaDB path in up().

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Secures file_disks.credentials by:
     *   1. Dropping the MySQL CHECK (json_valid) constraint that existed on the
     *      original json column (MySQL keeps it even after a column type change).
     *   2. Ensuring the column is longtext (not json), because AES-256-CBC
     *      ciphertext is NOT valid JSON and would be rejected by a json column.
     *   3. Encrypting all existing plaintext JSON rows using Laravel's
     *      Crypt::encryptString() (AES-256-CBC, keyed by APP_KEY).
     *
     * Safe to re-run: already-encrypted rows are detected and skipped.
     */
    public function up(): void
    {
        // Step 1 & 2 combined: Change column to LONGTEXT using raw ALTER TABLE MODIFY COLUMN.
        //
        // Why raw SQL instead of Schema::table()->change()?
        // MariaDB (and MySQL) keep the json_valid() CHECK constraint on the column even after
        // a type change via Laravel's Schema builder. The only reliable way to drop it is via
        // MODIFY COLUMN in a raw ALTER TABLE statement, which atomically changes the type AND
        // removes any CHECK constraints tied to that column. This handles all constraint naming
        // variants (file_disks_chk_1, credentials, etc.) without needing to know the name.
        //
        // This is idempotent: running MODIFY COLUMN on an already-longtext column is a no-op.
        $driver = config('database.default');
        if (in_array($driver, ['mysql', 'mariadb'])) {
            DB::statement('ALTER TABLE `file_disks` MODIFY COLUMN `credentials` LONGTEXT NOT NULL');
        } else {
            // PostgreSQL, SQLite: use Schema builder (no json_valid constraint issue)
            Schema::table('file_disks', function (Blueprint $table) {
                $table->longText('credentials')->nullable(false)->change();
            });
        }

        // Step 3: Encrypt all existing plaintext JSON rows.
        $rows = DB::table('file_disks')->get(['id', 'credentials']);

        foreach ($rows as $row) {
            if (is_null($row->credentials)) {
                continue;
            }

            // Try to decrypt — if it succeeds the row is already encrypted; skip.
            try {
                Crypt::decryptString($row->credentials);
                continue; // already encrypted
            } catch (\Exception $e) {
                // Not encrypted yet — fall through to encrypt
            }

            // Must be valid JSON to be a legitimate credentials row.
            json_decode($row->credentials, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Neither encrypted nor valid JSON — skip to avoid data corruption.
                continue;
            }

            DB::table('file_disks')
                ->where('id', $row->id)
                ->update(['credentials' => Crypt::encryptString($row->credentials)]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Decrypts all rows back to plaintext JSON strings.
     * Note: column stays as LONGTEXT (not restored to json type) because
     * MariaDB/MySQL would immediately re-add the json_valid CHECK constraint,
     * which is harmless since the data is valid JSON again. Keeping it as
     * LONGTEXT is safe — it is a superset of json.
     */
    public function down(): void
    {
        // Decrypt all rows back to plaintext JSON strings.
        $rows = DB::table('file_disks')->get(['id', 'credentials']);

        foreach ($rows as $row) {
            if (is_null($row->credentials)) {
                continue;
            }

            try {
                $decrypted = Crypt::decryptString($row->credentials);
                json_decode($decrypted);
                if (json_last_error() === JSON_ERROR_NONE) {
                    DB::table('file_disks')
                        ->where('id', $row->id)
                        ->update(['credentials' => $decrypted]);
                }
            } catch (\Exception $e) {
                // Already plaintext or unrecognisable — leave as-is.
            }
        }
    }
};
