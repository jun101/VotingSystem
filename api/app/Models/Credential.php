<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The current access code of a voter, as its HMAC only (docs/design/database.md section 2.3).
 * No uuid and no timestamps: it is never named by a URL and never output by the API.
 *
 * @property int $id
 * @property int $election_id
 * @property int $voter_id
 * @property string $code_hash
 * @property Carbon $issued_at
 * @property int|null $issued_by_user_id
 * @property int $reissue_count
 * @property string|null $email_status
 * @property Carbon|null $email_sent_at
 * @property string|null $email_error
 * @property string|null $email_hash
 * @property int|null $email_file_id
 */
class Credential extends Model
{
    use BelongsToInstitution;

    public const EMAIL_QUEUED = 'queued';

    public const EMAIL_SENT = 'sent';

    public const EMAIL_FAILED = 'failed';

    /** The one reason code a message that could not be sent carries: never the transport's text. */
    public const ERROR_SEND_FAILED = 'send_failed';

    public $timestamps = false;

    // Nothing is mass-assigned: the writer sets each attribute itself.
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'email_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Voter, $this> */
    public function voter(): BelongsTo
    {
        return $this->belongsTo(Voter::class);
    }
}
