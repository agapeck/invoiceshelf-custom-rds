<?php

use App\Models\FileDisk;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->withHeaders([
        'company' => $user->companies()->first()->id,
    ]);
    Sanctum::actingAs(
        $user,
        ['*']
    );
});

test('get file disks', function () {
    $response = getJson('/api/v1/disks');

    $response->assertOk();
});

test('create file disk', function () {
    $disk = FileDisk::factory()->raw();

    $response = postJson('/api/v1/disks', $disk);
    $response->assertOk();

    // 1. Verify model accessor round-trip: array in → same array back out
    $created = FileDisk::where('name', $disk['name'])->where('driver', $disk['driver'])->first();
    expect($created)->not->toBeNull();
    $decrypted = json_decode($created->credentials, true);
    expect($decrypted)->toBe($disk['credentials']);

    // 2. Verify raw DB value is NOT plaintext JSON — proves encryption is actually happening
    $rawCredentials = DB::table('file_disks')->where('id', $created->id)->value('credentials');
    expect(json_decode($rawCredentials))->toBeNull('Raw DB credentials must NOT be valid JSON — they must be encrypted ciphertext');

    // 3. Verify raw DB value IS valid ciphertext that decrypts to the original credentials
    $decryptedRaw = Crypt::decryptString($rawCredentials);
    expect(json_decode($decryptedRaw, true))->toBe($disk['credentials']);
});

test('update file disk', function () {
    $disk = FileDisk::factory()->create();

    $disk2 = FileDisk::factory()->raw();

    $response = putJson("/api/v1/disks/{$disk->id}", $disk2)->assertStatus(200);

    // 1. Verify model accessor round-trip: array in → same array back out
    $updated = FileDisk::find($disk->id);
    $decrypted = json_decode($updated->credentials, true);
    expect($decrypted)->toBe($disk2['credentials']);

    // 2. Verify raw DB value is NOT plaintext JSON — proves encryption is actually happening
    $rawCredentials = DB::table('file_disks')->where('id', $updated->id)->value('credentials');
    expect(json_decode($rawCredentials))->toBeNull('Raw DB credentials must NOT be valid JSON — they must be encrypted ciphertext');

    // 3. Verify raw DB value IS valid ciphertext that decrypts to the updated credentials
    $decryptedRaw = Crypt::decryptString($rawCredentials);
    expect(json_decode($decryptedRaw, true))->toBe($disk2['credentials']);
});

test('get disk', function () {
    $disk = FileDisk::factory()->create();

    $response = getJson("/api/v1/disks/{$disk->driver}");

    $response->assertStatus(200);
});

test('get drivers', function () {
    $response = getJson('/api/v1/disk/drivers');

    $response->assertStatus(200);
});
