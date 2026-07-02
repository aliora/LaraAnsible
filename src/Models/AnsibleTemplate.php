<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AnsibleTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'content',
        'kind',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function taskTemplates(): BelongsToMany
    {
        return $this->belongsToMany(TaskTemplate::class);
    }
}
