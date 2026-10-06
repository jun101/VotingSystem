<?php

use App\Http\Resources\ApiResource;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use JsonSerializable;

const RESOURCE_UUID = '6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

/** A record as the database would give it: numeric key, uuid and a foreign key. */
function recordOf(): Model
{
    return (new class extends Model
    {
        protected $guarded = [];
    })->forceFill(['id' => 42, 'uuid' => RESOURCE_UUID, 'title' => 'Conseil', 'election_id' => 7]);
}

function resourceOf(Model $record, array $fields): ApiResource
{
    return new class($record, $fields) extends ApiResource
    {
        public function __construct($resource, private readonly array $given)
        {
            parent::__construct($resource);
        }

        protected function fields(Request $request): array
        {
            return $this->given;
        }
    };
}

it('outputs the uuid as id and never the numeric key [NFR-SEC-08]', function () {
    $record = recordOf();

    $json = resourceOf($record, ['id' => $record->id, 'title' => $record->title])->toResponse(request())->getData(true);

    expect($json)->toBe(['data' => ['id' => RESOURCE_UUID, 'title' => 'Conseil']]);
});

it('drops every id-like field a resource returns [NFR-SEC-08]', function () {
    $record = recordOf();

    $json = resourceOf($record, [
        'title' => 'Conseil',
        'election_id' => $record->election_id,
        'voter_ids' => [1, 2],
        'ballot' => ['id' => 3, 'name' => 'President', 'party_id' => 9],
        'ballot_uuid' => RESOURCE_UUID,
    ])->toResponse(request())->getData(true);

    expect($json['data'])->toBe([
        'id' => RESOURCE_UUID,
        'title' => 'Conseil',
        'ballot' => ['name' => 'President'],
        'ballot_uuid' => RESOURCE_UUID,
    ]);
});

it('puts the id first [NFR-SEC-08]', function () {
    $json = resourceOf(recordOf(), ['title' => 'Conseil'])->resolve();

    expect(array_key_first($json))->toBe('id');
});

it('filters identifiers out of a nested model, whatever its attributes [NFR-SEC-08]', function () {
    $json = resourceOf(recordOf(), ['title' => 'x', 'election' => recordOf()])->resolve();

    // The nested model is converted, then filtered: no `election_id`, and its `uuid` stays.
    expect($json['election'])->toBe(['uuid' => RESOURCE_UUID, 'title' => 'Conseil']);
});

it('filters identifiers out of a collection of models and out of what is convertible to an array [NFR-SEC-08]', function () {
    $json = resourceOf(recordOf(), [
        'list' => collect([recordOf(), recordOf()]),
        'arrayable' => new class implements Arrayable
        {
            public function toArray(): array
            {
                return ['name' => 'a', 'party_id' => 5, 'inner' => ['voter_id' => 6, 'label' => 'b']];
            }
        },
        'serializable' => new class implements JsonSerializable
        {
            public function jsonSerialize(): array
            {
                return ['name' => 'c', 'id' => 7];
            }
        },
    ])->resolve();

    expect($json['list'])->toBe([
        ['uuid' => RESOURCE_UUID, 'title' => 'Conseil'],
        ['uuid' => RESOURCE_UUID, 'title' => 'Conseil'],
    ])
        ->and($json['arrayable'])->toBe(['name' => 'a', 'inner' => ['label' => 'b']])
        ->and($json['serializable'])->toBe(['name' => 'c']);
});

it('filters camel-case identifier names too [NFR-SEC-08]', function () {
    $json = resourceOf(recordOf(), [
        'institutionId' => 3,
        'voterIds' => [1, 2],
        'electionID' => 4,
        'nested' => ['ballotId' => 5, 'ballotUuid' => RESOURCE_UUID, 'valid' => true, 'paid' => true],
    ])->resolve();

    expect($json)->toBe([
        'id' => RESOURCE_UUID,
        'nested' => ['ballotUuid' => RESOURCE_UUID, 'valid' => true, 'paid' => true],
    ]);
});

it('keeps a nested resource, which guards itself and whose id is a UUID [NFR-SEC-08]', function () {
    $inner = resourceOf(recordOf(), ['title' => 'Inner']);

    $json = resourceOf(recordOf(), ['ballot' => $inner, 'ballots' => collect([$inner])])->toResponse(request())->getData(true);

    expect($json['data']['ballot'])->toBe(['id' => RESOURCE_UUID, 'title' => 'Inner'])
        ->and($json['data']['ballots'])->toBe([['id' => RESOURCE_UUID, 'title' => 'Inner']]);
});
