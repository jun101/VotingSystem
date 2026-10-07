<?php

namespace App\Enums;

/** What a user may do (docs/design/database.md section 2.1). Stored as text, no database ENUM. */
enum Role: string
{
    case PlatformAdmin = 'platform_admin';
    case Owner = 'owner';
    case Manager = 'manager';
}
