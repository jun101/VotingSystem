<?php

namespace App\Enums;

/** Where a voter import is (docs/design/database.md `voter_imports.status`). */
enum VoterImportStatus: string
{
    /** Queued or being checked. */
    case Uploaded = 'uploaded';
    case Checked = 'checked';
    case Importing = 'importing';
    case Done = 'done';
    case Failed = 'failed';
}
