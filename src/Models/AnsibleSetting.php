<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Database\Eloquent\Model;

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
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get active setting
     */
    public static function getActive(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * Get grouped inventory options from dynamic tables
     */
    public function getGroupedInventoryOptions(): array
    {
        if (! $this->child_table || ! $this->child_hostname_column) {
            return [];
        }

        $options = [];

        try {
            $query = \DB::table($this->child_table);

            if ($this->parent_table && $this->child_parent_foreign_key) {
                // Get grouped by parent
                $parents = \DB::table($this->parent_table)->get();

                foreach ($parents as $parent) {
                    $parentLabel = $this->parent_label_column
                        ? ($parent->{$this->parent_label_column} ?? "Parent #{$parent->id}")
                        : "Parent #{$parent->id}";

                    $children = \DB::table($this->child_table)
                        ->where($this->child_parent_foreign_key, $parent->id)
                        ->get();

                    $groupOptions = [];
                    foreach ($children as $child) {
                        $childLabel = $this->child_label_column
                            ? ($child->{$this->child_label_column} ?? "Device #{$child->id}")
                            : "Device #{$child->id}";

                        $hostname = $child->{$this->child_hostname_column} ?? '';

                        if ($hostname) {
                            $key = "dynamic_{$child->id}_{$hostname}";
                            $groupOptions[$key] = "{$childLabel} ({$hostname})";
                        }
                    }

                    if (! empty($groupOptions)) {
                        $options[$parentLabel] = $groupOptions;
                    }
                }
            } else {
                // Flat list without parent grouping
                $children = \DB::table($this->child_table)->get();

                foreach ($children as $child) {
                    $childLabel = $this->child_label_column
                        ? ($child->{$this->child_label_column} ?? "Device #{$child->id}")
                        : "Device #{$child->id}";

                    $hostname = $child->{$this->child_hostname_column} ?? '';

                    if ($hostname) {
                        $key = "dynamic_{$child->id}_{$hostname}";
                        $options[$key] = "{$childLabel} ({$hostname})";
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('AnsibleSetting getGroupedInventoryOptions error: '.$e->getMessage());
        }

        return $options;
    }
}
