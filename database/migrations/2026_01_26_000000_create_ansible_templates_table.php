<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Idempotent: some installs already have an `ansible_templates` table (created
     * before it was tracked by a migration). Create it fresh when missing, and in
     * every case ensure the columns the File Templates importer relies on exist.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ansible_templates')) {
            Schema::create('ansible_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->longText('content')->nullable();
                $table->string('kind')->nullable(); // task, template
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            return;
        }

        Schema::table('ansible_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('ansible_templates', 'kind')) {
                $table->string('kind')->nullable()->after('content');
            }

            if (! Schema::hasColumn('ansible_templates', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only drop columns we may have added; leave a pre-existing table intact.
        if (Schema::hasTable('ansible_templates')) {
            Schema::table('ansible_templates', function (Blueprint $table) {
                foreach (['kind', 'is_active'] as $column) {
                    if (Schema::hasColumn('ansible_templates', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
