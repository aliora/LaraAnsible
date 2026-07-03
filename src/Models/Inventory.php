<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Inventory extends Model
{
    use SoftDeletes;

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

    protected $appends = ['hosts_entry'];

    public function getHostsEntryAttribute(): array
    {
        $names = $this->name_list ?? [];
        $ips = $this->ip_list ?? [];

        if (is_string($ips)) {
            $ips = json_decode($ips, true) ?? [];
        }

        if (is_array($names) && is_array($ips)) {
            if (is_string($names)) {
                $names = json_decode($names, true) ?? [];
            }

            if (! empty($names) && ! empty($ips) && count($names) === count($ips)) {
                return array_combine($names, $ips);
            }
        }

        if (is_array($ips) && ! empty($ips)) {
            $firstKey = array_key_first($ips);
            if ($firstKey !== 0 && is_string($firstKey)) {
                return $ips;
            }
        }

        if ($this->hostname) {
            return [$this->name => $this->hostname];
        }

        return [];
    }

    /**
     * Persists the map into name_list/ip_list and mirrors the first IP into
     * `hostname` for backward-compatible single-host logic.
     */
    public function setHostsEntryAttribute($value): void
    {
        $hosts = self::normalizeHostsEntry($value);

        if (! empty($hosts)) {
            $this->attributes['name_list'] = json_encode(array_keys($hosts));
            $this->attributes['ip_list'] = json_encode($hosts);
            $this->attributes['hostname'] = reset($hosts);
        } else {
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
     * Transliterate a host label into a whitespace-free Ansible inventory alias.
     * Ansible splits each INI host line on whitespace, so a space in the alias makes
     * the remainder be read as key=value host vars and the whole inventory fails to
     * parse. Dots and dashes are valid in host names and kept.
     */
    public static function sanitizeHostAlias(?string $value): string
    {
        $alias = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', self::transliterate(trim((string) $value)));

        return trim((string) $alias, '_');
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
            $alias = self::sanitizeHostAlias($name);
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
        return $this->belongsTo((string) config('laraansible.park_model'));
    }

    public function hostCount(): int
    {
        if (filled($this->script)) {
            preg_match_all('/^([a-zA-Z0-9_.-]+)\s+ansible_host=/m', (string) $this->script, $matches);

            return count($matches[1] ?? []);
        }

        return count($this->hosts_entry);
    }

    /**
     * @return array{name: ?string, hosts: array<string, string>}
     */
    public static function parseInventoryScript(?string $script): array
    {
        $result = [
            'name' => null,
            'hosts' => [],
        ];

        if (! $script) {
            return $result;
        }

        $lines = explode("\n", $script);
        $currentSection = 'hosts';

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
                    if (! $result['name']) {
                        $result['name'] = $header;
                    }
                }

                continue;
            }

            if ($currentSection === 'hosts') {
                $parts = preg_split('/\s+/', $line);
                $alias = array_shift($parts);

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

                $result['hosts'][$alias] = $ip ?: $alias;
            }
        }

        return $result;
    }
}
