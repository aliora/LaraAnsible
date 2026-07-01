<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('task_templates', 'input_vars')) {
            return;
        }

        Schema::table('task_templates', function (Blueprint $table) {
            // Declared inputs asked at launch (name/label/type/options/default/required),
            // collected in the run modal and passed to the playbook as --extra-vars.
            $table->json('input_vars')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn('input_vars');
        });
    }
};
