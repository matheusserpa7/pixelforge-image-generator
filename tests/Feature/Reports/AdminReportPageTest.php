<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Pixelforge\FakePixelforgeClient;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    $this->client = new FakePixelforgeClient;
    $this->app->instance(PixelforgeClient::class, $this->client);
    $this->travelTo(now()->setTime(12, 0));
});

it('sends guests to login', function () {
    $this->get(route('admin.report'))->assertRedirect(route('login'));
});

it('forbids non-admins', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();

    expect($this->client->usageCalls)->toBeEmpty();
});

it('renders the default 30-day range for admins', function () {
    $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Succeeded,
        'provider_completed_at' => now()->subDay(),
    ]);
    LedgerEntry::query()->create([
        'generation_id' => $generation->id,
        'user_id' => $user->id,
        'provider_cost_nanos' => 40_000_000,
        'charge_nanos' => 50_000_000,
        'markup_percent' => '25',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('range.end', now('UTC')->format('Y-m-d'))
            ->where('range.start', now('UTC')->subDays(29)->format('Y-m-d'))
            ->has('days', 30)
            ->where('providerUsageAvailable', true)
            ->where('totals.generations', 1)
            ->where('totals.charged.amount', '0.050000000')
            ->where('totals.providerCost.amount', '0.040000000')
            ->where('totals.profit.amount', '0.010000000')
            ->where('totals.matches', false)
            ->where('users.0.name', 'Ada')
            ->where('users.0.email', 'ada@example.test'));
});

it('accepts an explicit range', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['start' => '2026-01-01', 'end' => '2026-01-03']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('days', 3)->where('range.start', '2026-01-01'));
});

it('rejects invalid ranges', function (array $query, string $field) {
    $this->actingAs(User::factory()->admin()->create())
        ->from(route('admin.report'))
        ->get(route('admin.report', $query))
        ->assertRedirect(route('admin.report'))
        ->assertSessionHasErrors($field);

    expect($this->client->usageCalls)->toBeEmpty();
})->with([
    'longer than 92 days' => [['start' => '2026-01-01', 'end' => '2026-04-03'], 'end'],
    'start after end' => [['start' => '2026-02-10', 'end' => '2026-02-01'], 'start'],
    'future end' => [['end' => '2999-01-01'], 'end'],
    'bad format' => [['start' => '10/02/2026'], 'start'],
    'impossible date' => [['start' => '2026-02-30'], 'start'],
]);

it('allows exactly 92 days', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['start' => '2026-01-01', 'end' => '2026-04-02']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('days', 92));
});

it('shares auth.isAdmin', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.isAdmin', true));

    $this->actingAs(User::factory()->create())
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.isAdmin', false));
});

it('sends an empty stuck list when nothing is stuck', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page->has('stuck', 0));
});

it('sends the stuck list when a generation is stuck', function () {
    $admin = User::factory()->admin()->create();
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Processing,
        'provider_id' => 'gen_stuck',
    ]);
    Generation::query()->whereKey($generation->id)->update(['created_at' => now()->subHours(2)]);

    $this->actingAs($admin)
        ->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('stuck', 1)
            ->where('stuck.0.id', $generation->id)
            ->where('stuck.0.providerId', 'gen_stuck')
            ->where('stuck.0.status', 'processing'));
});
