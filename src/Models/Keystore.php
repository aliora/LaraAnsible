<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Keystore extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'type',
        'is_main',
        'private_key',
        'public_key',
        'passphrase',
        'password',
    ];

    protected $hidden = [
        'private_key',
        'passphrase',
        'password',
    ];

    protected $casts = [
        'is_main' => 'boolean',
        'private_key' => 'encrypted',
        'passphrase' => 'encrypted',
        'password' => 'encrypted',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $keystore) {
            if (! static::exists()) {
                $keystore->is_main = true;
            }
        });

        static::saving(function (self $keystore) {
            if ($keystore->is_main) {
                static::where('id', '!=', $keystore->id)->update(['is_main' => false]);
            }
        });
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }
}
