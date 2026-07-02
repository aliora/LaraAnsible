<?php

namespace VisioSoft\LaraAnsible\Filament\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * Gates every LaraAnsible page/resource behind the configured `access_gate`
 * ability — the package's trust boundary (playbooks are remote code execution).
 * Opt-in: enforced only when the host app has defined the ability, so existing
 * installs stay open until they wire it up.
 */
trait AuthorizesAnsibleAccess
{
    public static function canAccess(): bool
    {
        $ability = config('laraansible.access_gate');

        if (blank($ability)) {
            return true;
        }

        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if (Gate::has($ability)) {
            return Gate::forUser($user)->allows($ability);
        }

        return true;
    }
}
