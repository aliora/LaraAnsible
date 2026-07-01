<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('deployments', 'command_output')) {
            return;
        }

        // Preserve existing DB logs as files before dropping the column.
        DB::table('deployments')
            ->whereNotNull('command_output')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    if (blank($row->command_output)) {
                        continue;
                    }

                    $path = storage_path('logs/ansible-playbook/'.$row->id.'.log');
                    if (is_file($path)) {
                        continue;
                    }

                    if (! is_dir(dirname($path))) {
                        @mkdir(dirname($path), 0775, true);
                    }

                    file_put_contents($path, $row->command_output);
                }
            });

        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn('command_output');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->text('command_output')->nullable();
        });
    }
};
