# 0013. Store images on a Laravel disk

Status: accepted (2026-11-16)

## Context

`image_url` expires 24 hours after completion.

## Decision

After success, a job downloads the PNG to a Laravel filesystem disk (`local` now, any S3-compatible
disk later through configuration). The UI serves our copy and falls back to the Pixelforge URL while
the download is pending.

## Consequences

- History keeps showing images after 24 hours.
- Storage grows with usage; acceptable for this scope.
- The copy lives on `GENERATION_IMAGES_DISK` (default `local`) at `generations/{id}.png`, written by
  a job dispatched after the success commits. It is not a Pixelforge API call and takes no limiter.
- Images are served only through `GET /generations/{id}/image`, authorized by the policy: our copy,
  else a redirect to the provider URL while it is valid, else `404` ("Image expired" in the UI).
- Only successes after spec 004 are copied; a lost download is not re-dispatched.
  Spec: [004-generate-and-history-ui](../specs/004-generate-and-history-ui/).
