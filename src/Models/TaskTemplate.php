<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'playbook_path',
        'playbook_content',
        'extra_vars',
        'type',
        'is_active',
        'templates',
    ];

    protected $casts = [
        'extra_vars' => 'array',
        'is_active' => 'boolean',
        'templates' => 'array',
    ];

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
