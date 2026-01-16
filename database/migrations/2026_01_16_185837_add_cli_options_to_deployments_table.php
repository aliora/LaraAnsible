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
            $table->json('cli_flags')->nullable()->after('extra_args');
            $table->string('limit_hosts')->nullable()->after('cli_flags');
            $table->string('tags')->nullable()->after('limit_hosts');
            $table->string('skip_tags')->nullable()->after('tags');
            $table->integer('forks')->nullable()->after('skip_tags');
            $table->string('start_at_task')->nullable()->after('forks');
            $table->string('remote_user')->nullable()->after('start_at_task');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn([
                'cli_flags',
                'limit_hosts',
                'tags',
                'skip_tags',
                'forks',
                'start_at_task',
                'remote_user',
            ]);
        });
    }
};
