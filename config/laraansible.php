<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LaraAnsible Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for the LaraAnsible plugin.
    |
    */

    'navigation_group' => 'Ansible Management',

    /*
    |--------------------------------------------------------------------------
    | Playbook Directory
    |--------------------------------------------------------------------------
    |
    | The directory where Ansible playbook files are stored.
    |
    */
    'playbook_directory' => env('LARA_ANSIBLE_PLAYBOOK_DIR', base_path('ansible')),

    /*
    |--------------------------------------------------------------------------
    | Inventory Directory
    |--------------------------------------------------------------------------
    |
    | The directory where Ansible inventory files are stored.
    |
    */
    'inventory_directory' => env('LARA_ANSIBLE_INVENTORY_DIR', base_path('ansible')),
];
