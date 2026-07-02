<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_template_id')->nullable()->constrained('task_templates')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('inventory_ids');
            $table->string('status')->default('pending');
            $table->text('command_input')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('extra_args')->nullable();
            $table->string('inventory_file')->nullable();
            $table->json('cli_flags')->nullable();
            $table->string('limit_hosts')->nullable();
            $table->string('tags')->nullable();
            $table->string('skip_tags')->nullable();
            $table->integer('forks')->nullable();
            $table->string('start_at_task')->nullable();
            $table->string('remote_user')->nullable();
            $table->json('extra_vars')->nullable();
            $table->string('job_id')->nullable();
            $table->string('target_ip')->nullable();
            $table->string('playbook_name')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->integer('total_hosts')->nullable();
            $table->integer('processed_hosts')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('job_id');
            $table->index('created_at');
            $table->index('target_ip');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
