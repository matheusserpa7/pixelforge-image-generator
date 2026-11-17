# 004 Generate and history UI: PRD

Status: **implemented** · 2026-11-16

## Problem

Everything behind a generation works: it is priced, submitted under the provider's limits, brought to
its final state by webhooks or polling, and charged once. A user still cannot see any of it. There is no
page to write a prompt, no price before submitting, and no list of past generations with their status,
cost and image. The dashboard is the starter kit's placeholder.

Pixelforge's image links also stop working 24 hours after completion, so a history that only links to
them loses every image after a day.

## Users

A signed-in user who wants to generate images, see what each one costs before and after, and find
their past images later.

## In scope

- One page, the user's home after login, with the generate form on top and their history below.
- The form: a prompt (up to 4,000 characters, with a counter), a size, a quality, and up to four
  reference image URLs that can be added and removed.
- Before submitting, the form shows the estimated charge and the maximum possible charge, markup
  included, and updates them as the inputs change. It says that the real charge is known when the image
  is ready, that it will not exceed the maximum unless Pixelforge changes its prices, and that failed
  generations are free.
- When prices cannot be read, the form says so and cannot be submitted.
- Submitting adds the generation to the top of the history as queued and confirms it with a short
  message. Submitting too often is refused with a message that says when to try again. Invalid input
  is shown next to the field.
- The history lists only the user's own generations, newest first, 20 per page. Each one shows the
  prompt, size, quality, number of reference images, when it was submitted, its status, the estimate
  and maximum, the actual charge once it succeeded, the reason and "no charge" if it failed, and the
  image once it is ready.
- Status updates on screen without reloading while any listed generation is still queued or
  processing, and stops checking when all are final.
- A queued generation explains that it is waiting for the image service and that busy periods take
  longer. A processing one says it is being generated.
- Every succeeded image is copied to our own storage so the history keeps showing it after Pixelforge's
  link expires. Until the copy exists, the provider's link is used. If neither is available, the
  history says the image expired.
- Images are visible only to their owner.
- The sidebar leads to this page. The starter kit's placeholder dashboard and its external links go
  away.

## Out of scope

- Credit balances and blocking generations a user cannot afford (spec 007).
- The operator report (spec 006).
- Deleting, retrying or editing a generation from the UI.
- Uploading reference images; they are URLs.
- Copying images of generations that succeeded before this spec ships, beyond what a later success
  triggers.
- Search or filters in the history.

## Acceptance

1. A guest is sent to login. After login the user lands on this page.
2. With the published example (high, 1536×1024, a 400-character prompt, two reference images, 25%
   markup) the form shows an estimate of `$0.4126` and a maximum of `$0.4673`, and the exact amounts
   (`0.412562500` and `0.467343750`) are available on hover. Changing quality or size updates both.
3. When pricing is unavailable and nothing is cached, the form shows "Pricing is unavailable. Try again
   in a moment." and the submit button is disabled. Submitting anyway is refused with that message.
4. A valid submission appears at the top of the history as queued with a confirmation. A fifth
   reference URL or a non-http(s) URL is shown as an error on that field and nothing is stored.
   Submitting more than 10 times in a minute shows a "try again in N seconds" message and stores
   nothing.
5. A user never sees another user's generation or image, in the list or by guessing its address.
6. A succeeded generation shows its actual charge and its image. A failed one shows its reason and
   "No charge". Neither shows provider cost, profit, token counts or rates.
7. While a generation is queued or processing, its status changes on screen without a reload; once
   every generation on the page is final, the page stops asking.
8. After success, the image is copied to our storage; the image still shows when the provider's link
   has expired. A generation whose link expired before the copy shows "Image expired".

## Open questions

None.
