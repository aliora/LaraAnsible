<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('hostname')->nullable();
            $table->unsignedSmallInteger('port')->nullable()->default(22);
            $table->string('username')->nullable();
            $table->foreignId('keystore_id')->nullable()->constrained('keystores')->nullOnDelete();
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source_type')->default('static');
            $table->unsignedBigInteger('dynamic_child_id')->nullable();
            $table->json('dynamic_child_ids')->nullable();
            $table->unsignedBigInteger('park_id')->nullable();
            $table->text('script')->nullable();
            $table->text('ip_list')->nullable();
            $table->text('name_list')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('park_id');
            $table->index('source_type');
            $table->index('dynamic_child_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
