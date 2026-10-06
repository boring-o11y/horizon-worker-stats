# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-10-06

### Added

- Both charts are broken down by supervisor: each is a stacked area chart with one band per supervisor, whose top edge is the fleet total. The legend shows each supervisor's latest value, and the tooltip lists every supervisor at the hovered point.
- Supervisors are grouped by the name in your Horizon config, so one keeps its band across Horizon restarts and across machines, including supervisor names that contain a colon. History recorded before upgrading is broken down too.
- Up to eight supervisors each get their own band. Past that, the eight using the most keep theirs and the rest fold into a grey "Other" band.
- A supervisor keeps its colour between refreshes for as long as it is in the window, and only gives its band up to one using clearly more.
- The charts follow Horizon's light and dark themes, and redraw when the theme is switched.
- The page's inline script and styles carry the nonce set with `Horizon::cspNonce()`, so the page works under a nonce-based Content Security Policy.
- The `api/worker-stats` response includes a `supervisors` breakdown, and its `memory` and `cpu` totals add up exactly to it.

### Changed

- The page stops polling while its browser tab is hidden and refreshes as soon as it is shown again.
- A refresh is skipped while the previous one is still loading, and a request is abandoned after one poll interval, so a slow response can no longer overwrite newer data.
- The tooltip sits beside the cursor, on whichever side it fits, instead of covering the point it describes.

## [0.1.0] - 2026-09-29

Initial release.

### Added

- A **Worker Stats** page in the Horizon dashboard, with a sidebar link, charting the resident memory and CPU cores used by every Horizon worker on every machine, summed, over the last 24 hours.
- Sampling inside Horizon's supervisors at most every 10 seconds, reading `/proc` on Linux and falling back to `ps` elsewhere, with nothing written on the job path.
- History kept in one small, self-expiring Redis hash per chart bucket, on Horizon's own connection and key prefix.
- The `horizon-worker-stats:clear` command to wipe the history.
- Configuration for the page path, sidebar label, bucket interval, retention and poll interval, and a switch to turn the package off entirely.
- The page and its `api/worker-stats` endpoint run behind the same middleware and `viewHorizon` gate as the rest of the dashboard, and keep the request scheme on a dashboard served from its own domain.

[0.2.0]: https://github.com/boring-o11y/horizon-worker-stats/compare/8abfa2c...v0.2.0
[0.1.0]: https://github.com/boring-o11y/horizon-worker-stats/tree/8abfa2c
