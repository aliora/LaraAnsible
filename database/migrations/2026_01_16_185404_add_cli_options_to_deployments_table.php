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
            $table->json('cli_check_flags')->nullable()->after('extra_args');
            $table->json('cli_target_flags')->nullable()->after('cli_check_flags');
            $table->string('cli_limit')->nullable()->after('cli_target_flags');
            $table->string('cli_tags')->nullable()->after('cli_limit');
            $table->string('cli_skip_tags')->nullable()->after('cli_tags');
            $table->string('cli_start_at_task')->nullable()->after('cli_skip_tags');
            $table->integer('cli_forks')->nullable()->after('cli_start_at_task');
            $table->string('cli_verbosity')->nullable()->after('cli_forks');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn([
                'cli_check_flags',
                'cli_target_flags',
                'cli_limit',
                'cli_tags',
                'cli_skip_tags',
                'cli_start_at_task',
                'cli_forks',
                'cli_verbosity',
            ]);
        });
    }
};
