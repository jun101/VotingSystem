<?php

/*
 * PUT /api/v1/ballots/{ballot}/result-note — docs/api/results/PUT-ballots-{ballot}-result-note.md
 * Tenant suite, route api/v1/ballots/{ballot}/result-note: another institution's ballot answers 404, the same as an
 * unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const NOTE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function noteUrl(string $uuid): string
{
    return "/api/v1/ballots/{$uuid}/result-note";
}

/** An owner of A signed in and a ballot of a closed election. Returns [team, election, ballot]. */
function noteSignedIn($test, string $status = 'closed'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => 'Président(e)']);
    Accounts::plantCandidate(['ballot' => $ballot]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $ballot];
}

it('sets the note and shows it in the results [FR-RES-03] (scenario 1)', function () {
    [$t, $election, $ballot] = noteSignedIn($this);

    $response = $this->browser->put(noteUrl($ballot), ['note' => 'Un second tour est possible.'])->assertOk();

    expect($response->json('data'))->toBe(['id' => $ballot, 'note' => 'Un second tour est possible.']);
    $this->browser->get("/api/v1/elections/{$election}/results")->assertOk()->assertJsonPath('data.ballots.0.note', 'Un second tour est possible.');
});

it('trims the note and removes control characters but keeps line breaks [FR-RES-03] (scenario 1)', function () {
    [$t, $election, $ballot] = noteSignedIn($this);

    $response = $this->browser->put(noteUrl($ballot), ['note' => "  Ligne un\nLigne deux\x07\x00 fin  "])->assertOk();

    expect($response->json('data.note'))->toBe("Ligne un\nLigne deux fin");
});

it('removes C1 controls, bidirectional controls and zero-width characters, and turns a lone carriage return into a line break [FR-RES-03] (scenario 1)', function () {
    [$t, $election, $ballot] = noteSignedIn($this);

    $response = $this->browser->put(noteUrl($ballot), ['note' => "Gagnant : \u{202E}eirreip\u{202C}\u{200B} \u{0085}fin\rsuite\u{2028}ok\u{FEFF}"])->assertOk();

    expect($response->json('data.note'))->toBe("Gagnant : eirreip fin\nsuiteok");
});

it('clears the note with null or an empty string [FR-RES-03] (scenario 2)', function (mixed $value) {
    [$t, $election, $ballot] = noteSignedIn($this);
    $this->browser->put(noteUrl($ballot), ['note' => 'À effacer'])->assertOk();

    $this->browser->put(noteUrl($ballot), ['note' => $value])->assertOk()->assertJsonPath('data.note', null);

    $this->browser->get("/api/v1/elections/{$election}/results")->assertOk()->assertJsonPath('data.ballots.0.note', null);
})->with(['null' => [null], 'empty' => [''], 'spaces' => ['   ']]);

it('accepts 1000 characters and refuses more, or a value that is not a string [FR-RES-03] (scenario 3)', function () {
    [$t, $election, $ballot] = noteSignedIn($this);

    $this->browser->put(noteUrl($ballot), ['note' => str_repeat('é', 1000)])->assertOk();
    $tooLong = $this->browser->put(noteUrl($ballot), ['note' => str_repeat('é', 1001)])->assertStatus(422);
    $notString = $this->browser->put(noteUrl($ballot), ['note' => ['x']])->assertStatus(422);

    expect($tooLong->json('error.fields.note'))->toContain('max')
        ->and($notString->json('error.fields.note'))->toContain('invalid');
});

it('works for a published or archived election and refuses a draft, scheduled or open one [FR-RES-03] (scenarios 1, 4)', function (string $status, int $expected) {
    [$t, $election, $ballot] = noteSignedIn($this, $status);

    $response = $this->browser->put(noteUrl($ballot), ['note' => 'Note'])->assertStatus($expected);

    if ($expected === 409) {
        expect($response->json('error.code'))->toBe('results_not_available');
        expect(Accounts::ballotRow($ballot)['result_note'] ?? null)->toBeNull();
    }
})->with([
    'closed' => ['closed', 200],
    'published' => ['published', 200],
    'archived' => ['archived', 200],
    'draft' => ['draft', 409],
    'scheduled' => ['scheduled', 409],
    'open' => ['open', 409],
]);

it('answers 404, the same for every case, for a ballot that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 5)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution'], 'status' => 'closed']);
    $foreign = Accounts::plantBallot(['election' => $foreignElection]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->put(noteUrl(NOTE_UNKNOWN), ['note' => 'x']));
    expect($unknown['status'])->toBe(404)->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->put(noteUrl($target), ['note' => 'x'])))->toBe($unknown);
    }
});

it('answers 401, 403, 419, 405 and 400 as every write, and lets a manager write [NFR-SEC-04] (scenario 6)', function () {
    [$t, $election, $ballot] = noteSignedIn($this);
    $this->browser->forgetCookies();

    $this->browser->put(noteUrl($ballot), ['note' => 'x'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['manager']);
    $this->browser->put(noteUrl($ballot), ['note' => 'Par le gestionnaire'])->assertOk();
    $this->browser->put(noteUrl($ballot), ['note' => 'x'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', noteUrl($ballot))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->put(noteUrl($ballot), ['note' => 'x'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 429 above 60 requests an hour from one user [NFR-SEC-05] (scenario 6)', function () {
    [$t, $election, $ballot] = noteSignedIn($this);
    foreach (range(1, 60) as $i) {
        $this->browser->put(noteUrl($ballot), ['note' => ['x']])->assertStatus(422);
    }

    $this->browser->put(noteUrl($ballot), ['note' => 'ok'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
