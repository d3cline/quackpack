# Release 0.5.1 — uploads and security fixes

Repairs phone-photo preparation for posts and avatars, bounds inbox key fetching and storage, enforces existing-object and reaction ownership, isolates replay receipts by actor, verifies foreign-origin discovery and API posts, and keeps saved browsing destinations private. Upgrade through the existing repair command; owner data and identity are preserved while disposable display caches are rebuilt. See VALIDATION.txt for tested scope.

# Release 0.5.0 — the ActivityPub browser

Adds an ActivityPub-first public reader, NodeInfo platform detection, server/person/community/channel cards, and native public index/profile adapters for Mastodon-compatible servers (including Pixelfed), Misskey relatives, PeerTube and Lemmy API v3. Closed whole-server feeds can expose public recommendations and directories instead of ending in a token prompt. Generic profiles/posts/collections support embedded and linked objects, pagination, Announce, articles, video/audio previews, polls and events. Existing identity, publishing, themes and managed PHP deployment remain intact.

This release is validated with protocol fixtures and DOM/FastCGI tests. Network requests to public endpoints during this build timed out; no new live multi-platform acceptance or universal interoperability claim is made. Native endpoint versions and server permissions vary. Lemmy v4-only indexes use public ActivityPub URLs rather than an implemented v4 adapter. Playback and voting open the original app. See README and VALIDATION.txt.

# Release 0.4.1 — discovery, access errors and themes

Adds saved Auto/Light/Dark palettes, homepage ActivityPub content negotiation and alternate links, interoperable extension contexts, precise remote error messages, Mastodon authentication-required 422 handling with public highlights fallback, and crawler access classification. Owner delivery status, public endpoint checks and an explicit recent-post resend support diagnosing and recovering federation without changing identity or server configuration.

The build environment reproduced mastodon.social's anonymous 422 and verified its public directory/highlights endpoints. Requests to the user's Quackpack domain failed from this environment; no live Mastodon Follow or post delivery is claimed. Queue/signature round trips, Follow/Accept/Create/Update/Delete and resend are verified using controlled PHP peer fixtures. See VALIDATION.txt for the tests run for this release.

# Release boundary — 0.4.0

Quackpack 0.4.0 is a working first implementation. It is not presented as a production-mature or fully interoperable ActivityPub server.

0.4.0 ports the core Mastodon Spider discovery, persistent frontier, retries, top-sample profile watching and published block-evidence monitoring into PHP. The existing minute worker now also collects public posts and maintains telemetry. A private master feed ranks recent discoveries with source variety and owner signals, or serves Newest order. The front end exposes actual crawler status/history, pause/resume, added sources and evidence. No automatic follows are performed. Public profile links and the chronological journal are preserved. The repair command upgrades a running HOME deployment and applies the queue schema without changing identity/configuration.

No complete ActivityPub server catalog or universal public-timeline API exists in this implementation. Coverage uses Mastodon-compatible feeds and known public actor outboxes; native platform-specific adapters, inaccessible resources and complete network coverage are outside this release. Public network crawling has not been exercised in the build environment; deterministic protocol fixtures cover the discovery paths. See README for operational limits.

0.3.2 fixes missing-dotfile installation and CLI version selection. A missing `.user.ini` is generated automatically. The executable launcher checks the system interpreter before loading modern PHP and can select a versioned CLI or `PUP_PHP`. Existing supported explicit interpreters are retained. Installation and repair report the actual PHP binary and pin it in cron. `repair` finishes an interrupted 0.3.1 HOME deployment without replacing configuration, keys, password, database contents or media; the old runtime is retained privately. The exact 0.3.1 missing-dotfile failure is reproduced and recovered in regression tests.

0.3.1 introduced the corrected managed Opalstack layout. Fresh installs move the implementation, worker, credentials, database, media and sessions under `$HOME/quackpack`, leaving public entrypoints and assets in the app docroot. Executable PHP query URLs and real discovery directories remove the need for rewrites, `.htaccess`, custom Nginx configuration or a `public/` mapping. Existing configuration without a routing mode retains its original IDs; migration of a live pretty-URL installation is not automated.

0.2.1 adopts the Quackpack name and a three-duck logo, adds `bin/quackpack`, and retains existing configuration, identities and `bin/pup` compatibility.

0.2.0 adds a public link stack above the feed and an owner-only editor in Your space. Link order, editing, visibility, deletion, URL validation, public HTML escaping and export are covered by the focused web/DOM checks. Existing identities, posts and configuration are retained; no new tables are needed.

Local verification covers schema creation/upsert/rollback, owner-only routes, public pagination and identity endpoints, signing and signature tampering, duplicate delivery, Follow/Accept/Undo, public-only ingestion, author/origin binding, Update/Delete, replies and reactions, media decoding/resizing, tombstones, queue processing and installation safeguards. The final packaged test results are in `VALIDATION.txt`.

No real domain was provisioned and no follow, like, reply or post was sent to another person's server during development. Browser visual testing was unavailable in the build environment. MySQL/MariaDB runtime verification is recorded separately from SQLite; do not infer a check passed unless it appears in `VALIDATION.txt`.

Before a production release, run a public two-peer acceptance pass on a disposable test domain:

1. Set the web PHP and cron PHP to the same supported version; confirm all required extensions in both runtimes. Confirm HTTPS, directory indexes, executable query URLs and unknown-path 404 responses.
2. Fetch WebFinger, the actor, a post and its outbox with ActivityPub Accept headers from outside the host. No protected file under the project root or private data directory may be served.
3. From a test Mastodon and a test Pixelfed account, resolve the Quackpack handle and follow it. Check that the signed Follow is accepted and new photos reach those accounts.
4. Edit and delete a photo, confirming peer updates/tombstones and local media removal. Like and unlike a public remote photo; publish and receive a public reply.
5. Follow an account, wait for Accept, and confirm only new public deliveries appear. Verify malformed signatures, invalid ownership and private messages are rejected or ignored.
6. Try a public server, an API restricted server with/without your read token, a photo hashtag, a public post URL, and an outbox restricted actor. Check that failure states preserve the browsing task.
7. Pause a test peer, publish, resume it, and verify queued retries. Kill and rerun the worker and confirm replay handling. Validate permissions and log retention.
8. Check mobile and desktop UI in a real browser, keyboard focus and dialog behavior, slow images, screen-reader labels, multiple upload failures, and a 16-megapixel photo under your PHP memory limit.
9. Restore a private backup onto the same test hostname, verifying keys and saved state. Exercise the MySQL/MariaDB configuration on your intended production database release.

Known first-release limits are explicit in README. Changes needed for a peer should be driven by captured delivery failures and protocol tests, without expanding the product's two jobs.
