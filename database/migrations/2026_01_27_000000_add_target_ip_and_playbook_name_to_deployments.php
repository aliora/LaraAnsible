<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denormalised metadata for a flat "ansible execution" view: the primary
     * target host/IP and the resolved playbook name. Raw output stays in the log
     * file (storage/logs/ansible-playbook/<log_id>.log) — these are metadata only.
     */
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            if (! Schema::hasColumn('deployments', 'target_ip')) {
                $table->string('target_ip')->nullable()->index()->after('inventory_ids');
            }

            if (! Schema::hasColumn('deployments', 'playbook_name')) {
                $table->string('playbook_name')->nullable()->after('target_ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            foreach (['target_ip', 'playbook_name'] as $column) {
                if (Schema::hasColumn('deployments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
