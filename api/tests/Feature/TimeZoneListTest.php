<?php

/*
 * The profile form offers the time zones of web/src/lib/format/timeZones.ts; the API accepts
 * those of PHP (the `timezone` rule). The two lists must stay the same.
 */

it('offers in the web form exactly the identifiers PHP knows', function () {
    $source = file_get_contents(base_path('../web/src/lib/format/timeZones.ts'));

    expect($source)->not->toBeFalse();

    preg_match_all("/^\\s*'([A-Za-z0-9_+\\-\\/]+)',\\s*$/m", (string) $source, $matches);

    $web = $matches[1];
    $php = DateTimeZone::listIdentifiers();

    expect(array_values(array_diff($php, $web)))->toBe([], 'known to PHP but missing in the form')
        ->and(array_values(array_diff($web, $php)))->toBe([], 'in the form but unknown to PHP')
        ->and(count($web))->toBe(count(array_unique($web)));
});
