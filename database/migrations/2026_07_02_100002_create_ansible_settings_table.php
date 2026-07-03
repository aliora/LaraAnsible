<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ansible_settings')) {
            return;
        }

        Schema::create('ansible_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('default');
            $table->string('parent_table');
            $table->string('parent_label_column');
            $table->string('child_table');
            $table->string('child_label_column');
            $table->string('child_hostname_column');
            $table->string('child_port_column')->nullable();
            $table->string('child_username_column')->nullable();
            $table->string('child_parent_foreign_key');
            $table->boolean('is_active')->default(true);
            $table->string('version_column')->nullable();
            $table->string('ssh_port')->default('22');
            $table->string('ssh_username')->default('root');
            $table->string('ssh_private_key_path')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ansible_settings');
    }
};
