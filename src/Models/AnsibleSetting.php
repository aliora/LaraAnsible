<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnsibleSetting extends Model
{
    protected $fillable = [
        'name',
        'parent_table',
        'parent_label_column',
        'child_table',
        'child_label_column',
        'child_hostname_column',
        'child_port_column',
        'child_username_column',
        'child_parent_foreign_key',
        'version_column',
        'ssh_port',
        'ssh_username',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::clearCache());
        static::deleted(fn () => static::clearCache());
    }

    /**
     * Clear all cached data for this model.
     */
    public static function clearCache(): void
    {
        Cache::forget('ansible_setting_instance');
        Cache::forget('ansible_available_tables');
    }

    /**
     * Get the singleton instance of AnsibleSetting.
     * Creates a default record if none exists.
     * Results are cached for 5 minutes.
     */
    public static function getInstance(): self
    {
        return Cache::remember('ansible_setting_instance', 300, function () {
            $setting = static::first();

            if (! $setting) {
                $setting = static::create([
                    'name' => 'default',
                    'is_active' => true,
                ]);
            }

            return $setting;
        });
    }

    /**
     * Alias for getInstance() for backwards compatibility.
     */
    public static function getActive(): ?self
    {
        return static::getInstance();
    }

    /**
     * Get all available database tables.
     * Results are cached for 10 minutes.
     */
    public static function getAvailableTables(): array
    {
        return Cache::remember('ansible_available_tables', 600, function () {
            try {
                $tables = Schema::getTables();
                $tableNames = array_column($tables, 'name');

                return array_combine($tableNames, $tableNames);
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    /**
     * Get columns for a specific table.
     * Results are cached for 10 minutes per table.
     */
    public static function getColumnsForTable(?string $table): array
    {
        if (! $table) {
            return [];
        }

        return Cache::remember("ansible_columns_{$table}", 600, function () use ($table) {
            try {
                $columns = DB::getSchemaBuilder()->getColumnListing($table);

                return array_combine($columns, $columns);
            } catch (\Exception $e) {
                return [];
            }
        });
    }
}
