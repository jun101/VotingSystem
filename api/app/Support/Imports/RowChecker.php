<?php

namespace App\Support\Imports;

use App\Enums\ImportRowStatus;
use App\Support\Voters\GroupByName;
use Illuminate\Support\Facades\Validator;

/**
 * Judges the rows of one voter file, in file order, with the rules of POST voters (trim and
 * blank done by the caller): lengths, the format of the email and of the phone, duplicates in the
 * file and in the election, and the 100 groups an election may hold.
 *
 * It holds the state of ONE check (what was seen so far): make one per file, never keep it.
 * The first row keeps an identifier or an email; the later ones are the duplicates. A row that
 * is invalid for another reason keeps nothing: it will not be imported.
 */
final class RowChecker
{
    /** @var array<string, true> */
    private array $takenIdentifiers = [];

    /** @var array<string, true> */
    private array $takenEmails = [];

    /** @var array<string, int> identifier key => line of the first row */
    private array $seenIdentifiers = [];

    /** @var array<string, int> */
    private array $seenEmails = [];

    /** @var array<string, true> group key => known */
    private array $groupKeys;

    /** @var array<string, string> key => first spelling, in file order */
    private array $newGroups = [];

    /**
     * @param  list<string|null>  $existingIdentifiers  those of the election's voters
     * @param  list<string|null>  $existingEmails
     * @param  list<string>  $existingGroupKeys  the `name_key` of the election's groups
     */
    public function __construct(array $existingIdentifiers, array $existingEmails, array $existingGroupKeys)
    {
        foreach ($existingIdentifiers as $identifier) {
            if ($identifier !== null) {
                $this->takenIdentifiers[Fold::key($identifier)] = true;
            }
        }

        foreach ($existingEmails as $email) {
            if ($email !== null) {
                $this->takenEmails[Fold::key($email)] = true;
            }
        }

        $this->groupKeys = array_fill_keys($existingGroupKeys, true);
    }

    /**
     * @param  array{full_name: string|null, group: string|null, identifier: string|null, email: string|null, phone: string|null}  $values  trimmed, blank as null
     * @return array{status: ImportRowStatus, reasons: list<string>, values: array{full_name: string|null, group: string|null, identifier: string|null, email: string|null, phone: string|null}}
     */
    public function check(int $line, array $values): array
    {
        $reasons = [];
        $duplicate = false;

        if ($values['email'] !== null) {
            $values['email'] = mb_strtolower($values['email']);
        }

        $this->length($values['full_name'], 150, 'full_name', $reasons);
        $this->length($values['group'], 100, 'group', $reasons);
        $this->length($values['identifier'], 50, 'identifier', $reasons);
        $this->length($values['email'], 255, 'email', $reasons);
        $this->length($values['phone'], 30, 'phone', $reasons);

        if ($values['full_name'] === null) {
            $reasons[] = 'full_name_required';
        }

        if ($values['email'] !== null && ! in_array('email_max', $reasons, true)
            && ! Validator::make(['email' => $values['email']], ['email' => 'email:strict,filter'])->passes()) {
            $reasons[] = 'email_invalid';
        }

        if ($values['phone'] !== null && ! in_array('phone_max', $reasons, true)
            && preg_match('/\A[0-9+\-(). ]*\z/', $values['phone']) !== 1) {
            $reasons[] = 'phone_invalid';
        }

        $identifierKey = $values['identifier'] !== null && ! in_array('identifier_max', $reasons, true) ? Fold::key($values['identifier']) : null;
        $emailKey = $values['email'] !== null && ! in_array('email_max', $reasons, true) && ! in_array('email_invalid', $reasons, true) ? Fold::key($values['email']) : null;

        if ($identifierKey !== null) {
            if (isset($this->seenIdentifiers[$identifierKey])) {
                $reasons[] = 'duplicate_identifier_in_file';
                $duplicate = true;
            } elseif (isset($this->takenIdentifiers[$identifierKey])) {
                $reasons[] = 'identifier_exists';
                $duplicate = true;
            }
        }

        if ($emailKey !== null) {
            if (isset($this->seenEmails[$emailKey])) {
                $reasons[] = 'duplicate_email_in_file';
                $duplicate = true;
            } elseif (isset($this->takenEmails[$emailKey])) {
                $reasons[] = 'email_exists';
                $duplicate = true;
            }
        }

        // A new group is created by the first valid row that names it, up to the limit.
        $newGroupKey = null;

        if ($reasons === [] && $values['group'] !== null) {
            $groupKey = mb_strtolower(trim($values['group']));

            if (! isset($this->groupKeys[$groupKey])) {
                if (count($this->groupKeys) >= GroupByName::LIMIT) {
                    $reasons[] = 'group_limit_reached';
                } else {
                    $newGroupKey = $groupKey;
                }
            }
        }

        if ($reasons === []) {
            // A valid row keeps its identifier and its email, and brings its group.
            if ($identifierKey !== null) {
                $this->seenIdentifiers[$identifierKey] = $line;
            }

            if ($emailKey !== null) {
                $this->seenEmails[$emailKey] = $line;
            }

            if ($newGroupKey !== null) {
                $this->groupKeys[$newGroupKey] = true;
                $this->newGroups[$newGroupKey] = $values['group'];
            }
        }

        $status = match (true) {
            $duplicate => ImportRowStatus::Duplicate,
            $reasons !== [] => ImportRowStatus::Invalid,
            default => ImportRowStatus::Ok,
        };

        return ['status' => $status, 'reasons' => $reasons, 'values' => $values];
    }

    /**
     * The names of the groups the valid rows would create, first spelling, in file order.
     *
     * @return list<string>
     */
    public function newGroups(): array
    {
        return array_values($this->newGroups);
    }

    /** @param  list<string>  $reasons */
    private function length(?string $value, int $max, string $field, array &$reasons): void
    {
        if ($value !== null && mb_strlen($value) > $max) {
            $reasons[] = $field.'_max';
        }
    }
}
