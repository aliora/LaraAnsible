<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'script',
        'park_id',
        'is_active',
    ];

    protected $casts = [
        'variables' => 'array',
        'port' => 'integer',
    ];

    /**
     * Virtual attribute for dynamic inventory version tracking.
     */
    public ?string $current_version = null;

    public function keystore(): BelongsTo
    {
        return $this->belongsTo(Keystore::class);
    }

    public function park(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Park::class);
    }
}
