<?php

namespace Tests\Support\Tenancy;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant record that exists only in the tests (table `tenant_probes`): it stands in for
 * elections, voters and the rest until slice 05 brings real ones.
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
class TenantProbe extends Model
{
    use BelongsToInstitution;
    use HasUuid;

    protected $table = 'tenant_probes';

    /** @var list<string> */
    protected $fillable = ['title'];

    /** @return HasMany<TenantProbeNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(TenantProbeNote::class, 'tenant_probe_id');
    }
}
