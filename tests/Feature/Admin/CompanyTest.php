<?php

use App\Http\Controllers\V1\Admin\Company\CompaniesController;
use App\Http\Requests\CompaniesRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::query()->firstOrFail();
    $this->user = $user;
    $this->withHeaders([
        'company' => $user->companies()->first()->id,
    ]);
    Sanctum::actingAs(
        $user,
        ['*']
    );
});

test('store user using a form request', function () {
    $this->assertActionUsesFormRequest(
        CompaniesController::class,
        'store',
        CompaniesRequest::class
    );
});

test('store company', function () {
    $company = Company::factory()->raw([
        'currency' => 12,
        'address' => [
            'country_id' => 12,
        ],
    ]);

    postJson('/api/v1/companies', $company)
        ->assertStatus(201);

    $company = collect($company)
        ->only([
            'name',
        ])
        ->toArray();

    $this->assertDatabaseHas('companies', $company);
});

test('delete company', function () {
    postJson('/api/v1/companies/delete', ['xyz'])
        ->assertStatus(422);
});

test('transfer ownership', function () {
    $companyId = (int) $this->user->companies()->first()->id;
    $member = User::factory()->create();
    $member->companies()->attach($companyId);

    postJson('/api/v1/transfer/ownership/'.$member->id)
        ->assertOk();

    expect(Company::findOrFail($companyId)->owner_id)->toBe($member->id);
});

test('transfer ownership rejects users outside active company', function () {
    $outsider = User::factory()->create();

    postJson('/api/v1/transfer/ownership/'.$outsider->id)
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
});

test('get companies', function () {
    getJson('/api/v1/companies')
        ->assertOk();
});
