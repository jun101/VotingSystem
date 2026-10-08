<?php

/*
 * The scope cannot be removed quietly — docs/slices/03-admin-shell-and-tenant-isolation.md,
 * section 1 "Removing the scope" and rule 4. Reads the source of the application.
 */

/** @return list<string> every PHP file of the application, its routes and its database code */
function sourceFiles(): array
{
    $files = [];
    foreach (['app', 'routes', 'database', 'bootstrap', 'config'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

const TRAIT_FILE = 'app/Models/Concerns/BelongsToInstitution.php';

it('calls withoutInstitutionScope() only under a comment that says why [NFR-SEC-03] (rule 4)', function () {
    $calls = 0;
    $problems = [];

    foreach (sourceFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        if ($relative === TRAIT_FILE) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $number => $line) {
            if (! str_contains($line, 'withoutInstitutionScope(')) {
                continue;
            }
            $calls++;

            // The comment lines directly above the call (or on its own line) must hold words.
            $comment = '';
            if (preg_match('#//\s*(.+)$#', $line, $found)) {
                $comment .= ' '.$found[1];
            }
            for ($i = $number - 1; $i >= 0; $i--) {
                $text = trim($lines[$i]);
                if (! preg_match('#^(//|/\*+|\*+/?)\s*(.*)$#', $text, $found)) {
                    break;
                }
                $comment .= ' '.preg_replace('#\*/$#', '', $found[2]);
            }

            if (str_word_count($comment) < 3) {
                $problems[] = $relative.':'.($number + 1).' withoutInstitutionScope() has no comment saying why';
            }
        }
    }

    expect($calls)->toBeGreaterThan(0, 'The flows that run before sign-in must remove the scope by name')
        ->and($problems)->toBe([], implode("\n", $problems));
});

it('removes a global scope in no other way [NFR-SEC-03] (rule 4)', function () {
    $problems = [];

    foreach (sourceFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        if ($relative === TRAIT_FILE) {
            continue;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $number => $line) {
            if (preg_match('/withoutGlobalScopes?\s*\(|->newQueryWithoutScopes?\s*\(|::withoutGlobalScopes?\s*\(|newQueryWithoutScope/', $line)) {
                $problems[] = $relative.':'.($number + 1).' removes a global scope other than by withoutInstitutionScope()';
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('defines the trait, the method and the scope where the brief says [NFR-SEC-03] (section 1)', function () {
    expect(trait_exists(App\Models\Concerns\BelongsToInstitution::class))->toBeTrue()
        ->and(method_exists(App\Models\Concerns\BelongsToInstitution::class, 'withoutInstitutionScope'))->toBeTrue()
        ->and((new ReflectionMethod(App\Models\Concerns\BelongsToInstitution::class, 'withoutInstitutionScope'))->isStatic())->toBeTrue()
        ->and(class_uses_recursive(App\Models\User::class))->toContain(App\Models\Concerns\BelongsToInstitution::class)
        ->and(class_exists(App\Policies\TenantPolicy::class))->toBeTrue();
});
