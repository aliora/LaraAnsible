<?php

namespace VisioSoft\LaraAnsible\Services;

use Illuminate\Support\Facades\Log;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Models\Inventory;

/**
 * Service for building Ansible inventory files
 * 
 * Handles the creation of inventory files from static and dynamic sources,
 * including host line generation, SSH key management, and host counting.
 * 
 * This service is responsible for translating database inventory records
 * into Ansible-compatible inventory file formats.
 */
class InventoryBuilderService
{
    /**
     * Prefix for dynamic inventory identifiers
     */
    const DYNAMIC_INVENTORY_PREFIX = 'dynamic_';
    /**
     * Create temporary inventory file or use existing inventory file
     * Returns array with 'path' and 'host_count'
     */
    public function createInventoryFile(Deployment $deployment): array
    {
        Log::info("Creating inventory for deployment {$deployment->id}");
        Log::info('Deployment inventory_ids: ' . json_encode($deployment->inventory_ids));
        Log::info('Deployment inventory_file: ' . ($deployment->inventory_file ?? 'null'));

        // If an inventory file is specified, use it directly
        if ($deployment->inventory_file && file_exists($deployment->inventory_file)) {
            $content = file_get_contents($deployment->inventory_file);
            $count = substr_count($content, 'ansible_host=');
            return [
                'path' => $deployment->inventory_file,
                'host_count' => max(1, $count)
            ];
        }

        $inventoryIds = $deployment->inventory_ids ?? [];
        $inventories = $this->resolveInventories($inventoryIds);

        Log::info('Inventories found: ' . $inventories->count());

        // Build inventory content
        $inventoryContent = $this->buildInventoryContent($inventories);
        $totalHostsCount = $this->countHostsInContent($inventoryContent);

        // Write to file
        $inventoryPath = $this->writeInventoryFile($deployment->id, $inventoryContent);

        Log::info("Inventory created at {$inventoryPath} with {$totalHostsCount} hosts");

        return [
            'path' => $inventoryPath,
            'host_count' => $totalHostsCount,
        ];
    }

    /**
     * Resolve inventory objects from IDs (static and dynamic)
     */
    protected function resolveInventories(array $inventoryIds): \Illuminate\Support\Collection
    {
        $staticInventoryIds = [];
        $dynamicInventoryIds = [];

        // Separate static and dynamic IDs
        foreach ($inventoryIds as $id) {
            if ($id === 'all') {
                $staticInventoryIds = ['all'];
                break;
            }

            if (is_string($id) && str_starts_with($id, self::DYNAMIC_INVENTORY_PREFIX)) {
                $dynamicInventoryIds[] = $id;
            } else {
                $staticInventoryIds[] = $id;
            }
        }

        $inventories = collect();

        // Handle Static Inventories
        if (!empty($staticInventoryIds)) {
            if (in_array('all', $staticInventoryIds)) {
                $inventories = Inventory::where('is_active', true)->get();
            } else {
                $inventories = Inventory::whereIn('id', $staticInventoryIds)->get();
            }
        }

        Log::info('Static Inventory IDs: ' . json_encode($staticInventoryIds));
        Log::info('Dynamic Inventory IDs: ' . json_encode($dynamicInventoryIds));

        // Handle Dynamic Inventories
        if (!empty($dynamicInventoryIds)) {
            $dynamicInventories = $this->resolveDynamicInventories($dynamicInventoryIds);
            $inventories = $inventories->merge($dynamicInventories);
        }

        return $inventories;
    }

    /**
     * Resolve dynamic inventories from database
     */
    protected function resolveDynamicInventories(array $dynamicInventoryIds): \Illuminate\Support\Collection
    {
        $inventories = collect();
        $setting = AnsibleSetting::getActive();

        if (!$setting || !$setting->child_table) {
            return $inventories;
        }

        // Validate table and column exist
        if (!$this->validateTableExists($setting->child_table) || 
            !$this->validateColumnExists($setting->child_table, $setting->child_hostname_column)) {
            Log::warning("Invalid dynamic inventory configuration: table or column does not exist");
            return $inventories;
        }

        foreach ($dynamicInventoryIds as $dynamicId) {
            // key format: dynamic_{child_id}_{hostname}
            $parts = explode('_', $dynamicId);
            if (count($parts) >= 3) {
                $childId = $parts[1];

                // Validate child ID is numeric
                if (!is_numeric($childId)) {
                    Log::warning("Invalid child ID in dynamic inventory: {$childId}");
                    continue;
                }

                try {
                    $child = \DB::table($setting->child_table)->find($childId);
                    if ($child) {
                        $inventory = new Inventory;
                        $inventory->hostname = $child->{$setting->child_hostname_column} ?? null;
                        $inventory->port = $setting->ssh_port ?? 22;
                        $inventory->username = $setting->ssh_username ?? 'root';

                        if ($inventory->hostname) {
                            $inventories->push($inventory);
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to fetch dynamic inventory item {$dynamicId}: " . $e->getMessage());
                }
            }
        }

        return $inventories;
    }

    /**
     * Validate that a table exists in the database
     */
    protected function validateTableExists(string $tableName): bool
    {
        try {
            return \DB::getSchemaBuilder()->hasTable($tableName);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate that a column exists in a table
     */
    protected function validateColumnExists(string $tableName, string $columnName): bool
    {
        try {
            return \DB::getSchemaBuilder()->hasColumn($tableName, $columnName);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build inventory file content
     */
    protected function buildInventoryContent($inventories): string
    {
        $content = '';

        foreach ($inventories as $inventory) {
            // Handle script-based inventories
            if (!empty($inventory->script)) {
                $content .= $inventory->script . "\n";
                continue;
            }

            // Handle host entry-based inventories
            $hostsEntry = $inventory->hosts_entry ?? [];
            if (is_string($hostsEntry)) {
                $hostsEntry = json_decode($hostsEntry, true) ?? [];
            }

            if (empty($hostsEntry) && $inventory->hostname) {
                $hostsEntry = [$inventory->hostname];
            }

            foreach ($hostsEntry as $host) {
                $hostLine = $this->buildHostLine($host, $inventory);
                $content .= $hostLine . "\n";
            }
        }

        return $content;
    }

    /**
     * Build a single host line for inventory
     */
    protected function buildHostLine(string $host, Inventory $inventory): string
    {
        $hostname = $inventory->hostname ?? $host;
        $line = "{$host} ansible_host={$hostname}";

        if ($inventory->port && $inventory->port != 22) {
            $line .= " ansible_port={$inventory->port}";
        }

        if ($inventory->username) {
            $line .= " ansible_user={$inventory->username}";
        }

        if ($inventory->keystore && $inventory->keystore->private_key) {
            $keyPath = $this->createTempKeyFile($inventory->keystore);
            $line .= " ansible_ssh_private_key_file={$keyPath}";
        }

        return $line;
    }

    /**
     * Count hosts in inventory content
     */
    protected function countHostsInContent(string $content): int
    {
        preg_match_all('/^([a-zA-Z0-9_.-]+)\s+ansible_host=/m', $content, $matches);
        return count($matches[1] ?? []);
    }

    /**
     * Write inventory content to file
     */
    protected function writeInventoryFile(int $deploymentId, string $content): string
    {
        $path = storage_path("app/ansible/inventory_{$deploymentId}");
        $dir = dirname($path);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($path, $content);
        
        return $path;
    }

    /**
     * Create temporary SSH key file
     */
    protected function createTempKeyFile($keystore): string
    {
        $path = storage_path('app/ansible/keys/key_' . $keystore->id);
        $dir = dirname($path);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        
        file_put_contents($path, $keystore->private_key);
        chmod($path, 0600);

        return $path;
    }

    /**
     * Extract hosts from inventory script
     */
    public function extractHostsFromInventoryScript(string $script): array
    {
        preg_match_all('/^([a-zA-Z0-9_.-]+)\s+ansible_host=/m', $script, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Extract host lines from inventory script
     */
    public function extractHostLinesFromInventoryScript(string $script): array
    {
        $lines = explode("\n", $script);
        $hostLines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#') || str_starts_with($line, '[')) {
                continue;
            }
            if (str_contains($line, 'ansible_host=')) {
                $hostLines[] = $line;
            }
        }

        return $hostLines;
    }
}
