<?php

namespace App\Support\Credentials;

use Illuminate\Database\Eloquent\Model;

/** Reads an aggregate column of a grouped query (`count`, `sum`) as an int. */
final class RowCount
{
    public static function of(Model $row, string $column): int
    {
        $value = $row->getAttribute($column);

        return is_numeric($value) ? (int) $value : 0;
    }
}
