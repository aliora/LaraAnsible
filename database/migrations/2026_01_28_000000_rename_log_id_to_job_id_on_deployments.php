<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename `log_id` -> `job_id`. It identifies the job (defaults to the row id)
     * and names its log file; "job id" reads clearer than "log id" in the UI/API.
     * Values are preserved, so existing log files (<id>.log) still resolve.
     */
    public function up(): void
    {
        if (Schema::hasColumn('deployments', 'log_id') && ! Schema::hasColumn('deployments', 'job_id')) {
            Schema::table('deployments', function (Blueprint $table) {
                $table->renameColumn('log_id', 'job_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('deployments', 'job_id') && ! Schema::hasColumn('deployments', 'log_id')) {
            Schema::table('deployments', function (Blueprint $table) {
                $table->renameColumn('job_id', 'log_id');
            });
        }
    }
};
