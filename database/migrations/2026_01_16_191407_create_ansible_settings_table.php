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
        Schema::create('ansible_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('default');
            $table->string('parent_table')->nullable();
            $table->string('parent_label_column')->nullable();
            $table->string('child_table')->nullable();
            $table->string('child_label_column')->nullable();
            $table->string('child_hostname_column')->nullable();
            $table->string('child_port_column')->nullable();
            $table->string('child_username_column')->nullable();
            $table->string('child_parent_foreign_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ansible_settings');
    }
};
