<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.generation_images_disk' => 'local']);
    Storage::fake('local');
});

it('serves the stored copy to its owner', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Succeeded,
        'image_path' => 'generations/copy.png',
    ]);
    Storage::disk('local')->put('generations/copy.png', 'png-bytes');

    $response = $this->actingAs($user)->get(route('generations.image', $generation));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->streamedContent())->toBe('png-bytes');
});

it('forbids another user', function () {
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Succeeded,
        'image_path' => 'generations/copy.png',
    ]);
    Storage::disk('local')->put('generations/copy.png', 'png-bytes');

    $this->actingAs(User::factory()->create())
        ->get(route('generations.image', $generation))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Succeeded]);

    $this->get(route('generations.image', $generation))->assertRedirect(route('login'));
});

it('redirects to the provider url when there is no copy yet', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Succeeded,
        'image_url' => 'https://images.example.test/gen.png',
        'image_url_expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->get(route('generations.image', $generation))
        ->assertRedirect('https://images.example.test/gen.png');
});

it('returns 404 when the provider url expired and there is no copy', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Succeeded,
        'image_url' => 'https://images.example.test/gen.png',
        'image_url_expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)->get(route('generations.image', $generation))->assertNotFound();
});

it('returns 404 for a queued generation', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create(['status' => GenerationStatus::Queued]);

    $this->actingAs($user)->get(route('generations.image', $generation))->assertNotFound();
});
