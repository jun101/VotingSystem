<?php

/*
 * Slice 05 review: what happens when the election is gone, or changed, between the binding of the
 * route and the locked read; and the dates the API accepts (whole seconds, years 1000 to 9999).
 */

use App\Models\Election;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Images;
use Tests\Support\Team;

beforeEach(function () {
    Accounts::reset();
    Team::clearMedia();
    $this->browser = new AuthClient($this);
});

afterEach(fn () => Event::forget('eloquent.retrieved: '.Election::class));

/** Runs $change once, right after the route has loaded the election (the first read). */
function afterBinding(Closure $change): void
{
    $done = false;

    Event::listen('eloquent.retrieved: '.Election::class, function () use (&$done, $change) {
        if (! $done) {
            $done = true;
            $change();
        }
    });
}

function raceDraft($test, array $options = []): string
{
    $t = Team::two();
    $id = Accounts::plantElection($options + ['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return $id;
}

function dropRow(string $id): Closure
{
    return fn () => DB::connection(useMigratorConnection())->table('elections')->where('uuid', $id)->delete();
}

it('answers 404 and leaves no file when the election is deleted while a cover is uploaded', function () {
    $id = raceDraft($this);
    afterBinding(dropRow($id));

    $this->browser->upload('PUT', "/api/v1/elections/{$id}/cover", ['file' => Images::upload(Images::png(1200, 600))])
        ->assertStatus(404)->assertJsonPath('error.code', 'not_found');

    expect(Team::mediaFiles())->toBe([]);
});

it('answers 404 when the election is deleted while its cover is removed', function () {
    $id = raceDraft($this);
    afterBinding(dropRow($id));

    $this->browser->delete("/api/v1/elections/{$id}/cover")->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('answers 409 when the election leaves draft while its cover is removed, and keeps the files', function () {
    $id = raceDraft($this);
    $this->browser->upload('PUT', "/api/v1/elections/{$id}/cover", ['file' => Images::upload(Images::png(1200, 600))])->assertOk();
    $files = Team::mediaFiles();
    afterBinding(fn () => DB::connection(useMigratorConnection())->table('elections')->where('uuid', $id)->update(['status' => 'scheduled']));

    $this->browser->delete("/api/v1/elections/{$id}/cover")->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');

    expect(Team::mediaFiles())->toBe($files);
});

it('answers 404, not 204, when the election is deleted while it is being deleted', function () {
    $id = raceDraft($this);
    afterBinding(dropRow($id));

    $this->browser->delete("/api/v1/elections/{$id}")->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('answers 404 when the election is deleted while it is being changed', function () {
    $id = raceDraft($this);
    afterBinding(dropRow($id));

    $this->browser->patch("/api/v1/elections/{$id}", ['title' => 'Après'])->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('answers 409 when the election leaves draft while it is being changed', function () {
    $id = raceDraft($this);
    afterBinding(fn () => DB::connection(useMigratorConnection())->table('elections')->where('uuid', $id)->update(['status' => 'scheduled']));

    $this->browser->patch("/api/v1/elections/{$id}", ['title' => 'Après'])->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');

    expect(Accounts::electionRow($id)['title'])->not->toBe('Après');
});

it('answers 422 after_start, not 500, when the stored dates moved while only one date is changed', function () {
    $id = raceDraft($this);
    // Between the validation and the lock the stored start moved to after the new end.
    afterBinding(fn () => DB::connection(useMigratorConnection())->table('elections')->where('uuid', $id)->update(['starts_at' => '2026-10-20 00:00:00', 'ends_at' => '2026-10-21 00:00:00']));

    $response = $this->browser->patch("/api/v1/elections/{$id}", ['ends_at' => '2026-10-16T19:00:00Z'])->assertStatus(422);

    expect($response->json('error.fields.ends_at'))->toContain('after_start')
        ->and(Accounts::electionRow($id)['ends_at'])->toBe('2026-10-21 00:00:00');
});

it('refuses on POST an end less than a second after the start (fraction dropped)', function (string $start, string $end) {
    raceDraft($this);

    $response = $this->browser->post('/api/v1/elections', ['title' => 'T', 'starts_at' => $start, 'ends_at' => $end])->assertStatus(422);

    expect($response->json('error.fields.ends_at'))->toContain('after_start');
})->with([
    ['2026-10-12T12:00:00.100Z', '2026-10-12T12:00:00.900Z'],
    ['2026-10-12T12:00:00.000Z', '2026-10-12T12:00:00.999Z'],
]);

it('refuses on PATCH an end less than a second after the start (fraction dropped)', function () {
    $id = raceDraft($this);

    $response = $this->browser->patch("/api/v1/elections/{$id}", ['starts_at' => '2026-10-12T12:00:00.100Z', 'ends_at' => '2026-10-12T12:00:00.900Z'])->assertStatus(422);

    expect($response->json('error.fields.ends_at'))->toContain('after_start');
});

it('stores whole seconds when a fraction is sent', function () {
    raceDraft($this);

    $response = $this->browser->post('/api/v1/elections', ['title' => 'T', 'starts_at' => '2026-10-12T12:00:00.750Z', 'ends_at' => '2026-10-12T12:00:01.250Z'])->assertStatus(201);

    expect($response->json('data.starts_at'))->toBe('2026-10-12T12:00:00Z')
        ->and($response->json('data.ends_at'))->toBe('2026-10-12T12:00:01Z');
});

it('refuses a UTC year outside 1000 to 9999 with the date code, on POST and PATCH', function (string $field, string $value) {
    $id = raceDraft($this);
    $valid = ['starts_at' => '2026-10-12T12:00:00Z', 'ends_at' => '2026-10-16T19:00:00Z'];

    $post = $this->browser->post('/api/v1/elections', ['title' => 'T', ...$valid, $field => $value])->assertStatus(422);
    $patch = $this->browser->patch("/api/v1/elections/{$id}", [$field => $value])->assertStatus(422);

    expect($post->json("error.fields.{$field}"))->toBe(['date'])
        ->and($patch->json("error.fields.{$field}"))->toBe(['date']);
})->with([
    ['starts_at', '0999-12-31T23:59:59Z'],
    ['starts_at', '1000-01-01T00:00:00+01:00'],
    ['ends_at', '9999-12-31T23:59:59-01:00'],
    ['ends_at', '0000-01-01T00:00:00Z'],
]);

it('accepts the first and the last instants of the range', function () {
    $id = raceDraft($this);

    $this->browser->patch("/api/v1/elections/{$id}", ['starts_at' => '1000-01-01T00:00:00Z', 'ends_at' => '9999-12-31T23:59:59Z'])->assertOk();
});
