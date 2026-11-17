<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests to login', function () {
    $this->get(route('generations.index'))->assertRedirect(route('login'));
});

it('lists only the user generations, newest first, 20 per page', function () {
    $user = User::factory()->create();
    Generation::factory()->count(22)->for($user)->sequence(
        fn ($sequence) => ['created_at' => now()->subMinutes(30 - $sequence->index)],
    )->create();
    Generation::factory()->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 20)
            ->where('generations.meta.total', 22)
            ->where('options.sizes.0.value', '1024x1024')
            ->where('options.qualities.2.value', 'high')
            ->where('maxReferenceImages', 4)
            ->where('maxPromptLength', 4000)
        );

    $ids = Generation::query()->forUser($user)->orderByDesc('created_at')->limit(20)->pluck('id')->all();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data', fn ($items) => collect($items)->pluck('id')->all() === $ids)
        );
});

it('exposes money as exact strings and only the documented keys', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Succeeded,
        'estimated_charge_nanos' => 412_562_500,
        'maximum_charge_nanos' => 467_343_750,
        'charge_nanos' => 412_562_500,
        'provider_cost_nanos' => 330_050_000,
        'provider_id' => 'gen_secret',
        'error_code' => 'x',
        'image_url' => 'https://cdn.example.com/signed',
        'image_url_expires_at' => now()->addHour(),
        'image_path' => 'generations/a.png',
    ]);

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.charge.amount', '0.412562500')
            ->where('generations.data.0.charge.display', '$0.4126')
            ->where('generations.data.0.estimated_charge.amount', '0.412562500')
            ->where('generations.data.0.maximum_charge.amount', '0.467343750')
            ->where('generations.data.0.maximum_charge.display', '$0.4673')
            ->where('generations.data.0.image_expired', false)
            ->where('generations.data.0', function ($item): bool {
                $keys = array_keys(collect($item)->all());
                sort($keys);
                $expected = [
                    'id', 'status', 'awaiting_confirmation', 'is_final', 'prompt', 'size', 'quality', 'reference_image_count',
                    'created_at', 'estimated_charge', 'maximum_charge', 'charge', 'error_message',
                    'image_url', 'image_expired',
                ];
                sort($expected);

                return $keys === $expected;
            })
            ->etc()
        );
});

it('shows the error of a failed generation and no charge', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Failed,
        'error_message' => 'The prompt was rejected.',
    ]);

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.error_message', 'The prompt was rejected.')
            ->where('generations.data.0.charge', null)
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.is_final', true)
            ->etc()
        );
});

it('serves images through the image route and flags expired ones', function () {
    $user = User::factory()->create();
    $stored = Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Succeeded,
        'image_path' => 'generations/a.png',
    ]);
    $expired = Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Succeeded,
        'image_url' => 'https://cdn.example.com/old',
        'image_url_expires_at' => now()->subHour(),
    ]);
    $queued = Generation::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data', function ($items) use ($stored, $expired, $queued): bool {
                $byId = collect($items)->keyBy('id');

                return $byId[$stored->id]['image_url'] === route('generations.image', $stored->id)
                    && $byId[$stored->id]['image_expired'] === false
                    && $byId[$expired->id]['image_url'] === null
                    && $byId[$expired->id]['image_expired'] === true
                    && $byId[$queued->id]['image_url'] === null
                    && $byId[$queued->id]['image_expired'] === false
                    && $byId[$queued->id]['is_final'] === false;
            })
            ->etc()
        );
});

it('flags awaiting confirmation only for queued rows without a provider id that are unconfirmed', function () {
    $user = User::factory()->create();
    $unconfirmed = Generation::factory()->for($user)->create(['submit_unconfirmed' => true]);
    $plain = Generation::factory()->for($user)->create(['submit_unconfirmed' => false]);
    $withProvider = Generation::factory()->for($user)->create([
        'submit_unconfirmed' => true,
        'provider_id' => 'gen_abc',
    ]);
    $processing = Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Processing,
        'submit_unconfirmed' => true,
    ]);

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data', function ($items) use ($unconfirmed, $plain, $withProvider, $processing): bool {
                $byId = collect($items)->keyBy('id');

                return $byId[$unconfirmed->id]['awaiting_confirmation'] === true
                    && $byId[$plain->id]['awaiting_confirmation'] === false
                    && $byId[$withProvider->id]['awaiting_confirmation'] === false
                    && $byId[$processing->id]['awaiting_confirmation'] === false;
            })
            ->etc()
        );
});
