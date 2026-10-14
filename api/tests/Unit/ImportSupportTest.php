<?php

use App\Actions\Imports\ImportVoterRows;
use App\Models\VoterImport;
use App\Support\Imports\ColumnMapper;
use App\Support\Imports\ErrorFileWriter;
use App\Support\Imports\Fold;
use App\Support\Imports\ReasonWords;
use App\Support\Imports\VoterFileReader;
use Tests\Support\Accounts;
use Tests\Support\Team;

it('matches the header names ignoring case, accents, spaces and language [FR-VOT-03]', function () {
    $mapper = new ColumnMapper;

    expect($mapper->propose(['Nom_Complet', 'Classe', 'Matricule', 'E-mail', 'Téléphone']))
        ->toBe(['full_name' => 0, 'group' => 1, 'identifier' => 2, 'email' => 3, 'phone' => 4])
        ->and($mapper->propose(['  FULL NAME ', 'group', 'id', 'Mail', 'phone']))
        ->toBe(['full_name' => 0, 'group' => 1, 'identifier' => 2, 'email' => 3, 'phone' => 4])
        ->and($mapper->propose(['nom', 'autre']))
        ->toBe(['full_name' => 0, 'group' => null, 'identifier' => null, 'email' => null, 'phone' => null]);
});

it('uses a column once, for the first field that names it [FR-VOT-03]', function () {
    $mapping = (new ColumnMapper)->propose(['nom', 'nom', 'name']);

    expect($mapping['full_name'])->toBe(0);
});

it('detects the separator of a text file from its first record, outside quotes [FR-VOT-03]', function () {
    expect(VoterFileReader::delimiterOf("nom;groupe;id\nA;B;C\n"))->toBe(';')
        ->and(VoterFileReader::delimiterOf("nom,groupe,id\nA;B;C;D;E\n"))->toBe(',')
        ->and(VoterFileReader::delimiterOf("\"a,b,c\";x;y\n"))->toBe(';')
        ->and(VoterFileReader::delimiterOf("nom\n"))->toBe(',');
});

it('accepts a mapping that fits the file and refuses the others [FR-VOT-03]', function () {
    $mapper = new ColumnMapper;
    $good = ['full_name' => 0, 'group' => 2, 'identifier' => null, 'email' => null, 'phone' => 1];

    expect($mapper->isValid($good, 3))->toBeTrue()
        ->and($mapper->isValid(['full_name' => null] + $good, 3))->toBeFalse()
        ->and($mapper->isValid(['group' => 0] + $good, 3))->toBeFalse()
        ->and($mapper->isValid(['phone' => 3] + $good, 3))->toBeFalse()
        ->and($mapper->isValid(['phone' => -1] + $good, 3))->toBeFalse()
        ->and($mapper->isValid(['phone' => '1'] + $good, 3))->toBeFalse()
        ->and($mapper->isValid(['full_name' => 0, 'group' => 1], 3))->toBeFalse()
        ->and($mapper->isValid($good + ['extra' => 1], 3))->toBeFalse();
});

it('folds case and accents for the comparison keys [FR-VOT-03]', function () {
    expect(Fold::key('  ÉLÈVE-1 '))->toBe('eleve-1')
        ->and(Fold::key('Rose@Example.HT'))->toBe('rose@example.ht')
        ->and(Fold::header('Nom_complet'))->toBe('nomcomplet');
});

it('has a phrase in French and in English for every reason code, never the code itself [FR-VOT-03]', function () {
    foreach (ReasonWords::CODES as $code) {
        foreach (['fr', 'en'] as $language) {
            $phrase = ReasonWords::phrase([$code], $language);

            expect($phrase)->not->toBe('')
                ->and($phrase)->not->toContain('_')
                ->and($phrase)->not->toBe('imports.reasons.'.$code);
        }
        expect(ReasonWords::phrase([$code], 'fr'))->not->toBe(ReasonWords::phrase([$code], 'en'));
    }
});

it('joins the phrases of several reasons with a semicolon and never shows an unknown code [FR-VOT-03]', function () {
    $two = ReasonWords::phrase(['email_invalid', 'identifier_exists'], 'en');

    expect($two)->toBe(ReasonWords::phrase(['email_invalid'], 'en').'; '.ReasonWords::phrase(['identifier_exists'], 'en'))
        ->and(ReasonWords::phrase(['made_up_code'], 'en'))->not->toContain('made_up_code')
        ->and(ReasonWords::phrase([], 'en'))->toBe('')
        ->and(ReasonWords::phrase(null, 'fr'))->toBe('');
});

it('puts a quote before a text a spreadsheet could run as a formula [NFR-SEC-06]', function (string $text, string $written) {
    expect(ErrorFileWriter::guard($text))->toBe($written);
})->with([
    ['=1+1', "'=1+1"],
    ['+509 3712 4455', "'+509 3712 4455"],
    ['-3', "'-3"],
    ['@SUM(A1)', "'@SUM(A1)"],
    ["\tx", "'\tx"],
    ['Rose-Marie', 'Rose-Marie'],
    ['a=b', 'a=b'],
    ['', ''],
]);

it('cuts a number of rows into chunks of 500 [NFR-PERF-03]', function () {
    expect(ImportVoterRows::CHUNK)->toBe(500)
        ->and(ImportVoterRows::chunks(0))->toBe(0)
        ->and(ImportVoterRows::chunks(1))->toBe(1)
        ->and(ImportVoterRows::chunks(500))->toBe(1)
        ->and(ImportVoterRows::chunks(501))->toBe(2)
        ->and(ImportVoterRows::chunks(1200))->toBe(3)
        ->and(ImportVoterRows::chunks(10000))->toBe(20);
});

it('writes the voters with nobody signed in, as a queue worker does [FR-VOT-03]', function () {
    Accounts::reset();
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $import = Accounts::plantImport(['election' => $election, 'status' => 'importing', 'rows' => [
        ['full_name' => 'Rose', 'group' => '4e année', 'identifier' => 'E-1'],
        ['full_name' => 'Jean', 'group' => '4E ANNÉE'],
    ]]);
    expect(auth()->user())->toBeNull();

    $model = VoterImport::withoutInstitutionScope()->where('uuid', $import)->firstOrFail();
    app(ImportVoterRows::class)($model);

    $done = Accounts::importRow($import);
    expect($done['status'])->toBe('done')
        ->and($done['imported_rows'])->toBe(2)
        ->and(Accounts::voterRows($election))->toHaveCount(2)
        ->and(Accounts::groupRows($election))->toHaveCount(1)
        ->and(Accounts::groupRows($election)[0]['institution_id'])->toBe(Accounts::electionRow($election)['institution_id']);
});
