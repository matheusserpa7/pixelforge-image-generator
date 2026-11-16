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
