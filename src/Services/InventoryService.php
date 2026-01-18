<?php

namespace VisioSoft\LaraAnsible\Services;

use Illuminate\Support\Facades\DB;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\Inventory;

class InventoryService
{
    /**
     * Calculate total host count from inventory IDs
     */
    public function calculateHostCount(array $inventoryIds): int
    {
        if (empty($inventoryIds)) {
            return 0;
        }

        $inventories = Inventory::whereIn('id', $inventoryIds)->get();
        $totalHosts = 0;

        foreach ($inventories as $inventory) {
            $totalHosts += $this->getInventoryHostCount($inventory);
        }

        return $totalHosts;
    }

    /**
     * Get host count from a single inventory
     */
    public function getInventoryHostCount(Inventory $inventory): int
    {
        if (!empty($inventory->script)) {
            // Count hosts in script format
            preg_match_all('/^([a-zA-Z0-9_.-]+)\s+ansible_host=/m', $inventory->script, $matches);
            return count($matches[1] ?? []);
        } elseif (!empty($inventory->hosts_entry)) {
            return count($inventory->hosts_entry);
        }

        return 0;
    }

    /**
     * Get parent options from configured table
     */
    public function getParentOptions(?AnsibleSetting $setting = null): array
    {
        $setting = $setting ?? AnsibleSetting::getInstance();

        if (!$setting || !$setting->parent_table) {
            return [];
        }

        try {
            $labelColumn = $setting->parent_label_column ?? 'name';
            return DB::table($setting->parent_table)
                ->pluck($labelColumn, 'id')
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get child hosts count for a parent
     */
    public function getChildHostsCount(int $parentId, ?AnsibleSetting $setting = null): int
    {
        $setting = $setting ?? AnsibleSetting::getInstance();

        if (!$setting || !$setting->child_table) {
            return 0;
        }

        $foreignKey = $setting->child_parent_foreign_key ?? 'parent_id';

        try {
            return DB::table($setting->child_table)
                ->where($foreignKey, $parentId)
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get already imported hosts count for a parent
     */
    public function getImportedHostsCount(int $parentId, ?AnsibleSetting $setting = null): int
    {
        $setting = $setting ?? AnsibleSetting::getInstance();

        if (!$setting || !$setting->child_table) {
            return 0;
        }

        $foreignKey = $setting->child_parent_foreign_key ?? 'parent_id';

        try {
            $childIds = DB::table($setting->child_table)
                ->where($foreignKey, $parentId)
                ->pluck('id')
                ->toArray();

            return Inventory::whereIn('external_source_id', $childIds)
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get new (not imported) hosts count for a parent
     */
    public function getNewHostsCount(int $parentId, ?AnsibleSetting $setting = null): int
    {
        $total = $this->getChildHostsCount($parentId, $setting);
        $imported = $this->getImportedHostsCount($parentId, $setting);

        return max(0, $total - $imported);
    }

    /**
     * Import hosts from configured database table
     */
    public function importFromDatabase(int $parentId, bool $refreshExisting = false, bool $createStaticInventory = false): array
    {
        $setting = AnsibleSetting::getInstance();

        if (!$setting || !$setting->child_table) {
            return ['success' => false, 'message' => 'Child table not configured'];
        }

        $foreignKey = $setting->child_parent_foreign_key ?? 'parent_id';
        $hostnameColumn = $setting->child_hostname_column ?? 'ip_address';

        try {
            $hosts = DB::table($setting->child_table)
                ->where($foreignKey, $parentId)
                ->get();

            $imported = 0;
            $updated = 0;
            $errors = [];

            foreach ($hosts as $host) {
                $hostId = $host->id;
                $hostname = $host->{$hostnameColumn} ?? null;

                if (!$hostname) {
                    $errors[] = "Host ID {$hostId} has no hostname";
                    continue;
                }

                $existingInventory = Inventory::where('external_source_id', $hostId)->first();

                if ($existingInventory) {
                    if ($refreshExisting) {
                        $existingInventory->update([
                            'name' => $hostname,
                            'hosts_entry' => [$hostname],
                        ]);
                        $updated++;
                    }
                } else {
                    Inventory::create([
                        'name' => $hostname,
                        'hosts_entry' => [$hostname],
                        'external_source_id' => $hostId,
                        'parent_id' => $parentId,
                    ]);
                    $imported++;
                }
            }

            return [
                'success' => true,
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
