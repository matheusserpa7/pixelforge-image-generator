# 004 Generate and history UI: tech spec

Status: **implemented** · 2026-11-16 · PRD: [prd.md](prd.md)

Implements ADR 0006 (estimate shown before submit), ADR 0013 (image storage), ADR 0014 (owner-only
reads) and ADR 0015 (Inertia polling) on top of specs 001, 002, 003 and 008. The provider contract is
[docs/product/pixelforge-api.md](../../product/pixelforge-api.md).

No new ADR. At close, the main session adds to ADR 0013's consequences: images are served through an
authorized route, the copy lives on `GENERATION_IMAGES_DISK` (default `local`) at
`generations/{id}.png`, and only successes after this spec are copied. Add to ADR 0015: the page polls
every 3 s, only the `generations` prop, only while a listed generation is not final. Roadmap: 004
`Done`, 005 `Next`. README status line. `docs/product/` and `CLAUDE.md` do not change. Do not edit
`docs/product/`, `docs/adr/`, `docs/roadmap.md`, `README.md` or `CLAUDE.md` in the tasks.

## Technical context

- `POST /generations` (`generations.store`, `auth`) validates with `StoreGenerationRequest`, calls
  `CreateGeneration`, and on `PricingUnavailable` returns `back()->withErrors(['pricing' => ...])`. On
  success it redirects to `dashboard` with flash `generation_id`. The `generations` rate limiter
  (10 per minute per user) exists in `AppServiceProvider` and is not attached yet.
- `GetCachedPricing` + `CostEstimator::estimate(...)` produce a `CostEstimate` (`estimatedCharge`,
  `maximumCharge`, provider costs too). `Money::toDecimalString()` gives 9 decimals;
  `Money::format(4)` gives `"$0.4126"` (half-up, display only).
- `ApplyGenerationSnapshot::applySucceeded` is where a generation becomes `succeeded`, inside a
  transaction with the row locked. It stores `image_url` and `image_url_expires_at`.
- `Generation::scopeForUser`, `GenerationPolicy::view` / `viewAny` exist.
- Front end: Vue 3 + Inertia v3 (`@inertiajs/vue3` exports `useForm`, `useHttp`, `usePoll`), Wayfinder
  route helpers in `resources/js/routes` and `resources/js/actions` (regenerate with
  `php artisan wayfinder:generate --with-form` inside the `app` container after adding routes; without
  `--with-form` the `.form` variants disappear and `vue-tsc` fails; both folders are gitignored), shadcn-vue
  components in `resources/js/components/ui`, toasts through `Inertia::flash('toast', [...])` and
  `resources/js/lib/flashToast.ts`. There is no JS unit-test runner; `vue-tsc`, ESLint and Prettier are
  the front-end bar.
- `config/filesystems.php` has the `local` disk (private). Fortify `home` is `/dashboard`.
- Feature tests: MySQL `testing`, `RefreshDatabase`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`. A
  job dispatched `afterCommit()` inside a transaction does not run in a feature test; assert it with
  `Queue::fake()`. `Http::preventStrayRequests()` everywhere; never call `pixelforge.promptedit.dev`.

### Decisions this spec locks

- One page: `GET /generations` (`generations.index`) renders `generations/Index`. `dashboard` stays as
  a named route that redirects there, and Fortify `home` becomes `/generations`.
- The estimate comes from the server, never computed in JavaScript: `POST /generations/estimate`
  (`generations.estimate`) answers JSON. It is a POST so the prompt never lands in a URL or access log.
- Money reaches the browser only as `{ amount: "0.412562500", display: "$0.4126" }`, built on the server
  from `Money`. The front end never does arithmetic on money.
- The browser never receives provider cost, profit, `provider_id`, usage, rates, `error_code` or the raw
  Pixelforge image URL in props. Images are always requested through
  `GET /generations/{generation}/image`, which may redirect to the provider URL as a fallback.
- The image copy is a queued job dispatched `afterCommit()` from the succeeded transition. It is not a
  Pixelforge API call: it does not take the provider limiter and sends no API key.
- `image_path` is written only by the download job. `ApplyGenerationSnapshot` stays the only writer of
  provider fields.

## Model and contracts

All PHP files `declare(strict_types=1)`, classes `final`, no floats.

### Migration (task T1)

`add_image_path_to_generations_table`: `image_path` string 255, nullable, after `image_url_expires_at`.
`down()` drops it. Not fillable.

### Config (task T1)

`config/filesystems.php`: top-level key `generation_images_disk` from `GENERATION_IMAGES_DISK`, default
`local`. Commented line in `.env.example`. Read as `config()->string('filesystems.generation_images_disk')`.

### `DownloadGenerationImage` job (task T1)

`App\Jobs\DownloadGenerationImage implements ShouldQueue, ShouldBeUnique` (`Queueable`). Constructor
`public string $generationId`. `uniqueId()` is the generation id, `$uniqueFor = 900`, `$tries = 5`,
`backoff(): array` returns `[10, 60, 300, 900]`, `$timeout = 60`. Constants `MAX_BYTES = 20 * 1024 *
1024`, `PNG_SIGNATURE = "\x89PNG\r\n\x1a\n"`.

`handle(): void`:

1. Load the generation. Return when it is missing, not `succeeded`, `image_path` is not null, or
   `image_url` is null.
2. If `image_url_expires_at` is not null and not in the future → log `info` "Generation image expired
   before download." with `generation_id`, return.
3. `Http::timeout(30)->get($generation->image_url)`. A connection exception or a non-2xx response →
   throw a `RuntimeException` ("Generation image download failed.") so the queue retries. Never put the
   URL in the message or the logs (it is signed).
4. Body longer than `MAX_BYTES`, or not starting with `PNG_SIGNATURE` → log `warning` "Generation image
   rejected." with `generation_id`, return without storing (the provider URL remains the fallback).
5. `Storage::disk(<disk>)->put("generations/{$id}.png", $body)`. Then
   `Generation::query()->whereKey($id)->whereNull('image_path')->update(['image_path' => $path])`.

`failed(Throwable $e)`: log `warning` "Generation image download gave up." with `generation_id` only.

### Dispatch (task T1)

In `ApplyGenerationSnapshot::applySucceeded`, after the save, `DownloadGenerationImage::dispatch($generation->id)->afterCommit();`.
Only on the transition to `succeeded`; every other branch dispatches nothing.

### Image route (task T1)

`GET /generations/{generation}/image`, name `generations.image`, middleware `auth` and
`can:view,generation`. Invokable `GenerationImageController::__invoke(Generation $generation)`:

1. `image_path` not null and the file exists on the disk → `Storage::disk(...)->response($path,
   "{$id}.png", ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=86400'])`.
2. Else, `status` succeeded, `image_url` not null, and `image_url_expires_at` null or in the future →
   `redirect()->away($generation->image_url)`.
3. Else `abort(404)`.

A non-owner gets `403` from the policy; a guest is redirected to login.

### `MoneyView` and `GenerationResource` (task T2)

`App\Http\Resources\MoneyView` is not a resource: a static `MoneyView::of(int $nanos): array{amount:
string, display: string}` using `Money::ofNanos($nanos)->toDecimalString()` and `->format(4)`.

`App\Http\Resources\GenerationResource extends JsonResource`, `toArray` returns exactly:

| Key | Value |
| --- | --- |
| `id` | ULID |
| `status` | enum value |
| `is_final` | `status->isFinal()` |
| `prompt` | string |
| `size`, `quality` | enum values |
| `reference_image_count` | `count(reference_images ?? [])` |
| `created_at` | ISO 8601 UTC (`toIso8601ZuluString()`) |
| `estimated_charge`, `maximum_charge` | `MoneyView::of(...)` of the stored estimate nanos |
| `charge` | `MoneyView::of(charge_nanos)` when succeeded and not null, else null |
| `error_message` | when failed, else null |
| `image_url` | `route('generations.image', $id)` when succeeded and (`image_path` not null, or `image_url` not null and not expired), else null |
| `image_expired` | true when succeeded, `image_path` null, and the provider URL is null or expired |

Nothing else. In particular no provider cost, `provider_id`, `usage`, `rates`, `error_code`, raw
`image_url`, `submit_attempts` or `user_id`.

### Routes and controller (task T2)

In `routes/web.php`, inside `auth`:

| Method | Path | Name | Action | Extra middleware |
| --- | --- | --- | --- | --- |
| GET | `generations` | `generations.index` | `GenerationController@index` | — |
| POST | `generations` | `generations.store` | `GenerationController@store` | `throttle:generations` |
| POST | `generations/estimate` | `generations.estimate` | `GenerationController@estimate` | `throttle:generation-estimates` |
| GET | `generations/{generation}/image` | `generations.image` | `GenerationImageController` (T1) | `can:view,generation` |
| GET | `dashboard` | `dashboard` | `Route::redirect` to `/generations` | — |

Register `generation-estimates` in `AppServiceProvider`: 60 per minute per user id. Change Fortify
`home` to `/generations`.

Both form requests define `attributes()` so validation messages read well: `reference_images` is
`reference images` and `reference_images.*` is `reference image URL` (for example "The reference image
URL field must be a valid URL.").

`index(Request $request, #[CurrentUser] User $user)`: `Gate::authorize('viewAny', Generation::class)`,
then `Generation::query()->forUser($user)->orderByDesc('created_at')->orderByDesc('id')->paginate(20)`,
render `generations/Index` with `generations` = `GenerationResource::collection($paginator)` and
`options` = `{ sizes: [{value, label}], qualities: [{value, label}] }` (labels `Square (1024×1024)`, `Landscape
(1536×1024)`, `Portrait (1024×1536)`, `Low`, `Medium`, `High`) and `maxReferenceImages` = 4,
`maxPromptLength` = 4000.

`store`: unchanged validation and action. On success `Inertia::flash('toast', ['type' => 'success',
'message' => 'Generation queued.'])` and `redirect()->route('generations.index')`. `PricingUnavailable`
unchanged.

`estimate(EstimateGenerationRequest $request, EstimateGeneration $estimate): JsonResponse`. New action
`App\Actions\EstimateGeneration::handle(string $prompt, ImageSize $size, ImageQuality $quality, int
$referenceImageCount): CostEstimate` = `GetCachedPricing` then `CostEstimator::estimate` with
`(string) config('billing.markup_percent')`. Response `200` `{ "estimated_charge": MoneyView,
"maximum_charge": MoneyView }`. `PricingUnavailable` → `503` `{ "message": <its message> }`. Nothing
is stored and `PixelforgeClient::create` is never called.

`EstimateGenerationRequest`: `prompt` nullable string max 4000 (null → `''`); `size` and `quality`
required enums; `reference_images` nullable array max 4, items string max 2048 (no URL rule: the
estimate depends only on the count). Authorize `create` on `Generation`. Validation failure → `422`
JSON (the request expects JSON).

### Throttled submit (task T2)

In `bootstrap/app.php` `withExceptions`, render
`Illuminate\Http\Exceptions\ThrottleRequestsException` for a request whose route is
`generations.store` and that is not JSON as `back()->withErrors(['generation' => "You are submitting
too fast. Try again in {$seconds} seconds."])`, where `$seconds` is the exception's `Retry-After`
header (fallback 60). Other routes keep the default rendering.

## Behaviour in code

### Page (task T3)

`resources/js/pages/generations/Index.vue`, layout with breadcrumb "Generations". Props typed in
`resources/js/types/generations.ts` (`MoneyView`, `Generation`, `Paginated<T>`, `Option`).

**Form** (`components/generations/GenerateForm.vue`), `useForm({ prompt: '', size: '1024x1024',
quality: 'medium', reference_images: [] as string[] })`, posted with the Wayfinder `store` helper,
`preserveScroll`, reset on success.

- Textarea for the prompt with a live counter `n / 4000`; the `maxlength` attribute is 4000.
- Selects for size and quality from `options`.
- Reference URLs: a list of URL inputs with a remove button each and an "Add reference image" button
  hidden at 4. Errors for `reference_images.N` show under that input.
- `InputError` under each field; an `Alert` for `pricing` and `generation` errors.
- **Estimate box**: a separate `useHttp('post', estimate.url(), {...})`. Re-request 400 ms after the
  last change of prompt, size, quality or the reference count, cancelling the previous request. Shows
  "Estimated cost **$0.4126**" and "Maximum **$0.4673**", each with `title` = the 9-decimal amount, and
  the note: "You pay the actual cost once the image is ready, never more than the maximum unless
  Pixelforge changes its prices. Failed generations are free." While loading, keep the last values with
  a spinner. On `503`, show the response `message` in an `Alert` and disable submit. On `429`, show
  "Estimate paused, try again in a moment." and keep submit enabled. On `422`, show nothing extra (the
  field errors come from submit).
- The submit button reads "Generate" and is disabled while processing or while pricing is unavailable.

**History** (`components/generations/GenerationCard.vue` in a responsive grid; pagination links
below using `generations.links` / `meta`):

- Image area at the aspect ratio of `size`: `<img loading="lazy">` from `image_url`; on `error` or when
  `image_url` is null show a placeholder: "Image expired" when `image_expired`, a spinner while
  `queued`/`processing`, "No image" when failed, "Image unavailable" on load error.
- Status badge: Queued, Processing, Succeeded, Failed (distinct variants).
- Status line: queued → "Waiting for the image service. Busy periods take longer."; processing →
  "Generating, usually 5 to 30 seconds."; failed → `error_message` and "No charge.".
- Cost: not final → "Estimate $x · up to $y"; succeeded → "Charged $z" and "Estimate was $x"; failed →
  "No charge". Every amount has the exact `title`.
- Prompt clamped to three lines with the full prompt in `title`; meta line "1536×1024 · high · 2
  references · 5 minutes ago" (relative time with `Intl.RelativeTimeFormat`, absolute in `title`).
- Empty state: "No generations yet. Write a prompt above to create your first image."

**Polling**: `const { start, stop } = usePoll(3000, { only: ['generations'] }, { autoStart: false })`.
A `watch` on the generations (immediate) starts polling when any item has `is_final === false` and stops
it otherwise. The form state is not touched by polling.

**Navigation**: `AppSidebar.vue` main nav has one item "Generations" → `generations.index` (an image
icon from `@lucide/vue`); remove the "Repository" and "Documentation" footer links; logo link goes to
`generations.index`. `AppHeader.vue` gets the same nav item and logo link. Delete `pages/Dashboard.vue` and `components/PlaceholderPattern.vue` if nothing
else imports it.

UI copy is English.

## Tests

Pest. Compare money as strings. Use `assertInertia` for props.

| Test | Task | Proves |
| --- | --- | --- |
| `tests/Feature/Jobs/DownloadGenerationImageTest.php` | T1 | `Storage::fake()` on the configured disk, `Http::fake()` with a PNG body (signature + bytes): stores `generations/{id}.png` and sets `image_path`; a second `handle()` sends no request. Expired `image_url_expires_at` sends no request and stores nothing. A non-PNG body and a body over `MAX_BYTES` (fake a string just over) store nothing, leave `image_path` null and do not throw. A `500` and a connection exception throw. Not `succeeded` or null `image_url` sends nothing. `uniqueId()` is the id. |
| `tests/Feature/Generations/ApplyGenerationSnapshotTest.php` | T1 | With `Queue::fake()`: the succeeded transition pushes `DownloadGenerationImage` for that id; `processing`, `failed`, `incomplete` and a repeated success push nothing new. |
| `tests/Feature/Generations/GenerationImageTest.php` | T1 | Owner with a stored file gets `200`, `image/png`, the bytes, `private` cache header. Another user gets `403`; a guest is redirected to login. No `image_path` and a valid provider URL → redirect to that URL. Expired URL and no copy → `404`. Queued → `404`. |
| `tests/Feature/Generations/GenerationIndexTest.php` | T2 | Guest → login. Renders `generations/Index` with only the user's rows, newest first, 20 per page. A succeeded row with `charge_nanos` 412562500 has `charge.amount` `0.412562500` and `charge.display` `$0.4126`; `estimated_charge` and `maximum_charge` match the stored nanos. The item has exactly the keys in the resource table (assert the key set). A failed row has `error_message`, null `charge`. `image_url` is the image route for a succeeded row with `image_path`, null for queued; `image_expired` is true for a succeeded row with an expired URL and no copy. `options`, `maxReferenceImages`, `maxPromptLength` are present. `dashboard` redirects to `generations.index`. |
| `tests/Feature/Generations/EstimateGenerationTest.php` | T2 | The worked example returns `0.412562500` / `$0.4126` and `0.467343750` / `$0.4673`. Empty prompt is accepted. Five references or an unknown size → `422`. Empty cache and a pricing `503` → `503` with the message. Nothing is stored and the fake's `created` is empty. Guest → `401`. The 61st call in a minute → `429`. |
| `tests/Feature/Generations/CreateGenerationTest.php` | T2 | Success now redirects to `generations.index` and flashes the toast. The 11th submit in a minute redirects back with the `generation` error naming the seconds and stores nothing. Existing cases stay green. |
| `tests/Feature/DashboardTest.php` | T2 | Updated: guests → login; users → redirect to `generations.index`. |
| Login redirect (existing auth test) | T2 | Update the expected home to `/generations` where a test asserts it. |

T3 has no PHP test beyond the bar (`vue-tsc`, ESLint, Prettier). After T3 the main session checks the
page in the browser on the dev stack. The dev `.env` has no Pixelforge API key: pricing is public, so
the live estimate is real, and a submission ends `failed` with the API-key message at no charge, which
exercises the queued → failed path and polling. The check covers the form, the worked-example
estimate, validation errors, a submission, and a history seeded with factory rows in each status.

## Risks

- The image URL may be served by the same host and count toward the provider's per-minute limit. The
  download is one request per success and its `429` is retried by the job's backoff; if this proves
  wrong, route it through `PixelforgeRequestLimiter`.
- A lost or failed download leaves the provider URL as the image for 24 hours and then "Image
  expired". Nothing re-dispatches it; a later spec can add it to reconciliation.
- Polling every 3 s with 20 items is cheap, but a user with many tabs multiplies it. Polling stops when
  everything on the page is final.
- The estimate endpoint lets a signed-in user read the cached pricing at 60 requests per minute. It
  never calls Pixelforge more than once per cache lifetime.
- `redirect()->away()` to the signed provider URL reveals that URL to the owner only, for their own
  image, which they could already open on Pixelforge's terms.
