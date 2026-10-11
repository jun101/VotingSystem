<?php

/*
 * GET /api/v1/public/institutions/{institution} — docs/api/publication/GET-public-institutions-{institution}.md
 * Public route api/v1/public/institutions/{institution}.
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Publication;
use Tests\Support\Team;

function publicInstitutionUrl(string $uuid): string
{
    return "/api/v1/public/institutions/{$uuid}";
}

it('gives the profile and only the published elections, newest publication first [FR-RES-07] (scenarios 1, 3)', function () {
    $t = Team::two();
    $institution = $t['a']['owner']['institution'];
    Accounts::updateInstitution($institution, ['name' => 'Collège Étoile du Matin', 'type' => 'school', 'description' => 'Un collège de Pétion-Ville.', 'city' => 'Pétion-Ville', 'address' => '12 rue Lamarre', 'phone' => '+509 2200 0000', 'contact_email' => 'secret@example.ht']);
    $old = Publication::election($institution, 'published', 5, 3, 2, ['title' => 'Ancienne', 'published_at' => '2026-09-01 10:00:00']);
    $new = Publication::election($institution, 'published', 5, 3, 2, ['title' => 'Récente', 'published_at' => '2026-10-17 14:00:00']);
    Publication::election($institution, 'closed', 5, 3, 2, ['title' => 'Fermée']);
    Publication::election($institution, 'open', 5, 3, 2, ['title' => 'Ouverte']);
    Publication::election($institution, 'draft', 5, 3, 2, ['title' => 'Brouillon']);
    Publication::election($institution, 'archived', 5, 3, 2, ['title' => 'Archivée']);
    Publication::election($t['b']['owner']['institution'], 'published', 5, 3, 2, ['title' => 'Autre institution']);

    $response = (new AuthClient($this))->get(publicInstitutionUrl($institution))->assertOk();
    $data = $response->json('data');

    expect(array_keys($data))->toBe(['id', 'name', 'type', 'logo', 'description', 'city', 'elections'])
        ->and($data)->toMatchArray(['id' => $institution, 'name' => 'Collège Étoile du Matin', 'type' => 'school', 'logo' => null, 'description' => 'Un collège de Pétion-Ville.', 'city' => 'Pétion-Ville'])
        ->and(array_column($data['elections'], 'title'))->toBe(['Récente', 'Ancienne'])
        ->and(array_column($data['elections'], 'id'))->toBe([$new['election'], $old['election']])
        ->and(array_keys($data['elections'][0]))->toBe(['id', 'title', 'published_at', 'starts_at', 'ends_at', 'timezone', 'language'])
        ->and($response->getContent())->not->toContain('12 rue Lamarre')->not->toContain('2200 0000')->not->toContain('secret@example.ht')->not->toContain('institution_id');
});

it('answers an empty list for an institution with no published election [FR-RES-07] (scenario 2)', function () {
    $t = Team::two();

    $data = (new AuthClient($this))->get(publicInstitutionUrl($t['a']['owner']['institution']))->assertOk()->json('data');

    expect($data['elections'])->toBe([]);
});

it('answers the same 404 for an unknown institution, a value that is not a UUID and a suspended institution [FR-INST-06] (scenario 3)', function () {
    $t = Team::two();
    Publication::election($t['a']['owner']['institution'], 'published');
    Accounts::suspend($t['a']['owner']['institution']);
    $anonymous = new AuthClient($this);

    $unknown = Team::shape($anonymous->get(publicInstitutionUrl('3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa')));
    expect($unknown['status'])->toBe(404);

    foreach ([$t['a']['owner']['institution'], 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($anonymous->get(publicInstitutionUrl($target))))->toBe($unknown);
    }
});

it('answers 429 above 120 requests a minute from one address and 405 for another method [NFR-SEC-05] (scenario 4)', function () {
    $t = Team::two();
    $anonymous = (new AuthClient($this))->fromAddress('10.32.32.32');
    $anonymous->other('POST', publicInstitutionUrl($t['a']['owner']['institution']))->assertStatus(405);

    foreach (range(1, 120) as $i) {
        $anonymous->get(publicInstitutionUrl($t['a']['owner']['institution']))->assertOk();
    }

    $anonymous->get(publicInstitutionUrl($t['a']['owner']['institution']))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
