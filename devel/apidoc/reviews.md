# Reviews

These endpoints read reviews, manage review ratings and history, and drive the
external-review request lifecycle (request, accept, decline, reassign).

## Review objects

A review is returned as a JSON **review object** with an `object` field equal
to `"review"`. Every review object has a stable numeric `rid` (review ID) and
the `pid` of the submission it belongs to. Beyond that core, a review object
mixes three kinds of information, each gated by the caller’s permissions:

* **Metadata** — `rtype` (review type, see below), `round` (review round name,
  if rounds are configured), `status` (lifecycle state, see below), `version`
  (a counter that increases on every edit), and `ordinal` (a display label like
  `A`, `B`, … assigned once the review becomes visible to authors/PC). Drafts
  have no `ordinal` and carry `"draft": true`. Booleans `blind`, `subreview`,
  and `ghost` may also appear.
* **Reviewer identity** — `reviewer` (display name) and `reviewer_email`, present
  only when the caller may see who wrote the review. On anonymous reviews these
  are omitted even when the review itself is visible.
* **Content** — the review-form field values. Each configured review field is
  keyed by its **field UID** (an uppercase identifier such as `S01` or
  `overAllMerit`’s UID), so field values never collide with the core fields
  above. Score fields render as their symbolic value, text fields as strings.
  Fields the caller may not see are omitted; their UIDs may be listed in
  `hidden_fields`.

Depending on the request, a review object can also include `editable`,
`my_review`, `my_request`, `review_token`, `modified_at`/`modified_at_text`,
`ratings`/`user_rating`, a review-specific `message_list`, and a `format`
(the default text format for rendering field values).

### Review types

`rtype` is an integer: `1` external, `2` PC, `3` secondary, `4` primary, `5`
metareview. It is reported only to callers who may view review metadata.

### Review status

`status` names the review’s position in its lifecycle:

* `empty` — assigned but not started
* `acknowledged` — the reviewer has accepted the assignment but not entered content
* `draft` — content saved but not submitted
* `delivered` — submitted (for subreviews, awaiting approval)
* `approved` — a subreview approved by its primary reviewer
* `complete` — finished and counted

## Identifying a review

The `r` parameter selects a review on submission `p`. The read endpoints
([`review`](#get-review), [`reviewhistory`](#get-reviewhistory),
[`reviewrating`](#get-reviewrating)) accept either a numeric review ID (`4`) or a
display ordinal (`A`). The lifecycle endpoints ([`acceptreview`](#post-acceptreview),
[`declinereview`](#post-declinereview), [`claimreview`](#post-claimreview))
require a numeric review ID.

To retrieve many reviews at once, use [`reviews`](#get-reviews), which selects
reviews by submission search rather than by ID.


# get /{p}/review

> Retrieve one review

Return a single review of submission `p`, selected by `r`. The review is
returned in the `review` field as a [review object](#tag-reviews). If the
review does not exist the response is a `404`; if it exists but the caller may
not see it, a `403`.

* badge featured
* param r rid: Review to return, as a numeric review ID or a display ordinal (`A`).
* param ?forceShow boolean: Whether administrators override their own conflicts. Defaults to `true`; set `forceShow=false` to respect conflicts instead.
* response review object: The requested [review object](#tag-reviews).


# get /reviews

> Retrieve multiple reviews

Return every review the caller can see on a set of submissions, in the `reviews`
array. Select the submissions either with a search (`q`, optionally narrowed by
`t`) or with a single submission `p`; supply exactly one of the two. Search
diagnostics, if any, are reported in `message_list`.

The optional `rq` and `u` parameters filter *which* reviews are returned (not
which submissions are searched). Supply at most one of them:

* `u` returns only reviews written by the user with that email.
* `rq` is a review search expression (the same syntax as a `re:` search),
  evaluated with `reviewer` as its viewpoint, and returns only matching reviews.

* badge featured
* param ?q search_string: Search selecting submissions whose reviews to return. Required unless `p` is given.
* param ?t search_collection: Search collection for `q`; defaults to the submissions the caller can view.
* param ?p pid: Return reviews of this single submission instead of running a search.
* param ?rq string: Review search expression limiting which reviews are returned.
* param ?u email: Return only reviews written by this user. Mutually exclusive with `rq`.
* param ?reviewer search_reviewer: Reviewer viewpoint used to evaluate `rq`.
* param ?forceShow boolean: Whether administrators override their own conflicts. Defaults to `true`; set `forceShow=false` to respect conflicts instead.
* response reviews [object]: Matching [review objects](#tag-reviews).


# get /{p}/reviewhistory

> Retrieve a review’s edit history

Return the successive versions of one review, newest first, in the `versions`
array. Only the review’s own reviewer or a submission administrator may read its
history.

Each entry is either a full [review object](#tag-reviews) (`object: "review"`)
or, for an incremental edit, a **review delta** (`object: "review_delta"`) that
records only the fields that changed in that version. Set `expand=1` to receive
every version as a full review object instead of a delta.

* param r rid: Review whose history to return, as a numeric review ID or ordinal.
* param ?expand boolean: If true, return each version as a complete review object rather than a delta.
* response pid pid: Submission ID.
* response rid rid: Review ID.
* response versions [object]: Review versions, newest first, each a review object or review delta.


# get /{p}/reviewrating

> Retrieve review ratings

Return the ratings recorded for a review. Ratings let readers flag a review as
helpful or as having problems.

`ratings` is the aggregate of all raters’ flags and is present only when the
caller may view ratings. `user_rating` is the caller’s own rating and is present
only when the caller is allowed to rate this review. Each value is a rating (see
[`reviewrating` POST](#post-reviewrating) for the vocabulary): the string `none`,
a single flag, or an array of flags.

* param r rid: Review whose ratings to return.
* response ?ratings string|[string]: Aggregate rating flags, or `none`.
* response ?user_rating string|[string]: The caller’s own rating, or `none`.


# post /{p}/reviewrating

> Set a review rating

Record the caller’s rating of a review in `user_rating`, then return the updated
ratings as for [`reviewrating` GET](#get-reviewrating).

A rating is a space-separated list of flags, the string `none` to clear the
caller’s rating, or the equivalent integer bitmask. The flags are `good`,
`needswork`, `short`, `vague`, `narrow`, `disrespectful`, and `wrong`.
Administrators may pass `clearall` to remove *every* rating on the review.

* param r rid: Review to rate.
* param =user_rating string: New rating: a space-separated list of flags, `none`, or (administrators) `clearall`.
* response ?ratings string|[string]: Updated aggregate rating flags, or `none`.
* response ?user_rating string|[string]: The caller’s rating after the change, or `none`.


# post /{p}/requestreview

> Request an external review

Ask a person identified by `email` to review submission `p`. Depending on
conference policy and the caller’s role, this either assigns the review
directly, files a **proposal** for an administrator to approve, or (for
administrators requesting an anonymous reviewer) mints a review token. The
`action` field reports which happened, and `message_list` carries the
human-readable outcome.

If the person has no account yet, supply their name (`given_name` and
`family_name`, or a combined `name`) and `affiliation` so an account can be
created. Administrators can set `override=1` to bypass conflict and
prior-refusal checks that would otherwise block or downgrade the request to a
proposal.

* param =email email: Email of the person to ask. The special value `newanonymous` (administrators only) creates an anonymous review with a token.
* param ?=given_name string: Reviewer’s first name (used when creating a new account).
* param ?=family_name string: Reviewer’s last name.
* param ?=name string: Reviewer’s full name, as an alternative to `given_name`/`family_name`.
* param ?=affiliation string: Reviewer’s affiliation.
* param ?round string: Review round to assign the review to.
* param ?=reason string: Explanation included in the request email.
* param ?=override boolean: Administrators only: bypass conflict and refusal checks.
* response action =propose|request|token: What happened: `request` (review assigned), `propose` (proposal filed for administrator approval), or `token` (anonymous review token created).
* response ?review_token string: The review token, present only when `action` is `token`.

    * condition action=token


# post /{p}/acceptreview

> Accept a review assignment

Accept the review-assignment invitation identified by `r` on submission `p`.
This acknowledges the assignment (advancing it out of the `empty` state) and, if
the review had previously been declined, reinstates it. The response’s
`review_site_relative` is a site-relative URL for the review page.

* param r rid: Numeric review ID of the assignment to accept.
* response action =accept: Always `accept`.
* response review_site_relative string: Site-relative URL of the review.


# post /{p}/declinereview

> Decline a review assignment

Decline the review identified by `r` on submission `p`, optionally recording a
`reason`. A review that has already been submitted cannot be declined, nor can a
primary or secondary review. The response’s `review_site_relative` is a
site-relative URL for the review page.

* param r rid: Numeric review ID of the assignment to decline.
* param ?=reason string: Optional explanation, shown to the requester.
* response action =decline: Always `decline`.
* response ?reason string: The recorded reason, if any.
* response review_site_relative string: Site-relative URL of the review.


# post /{p}/claimreview

> Reassign a review to another of your accounts

Move the unsubmitted review `r` on submission `p` from its current reviewer to
the account identified by `email`. This is used to consolidate a review onto a
different account **you are currently signed in to** in the same session;
reassigning to an account you are not signed into is refused, as is reassigning a
review that has already been submitted. The returned `review_site_relative` URL
points at the review under the destination account (with a `u/<index>/` prefix
when that account differs from the current one).

* param r rid: Numeric review ID to reassign.
* param email email: Email of the destination account; must be one you are signed in to this session.
* response action =claim: Always `claim`.
* response review_site_relative string: Site-relative URL of the review under the destination account.


# get /reviewtoken

> List active review tokens

Return the review tokens currently active in the caller’s session, in the
`token` array (encoded form). Review tokens grant the ability to edit specific
anonymous reviews.

* response token [string]: Encoded review tokens active in the session.


# post /reviewtoken

> Activate or clear review tokens

Set the review tokens active in the caller’s session and return the resulting
list as for [`reviewtoken` GET](#get-reviewtoken). Pass `token` as one or more
tokens (whitespace/comma separated, or a JSON array of strings); each valid
token is activated. Submitting with no usable tokens clears the active tokens.

For security, the session is locked out after five failed token attempts until
the user signs out. Per-token results are reported in `message_list`.

* param ?token string: Review token(s) to activate, separated by whitespace or commas, or a JSON array. Omit (or pass none) to clear active tokens.
* response token [string]: Encoded review tokens active after the change.
