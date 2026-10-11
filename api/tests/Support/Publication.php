<?php

namespace Tests\Support;

/**
 * A finished election for the publication tests (slice 16, docs/slices/16-publication.md): closed (or the given status)
 * with two ballots and voters who voted, so the bulletin check passes. Part of the acceptance harness: it is not edited
 * when a slice is coded.
 */
final class Publication
{
    /**
     * An election of the institution with: ballot P (1 seat, candidates A `$a` votes, B `$b` votes, blank `$blank`) and
     * group ballot G (the group "4e année", candidates H 2 votes and I 1 vote), `$a + $b + $blank` voters of which 3 in
     * the group "4e année"... all voted.
     *
     * @return array{election: string, ballots: array<string, string>, candidates: array<string, string>, group: string, voters: int}
     */
    public static function election(string $institution, string $status = 'closed', int $a = 5, int $b = 3, int $blank = 2, array $options = []): array
    {
        $election = Accounts::plantElection($options + [
            'institution' => $institution,
            'status' => $status,
            'title' => 'Conseil des élèves 2026',
            'language' => 'fr',
            'results_display' => 'full',
            'published_at' => $status === 'published' ? '2026-10-17 14:00:00' : null,
            'archived_at' => $status === 'archived' ? '2026-10-20 10:00:00' : null,
            'closed_at' => '2026-10-16 19:00:00',
        ]);
        $group = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
        $party = Accounts::plantParty(['election' => $election, 'name' => 'Avenir Étudiant', 'acronym' => 'AE']);
        $p = Accounts::plantBallot(['election' => $election, 'title' => 'Président(e)', 'seats' => 1, 'allow_blank' => true]);
        $g = Accounts::plantBallot(['election' => $election, 'title' => 'Délégué(e) de classe', 'seats' => 1, 'allow_blank' => true, 'groups' => [$group]]);
        $c = [
            'A' => Accounts::plantCandidate(['ballot' => $p, 'first_name' => 'Nadège', 'last_name' => 'Pierre-Louis', 'party' => $party]),
            'B' => Accounts::plantCandidate(['ballot' => $p, 'first_name' => 'Jean-Marc', 'last_name' => 'Désir']),
            'H' => Accounts::plantCandidate(['ballot' => $g, 'first_name' => 'Ricardo', 'last_name' => 'Saint-Fleur']),
            'I' => Accounts::plantCandidate(['ballot' => $g, 'first_name' => 'Marie', 'last_name' => 'Augustin']),
        ];
        $total = $a + $b + $blank;
        Results::voters($election, 3, $group);
        Results::voters($election, max(0, $total - 3));
        Results::paper($p, [$c['A']], $a);
        Results::paper($p, [$c['B']], $b);
        Results::paper($p, [], $blank);
        Results::paper($g, [$c['H']], 2);
        Results::paper($g, [$c['I']], 1);

        return ['election' => $election, 'ballots' => ['P' => $p, 'G' => $g], 'candidates' => $c, 'group' => $group, 'voters' => $total];
    }
}
