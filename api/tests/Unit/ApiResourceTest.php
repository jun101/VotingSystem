<?php

use App\Http\Resources\ApiResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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
