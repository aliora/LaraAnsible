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
        Schema::table('inventories', function (Blueprint $table) {
            $table->foreignId('inventory_group_id')->nullable()->after('id')->constrained('inventory_groups')->nullOnDelete();
            $table->string('source_type')->default('static')->after('is_active'); // 'static' or 'dynamic'
            $table->unsignedBigInteger('dynamic_child_id')->nullable()->after('source_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropForeign(['inventory_group_id']);
            $table->dropColumn(['inventory_group_id', 'source_type', 'dynamic_child_id']);
        });
    }
};
