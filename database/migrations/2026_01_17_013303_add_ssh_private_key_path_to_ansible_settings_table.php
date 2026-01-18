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
        Schema::table('ansible_settings', function (Blueprint $table) {
            $table->string('ssh_private_key_path')->nullable()->after('ssh_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ansible_settings', function (Blueprint $table) {
            $table->dropColumn(['ssh_private_key_path']);
        });
    }
};
