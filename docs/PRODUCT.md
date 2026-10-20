# The product contract

Quackpack is a personal photo journal with an excellent window onto the Fediverse. The domain is the account. Federation is delivery infrastructure; the user should spend their attention on photos and people.

## Product priorities

1. **A moment is easy to publish.** Choose photos, describe them, write a caption, publish. A failed peer must not block the journal. Editing retains the post's identity and photos; deletion creates a tombstone and removes local image bytes.
2. **Browsing finds good moments automatically.** The private master feed collects public discoveries in the background and mixes sources. You can also go directly to a person, server or hashtag. Start with photos, switch to reading when useful. Keep your place across navigation. A source's access limitation must be legible and recoverable.
3. **The owner controls the experience.** Following is explicit. Saving is private. The public journal and following feed are chronological. The private master feed offers an explicit ranked Mix or Newest order, influenced by follows and saved/liked authors. Discovery does not create follows.
4. **Deployment stays ordinary.** A current PHP runtime, one PDO database, a private data directory and a cron entry. Scriptable installation, no management plane to maintain.

## Boundaries

One owner does not need registrations, roles, staff tools, tenant billing, invite flows or an admin dashboard. Photos do not require video encoding, ephemeral stories, livestreaming or messaging. Discovery runs continuously through public sources with visible progress, bounded storage and retry queues; it never claims complete network coverage. Server blocks and delivery visibility are operational necessities; a trust-and-safety console is a different product.

## Evaluate it with observable outcomes

| Job | Acceptance condition |
| --- | --- |
| Publish | A 3-photo post appears locally immediately; delivery remains queued if the peer is down. |
| Preserve | Editing captions keeps the canonical post ID and original image order. |
| Browse | Domain, handle, and public post URL work from one field, with clear source labels. |
| Keep place | Opening a person and returning restores the previous stream and scroll position. |
| Be understandable | “Permission required”, “unavailable”, “rate limited”, and “empty” have distinct recovery paths. |
| Federate | External Mastodon and Pixelfed instances can find, follow, receive, update and delete test photos. |
| Own it | A fresh install and a full backup restore preserve the actor ID and key. |

The final two require testing on the actual hosting setup and public peers. A polished shell is not evidence of federation interoperability.
