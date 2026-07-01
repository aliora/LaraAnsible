<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('deployments', 'log_id')) {
            return;
        }

        Schema::table('deployments', function (Blueprint $table) {
            // Identifier used to name the log file: storage/logs/ansible-playbook/<log_id>.log
            $table->string('log_id')->nullable()->index();
        });

        // Backfill existing rows: log_id = id (the log files are already named <id>.log).
        DB::table('deployments')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    if (blank($row->log_id)) {
                        DB::table('deployments')->where('id', $row->id)->update(['log_id' => (string) $row->id]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn('log_id');
        });
    }
};
