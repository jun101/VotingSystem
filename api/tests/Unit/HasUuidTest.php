<?php

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
 * The trait is tested on a real table of the test database, created and dropped here
 * through the `migrator` account (the application account cannot change the schema).
 */
class UuidProbe extends Model
{
    use HasUuid;

    protected $connection = 'migrator';

    protected $table = 'uuid_probes';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function () {
    Schema::connection('migrator')->dropIfExists('uuid_probes');
    Schema::connection('migrator')->create('uuid_probes', function ($table) {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->string('name');
    });
});

afterEach(fn () => Schema::connection('migrator')->dropIfExists('uuid_probes'));

it('gives a new record a random version 4 uuid [NFR-SEC-08]', function () {
    $first = UuidProbe::create(['name' => 'a']);
    $second = UuidProbe::create(['name' => 'b']);

    expect($first->uuid)->toMatch(UUID_V4)
        ->and($second->uuid)->toMatch(UUID_V4)
        ->and($second->uuid)->not->toBe($first->uuid);
});

it('keeps a uuid given on purpose [NFR-SEC-08]', function () {
    $uuid = '6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

    expect(UuidProbe::create(['name' => 'a', 'uuid' => $uuid])->uuid)->toBe($uuid);
});

it('is bound in routes by uuid, never by the numeric key [NFR-SEC-08]', function () {
    $probe = UuidProbe::create(['name' => 'a']);
    Route::middleware('api')->get('/api/v1/_test/probes/{probe}', fn (UuidProbe $probe) => ['data' => ['name' => $probe->name]]);

    expect($probe->getRouteKeyName())->toBe('uuid');

    $this->getJson('/api/v1/_test/probes/'.$probe->uuid)->assertOk()->assertJsonPath('data.name', 'a');
    $this->getJson('/api/v1/_test/probes/'.$probe->getKey())->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

it('hides the numeric key from the array and JSON forms [NFR-SEC-08]', function () {
    $probe = UuidProbe::create(['name' => 'a']);

    expect($probe->toArray())->not->toHaveKey('id')
        ->and($probe->toArray())->toHaveKey('uuid')
        ->and(json_decode($probe->toJson(), true))->not->toHaveKey('id');
});
