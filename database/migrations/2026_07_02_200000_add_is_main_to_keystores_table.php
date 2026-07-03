<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('keystores', 'is_main')) {
            return;
        }

        Schema::table('keystores', function (Blueprint $table) {
            $table->boolean('is_main')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('keystores', 'is_main')) {
            return;
        }

        Schema::table('keystores', function (Blueprint $table) {
            $table->dropColumn('is_main');
        });
    }
};
