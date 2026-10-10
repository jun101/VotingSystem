<?php

namespace App\Support\Imports;

use App\Models\VoterImport;

/**
 * Which column of a voter file feeds which voter field: the proposal made from the header
 * names (French or English, case, accents and spaces ignored), and the check of a mapping
 * a user sends back.
 */
final class ColumnMapper
{
    /** Header names (as `Fold::header` writes them) that stand for each voter field. */
    private const SYNONYMS = [
        'full_name' => ['nomcomplet', 'fullname', 'nom', 'name', 'nomprenom', 'nomsetprenoms', 'nomsprenoms', 'eleve', 'electeur', 'voter'],
        'group' => ['groupe', 'group', 'classe', 'class', 'section'],
        'identifier' => ['identifiant', 'identifier', 'matricule', 'id', 'identite', 'identity'],
        'email' => ['courriel', 'email', 'mail', 'courrielelectronique', 'adressecourriel'],
        'phone' => ['telephone', 'phone', 'tel', 'mobile', 'numerodetelephone'],
    ];

    /**
     * The proposed mapping: each field takes the first column whose header names it; a column
     * is used once. A field with no such column is null.
     *
     * @param  list<string>  $columns  the header cells
     * @return array<string, int|null>
     */
    public function propose(array $columns): array
    {
        $names = array_map(Fold::header(...), $columns);
        $taken = [];
        $mapping = [];

        foreach (VoterImport::FIELDS as $field) {
            $mapping[$field] = null;

            foreach ($names as $index => $name) {
                if (isset($taken[$index]) || ! in_array($name, self::SYNONYMS[$field], true)) {
                    continue;
                }

                $mapping[$field] = $index;
                $taken[$index] = true;

                break;
            }
        }

        return $mapping;
    }

    /**
     * Whether a whole mapping is acceptable for a file of this many columns: the five fields,
     * an index within the columns or null, the name never null, no column twice.
     *
     * @param  array<array-key, mixed>  $mapping
     */
    public function isValid(array $mapping, int $columnCount): bool
    {
        $keys = array_keys($mapping);
        sort($keys);
        $fields = VoterImport::FIELDS;
        sort($fields);

        if ($keys !== $fields) {
            return false;
        }

        $used = [];

        foreach ($mapping as $field => $index) {
            if ($index === null) {
                if ($field === 'full_name') {
                    return false;
                }

                continue;
            }

            if (! is_int($index) || $index < 0 || $index >= $columnCount || isset($used[$index])) {
                return false;
            }

            $used[$index] = true;
        }

        return true;
    }
}
