<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('playbook_path')->nullable();
            $table->text('playbook_content')->nullable();
            $table->json('extra_vars')->nullable();
            $table->string('type')->default('playbook');
            $table->boolean('is_active')->default(true);
            $table->json('input_vars')->nullable();
            $table->json('templates')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};
