<?php

namespace VisioSoft\LaraAnsible\Services;

use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Models\Keystore;

/**
 * Imports parent/child database hosts into a single dynamic Inventory per park.
 * Reused by the Inventory table "import" action and by the Device single-task
 * button, so the create/merge/dedup logic lives in one place.
 */
class InventoryImportService
{
    /**
     * Sync every (or a given subset of) child under a parent into the park's
     * dynamic inventory: creates it, or merges only the new hosts into the
     * existing one. Dedup is hostname-based, so re-running is idempotent.
     *
     * @param  array<int|string>|null  $childIds  null → all children of the parent
     * @return array{inventory: Inventory|null, imported: int, skipped: int}
     */
    public function syncPark(int|string|null $parentId, ?array $childIds = null): array
    {
        $setting = AnsibleSetting::getInstance();

        if (! $setting || ! $setting->child_table || blank($parentId)) {
            return ['inventory' => null, 'imported' => 0, 'skipped' => 0];
        }

        $childIds ??= $setting->childIdsOf($parentId);
        if (empty($childIds)) {
            return ['inventory' => null, 'imported' => 0, 'skipped' => 0];
        }

        $children = $setting->childrenById($childIds);
        $existing = Inventory::where('source_type', 'dynamic')
            ->where('park_id', $parentId)
            ->first();

        if ($children->isEmpty()) {
            return ['inventory' => $existing, 'imported' => 0, 'skipped' => 0];
        }

        $labelColumn = $setting->child_label_column ?? 'name';
        $hostnameColumn = $setting->child_hostname_column;
        $portColumn = $setting->child_port_column;
        $usernameColumn = $setting->child_username_column;

        $existingHostnames = $this->importedHostnames();
        $hostsEntry = [];
        $importedChildIds = [];
        $portValues = [];
        $usernameValues = [];
        $skipped = 0;

        foreach ($children as $child) {
            $hostname = $child->{$hostnameColumn} ?? null;
            if (! $hostname) {
                $skipped++;

                continue;
            }

            $cleanHostname = preg_replace('/[\r\n\t]+/', '', $hostname);
            if (isset($existingHostnames[$cleanHostname])) {
                $skipped++;

                continue;
            }

            $label = $child->{$labelColumn} ?? 'host_'.$child->id;
            $alias = trim((string) $label);
            if ($alias === '') {
                $alias = 'host_'.$child->id;
            }
            $alias = Inventory::sanitizeHostAlias($alias);

            $finalAlias = $alias;
            $suffix = 2;
            while (array_key_exists($finalAlias, $hostsEntry)) {
                $finalAlias = $alias.'_'.$suffix;
                $suffix++;
            }

            $hostsEntry[$finalAlias] = $cleanHostname;
            $importedChildIds[] = $child->id;

            if ($portColumn && isset($child->{$portColumn})) {
                $portValues[] = $child->{$portColumn};
            }
            if ($usernameColumn && isset($child->{$usernameColumn})) {
                $usernameValues[] = $child->{$usernameColumn};
            }
        }

        if (empty($hostsEntry)) {
            return ['inventory' => $existing, 'imported' => 0, 'skipped' => $skipped];
        }

        $portValues = array_values(array_unique(array_filter($portValues, fn ($value) => $value !== null && $value !== '')));
        $usernameValues = array_values(array_unique(array_filter($usernameValues, fn ($value) => $value !== null && $value !== '')));

        $port = $setting->ssh_port ?? 22;
        if (count($portValues) === 1) {
            $port = (int) $portValues[0];
        }

        $username = $setting->ssh_username ?? 'root';
        if (count($usernameValues) === 1) {
            $username = (string) $usernameValues[0];
        }

        $mainKeystoreId = Keystore::where('is_main', true)->value('id');

        if ($existing) {
            $mergedHosts = array_merge($existing->hosts_entry, $hostsEntry);
            $mergedChildIds = array_values(array_unique(array_merge(
                $existing->dynamic_child_ids ?? [],
                $importedChildIds
            )));

            $mergeData = [
                'hosts_entry' => $mergedHosts,
                'dynamic_child_id' => $mergedChildIds[0] ?? null,
                'dynamic_child_ids' => $mergedChildIds,
                'keystore_id' => $mainKeystoreId ?? $existing->keystore_id,
            ];

            $script = Inventory::buildInventoryScriptFromData(array_merge(
                $existing->toArray(),
                ['hosts_entry' => $mergedHosts]
            ));
            if ($script !== null) {
                $mergeData['script'] = $script;
            }

            $existing->update($mergeData);
            $inventory = $existing;
        } else {
            $inventoryData = [
                'name' => $setting->parentLabelFor($parentId) ?? __('laraansible::laraansible.imported_hosts_name'),
                'port' => $port,
                'username' => $username,
                'source_type' => 'dynamic',
                'dynamic_child_id' => $importedChildIds[0] ?? null,
                'dynamic_child_ids' => $importedChildIds,
                'hosts_entry' => $hostsEntry,
                'is_active' => true,
                'park_id' => $parentId,
                'keystore_id' => $mainKeystoreId,
            ];

            $script = Inventory::buildInventoryScriptFromData($inventoryData);
            if ($script !== null) {
                $inventoryData['script'] = $script;
            }

            $inventory = Inventory::create($inventoryData);
        }

        return ['inventory' => $inventory, 'imported' => count($hostsEntry), 'skipped' => $skipped];
    }

    /**
     * The inventory host alias (name_list key) mapped to a given device IP, for
     * passing to `ansible-playbook --limit`. Null if the IP is not in the map.
     */
    public function resolveHostAlias(Inventory $inventory, ?string $deviceIp): ?string
    {
        if (blank($deviceIp)) {
            return null;
        }

        $clean = preg_replace('/[\r\n\t]+/', '', $deviceIp);
        $alias = array_search($clean, $inventory->hosts_entry, true);

        return $alias === false ? null : Inventory::sanitizeHostAlias((string) $alias);
    }

    /**
     * All hostnames already imported across dynamic inventories, flipped to a
     * lookup set for O(1) dedup checks.
     *
     * @return array<string, int>
     */
    protected function importedHostnames(): array
    {
        return Inventory::where('source_type', 'dynamic')
            ->whereNotNull('ip_list')
            ->pluck('ip_list')
            ->filter()
            ->flatMap(fn ($ips) => is_array($ips) ? $ips : (json_decode($ips, true) ?? []))
            ->filter()
            ->map(fn ($h) => preg_replace('/[\r\n\t]+/', '', $h))
            ->filter()
            ->flip()
            ->toArray();
    }
}
