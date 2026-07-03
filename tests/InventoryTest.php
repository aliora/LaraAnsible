<?php

namespace VisioSoft\LaraAnsible\Tests;

use VisioSoft\LaraAnsible\Models\Inventory;

class InventoryTest extends TestCase
{
    public function test_normalize_hosts_entry_accepts_plain_map(): void
    {
        $hosts = Inventory::normalizeHostsEntry(['web1' => '10.0.0.1', 'web2' => '10.0.0.2']);

        $this->assertSame(['web1' => '10.0.0.1', 'web2' => '10.0.0.2'], $hosts);
    }

    public function test_normalize_hosts_entry_accepts_json_string(): void
    {
        $hosts = Inventory::normalizeHostsEntry('{"web1":"10.0.0.1"}');

        $this->assertSame(['web1' => '10.0.0.1'], $hosts);
    }

    public function test_normalize_hosts_entry_accepts_key_value_rows(): void
    {
        $hosts = Inventory::normalizeHostsEntry([
            ['key' => 'web1', 'value' => '10.0.0.1'],
            ['key' => 'web2', 'value' => '10.0.0.2'],
        ]);

        $this->assertSame(['web1' => '10.0.0.1', 'web2' => '10.0.0.2'], $hosts);
    }

    public function test_normalize_hosts_entry_strips_newlines_and_drops_blanks(): void
    {
        $hosts = Inventory::normalizeHostsEntry([
            'web1' => "10.0.0.1\r\n",
            'empty' => '   ',
            '' => '10.0.0.9',
        ]);

        $this->assertSame(['web1' => '10.0.0.1'], $hosts);
    }

    public function test_normalize_hosts_entry_returns_empty_for_invalid_input(): void
    {
        $this->assertSame([], Inventory::normalizeHostsEntry(null));
        $this->assertSame([], Inventory::normalizeHostsEntry('not json'));
        $this->assertSame([], Inventory::normalizeHostsEntry([]));
    }

    public function test_transliterate_handles_turkish_characters(): void
    {
        $this->assertSame('IstanbulGuneyOtoparkiCS', Inventory::transliterate('İstanbulGüneyOtoparkıÇŞ'));
    }

    public function test_ansible_group_name_produces_clean_token(): void
    {
        $this->assertSame('Sisli_Park_3', Inventory::ansibleGroupName('Şişli Park-3!'));
        $this->assertSame('hosts', Inventory::ansibleGroupName('   '));
        $this->assertSame('hosts', Inventory::ansibleGroupName(null));
    }

    public function test_sanitize_host_alias_removes_whitespace_and_transliterates(): void
    {
        $this->assertSame('3.Matbaacilar_-_756d', Inventory::sanitizeHostAlias('3.Matbaacilar - 756d'));
        $this->assertSame('Kapali_Otopark', Inventory::sanitizeHostAlias('Kapalı Otopark'));
        $this->assertSame('spaced_name', Inventory::sanitizeHostAlias('  spaced  name  '));
        $this->assertSame('web1.example.com', Inventory::sanitizeHostAlias('web1.example.com'));
    }

    public function test_build_inventory_script_keeps_spaced_label_parseable(): void
    {
        $hosts = ['3.Matbaacilar - 756dc23f' => '100.114.121.68'];

        $script = Inventory::buildInventoryScript($hosts, 'gate server', 'visioai');

        $this->assertStringContainsString('3.Matbaacilar_-_756dc23f ansible_host=100.114.121.68', $script);
        $this->assertStringNotContainsString('Matbaacilar - 756', $script);

        $parsed = Inventory::parseInventoryScript($script);

        $this->assertSame(['3.Matbaacilar_-_756dc23f' => '100.114.121.68'], $parsed['hosts']);
    }

    public function test_build_inventory_script_roundtrips_through_parse(): void
    {
        $hosts = ['pi5' => '100.88.196.89', 'pi5-3' => '100.89.209.23'];

        $script = Inventory::buildInventoryScript($hosts, 'gate server', 'root', '/keys/id_ed25519', 2222);

        $this->assertStringContainsString('[gate_server]', $script);
        $this->assertStringContainsString('ansible_user=root', $script);
        $this->assertStringContainsString('ansible_port=2222', $script);
        $this->assertStringContainsString('ansible_ssh_private_key_file=/keys/id_ed25519', $script);

        $parsed = Inventory::parseInventoryScript($script);

        $this->assertSame('gate_server', $parsed['name']);
        $this->assertSame($hosts, $parsed['hosts']);
    }

    public function test_build_inventory_script_skips_blank_entries_and_optional_vars(): void
    {
        $script = Inventory::buildInventoryScript(['web1' => '10.0.0.1', '' => '10.0.0.2'], null, null);

        $this->assertStringContainsString('[hosts]', $script);
        $this->assertStringContainsString('web1 ansible_host=10.0.0.1', $script);
        $this->assertStringNotContainsString('10.0.0.2', $script);
        $this->assertStringNotContainsString('ansible_user=', $script);
        $this->assertStringNotContainsString('ansible_port=', $script);
    }

    public function test_parse_inventory_script_ignores_comments_vars_and_children(): void
    {
        $script = <<<'INI'
        # comment
        [gate_server]
        pi5 ansible_host=100.88.196.89
        ; another comment
        [gate_server:vars]
        ansible_user=root
        [all:children]
        gate_server
        INI;

        $parsed = Inventory::parseInventoryScript($script);

        $this->assertSame('gate_server', $parsed['name']);
        $this->assertSame(['pi5' => '100.88.196.89'], $parsed['hosts']);
    }

    public function test_parse_inventory_script_falls_back_to_alias_as_ip(): void
    {
        $parsed = Inventory::parseInventoryScript("[hosts]\n10.0.0.5\n");

        $this->assertSame(['10.0.0.5' => '10.0.0.5'], $parsed['hosts']);
    }
}
