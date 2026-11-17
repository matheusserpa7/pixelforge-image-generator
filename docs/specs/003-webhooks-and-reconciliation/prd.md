# 003 Webhooks and reconciliation: PRD

Status: **implemented** · 2026-11-16

## Problem

A submitted generation reaches Pixelforge and then stays `queued` locally forever. Nothing listens for
the provider's webhooks, so a generation never moves to `processing`, never succeeds, never gets charged
and never shows a failure. Webhooks are also untrusted input: anyone can POST to the URL, the provider
delivers the same event more than once and in any order, an event can arrive before our submit call
returns, and a delivery can be lost for good after six attempts.

## Users

A signed-in user who submitted a generation: their generation must reach its real final state and be
charged exactly once, or cost nothing if it failed. They still have no page to watch it (spec 004).

Operators, indirectly: a stored trail of every received event, and a generation that never stays stuck
because one webhook was lost.

## In scope

- An endpoint that receives Pixelforge webhooks. It accepts only deliveries signed with this
  environment's secret and recent enough, and refuses everything else.
- Every accepted event is recorded once. A second delivery of the same event is acknowledged and does
  nothing else.
- The endpoint answers quickly and the work happens in the background.
- Processing an event moves the generation forward to the state the event shows, charges a success once
  from that generation's own usage and rates, and records a failure at no cost. Late or out-of-order
  events change nothing. This holds when the event arrives before the submit call has returned.
- Each recorded event keeps what processing did with it (applied, ignored, not ours, unreadable) so an
  operator can tell why an event changed nothing.
- `ping` test events from the provider dashboard are accepted and recorded and change nothing.
- A scheduled safety net: generations that have not moved for a while are asked about at the provider
  and brought to their real state through the same rules as webhooks. It respects the provider limits
  shared with submission.
- A generation whose submission job was lost (still `queued`, never accepted by the provider, untouched
  for ten minutes) is submitted again. The provider's idempotency key guarantees no duplicate.

## Out of scope

- Downloading the image to our storage and refreshing an expired image URL (spec 004, with the page that
  shows it).
- Any page or live status on screen (spec 004).
- Replaying stored events by hand, or an admin view of events.
- Changing how a snapshot is applied, how money is computed, or how submission retries work (spec 002).
- Repairing a generation already failed locally that the provider later reports as succeeded. Final
  states stay final; this is a known gap from spec 002.

## Acceptance

1. A delivery with no signature, a wrong signature, a signature from another secret, or a timestamp more
   than 300 seconds from now is refused with `401`. Nothing is recorded and nothing changes.
2. A correctly signed delivery whose body is not a readable event (no event id or no type) is refused
   with `400`. Nothing is recorded.
3. A correctly signed event is recorded and answered with `2xx`. The same event delivered again is
   answered with `2xx`, is not recorded again and is not processed again.
4. `processing`, then `succeeded` with usage, moves the generation to `succeeded` and writes one charge
   equal to the generation's own usage times its own rates plus markup. Receiving `succeeded` twice,
   or `processing` after `succeeded`, leaves one charge and status `succeeded`.
5. `failed` moves the generation to `failed` with a readable reason and no charge. A later `succeeded`
   for that generation changes nothing.
6. An event that arrives while the local generation does not yet know its provider id still reaches that
   generation, through the local id we sent in metadata.
7. An event for a generation we do not have, a `ping`, and an event whose generation object cannot be
   read are recorded with that reason and change no generation.
8. A generation accepted by the provider and unchanged for longer than the reconciliation threshold
   (default two minutes) is fetched from the provider and brought to its real state, charged once if it
   succeeded. A generation the provider does not know is failed at no cost. The fetch counts against the
   same concurrency and per-minute limits as submission.
9. A generation still `queued`, without a provider id and unchanged for ten minutes, is submitted again
   and does not create a second generation at the provider.
10. The safety net runs every minute in the scheduler and never asks about the same generation twice
    within the threshold.

## Open questions

- Should the image download (ADR 0013) be triggered from the success path here or in spec 004? This
  draft leaves it to 004, which also shows the image.
