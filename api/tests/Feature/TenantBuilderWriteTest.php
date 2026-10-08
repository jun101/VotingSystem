<?php

/*
 * Builder-level writes (query()->update(), insert(), upsert(), DB::table()) skip the model
 * events, so they skip the rules of BelongsToInstitution. The application code must not name
 * `institution_id` in such a write; a record is written through its model
 * (docs/design/architecture.md section 4.4, "Limit of the model rules").
 */

/** @return list<string> */
function builderWriteSourceFiles(): array
{
    $files = [];
    foreach (['app', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

/** The text of the call whose opening parenthesis is at $open, parentheses balanced. */
function builderWriteCallText(string $source, int $open): string
{
    $depth = 0;
    $length = strlen($source);
    for ($i = $open; $i < $length; $i++) {
        if ($source[$i] === '(') {
            $depth++;
        } elseif ($source[$i] === ')' && --$depth === 0) {
            return substr($source, $open, $i - $open + 1);
        }
    }

    return substr($source, $open);
}

/** @return list<string> the places where a builder-level write names institution_id */
function builderWritesNamingInstitution(string $source, string $label): array
{
    $problems = [];
    $pattern = '/(?:->(?:update|insert|insertGetId|insertOrIgnore|insertUsing|upsert|updateOrInsert|increment|decrement)|DB::(?:connection\([^)]*\)->)?table)\s*\(/';

    if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as [$text, $offset]) {
            $call = builderWriteCallText($source, $offset + strlen($text) - 1);
            // For DB::table('x'), the write is the chain after it: look at the statement.
            if (str_contains($text, 'table')) {
                $end = strpos($source, ';', $offset);
                $call = substr($source, $offset, ($end === false ? strlen($source) : $end) - $offset);
            }
            if (str_contains($call, 'institution_id')) {
                $problems[] = $label.':'.(substr_count(substr($source, 0, $offset), "\n") + 1).' names institution_id in a builder-level write';
            }
        }
    }

    return $problems;
}

it('names institution_id in no builder-level write of the application [NFR-SEC-03] (rule 1)', function () {
    $problems = [];

    foreach (builderWriteSourceFiles() as $path) {
        $problems = array_merge($problems, builderWritesNamingInstitution(file_get_contents($path), str_replace(base_path().'/', '', $path)));
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('notices a builder-level write that names institution_id [NFR-SEC-03] (rule 1)', function () {
    $bad = [
        '<?php User::query()->where("a", 1)->update(["institution_id" => 2]);',
        '<?php Election::query()->insert([["title" => "x", "institution_id" => 2]]);',
        '<?php DB::table("elections")->where("uuid", $u)->update(["institution_id" => 2]);',
        '<?php Election::query()->upsert([["institution_id" => 2]], ["uuid"]);',
    ];
    $fine = '<?php Election::query()->where("uuid", $u)->update(["title" => "x"]); DB::table("jobs")->insert(["queue" => "a"]);';

    foreach ($bad as $source) {
        expect(builderWritesNamingInstitution($source, 'x'))->not->toBe([], $source);
    }
    expect(builderWritesNamingInstitution($fine, 'x'))->toBe([]);
});
