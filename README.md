# Quackpack

**Your photos. Your domain. A wider world.**

Quackpack is a PHP photo journal and personal Fediverse browser. One installation is one person and one ActivityPub actor. There is no registration, user administration, role system, or separate admin application. Sign in as the owner to publish, browse, follow and save. Visitors see your public journal.

**Version 0.5.0: working first implementation, pre-production.** Local tests are not a substitute for interoperability testing on public HTTPS domains. See [docs/RELEASE.md](docs/RELEASE.md) for the exact validation boundary.

## Two jobs, done deliberately

**Keep a photo journal.** Up to ten images per moment, captions and hashtags, alt text, content warnings, photo carousels, a grid or reading view, stable permalinks, profile editing, post editing and deletion. A chronological journal; no recommendations or engagement ranking. Images are decoded, oriented when EXIF support exists, resized to a maximum 2,560-pixel edge, re-encoded as JPEG and stripped of metadata. Transparency is flattened against white. Limit: 15 MB and 16 megapixels per image.

**Wander the open web.** The owner's **The feed** combines discoveries from remote public timelines and actor outboxes. A background crawler finds servers, expands their peer lists, samples active public profiles and keeps collecting. The Mix favors freshness, photos, engagement, followed accounts, saved/liked authors and varied sources; Newest provides publication order. **Open a place** still opens a specific server, person, hashtag or public post. Your following feed contains new public deliveries after a follow is accepted.

ActivityPub is the delivery layer. Server APIs and public actor outboxes are browsing sources. Servers can restrict either. An optional per-server read token supports API access you are entitled to use; it does not bypass permissions. Browsing does not automatically follow anyone.

## Background discovery and the master feed

The first owner visit to **The feed** starts discovery with `opalstack.social`, `pixelfed.social`, `mastodon.social` and saved places. The existing minute cron runs both federation and crawling, even with the browser closed. There is no daily discovery quota. Pause/Resume controls crawler requests; an explicit pause is preserved across visits.

The persistent queue expands public peer lists, collects Mastodon-compatible public timelines (including compatible Pixelfed/Pleroma deployments), reads NodeInfo, samples up to 80 discoverable local profiles and watches the ten highest-follower eligible profiles in that sample. This is a sampled roster, not a complete global leaderboard. You can add a server or any public ActivityPub profile URL yourself. Watched profiles are polled through their public outboxes; they are not automatically followed in your name.

**The feed** is private. Your public landing page remains your links and chronological photo journal. The mixed feed has Photos only, Mix/Newest, reading/grid layouts, stable paged snapshots and a refresh control for new discoveries. Likes and saves influence author affinity, and recently served posts are downranked on a fresh mix. These signals stay in your private database; no external AI service or dwell-time tracking is used. The mix penalizes repeated authors/servers within a page. It ranks a bounded recent candidate pool, keeping browsing responsive while the crawler continues.

Live status shows discovered servers, collected posts, watched canonical actors, online sources, due tasks, the current visit, a recorded 24-hour cache-history chart, recent events and published restrictions against your domain. Evidence matches exact domains or their published SHA-256 digest. Censored names are not wildcards. A 403, timeout or private list is unknown; a failed recheck retains previous evidence as unverified. Positive restrictions are scheduled for 15-minute rechecks; other audits normally run hourly. Backlogs and remote cooldowns can delay checks; this is not a guarantee of universally fresh coverage.

Per-host pacing, Retry-After/rate-reset cooldowns, exponential retries, bounded request bodies, process locks and per-cron time/work budgets keep crawling persistent. The compact discovery cache retains up to 20,000 posts for seven days, at most 32 KiB of display data per post; remote images load from their original hosts. Verified incoming Deletes/private updates and local blocks remove cached discoveries. An unfollowed server may not send its deletions here, so cached public posts can remain stale until expiry. Feed snapshots expire after one hour and hold up to 1,000 references. There is no hard daily crawler count; time, storage and public API availability determine coverage.

ActivityPub defines actor outboxes, not a universal all-server public timeline or a complete network directory. The background crawler uses Mastodon-compatible public APIs and known readable Note/Article/Image outboxes. The interactive browser additionally supports the platforms and ActivityPub object types below. Private access and complete network coverage are not implied. Servers exposing neither a compatible public feed nor a known readable actor outbox remain visible as unsupported/access-required sources.

Protocol references: [ActivityPub outboxes](https://www.w3.org/TR/activitypub/#outbox), [Mastodon public timelines](https://docs.joinmastodon.org/methods/timelines/#public), [peer discovery](https://docs.joinmastodon.org/methods/instance/#peers), [profile directories](https://docs.joinmastodon.org/methods/directory/), and [published domain restrictions](https://docs.joinmastodon.org/methods/instance/#domain_blocks).

## Browse across the Fediverse — 0.5.0

Open a server domain, `@name@server`, profile, community, channel, public collection or post URL in **Open a place**. Quackpack detects server software through NodeInfo and opens public feeds or indexes. Click a person, community or channel card to browse their posts. Public API profile reads work independently of ActivityPub follow/signature delivery.

| Source | Browsing path |
| --- | --- |
| Mastodon, Pixelfed, Pleroma, Akkoma, compatible servers | Public timelines; public highlights and profile directory when the full timeline is restricted; individual public account posts |
| Misskey, Sharkey, Firefish, Iceshrimp, FoundKey/Calckey relatives | Public local notes, highlights when restricted, public people and their notes through compatible Misskey endpoints |
| PeerTube | Public video index, channels and channel videos; thumbnail cards open the original video |
| Lemmy API v3 | Public discussions, local communities and community posts |
| Other ActivityPub software, including publishing apps | Canonical profiles, posts and public collections; HTML/HTTP alternate-link discovery; signed public outbox reads |

The generic reader supports Note, Article, Image, Page, Video, Audio, Event and Question. It reads Collection/OrderedCollection pages, embedded and linked objects, Create and Announce activities, and inline or URL-valued first/next pages. Linked items are fetched in bounded batches with continuation cursors, so later objects are not silently dropped. Articles, polls and events display readable text; media previews link to the original. This release does not add a native video/audio player or voting UI.

Mastodon.social opens public highlights and clickable profiles when available, without requiring a token to begin browsing. A server may expose a directory while restricting both its complete timeline and recommendations; that directory still opens. The complete restricted live feed remains optional through its saved per-server token. Servers that expose no public index get a source page where you can enter a public profile, channel, community or post URL. A domain alone cannot enumerate content an implementation does not publish.

Photo filtering is retained for photo sources and public profiles. Video indexes and text-only discussion/collection pages do not open as a blank photo-only feed. Original links, warnings and alt text remain available. Existing owner-only browsing, local blocks and URL/network protections apply to the new paths. A browse operation has a bounded request/time budget; collection pages are cached briefly for pagination. No remote HTML or scripts are executed.

Native adapters depend on those servers' compatible public endpoint versions and settings. Lemmy v4-only server indexes are not implemented; public ActivityPub community/profile/collection URLs use the generic reader. Public support is broad, not a guarantee that every implementation, fork or restricted endpoint is reachable. See VALIDATION.txt for fixture coverage and live-test limits. The current crawler's platform coverage is unchanged by these interactive browsing adapters.

## 0.4.1 fixes

Choose **Auto**, **Light**, or **Dark** in the top bar. The choice is saved per browser; Auto follows the operating system.

Mastodon.social currently returns HTTP 422 with `This method requires an authenticated user` for its anonymous public timeline. Quackpack now recognizes that access restriction and tries the server's public highlights, clearly labelled as a finite recommendation feed that may include other servers. Hashtag restrictions and unrelated validation failures remain explicit errors. To open the full feed, save a user access token from an account on that same server, with `read:statuses` or `read` permission, in the saved place. An Opalstack.social token cannot authenticate to Mastodon.social. Public profile/outbox browsing remains separate. The crawler treats this 422 as access-required and continues its other discovery tasks.

The homepage now serves the stable actor for ActivityPub requests and advertises it in HTML and HTTP Link headers. Existing domain, username, keys and object IDs are preserved. Search your full `@username@your-domain` or HTTPS homepage URL from Mastodon, then follow it to receive new photos. ActivityPub deliveries go to followers; publishing does not push to every server automatically.

In **Your space**, see follower counts, the last accepted delivery, and failed jobs. **Check public endpoints** checks WebFinger, actor, homepage discovery, outbox and the latest post from your host. The equivalent command is:

```bash
php85 "$HOME/quackpack/runtime/bin/quackpack" doctor --federation
```

After your first follow, **Send recent posts to followers** queues up to 24 existing posts and a profile refresh. Original Create IDs are retained so peers can deduplicate. **Retry failed deliveries** retries failed jobs. Both use the existing minute worker.

## Upgrade your running HOME installation

Extract the new release into a private staging directory, then run its CLI. For the standard layout, for example:

```bash
unzip quackpack-v0.5.0.zip -d "$HOME/quackpack-update-0.5.0"
php85 "$HOME/quackpack-update-0.5.0/quackpack/bin/quackpack" repair
php85 "$HOME/quackpack/runtime/bin/quackpack" discover --action=start
php85 "$HOME/quackpack/runtime/bin/quackpack" doctor
```

Repair stages the new runtime, applies the idempotent schema update, publishes public assets/entrypoints and retains the previous runtime privately. Existing configuration, password, domain, username, keys, posts, links and media are preserved. When staging outside the document root, existing public `.user.ini` settings are preserved. The existing minute cron keeps working and picks up discovery on its next run. Use the actual supported PHP CLI matching your app's selected web PHP. If your config is in a custom location, supply that existing path through `PUP_CONFIG`.

Useful crawler commands:

```bash
php85 "$HOME/quackpack/runtime/bin/quackpack" discover --action=status
php85 "$HOME/quackpack/runtime/bin/quackpack" discover --action=pause
php85 "$HOME/quackpack/runtime/bin/quackpack" discover --action=start
php85 "$HOME/quackpack/runtime/bin/quackpack" discover --action=seed --value=server.example
php85 "$HOME/quackpack/runtime/bin/quackpack" discover --action=watch --value=https://server.example/users/someone
```

## Your links, then your feed

Visitors land on your profile and a stack of Linktree-style buttons, followed by your chronological photo feed. In **Your space → Your links**, add or edit titles and addresses, move links up/down, hide them temporarily, or remove them. Click **Save links** to publish the whole list in that order. Hidden links are omitted from public HTML and page data. The section disappears when there are no visible links. Individual post permalinks stay focused on the post.

Use up to 30 links with labels up to 80 characters. Addresses can use HTTP, HTTPS, a plain `mailto:you@example.com`, or `tel:+16195550100`. These are ordinary links; they are not fetched or indexed by the server. Your links are included in the content export. The link stack needs no additional database tables.

## Document root and private storage

Quackpack works with an ordinary managed PHP + Nginx app or a generic LAMP host. No Nginx configuration access, rewrites, `.htaccess`, symlink application, or `public/` document-root mapping is required.

Extract the release directly into the app directory. Installation automatically relocates the private runtime and release tools into `$HOME/quackpack/runtime`. The installed app directory contains only:

- `index.php`, a bootstrap containing paths rather than secrets.
- `assets/`, containing CSS, JavaScript and the duck logo.
- `.well-known/webfinger/index.php` and `.well-known/nodeinfo/index.php` for discovery.
- `.user.ini`, containing ordinary PHP upload/memory settings.

For the shell user `/home/USER`, the layout is:

| Location | Contents |
| --- | --- |
| `/home/USER/apps/quackpack/` | Document root: public entrypoints and assets |
| `/home/USER/quackpack/config.php` | Private configuration, owner password hash, federation keys and DB credentials |
| `/home/USER/quackpack/pup.sqlite` | SQLite database, when selected |
| `/home/USER/quackpack/media/` | Processed photos, served through PHP |
| `/home/USER/quackpack/sessions/` | Private owner sessions |
| `/home/USER/quackpack/runtime/` | PHP implementation, templates, worker, docs and tests |

The private directory is created with mode 0700 and configuration with mode 0600. Web PHP and cron use persisted absolute paths; PHP-FPM does not need to inherit `$HOME` or `PUP_CONFIG`.

## Requirements

- PHP 8.2+; PDO, cURL, OpenSSL, GD, mbstring and fileinfo. EXIF recommended.
- PDO SQLite with SQLite 3.24+ or an existing MySQL/MariaDB database.
- Ordinary PHP execution and directory indexes (`index.php`) under Nginx or Apache.
- An HTTPS domain or subdomain routed at `/`; subdirectory installs are unsupported.
- Cron every minute and writable private storage. No Composer download, Node, Redis or daemon is required.

Choose your permanent domain and username before federating. Run installation as the account that runs PHP and cron; on Opalstack this is the application's shell user. Select the same supported PHP version for web requests and cron.

## Recover the interrupted 0.3.1 installation

If installation stopped with `copy(.../.user.ini): No such file or directory`, your private configuration and keys were already created. Replace the app's source files with this release, then run **repair**, not install:

```bash
cd "$HOME/apps/quackpack"
php85 bin/quackpack repair
php85 "$HOME/quackpack/runtime/bin/quackpack" work --seconds=1
php85 "$HOME/quackpack/runtime/bin/quackpack" doctor
```

Repair does not require your password. It preserves the existing configuration byte-for-byte, including the owner password hash, domain, username and federation keys. It preserves database contents and media. The new runtime is staged under the private directory, and an existing incomplete runtime is retained as `runtime-backup-TIMESTAMP-RANDOM`. The repair result includes the exact cron command for the PHP binary you used.

The installer now generates `.user.ini` automatically if it was omitted while copying the release. Existing PHP settings are retained. No dotfile is required to complete installation.

## Selecting PHP on Opalstack

The app's web PHP version and the shell's default `php` command are separate. Setting PHP 8.5 in the app panel does not change the shell command. Match them explicitly:

```bash
php85 bin/quackpack install --url=https://photos.example.com --username=john --name='John'
# Or pin the scripted installer to that same version:
PUP_PHP=php85 bash deploy/opalstack.sh
# For an interrupted installation:
PUP_PHP=php85 bash deploy/opalstack.sh repair
```

`php85` with no filename opens PHP interactively; append the CLI filename and command. Other supported installed commands, such as `php82`, `php83` and `php84`, work the same way. See [Opalstack's PHP version documentation](https://docs.opalstack.com/topic-guides/php/#using-different-php-versions-at-the-command-line).

`bin/quackpack` is also executable directly. Its small bootstrap supports old system PHP: if that interpreter is below 8.2, it tries `php85`, `php84`, `php83`, then `php82` before loading the application. `PUP_PHP` overrides this choice. An already supported interpreter is retained when no override is supplied, so `php85 bin/quackpack ...` stays on PHP 8.5. The installer, repair output and doctor report the actual PHP version and absolute binary; cron pins that binary. The dispatcher cannot infer the web PHP version selected in the hosting panel.

## Install without a browser

Extract the archive's contents into your app directory so `index.php`, `assets/` and `bin/` initially sit directly in it, then:

```bash
cd /home/USER/apps/quackpack
read -r -s -p 'Owner password (14+ characters): ' PUP_PASSWORD
export PUP_PASSWORD
php85 bin/quackpack install --url=https://photos.example.com --username=john --name='John'
unset PUP_PASSWORD
```

Use the PHP 8.2+ CLI binary matching your app version (`php82`, `php83`, `php84`, `php85`, or a supported generic `php`). The app directory itself remains the document root. The installer moves its implementation and tools into `$HOME/quackpack/runtime`, creates the owner identity, and returns JSON with the exact private CLI path and cron command. It refuses to replace an existing configuration or regenerate its keys. There is no web installer.

After installation, use the private CLI:

```bash
php85 "$HOME/quackpack/runtime/bin/quackpack" work --seconds=1
php85 "$HOME/quackpack/runtime/bin/quackpack" doctor
```

Add the cron line returned by installation. For example (use your actual PHP path):

```cron
* * * * * /usr/bin/php85 /home/USER/quackpack/runtime/bin/quackpack work --seconds=45 --limit=50 >> /home/USER/logs/quackpack-worker.log 2>&1
```

Open the domain and click **Owner sign in**, or visit `/index.php?route=%2Flogin`. Cron processes deliveries when the journal is closed.

For scripting, set `PUP_URL`, `PUP_USERNAME`, `PUP_NAME`, and `PUP_PASSWORD`, then run `bash deploy/install.sh` or `bash deploy/opalstack.sh`. The wrapper installs, runs the worker once and checks `doctor`; its `repair` argument performs recovery using the existing configuration instead. Optional overrides: `PUP_PHP`, `PUP_DATA_DIR`, `PUP_CONFIG`, and `PUP_DOCROOT`.

`--data-dir=/home/USER/quackpack-other` selects a different private directory for another installation. `--docroot=/absolute/app/path` publishes into a separate empty app directory while keeping the source checkout private. An in-place install removes only release-owned source paths after copying them into the private runtime. It refuses to deploy a Git checkout in place; use `--docroot` instead.

Private storage, configuration and SQLite files must be outside the document root. The installer resolves directory symlinks before checking this boundary. Keep the private configuration and media when replacing code; do not rerun `install` on a live identity.

## Routing without rewrites

New installations use ordinary executable PHP URLs, including actor, inbox, outbox, activity, post and media URLs. For example, the inbox is `/index.php?route=%2Finbox`; collection pagination appends `&page=1`. Signed requests are checked against the actual request URI, including its query string and encoding. All UI links and API requests use the same routing mode.

WebFinger has a fixed discovery path. Its real directory `/.well-known/webfinger/` contains an `index.php` entrypoint. An ordinary Nginx directory redirect adds the trailing slash while retaining the `resource` query, then PHP returns `application/jrd+json`. Redirects are allowed by [RFC 7033, section 4.2](https://www.rfc-editor.org/rfc/rfc7033#section-4.2). NodeInfo uses the same directory-index approach. Run the site on HTTPS so these redirects remain HTTPS.

## MySQL / MariaDB

Provision an empty database and a user restricted to that database. Before installing:

```bash
export PUP_DSN='mysql:host=localhost;dbname=pup;charset=utf8mb4'
export PUP_DB_USER=pup
read -r -s -p 'Database password: ' PUP_DB_PASSWORD
export PUP_DB_PASSWORD
# Set PUP_PASSWORD and run the same install command.
```

PDO credentials are stored in private configuration. The schema uses InnoDB and utf8mb4. Do not share the tables with another Quackpack installation. The app does not create database accounts or require a privileged database user.

## Opalstack: managed PHP + Nginx app

1. Create an ordinary **PHP + Nginx** application with PHP 8.2+ for web requests.
2. Extract the release contents into `/home/USER/apps/quackpack/`, without an extra nested archive folder.
3. Set the installation variables and run `bash deploy/opalstack.sh`, or run the direct PHP install command above. Private files move to `$HOME/quackpack` automatically.
4. Route the HTTPS domain at `/` to this application and add the cron command returned by installation.
5. Run `php85 "$HOME/quackpack/runtime/bin/quackpack" doctor` and open your domain.

No shared Nginx changes or custom server are involved. Opalstack documents that custom configuration is unavailable on managed Nginx/PHP-FPM and recommends keeping private data outside the app directory: [managed Nginx support guidance](https://community.opalstack.com/d/1664-deny-file-web-access-on-nginx). PHP settings belong in `.user.ini`: [Opalstack PHP documentation](https://docs.opalstack.com/topic-guides/php/).

## Earlier Pup / Quackpack identities

The `PUP_*` names, `bin/pup`, session names and export format remain compatible. Existing configurations without a `routing` key keep their original pretty identity/object URLs. Merely replacing application code does not change those IDs.

The HOME layout supports fresh installations and repair of interrupted 0.3.1/0.3.2 installations. Do not extract over a running 0.3.0 site or rerun `install` to move one: that site may contain its only config locator. Preserve the private directory, config, existing URLs and keys before a migration. Switching a live pretty-URL identity to a server without rewrites requires preserving its existing URLs and is not an automated migration in this release.

## Federation

- WebFinger, actor and RSA public key; NodeInfo with registrations closed.
- Public outbox and post objects; counts-only relationship collections.
- `Create`, `Update`, `Delete` and tombstones for local photos.
- Incoming `Follow`/`Undo`, outgoing `Accept`; outgoing `Follow`/`Undo` and incoming `Accept`/`Reject`.
- Incoming public notes and replies from authenticated authors; incoming Likes and Undo Likes.
- Outgoing Like/Undo and public replies, delivered with signed HTTP requests.
- Cavage draft RSA-SHA256 signatures with signed request target, Host, Date and Digest; verification binds keys to their actor. Expired signatures and mismatched origins are rejected. Recent activity IDs are deduplicated.
- Durable cron queue, retry/backoff with Retry-After, per-run flock, failed delivery visibility, manual retry, public history retention and expiring browse caches.

This is an intentionally bounded federation subset. No private messages, C2S API, OAuth provider, relays, story/video pipeline, boosts UI, federation migration, or RFC 9421-only peer support. No full ActivityPub conformance claim. Peers requiring only modern message signatures or additional server policy may not interoperate. Follow activity forwarding, LD signatures, Announce ingestion, and account move handling are not implemented.

Public timeline support depends on Mastodon-compatible APIs. Actor outboxes need embedded public `Create`/`Note` items and readable pagination; string-only or authorization-restricted outboxes may appear empty. Servers that expose neither a compatible public API nor an accessible outbox cannot be browsed here. No historical backfill after following or global full-text search is included. Public discovery is described above.

## Ownership and boundaries

The owner login uses secure cookies, CSRF protection, password hashing and a small per-IP login limiter. Changing the owner password invalidates existing sessions. Federation does not use owner sessions. Remote text is rendered inert, remote images use no-referrer, outbound requests pin validated public DNS addresses, and redirects never carry a saved API token. Only the owner can ask the server to browse remote resources.

Remote photos load directly from their original hosts. Those hosts can see the viewer's IP. Saved posts retain public text and image links, not a permanent media archive. Posts removed through a verified Delete, made private, or blocked locally are also removed from local saved caches. Remote servers control their own copies; deletion requests do not guarantee erasure elsewhere.

Server blocks are personal boundaries, not a community moderation product. Blocking drops local relationships and cached posts from that domain and rejects future outbound/inbound access. This does not run a public blocklist or make claims about why a peer is unreachable. Public follower/following counts are visible; relationship identities, saved posts, places and tokens stay private.

## Operate, update and back up

```bash
php85 "$HOME/quackpack/runtime/bin/quackpack" doctor
php85 "$HOME/quackpack/runtime/bin/quackpack" work --limit=50 --seconds=45
php85 "$HOME/quackpack/runtime/bin/quackpack" retry
php85 "$HOME/quackpack/runtime/bin/quackpack" export > quackpack-export.json
```

`export` is a content/relationship export, not a complete disaster-recovery backup and does not include keys or image bytes. Back up **all private configuration, the media directory, and the database**. On SQLite, stop web writes and the cron worker while copying the DB, or use SQLite's backup command (not a live copy of only the main WAL database file). For MySQL use `mysqldump --single-transaction` plus a media/config backup. Store backups privately; they contain your identity and per-server tokens.

Use the private staging-and-repair upgrade above. Back up before updating the runtime or schema. Do not rerun install or regenerate keys. A deployment should keep one worker host per installation; the filesystem lock is not a distributed job claim mechanism.

Password reset is headless:

```bash
read -r -s -p 'New password: ' PUP_PASSWORD
export PUP_PASSWORD
php85 "$HOME/quackpack/runtime/bin/quackpack" password
unset PUP_PASSWORD
```

## Tests

```bash
php85 "$HOME/quackpack/runtime/tests/run.php"
php85 "$HOME/quackpack/runtime/tests/discovery.php"
python3 "$HOME/quackpack/runtime/tests/web.py" --php=/absolute/path/php
node --check "$HOME/quackpack/runtime/assets/app.js"  # optional development syntax check
# Optional frontend DOM smoke test (development dependency only):
npm install --no-save happy-dom
node "$HOME/quackpack/runtime/tests/ui.mjs"
python3 "$HOME/quackpack/runtime/tests/install.py" --php=/absolute/path/php
# Optional real Nginx + PHP FastCGI hosting test (requires these local binaries):
python3 "$HOME/quackpack/runtime/tests/nginx.py" --nginx=/usr/sbin/nginx --php=/usr/bin/php --php-cgi=/usr/bin/php-cgi --fastcgi-params=/etc/nginx/fastcgi_params
```

The PHP suite runs in temporary SQLite storage. To test the same behavior against a disposable MySQL/MariaDB database, create **`pup_test`**, set `PUP_TEST_DSN='mysql:host=127.0.0.1;dbname=pup_test;charset=utf8mb4'`, `PUP_TEST_DB_USER` and `PUP_TEST_DB_PASSWORD`, then run `php85 "$HOME/quackpack/runtime/tests/run.php"`. The test deletes all rows in its dedicated test tables. Do not point it at production.

License: MIT.
