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
        // Table name is 'inventories' based on 2025_11_14_165425_create_inventories_table.php
        Schema::table('inventories', function (Blueprint $table) {
            if (! Schema::hasColumn('inventories', 'park_id')) {
                // Assuming parks table exists in main app.
                // We use bigInteger and unsigned to match standard id.
                // We don't use constrained() immediately to avoid failure if 'parks' table is missing or named differently,
                // but usually we should. Let's assume standard 'parks'.
                $table->foreignId('park_id')->nullable()->after('name')->constrained('parks')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            if (Schema::hasColumn('inventories', 'park_id')) {
                $table->dropForeign(['park_id']);
                $table->dropColumn('park_id');
            }
        });
    }
};
