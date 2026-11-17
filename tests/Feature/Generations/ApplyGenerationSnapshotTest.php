<?php

declare(strict_types=1);

use App\Actions\ApplyGenerationSnapshot;
use App\Actions\CreateGeneration;
use App\Actions\FailGeneration;
use App\Actions\GetCachedPricing;
use App\Actions\GetCreditBalance;
use App\Data\GenerationRates;
use App\Data\PixelforgeGeneration;
use App\Data\PixelforgeGenerationError;
use App\Data\TokenUsage;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\SnapshotOutcome;
use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Billing\InsufficientCredits;
use App\Services\Pixelforge\GenerationFailureMessage;
use App\Services\Pixelforge\SamplePricing;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['billing.markup_percent' => '25']);
});

/**
 * @param  array{
 *     id?: string,
 *     usage?: TokenUsage|null,
 *     rates?: GenerationRates,
 *     error?: PixelforgeGenerationError|null,
 *     imageUrl?: string|null,
 *     imageUrlExpiresAt?: CarbonImmutable|null,
 *     createdAt?: CarbonImmutable,
 *     startedAt?: CarbonImmutable|null,
 *     completedAt?: CarbonImmutable|null,
 * }  $overrides
 */
function pixelforgeSnapshot(Generation $generation, GenerationStatus $status, array $overrides = []): PixelforgeGeneration
{
    $now = CarbonImmutable::now('UTC');

    return new PixelforgeGeneration(
        id: $overrides['id'] ?? 'gen_provider_1',
        status: $status,
        prompt: $generation->prompt,
        size: $generation->size,
        quality: $generation->quality,
        referenceImages: $generation->reference_images,
        metadata: ['generation_id' => $generation->id],
        imageUrl: $overrides['imageUrl'] ?? null,
        imageUrlExpiresAt: $overrides['imageUrlExpiresAt'] ?? null,
        usage: array_key_exists('usage', $overrides) ? $overrides['usage'] : null,
        rates: $overrides['rates'] ?? new GenerationRates(
            Money::ofNanos(5000),
            Money::ofNanos(10000),
            Money::ofNanos(40000),
            Money::ofNanos(12500),
        ),
        error: $overrides['error'] ?? null,
        createdAt: $overrides['createdAt'] ?? $now,
        startedAt: $overrides['startedAt'] ?? null,
        completedAt: $overrides['completedAt'] ?? null,
    );
}

it('fills provider id and rates on a queued snapshot without charging', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);

    $outcome = $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Queued, ['id' => 'gen_queued_1']),
    );

    $generation->refresh();

    expect($outcome)->toBe(SnapshotOutcome::Applied)
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBe('gen_queued_1')
        ->and($generation->rates)->toBeArray()
        ->and($generation->rates['input_text_tokens'])->toBe(5000)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('ignores a second queued snapshot when nothing is left to fill', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);
    $snapshot = pixelforgeSnapshot($generation, GenerationStatus::Queued, ['id' => 'gen_queued_1']);

    $apply->handle($generation->id, $snapshot);
    $outcome = $apply->handle($generation->id, $snapshot);

    $generation->refresh();

    expect($outcome)->toBe(SnapshotOutcome::Ignored)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('moves queued to processing without a ledger row', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);

    $outcome = $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Processing),
    );

    $generation->refresh();

    expect($outcome)->toBe(SnapshotOutcome::Applied)
        ->and($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->provider_id)->toBe('gen_provider_1')
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('charges on succeeded with usage using half-up markup', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);
    $rates = new GenerationRates(
        Money::ofNanos(1),
        Money::zero(),
        Money::zero(),
        Money::zero(),
    );
    $usage = new TokenUsage(inputText: 2, inputImage: 0, output: 0, reasoning: 0);

    $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Succeeded, [
            'rates' => $rates,
            'usage' => $usage,
        ]),
    );

    $generation->refresh();
    $ledger = LedgerEntry::query()->where('generation_id', $generation->id)->first();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nanos)->toBe(2)
        ->and($generation->charge_nanos)->toBe(3)
        ->and($ledger)->not->toBeNull()
        ->and($ledger->provider_cost_nanos)->toBe(2)
        ->and($ledger->charge_nanos)->toBe(3)
        ->and($ledger->markup_percent)->toBe('25');
});

it('leaves charge equal to provider cost when markup is zero', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued, 'markup_percent' => '0']);
    $apply = app(ApplyGenerationSnapshot::class);
    $rates = new GenerationRates(
        Money::ofNanos(1),
        Money::zero(),
        Money::zero(),
        Money::zero(),
    );
    $usage = new TokenUsage(inputText: 2, inputImage: 0, output: 0, reasoning: 0);

    $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Succeeded, [
            'rates' => $rates,
            'usage' => $usage,
        ]),
    );

    $generation->refresh();

    expect($generation->provider_cost_nanos)->toBe(2)
        ->and($generation->charge_nanos)->toBe(2);
});

it('bills from snapshot rates even when the pricing cache holds junk', function () {
    Cache::put(GetCachedPricing::CACHE_KEY, ['broken' => true], 60);

    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);
    $rates = new GenerationRates(
        Money::perToken('6.00'),
        Money::zero(),
        Money::zero(),
        Money::zero(),
    );
    $usage = new TokenUsage(inputText: 2, inputImage: 0, output: 0, reasoning: 0);

    $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Succeeded, [
            'rates' => $rates,
            'usage' => $usage,
        ]),
    );

    $ledger = LedgerEntry::query()->where('generation_id', $generation->id)->first();

    expect($ledger)->not->toBeNull()
        ->and($ledger->provider_cost_nanos)->toBe(12000)
        ->and($ledger->charge_nanos)->toBe(15000);
});

it('does not double charge when the succeeded snapshot is applied again', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);
    $snapshot = pixelforgeSnapshot($generation, GenerationStatus::Succeeded, [
        'rates' => new GenerationRates(Money::ofNanos(1), Money::zero(), Money::zero(), Money::zero()),
        'usage' => new TokenUsage(inputText: 2, inputImage: 0, output: 0, reasoning: 0),
    ]);

    $apply->handle($generation->id, $snapshot);
    $outcome = $apply->handle($generation->id, $snapshot);

    $generation->refresh();

    expect($outcome)->toBe(SnapshotOutcome::Ignored)
        ->and(LedgerEntry::query()->where('generation_id', $generation->id)->count())->toBe(1)
        ->and($generation->charge_nanos)->toBe(3);
});

it('returns incomplete for succeeded without usage but stores provider data', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);
    $snapshot = pixelforgeSnapshot($generation, GenerationStatus::Succeeded, ['usage' => null]);

    $outcome = $apply->handle($generation->id, $snapshot);

    $generation->refresh();

    expect($outcome)->toBe(SnapshotOutcome::Incomplete)
        ->and($generation->provider_id)->toBe($snapshot->id)
        ->and($generation->rates)->not->toBeNull()
        ->and($generation->provider_created_at)->not->toBeNull()
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and(LedgerEntry::query()->count())->toBe(0);

    $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Succeeded, [
            'id' => $snapshot->id,
            'usage' => new TokenUsage(inputText: 10, inputImage: 0, output: 100, reasoning: 0),
        ]),
    );

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('ignores an incomplete snapshot with a mismatched provider id', function () {
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Queued,
        'provider_id' => 'gen_mine',
    ]);

    $outcome = app(ApplyGenerationSnapshot::class)->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Succeeded, ['id' => 'gen_other', 'usage' => null]),
    );

    expect($outcome)->toBe(SnapshotOutcome::Ignored)
        ->and($generation->refresh()->provider_id)->toBe('gen_mine');
});

it('writes a zero cost ledger row when usage is all zeros', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);
    $usage = new TokenUsage(inputText: 0, inputImage: 0, output: 0, reasoning: 0);

    $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Succeeded, ['usage' => $usage]),
    );

    $ledger = LedgerEntry::query()->where('generation_id', $generation->id)->first();

    expect($ledger)->not->toBeNull()
        ->and($ledger->provider_cost_nanos)->toBe(0)
        ->and($ledger->charge_nanos)->toBe(0);
});

it('maps provider failure codes to local messages and does not charge', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);

    $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Failed, [
            'error' => new PixelforgeGenerationError(
                'content_policy_violation',
                'Echo of the user prompt should never appear here.',
            ),
        ]),
    );

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('content_policy_violation')
        ->and($generation->error_message)->toBe(GenerationFailureMessage::forCode('content_policy_violation'))
        ->and($generation->error_message)->not->toContain('Echo')
        ->and($generation->provider_cost_nanos)->toBeNull()
        ->and($generation->charge_nanos)->toBeNull()
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('keeps a succeeded generation when a later failed snapshot arrives', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);
    $succeeded = pixelforgeSnapshot($generation, GenerationStatus::Succeeded, [
        'rates' => new GenerationRates(Money::ofNanos(1), Money::zero(), Money::zero(), Money::zero()),
        'usage' => new TokenUsage(inputText: 2, inputImage: 0, output: 0, reasoning: 0),
    ]);

    $apply->handle($generation->id, $succeeded);
    $outcome = $apply->handle($generation->id, pixelforgeSnapshot($generation, GenerationStatus::Failed));

    $generation->refresh();

    expect($outcome)->toBe(SnapshotOutcome::Ignored)
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and(LedgerEntry::query()->where('generation_id', $generation->id)->count())->toBe(1);
});

it('keeps a failed generation when a later succeeded snapshot arrives', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);

    $apply->handle($generation->id, pixelforgeSnapshot($generation, GenerationStatus::Failed));
    $outcome = $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Succeeded, [
            'usage' => new TokenUsage(inputText: 1, inputImage: 0, output: 0, reasoning: 0),
        ]),
    );

    $generation->refresh();

    expect($outcome)->toBe(SnapshotOutcome::Ignored)
        ->and($generation->status)->toBe(GenerationStatus::Failed)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('keeps processing when a later queued snapshot arrives', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);

    $apply->handle($generation->id, pixelforgeSnapshot($generation, GenerationStatus::Processing));
    $outcome = $apply->handle($generation->id, pixelforgeSnapshot($generation, GenerationStatus::Queued));

    $generation->refresh();

    expect($outcome)->toBe(SnapshotOutcome::Ignored)
        ->and($generation->status)->toBe(GenerationStatus::Processing);
});

it('ignores a snapshot whose provider id does not match the stored id', function () {
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Queued,
        'provider_id' => 'gen_original',
    ]);
    $apply = app(ApplyGenerationSnapshot::class);

    $outcome = $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Processing, ['id' => 'gen_other']),
    );

    $generation->refresh();

    expect($outcome)->toBe(SnapshotOutcome::Ignored)
        ->and($generation->status)->toBe(GenerationStatus::Queued);
});

it('fails a queued generation locally without a ledger row', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);

    app(FailGeneration::class)->handle(
        $generation->id,
        'submit_failed',
        GenerationFailureMessage::forCode('submit_failed'),
    );

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('submit_failed')
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('does not change an already succeeded generation when fail generation runs', function () {
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Succeeded,
        'provider_cost_nanos' => 2,
        'charge_nanos' => 3,
    ]);

    app(FailGeneration::class)->handle(
        $generation->id,
        'submit_failed',
        GenerationFailureMessage::forCode('submit_failed'),
    );

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nanos)->toBe(3);
});

it('refuses ledger updates and deletes', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);
    $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Succeeded, [
            'rates' => new GenerationRates(Money::ofNanos(1), Money::zero(), Money::zero(), Money::zero()),
            'usage' => new TokenUsage(inputText: 1, inputImage: 0, output: 0, reasoning: 0),
        ]),
    );

    $ledger = LedgerEntry::query()->where('generation_id', $generation->id)->firstOrFail();

    expect(fn () => $ledger->update(['charge_nanos' => 99]))->toThrow(LogicException::class)
        ->and(fn () => $ledger->delete())->toThrow(LogicException::class);
});

it('rejects a second ledger row for the same generation', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);
    $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Succeeded, [
            'rates' => new GenerationRates(Money::ofNanos(1), Money::zero(), Money::zero(), Money::zero()),
            'usage' => new TokenUsage(inputText: 1, inputImage: 0, output: 0, reasoning: 0),
        ]),
    );

    expect(fn () => LedgerEntry::query()->create([
        'generation_id' => $generation->id,
        'user_id' => $generation->user_id,
        'provider_cost_nanos' => 1,
        'charge_nanos' => 1,
        'markup_percent' => '25',
    ]))->toThrow(QueryException::class);
});

it('nulls user id on generation and ledger when the user is deleted', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);
    $apply->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Succeeded, [
            'rates' => new GenerationRates(Money::ofNanos(1), Money::zero(), Money::zero(), Money::zero()),
            'usage' => new TokenUsage(inputText: 1, inputImage: 0, output: 0, reasoning: 0),
        ]),
    );

    $ledgerId = LedgerEntry::query()->where('generation_id', $generation->id)->value('id');
    $user->delete();

    expect(Generation::query()->find($generation->id))->not->toBeNull()
        ->and(Generation::query()->find($generation->id)?->user_id)->toBeNull()
        ->and(LedgerEntry::query()->find($ledgerId))->not->toBeNull()
        ->and(LedgerEntry::query()->find($ledgerId)?->user_id)->toBeNull();
});

it('dispatches the image download only on the succeeded transition', function () {
    Queue::fake();

    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued]);
    $apply = app(ApplyGenerationSnapshot::class);
    $usage = new TokenUsage(inputText: 2, inputImage: 0, output: 0, reasoning: 0);

    $apply->handle($generation->id, pixelforgeSnapshot($generation, GenerationStatus::Queued));
    $apply->handle($generation->id, pixelforgeSnapshot($generation, GenerationStatus::Processing));
    $apply->handle($generation->id, pixelforgeSnapshot($generation, GenerationStatus::Succeeded));

    Queue::assertNotPushed(DownloadGenerationImage::class);

    $succeeded = pixelforgeSnapshot($generation, GenerationStatus::Succeeded, ['usage' => $usage]);
    $apply->handle($generation->id, $succeeded);

    Queue::assertPushed(DownloadGenerationImage::class, fn (DownloadGenerationImage $job): bool => $job->generationId === $generation->id);
    Queue::assertPushed(DownloadGenerationImage::class, 1);

    $apply->handle($generation->id, $succeeded);

    Queue::assertPushed(DownloadGenerationImage::class, 1);
});

it('dispatches no image download for a failed generation', function () {
    Queue::fake();

    $generation = Generation::factory()->create(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_provider_1']);

    app(ApplyGenerationSnapshot::class)->handle($generation->id, pixelforgeSnapshot($generation, GenerationStatus::Failed));

    Queue::assertNotPushed(DownloadGenerationImage::class);
});

it('charges at the markup frozen on the generation, not the current configuration', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued, 'markup_percent' => '25']);
    config(['billing.markup_percent' => '40']);

    app(ApplyGenerationSnapshot::class)->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Succeeded, [
            'rates' => new GenerationRates(Money::ofNanos(1), Money::zero(), Money::zero(), Money::zero()),
            'usage' => new TokenUsage(inputText: 1000, inputImage: 0, output: 0, reasoning: 0),
        ]),
    );

    $ledger = LedgerEntry::query()->where('generation_id', $generation->id)->sole();

    expect($ledger->provider_cost_nanos)->toBe(1000)
        ->and($ledger->charge_nanos)->toBe(1250)
        ->and($ledger->markup_percent)->toBe('25');
});

it('rounds the frozen markup half-up', function (int $tokens, int $expectedCharge) {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued, 'markup_percent' => '12.5']);

    app(ApplyGenerationSnapshot::class)->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Succeeded, [
            'rates' => new GenerationRates(Money::ofNanos(1), Money::zero(), Money::zero(), Money::zero()),
            'usage' => new TokenUsage(inputText: $tokens, inputImage: 0, output: 0, reasoning: 0),
        ]),
    );

    expect($generation->refresh()->provider_cost_nanos)->toBe($tokens)
        ->and($generation->charge_nanos)->toBe($expectedCharge);
})->with([
    '3.375 rounds down' => [3, 3],
    '4.5 rounds up' => [4, 5],
]);

/**
 * Maximum tokens are 1000 text tokens: at 5 000 nanos per token and 25% markup the quoted hold is 6 250 000.
 *
 * @param  array<string, mixed>  $overrides
 */
function heldGeneration(array $overrides = []): Generation
{
    return Generation::factory()->create(array_merge([
        'status' => GenerationStatus::Queued,
        'markup_percent' => '25',
        'maximum_tokens' => (new TokenUsage(1000, 0, 0, 0))->toStoredArray(),
        'maximum_provider_cost_nanos' => 5_000_000,
        'maximum_charge_nanos' => 6_250_000,
    ], $overrides));
}

function textRates(int $nanosPerToken): GenerationRates
{
    return new GenerationRates(Money::ofNanos($nanosPerToken), Money::zero(), Money::zero(), Money::zero());
}

it('raises the hold to the maximum at the generation rates when they are higher', function () {
    $user = User::factory()->withCredit('0.02')->create();
    $generation = heldGeneration(['user_id' => $user->id]);

    expect(app(GetCreditBalance::class)->handle($user)->available()->nanos)->toBe(20_000_000 - 6_250_000);

    app(ApplyGenerationSnapshot::class)->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Processing, ['rates' => textRates(10_000)]),
    );

    $generation->refresh();
    $balance = app(GetCreditBalance::class)->handle($user);

    expect($generation->maximum_provider_cost_nanos)->toBe(10_000_000)
        ->and($generation->maximum_charge_nanos)->toBe(12_500_000)
        ->and($balance->held->nanos)->toBe(12_500_000)
        ->and($balance->available()->nanos)->toBe(20_000_000 - 12_500_000);
});

it('raises the hold on the first rates seen on a queued snapshot too', function () {
    $generation = heldGeneration();

    app(ApplyGenerationSnapshot::class)->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Queued, ['rates' => textRates(10_000)]),
    );

    expect($generation->refresh()->maximum_charge_nanos)->toBe(12_500_000);
});

it('never lowers the hold when the generation rates are lower', function () {
    $generation = heldGeneration();

    app(ApplyGenerationSnapshot::class)->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Processing, ['rates' => textRates(2_500)]),
    );

    $generation->refresh();

    expect($generation->maximum_provider_cost_nanos)->toBe(5_000_000)
        ->and($generation->maximum_charge_nanos)->toBe(6_250_000);
});

it('only raises the hold on the first rates, not on later snapshots', function () {
    $generation = heldGeneration();
    $apply = app(ApplyGenerationSnapshot::class);

    $apply->handle($generation->id, pixelforgeSnapshot($generation, GenerationStatus::Queued, ['rates' => textRates(5_000)]));
    $apply->handle($generation->id, pixelforgeSnapshot($generation, GenerationStatus::Processing, ['rates' => textRates(10_000)]));

    expect($generation->refresh()->maximum_charge_nanos)->toBe(6_250_000);
});

it('leaves the hold alone on a legacy generation without maximum tokens', function () {
    $generation = heldGeneration(['maximum_tokens' => null]);

    app(ApplyGenerationSnapshot::class)->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Processing, ['rates' => textRates(10_000)]),
    );

    expect($generation->refresh()->maximum_charge_nanos)->toBe(6_250_000);
});

it('backfills the markup from the ledger row of a charged generation', function () {
    $charged = Generation::factory()->create(['status' => GenerationStatus::Succeeded, 'markup_percent' => '99']);
    LedgerEntry::query()->create([
        'generation_id' => $charged->id,
        'user_id' => $charged->user_id,
        'provider_cost_nanos' => 10,
        'charge_nanos' => 13,
        'markup_percent' => '30',
    ]);
    $queued = Generation::factory()->create(['status' => GenerationStatus::Queued, 'markup_percent' => '99']);
    config(['billing.markup_percent' => '17.5']);

    $migration = require database_path('migrations/2026_11_17_200000_add_markup_and_maximum_tokens_to_generations_table.php');
    $migration->backfill();

    expect($charged->refresh()->markup_percent)->toBe('30')
        ->and($queued->refresh()->markup_percent)->toBe('17.5');
});

it('refuses a second generation that fitted only the old hold once the hold is raised', function () {
    Queue::fake();
    Cache::put(GetCachedPricing::CACHE_KEY, SamplePricing::pricing()->toCacheArray(), 300);
    // The old hold of 6 250 000 plus the worked example maximum of 467 343 750.
    $user = User::factory()->withCredit('0.47359375')->create();
    $generation = heldGeneration(['user_id' => $user->id]);
    $createWorkedExample = fn () => app(CreateGeneration::class)->handle(
        $user,
        str_repeat('a', 400),
        ImageSize::Landscape,
        ImageQuality::High,
        ['https://example.com/a.jpg', 'https://example.com/b.jpg'],
    );

    expect(app(GetCreditBalance::class)->handle($user)->available()->nanos)->toBe(467_343_750);

    app(ApplyGenerationSnapshot::class)->handle(
        $generation->id,
        pixelforgeSnapshot($generation, GenerationStatus::Processing, ['rates' => textRates(10_000)]),
    );

    expect($createWorkedExample)->toThrow(InsufficientCredits::class)
        ->and(Generation::query()->count())->toBe(1);
});
