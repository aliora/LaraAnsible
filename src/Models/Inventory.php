<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Parking\Models\Park;

class Inventory extends Model
{
    protected $fillable = [
        'name',
        'description',
        'hostname',
        'port',
        'username',
        'keystore_id',
        'variables',
        'source_type',
        'dynamic_child_id',
        'dynamic_child_ids',
        'ip_list',
        'name_list',
        'script',
        'park_id',
        'is_active',
        'hosts_entry',
    ];

    protected $casts = [
        'variables' => 'array',
        'dynamic_child_ids' => 'array',
        'ip_list' => 'array',
        'name_list' => 'array',
        'port' => 'integer',
    ];

    /**
     * Virtual attribute for dynamic inventory version tracking.
     */
    public ?string $current_version = null;

    protected $appends = ['hosts_entry'];

    public function getHostsEntryAttribute(): array
    {
        $names = $this->name_list ?? [];
        $ips = $this->ip_list ?? [];

        // If ip_list is stored as JSON string, decode it
        if (is_string($ips)) {
            $ips = json_decode($ips, true) ?? [];
        }

        // If names and ips are both arrays with same count, combine them
        if (is_array($names) && is_array($ips)) {
            if (is_string($names)) {
                $names = json_decode($names, true) ?? [];
            }

            if (! empty($names) && ! empty($ips) && count($names) === count($ips)) {
                return array_combine($names, $ips);
            }
        }

        // If ip_list is already an associative array with keys (from newer format)
        if (is_array($ips) && ! empty($ips)) {
            // Check if it's already a key => value map
            $firstKey = array_key_first($ips);
            if ($firstKey !== 0 && is_string($firstKey)) {
                // It's already a proper map
                return $ips;
            }
        }

        // Fallback: if lists are empty but hostname exists, show it as a single entry
        if ($this->hostname) {
            return [$this->name => $this->hostname];
        }

        return [];
    }

    public function setHostsEntryAttribute($value): void
    {
        $hosts = self::normalizeHostsEntry($value);

        if (! empty($hosts)) {
            $this->attributes['name_list'] = json_encode(array_keys($hosts));
            $this->attributes['ip_list'] = json_encode($hosts);

            // For backward compatibility and single-host logic elsewhere,
            // set 'hostname' to the FIRST IP in the list.
            $firstIp = reset($hosts);
            $this->attributes['hostname'] = $firstIp;
        } else {
            // Cleared
            $this->attributes['name_list'] = null;
            $this->attributes['ip_list'] = null;
            $this->attributes['hostname'] = null;
        }
    }

    /**
     * Normalize hosts entry state into a simple host => ip map.
     *
     * @return array<string, string>
     */
    public static function normalizeHostsEntry(mixed $value): array
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (! is_array($value) || $value === []) {
            return [];
        }

        $firstKey = array_key_first($value);
        $first = $firstKey !== null ? $value[$firstKey] : null;

        if (is_array($first) && (array_key_exists('key', $first) || array_key_exists('value', $first))) {
            $hosts = [];
            foreach ($value as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $key = trim((string) ($entry['key'] ?? ''));
                $val = preg_replace('/[\r\n\t]+/', '', trim((string) ($entry['value'] ?? '')));

                if ($key === '' || $val === '') {
                    continue;
                }

                $hosts[$key] = $val;
            }

            return $hosts;
        }

        $hosts = [];
        foreach ($value as $key => $val) {
            if (is_array($val)) {
                $keyCandidate = trim((string) ($val['key'] ?? ''));
                $valCandidate = preg_replace('/[\r\n\t]+/', '', trim((string) ($val['value'] ?? '')));

                if ($keyCandidate === '' || $valCandidate === '') {
                    continue;
                }

                $hosts[$keyCandidate] = $valCandidate;

                continue;
            }

            $key = trim((string) $key);
            $val = preg_replace('/[\r\n\t]+/', '', trim((string) $val));

            if ($key === '' || $val === '') {
                continue;
            }

            $hosts[$key] = $val;
        }

        return $hosts;
    }

    /**
     * Turkish-aware ASCII transliteration (ü→u, ö→o, ç→c, ş→s, ğ→g, ı→i).
     */
    public static function transliterate(?string $value): string
    {
        return Str::ascii((string) $value, 'tr');
    }

    /**
     * Transliterate then reduce to a clean Ansible group token (single underscores).
     */
    public static function ansibleGroupName(?string $value): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_]+/', '_', self::transliterate($value));

        return trim((string) $slug, '_') ?: 'hosts';
    }

    /**
     * Build inventory script content for host entries and connection details.
     */
    public static function buildInventoryScript(
        array $hosts,
        ?string $groupName,
        ?string $sshUser,
        ?string $sshKeyPath = null,
        ?int $sshPort = null
    ): string {
        $groupName = $groupName ?: 'hosts';
        $sanitizedGroupName = self::ansibleGroupName($groupName);

        $lines = [];
        $lines[] = "[{$sanitizedGroupName}]";
        $lines[] = '';

        foreach ($hosts as $name => $ip) {
            // Keep the original hostname but replace only Ansible-incompatible special chars
            // Allow spaces, letters, numbers, dots, hyphens, underscores
            $alias = preg_replace('/[^a-zA-Z0-9_\.\- ]/', '_', self::transliterate(trim((string) $name)));
            $hostIp = trim((string) $ip);

            if ($alias === '' || $hostIp === '') {
                continue;
            }

            $lines[] = "{$alias} ansible_host={$hostIp}";
        }

        $lines[] = '';
        $lines[] = "[{$sanitizedGroupName}:vars]";

        $sshUser = trim((string) $sshUser);
        if ($sshUser !== '') {
            $lines[] = "ansible_user={$sshUser}";
        }

        if (! empty($sshPort)) {
            $lines[] = "ansible_port={$sshPort}";
        }

        $sshKeyPath = trim((string) $sshKeyPath);
        if ($sshKeyPath !== '') {
            $lines[] = "ansible_ssh_private_key_file={$sshKeyPath}";
        }

        return trim(implode("\n", $lines));
    }

    public static function buildInventoryScriptFromData(array $data): ?string
    {
        $hosts = self::normalizeHostsEntry($data['hosts_entry'] ?? []);

        if (empty($hosts) && ! empty($data['hostname'])) {
            $fallbackName = trim((string) ($data['name'] ?? $data['hostname']));
            $hosts = [$fallbackName => $data['hostname']];
        }

        if (empty($hosts)) {
            return null;
        }

        $sshKeyPath = null;
        if (! empty($data['keystore_id'])) {
            $sshKeyPath = self::ensureKeystorePath((int) $data['keystore_id']);
        }

        if ($sshKeyPath === null) {
            $defaultKeyPath = trim((string) (AnsibleSetting::getInstance()->ssh_private_key_path ?? ''));
            if ($defaultKeyPath !== '') {
                $sshKeyPath = $defaultKeyPath;
            }
        }

        return self::buildInventoryScript(
            $hosts,
            $data['name'] ?? null,
            $data['username'] ?? null,
            $sshKeyPath,
            $data['port'] ?? null
        );
    }

    protected static function ensureKeystorePath(int $keystoreId): ?string
    {
        $keystore = Keystore::find($keystoreId);
        if (! $keystore || empty($keystore->private_key)) {
            return null;
        }

        $keyPath = storage_path('app/ansible/keys/key_'.$keystore->id);
        $dir = dirname($keyPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($keyPath, $keystore->private_key);
        chmod($keyPath, 0600);

        return $keyPath;
    }

    public function keystore(): BelongsTo
    {
        return $this->belongsTo(Keystore::class);
    }

    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }

    public static function parseInventoryScript(?string $script): array
    {
        $result = [
            'name' => null, // Parent Name / Group Name
            'hosts' => [],
        ];

        if (! $script) {
            return $result;
        }

        $lines = explode("\n", $script);
        $currentSection = 'hosts'; // default is hosts until we see a header

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, ';') || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^\[(.*?)\]$/', $line, $matches)) {
                $header = $matches[1];
                if (str_contains($header, ':vars')) {
                    $currentSection = 'vars';
                } elseif (str_contains($header, ':children')) {
                    $currentSection = 'children';
                } else {
                    $currentSection = 'hosts';
                    // If we haven't found a main name yet, use this one
                    if (! $result['name']) {
                        $result['name'] = $header;
                    }
                }

                continue;
            }

            if ($currentSection === 'hosts') {
                // Parse host line
                // Format: alias ansible_host=IP ... OR just IP/Hostname
                $parts = preg_split('/\s+/', $line);
                $alias = array_shift($parts);

                // If alias contains '=', it might be a var line and not a host line (shouldn't happen in [hosts] ideally but possible)
                if (str_contains($alias, '=')) {
                    continue;
                }

                $ip = null;
                foreach ($parts as $part) {
                    if (str_starts_with($part, 'ansible_host=')) {
                        $ip = substr($part, 13);
                        break;
                    }
                }

                if (! $ip) {
                    // Fallback: use alias as IP/Hostname
                    $ip = $alias;
                }

                $result['hosts'][$alias] = $ip;
            }
        }

        return $result;
    }
}
