<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->unsignedInteger('progress')->default(0)->after('exit_code');
            $table->unsignedInteger('total_hosts')->default(0)->after('progress');
            $table->unsignedInteger('processed_hosts')->default(0)->after('total_hosts');
            $table->foreignId('inventory_group_id')->nullable()->after('inventory_file')->constrained('inventory_groups')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropForeign(['inventory_group_id']);
            $table->dropColumn(['progress', 'total_hosts', 'processed_hosts', 'inventory_group_id']);
        });
    }
};
