# Fediverse Developers

This page is mainly for outlining the activities and circumstances Mbin sends out activities and 
how activities, objects and actors are represented.
To communicate between instances, Mbin utilizes the ActivityPub protocol 
([ActivityPub standard](https://www.w3.org/TR/activitypub/), [ActivityPub vocabulary](https://www.w3.org/TR/activitystreams-vocabulary/))
and the [FEP Group federation](https://codeberg.org/fediverse/fep/src/branch/main/feps/fep-1b12.md).

## Context

The `@context` property for all Mbin payloads **should** be this:

```json
%@context%
```

The `/contexts` endpoint resolves to this:

```json
%@context_additional%
```

## Actors

The actors Mbin uses are

- the Instance actor (AP `Application`)
- the User actor (AP `Person`)
- the Magazine actor (AP `Group`)

### Instance Actor

Each instance has an instance actor at `https://instance.tld/i/actor` and `https://instance.tld` (they are the same):

```json
%actor_instance%
```

### User actor

Each registered user has an AP actor at `https://instance.tld/u/username`:

```json
%actor_user%
```

### Magazine actor

Each magazine has an AP actor at `https://instance.tld/m/name`:

```json
%actor_magazine%
```

## Objects

### Threads

```json
%object_entry%
```

### Comments on threads

```json
%object_entry_comment%
```

### Microblogs

```json
%object_post%
```

### Comments on microblogs

```json
%object_post_comment%
```

### Private message

```json
%object_message%
```

## Collections

### User Outbox

```json
%collection_user_outbox%
```

First Page:

```json
%collection_items_user_outbox%
```

### User Followers

```json
%collection_user_followers%
```

### User Followings

```json
%collection_user_followings%
```

### Magazine Outbox

The magazine outbox endpoint does technically exist, but it just returns an empty JSON object at the moment.
```json
%collection_magazine_outbox%
```

### Magazine Moderators

The moderators collection contains all moderators and is not paginated:

```json
%collection_magazine_moderators%
```

### Magazine Featured

The featured collection contains all threads and is not paginated:

```json
%collection_magazine_featured%
```

### Magazine Followers

The followers collection does not contain items, it only shows the number of subscribed users:

```json
%collection_magazine_followers%
```

## User Activities

### Follow and unfollow

If the user wants to follow another user or magazine:

```json
%activity_user_follow%
```

If the user stops following another user or magazine:

```json
%activity_user_undo_follow%
```

### Accept and Reject

Mbin automatically sends an `Accept` activity when a user receives a `Follow` activity.

```json
%activity_user_accept%
```

### Create

```json
%activity_user_create%
```

### Report

```json
%activity_user_flag%
```

### Vote

When a user votes it is translated to a `Like` activity for an up-vote and a `Dislike` activity for a down-vote. 
Down-votes are not federated, yet.

```json
%activity_user_like%
```

If the vote is removed:

```json
%activity_user_undo_like%
```

### Boost

If a user boosts content:

```json
%activity_user_announce%
```

### Edit account

```json
%activity_user_update_user%
```

### Edit content

```json
%activity_user_update_content%
```

### Delete content

```json
%activity_user_delete%
```

### Delete own account

```json
%activity_user_delete_account%
```

### Lock own content

Only top level content (meaning no comments) can be locked.
When content is locked, comments can no longer be created for it.

```json
%activity_user_lock%
```

## Moderator Activities

### Add or Remove moderator

When a moderator is added:

```json
%activity_mod_add_mod%
```

When a moderator is removed:

```json
%activity_mod_remove_mod%
```

### Pin or Unpin a thread

When a thread is pinned:

```json
%activity_mod_add_pin%
```

When a thread is unpinned:

```json
%activity_mod_remove_pin%
```

### Delete content

```json
%activity_mod_delete%
```

### Ban user from magazine

```json
%activity_mod_ban%
```

### Lock content

When content is locked, comments can no longer be created for it.

```json
%activity_mod_lock%
```

## Admin Activities

### Ban user from instance

```json
%activity_admin_ban%
```

### Delete account

If an admin deletes another user's account the activity actually does not reflect that, it looks exactly as if the user deleted their own account.

```json
%activity_admin_delete_account%
```

## Magazine Activities

### Announce activities

The magazine is mainly there to announce the activities users do with it as the audience.
The announced type can be `Create`, `Update`, `Add`, `Remove`, `Announce`, `Delete`, `Like`, `Dislike`, `Flag` and `Lock`.
`Announce(Flag)` activities are only sent to instances with moderators of this magazine on them. 

```json
%activity_mag_announce%
```

## Receiving activities

### Routing an inbound object to a magazine

An object arriving at an inbox has to be filed under some magazine. Mbin decides in
this order:

1. **Addressing.** If `audience`, `to` or `cc` names a magazine, that magazine wins.
   Software that models communities says so explicitly, and an explicit statement beats
   any inference.
2. **The delivering actor.** Otherwise, if a magazine follows the actor that delivered
   and signed the activity, the object is filed there. The deliverer is read from the
   activity, not from the object: an actor that delivered an activity has asserted
   something about it, while `attributedTo` only states who composed it. This is what
   lets a magazine follow an instance actor that announces posts written by the accounts
   it hosts, which is how WriteFreely federates a whole site through one actor.
3. **The author.** Otherwise, if a magazine follows the actor in `attributedTo`. An
   object Mbin fetched itself has no delivering actor, so the author is the only claim
   available for it.
4. **The `random` magazine**, if one exists. Otherwise the object is dropped and the
   reason is logged.

A magazine follow declares which activity kinds it carries: `create`, `announce` or
`both`. A follow that does not carry the delivered kind does not answer, and the object
falls through to the next step rather than being filed under a magazine whose moderator
asked not to receive that kind. The default is taken from the followed actor's type when
the follow is created, and a moderator can change it:

| Followed actor | Default | Why |
|---|---|---|
| `Person` and anything unlisted below | `create` | their own posts. Carrying their announces would file everything they boost into the magazine |
| `Application`, `Service` | `both` | an instance actor may relay by announcing or deliver directly, so both makes the follow work either way |
| `Group` | `announce` | a Group's announces are the one-to-one equivalent of Mbin threads |

### Routing does not change visibility

Visibility is decided separately, from the object's own addressing:

* An object naming the Public collection in `to` or `cc` is **visible**.
* An object naming only the author's followers collection is **followers-only**, and is
  stored private. WriteFreely addresses an unlisted blog's posts this way, and
  Mastodon's followers-only posts have the same shape.

A private object is shown to a signed in user who follows its author, and to nobody
else. **A magazine that follows the author does not widen that.** Such an object is
routed into the magazine like any other and then remains invisible to everyone who does
not personally follow the author.

That is deliberate, not an oversight. Re-delivering followers-only content to everyone
who can read a magazine would show it to people the sender never addressed, which is
the opposite of what "followers only" means on the wire. A reader who wants an unlisted
blog's posts should follow that blog's actor.
