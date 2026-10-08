<?php

namespace Tests\Support\Tenancy;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A child of a probe (table `tenant_probe_notes`), to test a route with a parent and a child.
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
class TenantProbeNote extends Model
{
    use BelongsToInstitution;
    use HasUuid;

    protected $table = 'tenant_probe_notes';

    /** @var list<string> */
    protected $fillable = ['body'];

    /** @return BelongsTo<TenantProbe, $this> */
    public function probe(): BelongsTo
    {
        return $this->belongsTo(TenantProbe::class, 'tenant_probe_id');
    }
}
