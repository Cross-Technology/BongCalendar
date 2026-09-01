<?php

namespace App\Support;

use App\Models\Tenant;
use RuntimeException;

/**
 * Holds the tenant for the current request. Registered as a singleton so
 * controllers, Livewire components and policies all read the same value.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    /** @throws RuntimeException when no tenant has been resolved. */
    public function require(): Tenant
    {
        return $this->tenant ?? throw new RuntimeException('No tenant resolved for the current request.');
    }
}
