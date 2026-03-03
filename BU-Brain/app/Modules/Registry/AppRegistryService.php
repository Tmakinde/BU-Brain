<?php

namespace App\Modules\Registry;

use App\Modules\Registry\Models\AppRegistry;
use Illuminate\Support\Collection;

class AppRegistryService
{
    /**
     * Get all registered apps.
     */
    public function all(): Collection
    {
        return AppRegistry::all();
    }

    /**
     * Find an app by name.
     */
    public function findByName(string $name): ?AppRegistry
    {
        return AppRegistry::where('name', $name)->first();
    }

    /**
     * Get apps that this app talks to.
     */
    public function getConnectedApps(string $appName): Collection
    {
        $app = $this->findByName($appName);
        
        if (!$app) {
            return collect();
        }

        return AppRegistry::whereIn('name', $app->talks_to)->get();
    }

    /**
     * Mark an app as indexed.
     */
    public function markAsIndexed(string $appName): void
    {
        AppRegistry::where('name', $appName)->update([
            'last_indexed_at' => now(),
        ]);
    }

    /**
     * Get all app names.
     */
    public function allNames(): array
    {
        return AppRegistry::pluck('name')->toArray();
    }
}
