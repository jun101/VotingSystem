<?php

/*
 * Tenant isolation, behaviour — docs/slices/03-admin-shell-and-tenant-isolation.md, part 3
 * and "Rules checked by tests" 1 and 2. Runs on the test-only probe routes
 * (Tests\Support\Tenancy::registerProbeRoutes), bound the way every later route is.
 */

use Tests\Support\Tenancy;

beforeEach(function () {
    Tenancy::registerProbeRoutes();
    $this->t = Tenancy::twoInstitutions();
    $this->unknown = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';
});

function signIn($test, array $account): void
{
    $test->browser->login($account['email'], $account['password'])->assertOk();
}

/** The parts of an answer that must be identical for "not yours" and "does not exist". */
function shape(Illuminate\Testing\TestResponse $response): array
{
    $headers = $response->headers->allPreserveCase();
    foreach (['X-Request-Id', 'Date', 'Set-Cookie', 'Content-Length', 'Age'] as $volatile) {
        unset($headers[$volatile], $headers[strtolower($volatile)]);
    }
    ksort($headers);

    return ['status' => $response->getStatusCode(), 'body' => $response->getContent(), 'headers' => $headers];
}

it('lists only the rows of the caller\'s institution [NFR-SEC-03] (rule 1)', function () {
    signIn($this, $this->t['a']['owner']);

    $response = $this->browser->get('/api/v1/probes')->assertOk();

    expect(array_column($response->json('data'), 'id'))->toEqualCanonicalizing([$this->t['a']['probe']['uuid'], $this->t['a']['second']['uuid']])
        ->and($response->json('meta.total'))->toBe(2);
});

it('gives a manager the same view as the owner of their institution [FR-INST-05] (rule 1)', function () {
    signIn($this, $this->t['b']['manager']);

    $response = $this->browser->get('/api/v1/probes')->assertOk();

    expect(array_column($response->json('data'), 'id'))->toEqualCanonicalizing([$this->t['b']['probe']['uuid'], $this->t['b']['second']['uuid']])
        ->and($response->json('meta.total'))->toBe(2);
});

it('shows a record of the caller\'s institution [NFR-SEC-03] (rule 1)', function () {
    signIn($this, $this->t['a']['owner']);

    $this->browser->get('/api/v1/probes/'.$this->t['a']['probe']['uuid'])
        ->assertOk()->assertJsonPath('data.id', $this->t['a']['probe']['uuid']);
});

it('answers 404 to show, update and delete of another institution\'s record, as for an unknown one [NFR-SEC-03] (rule 2)', function () {
    signIn($this, $this->t['b']['owner']);
    $foreign = $this->t['a']['probe']['uuid'];

    $unknownShow = shape($this->browser->get('/api/v1/probes/'.$this->unknown));
    $unknownPatch = shape($this->browser->patch('/api/v1/probes/'.$this->unknown, ['title' => 'x']));
    $unknownDelete = shape($this->browser->change('DELETE', '/api/v1/probes/'.$this->unknown));

    $foreignShow = $this->browser->get('/api/v1/probes/'.$foreign);
    $foreignPatch = $this->browser->patch('/api/v1/probes/'.$foreign, ['title' => 'taken over']);
    $foreignDelete = $this->browser->change('DELETE', '/api/v1/probes/'.$foreign);

    $foreignShow->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    expect(shape($foreignShow))->toBe($unknownShow)
        ->and(shape($foreignPatch))->toBe($unknownPatch)
        ->and(shape($foreignDelete))->toBe($unknownDelete)
        ->and($unknownShow['status'])->toBe(404);

    // And nothing changed.
    expect(Tenancy::probeCount())->toBe(4);
    signIn($this, $this->t['a']['owner']);
    $this->browser->get('/api/v1/probes/'.$foreign)->assertOk()->assertJsonPath('data.title', 'Probe a1');
});

it('answers 404 when the identifier is not a uuid at all [NFR-SEC-08] (rule 2)', function (string $value) {
    signIn($this, $this->t['a']['owner']);

    $unknown = shape($this->browser->get('/api/v1/probes/'.$this->unknown));
    $response = $this->browser->get('/api/v1/probes/'.rawurlencode($value));

    expect(shape($response))->toBe($unknown);
})->with([['1'], ['0'], ['-1'], ['abc'], ["' OR 1=1 --"]]);

it('fills the caller\'s institution on create and ignores a body that names another [NFR-SEC-03] (rule 1)', function () {
    signIn($this, $this->t['a']['owner']);

    $response = $this->browser->post('/api/v1/probes', [
        'title' => 'New one',
        'institution_id' => Tenancy::institutionKey($this->t['b']['institution']),
        'institution' => $this->t['b']['institution'],
    ])->assertCreated();

    $uuid = $response->json('data.id');
    expect(Tenancy::probeInstitutionKey($uuid))->toBe(Tenancy::institutionKey($this->t['a']['institution']));

    $other = new Tests\Support\AuthClient($this);
    $other->login($this->t['b']['owner']['email'], $this->t['b']['owner']['password'])->assertOk();
    $other->get('/api/v1/probes/'.$uuid)->assertStatus(404);
});

it('updates and deletes a record of the caller\'s institution [NFR-SEC-03] (rule 1)', function () {
    signIn($this, $this->t['a']['owner']);
    $uuid = $this->t['a']['second']['uuid'];

    $this->browser->patch('/api/v1/probes/'.$uuid, ['title' => 'Renamed'])->assertOk()->assertJsonPath('data.title', 'Renamed');
    $this->browser->change('DELETE', '/api/v1/probes/'.$uuid)->assertNoContent();
    $this->browser->get('/api/v1/probes/'.$uuid)->assertStatus(404);
});

it('reaches a child through its own parent only [NFR-SEC-03] (rule 2)', function () {
    $a = $this->t['a'];
    $b = $this->t['b'];

    // A second probe in A, to have a parent that is the caller's but is not the note's.
    signIn($this, $a['owner']);
    $this->browser->get("/api/v1/probes/{$a['probe']['uuid']}/notes/{$a['note']['uuid']}")
        ->assertOk()->assertJsonPath('data.id', $a['note']['uuid'])->assertJsonPath('data.probe', $a['probe']['uuid']);

    $unknown = shape($this->browser->get("/api/v1/probes/{$a['probe']['uuid']}/notes/{$this->unknown}"));

    // The right note under the wrong parent of the same institution.
    expect(shape($this->browser->get("/api/v1/probes/{$a['second']['uuid']}/notes/{$a['note']['uuid']}")))->toBe($unknown);

    // Another institution's parent, and another institution's note.
    signIn($this, $b['owner']);
    expect(shape($this->browser->get("/api/v1/probes/{$a['probe']['uuid']}/notes/{$a['note']['uuid']}")))->toBe($unknown)
        ->and(shape($this->browser->get("/api/v1/probes/{$b['probe']['uuid']}/notes/{$a['note']['uuid']}")))->toBe($unknown)
        ->and(shape($this->browser->get("/api/v1/probes/{$a['probe']['uuid']}/notes/{$b['note']['uuid']}")))->toBe($unknown);
});

it('counts only the caller\'s rows, so a total never reveals another institution [NFR-SEC-03] (rule 1)', function () {
    foreach (range(1, 5) as $i) {
        Tenancy::probe($this->t['b']['institution'], "More b{$i}");
    }

    signIn($this, $this->t['a']['owner']);
    $this->browser->get('/api/v1/probes')->assertOk()->assertJsonPath('meta.total', 2);
});

it('answers 401 and shows nothing when nobody is signed in [NFR-SEC-03] (rule 3)', function () {
    $this->browser->get('/api/v1/probes')->assertStatus(401);
    $this->browser->get('/api/v1/probes/'.$this->t['a']['probe']['uuid'])->assertStatus(401);
});
