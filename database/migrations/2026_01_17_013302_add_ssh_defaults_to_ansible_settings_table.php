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
            $table->string('ssh_port')->default('22')->after('child_hostname_column');
            $table->string('ssh_username')->default('root')->after('ssh_port');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ansible_settings', function (Blueprint $table) {
            $table->dropColumn(['ssh_port', 'ssh_username']);
        });
    }
};
