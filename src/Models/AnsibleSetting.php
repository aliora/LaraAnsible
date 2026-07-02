<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnsibleSetting extends Model
{
    use SoftDeletes;

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
        'ssh_private_key_path',
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

    public static function clearCache(): void
    {
        Cache::forget('ansible_setting_instance');
        Cache::forget('ansible_available_tables');
    }

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

    public static function getActive(): ?self
    {
        return static::getInstance();
    }

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

    public function parentLabelColumn(): string
    {
        return $this->parent_label_column ?? 'name';
    }

    public function childForeignKey(): string
    {
        return $this->child_parent_foreign_key ?? 'parent_id';
    }

    public function findParent(int|string|null $id): ?object
    {
        if (! $this->parent_table || $id === null) {
            return null;
        }

        try {
            return DB::table($this->parent_table)->find($id);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function findChild(int|string|null $id): ?object
    {
        if (! $this->child_table || $id === null) {
            return null;
        }

        try {
            return DB::table($this->child_table)->find($id);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function parentLabelFor(int|string|null $parentId): ?string
    {
        return $this->findParent($parentId)?->{$this->parentLabelColumn()} ?? null;
    }

    public function parentOptions(): array
    {
        if (! $this->parent_table) {
            return [];
        }

        return Cache::remember("ansible_parent_options_{$this->id}", 300, function () {
            try {
                return DB::table($this->parent_table)
                    ->get()
                    ->mapWithKeys(fn ($parent) => [$parent->id => $parent->{$this->parentLabelColumn()} ?? "#{$parent->id}"])
                    ->all();
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    public function childrenOf(int|string|null $parentId): Collection
    {
        if (! $this->child_table || $parentId === null) {
            return collect();
        }

        try {
            return DB::table($this->child_table)
                ->where($this->childForeignKey(), $parentId)
                ->get();
        } catch (\Exception $e) {
            return collect();
        }
    }

    public function childIdsOf(int|string|null $parentId): array
    {
        return $this->childrenOf($parentId)->pluck('id')->all();
    }

    public function childrenById(array $ids): Collection
    {
        if (! $this->child_table || $ids === []) {
            return collect();
        }

        try {
            return DB::table($this->child_table)->whereIn('id', $ids)->get();
        } catch (\Exception $e) {
            return collect();
        }
    }

    public function allChildIds(): array
    {
        if (! $this->child_table) {
            return [];
        }

        try {
            return DB::table($this->child_table)->pluck('id')->all();
        } catch (\Exception $e) {
            return [];
        }
    }
}
