<?php

namespace VisioSoft\LaraAnsible\Database\Seeders;

use Illuminate\Database\Seeder;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Models\Keystore;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

class LaraAnsibleSeeder extends Seeder
{
    public function run(): void
    {
        // Demo data only — dummy SSH key + example hosts (localhost / 192.168.x).
        // Never seed this in production. AnsibleSetting is auto-created on first
        // access, so prod needs no seeding at all.
        if (! app()->environment('local', 'testing')) {
            return;
        }

        // 1. Create a Keystore (SSH Key)
        $keystore = Keystore::firstOrCreate([
            'name' => 'Default SSH Key',
        ], [
            'description' => 'Default SSH key for development servers',
            'type' => 'ssh',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
... (This is a dummy key for demo purposes) ...
-----END OPENSSH PRIVATE KEY-----',
            'public_key' => 'ssh-rsa AAAA... dummy-public-key',
        ]);

        // 2. Create Inventories (Servers)
        Inventory::firstOrCreate([
            'hostname' => 'localhost',
        ], [
            'name' => 'Local Server',
            'description' => 'Local development server',
            'port' => 22,
            'username' => 'polat',
            'keystore_id' => $keystore->id,
            'is_active' => true,
        ]);

        Inventory::firstOrCreate([
            'hostname' => '192.168.1.100',
        ], [
            'name' => 'Staging Server',
            'description' => 'Staging environment server',
            'port' => 22,
            'username' => 'deploy',
            'keystore_id' => $keystore->id,
            'is_active' => true,
        ]);

        // 3. Create Task Templates (Playbooks)
        TaskTemplate::firstOrCreate([
            'name' => 'Ping Check',
        ], [
            'description' => 'Check connectivity to servers',
            'playbook_content' => '---
- name: Ping Check
  hosts: all
  gather_facts: no
  tasks:
    - name: Ping
      ansible.builtin.ping:
',
            'is_active' => true,
        ]);

        TaskTemplate::firstOrCreate([
            'name' => 'System Update',
        ], [
            'description' => 'Update system packages (apt)',
            'playbook_content' => '---
- name: System Update
  hosts: all
  become: yes
  tasks:
    - name: Update apt cache
      ansible.builtin.apt:
        update_cache: yes
    
    - name: Upgrade packages
      ansible.builtin.apt:
        upgrade: dist
',
            'is_active' => true,
        ]);
    }
}
