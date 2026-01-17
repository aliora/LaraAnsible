<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Database\Eloquent\Model;

class AnsibleTemplate extends Model
{
    protected $fillable = [
        'name',
        'filename',
        'content',
        'description',
    ];

    public function taskTemplates(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(TaskTemplate::class);
    }
}
