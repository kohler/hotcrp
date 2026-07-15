# Reviews

These endpoints read reviews, manage review ratings and history, and drive the
external-review request lifecycle (request, accept, decline, reassign).

## Review objects

A review is returned as a JSON **review object** with an `object` field equal
to `"review"`. Every review object has a stable numeric `rid` (review ID) and
the `pid` of the submission it belongs to. Beyond that core, a review object
mixes three kinds of information, each gated by the caller’s permissions:

* **Metadata**—`rtype` (review type, see below), `round` (review round name,
  if rounds are configured), `status` (lifecycle state, see below), `version`
  (a counter that increases on every edit), and `ordinal` (a display label like
  `A`, `B`, … assigned once the review becomes visible to authors/PC). Drafts
  have no `ordinal` and carry `"draft": true`. Booleans `blind`, `subreview`,
  and `ghost` may also appear.
* **Reviewer identity**—`reviewer` (display name) and `reviewer_email`, present
  only when the caller may see who wrote the review. On anonymous reviews these
  are omitted even when the review itself is visible.
* **Content**—the review-form field values. Each configured review field is
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

* `empty`—assigned but not started
* `acknowledged`—the reviewer has accepted the assignment but not entered content
* `draft`—content saved but not submitted
* `delivered`—submitted (for subreviews, awaiting approval)
* `approved`—a subreview approved by its primary reviewer
* `complete`—finished and counted

## Identifying a review

The `r` parameter selects a review on submission `p`. The read endpoints
([`review`](#get-review), [`reviewhistory`](#get-reviewhistory),
[`reviewrating`](#get-reviewrating)) accept either a numeric review ID (`4`) or a
display ordinal (`A`). The lifecycle endpoints ([`acceptreview`](#post-acceptreview),
[`declinereview`](#post-declinereview), [`claimreview`](#post-claimreview))
require a numeric review ID. The upload endpoint ([`review` POST](#post-review))
also accepts `new` (require a freshly-created review) or an empty `r` (the
caller’s own review, created if necessary).

To retrieve many reviews at once, use [`reviews`](#get-reviews), which selects
reviews by submission search rather than by ID. To create or modify many reviews
at once—including from an offline review form spanning several submissions—use
[`reviews` POST](#post-reviews).


# get /{p}/review

> Retrieve one review

Return a single review of submission `p`, selected by `r`. The review is
returned in the `review` field as a [review object](#tag-reviews). If the
review does not exist the response is a `404`; if it exists but the caller may
not see it, a `403`.

* param r rid: Review to return, as a numeric review ID or a display ordinal (`A`).
* param ?forceShow
* response review review: The requested [review object](#tag-reviews).
* badge featured


# post /review

> Create or modify a review

Create or modify one review of submission `p`. The review being edited is
selected by `r`:

* a numeric review ID (`4`) or display ordinal (`A`) names an existing review;
* `new` requires that a *new* review be created (the request fails if the
  reviewer already has a review);
* an empty or absent `r` addresses the caller’s own review, creating it if
  necessary.

The modification is specified in JSON in any of these forms:

1. In a JSON-formatted request parameter named `json`.
2. As a JSON request body (content-type `application/json`).
3. As an offline review form in plain text—either the whole request body (with
   content-type `text/plain`) or a `file` upload in a form POST. The text is
   parsed exactly as an offline form uploaded from the review page; only its
   sections for submission `p` are applied (any other sections are ignored).
4. As a previously-uploaded JSON or text file, named by an
   [upload token](#post-upload) in the `upload` parameter.

In the JSON forms the modification is a [review object](#tag-reviews). Absent
fields are left unchanged; fields keyed by their UID set review-form content.
Set `submitted` (or `ready`) to `true` to submit the review, or `draft` to
`true` to save it without submitting. Submission administrators assigning a
review on someone’s behalf may name the reviewer with `email` (plus
`given_name`/`family_name`/`affiliation` for a new account).

The `p` request parameter is optional for the JSON and text forms: if it is
unset, HotCRP uses the `pid` from the supplied data. If both `p` and a body
`pid` are present they must match; likewise `r` and a body `rid`.

The API also supports form upload using the parameter conventions of the HotCRP
web application. These conventions are subject to change, and third-party
applications should prefer JSON.

To test a modification without saving, supply a `dry_run=1` parameter. This will
test the input but make no changes to the database.

* param ?p pid: Submission to review. Optional when the JSON or text data
  supplies a `pid`; if both are present they must match.
* param ?r rid: Review to create or modify: `new`, a numeric review ID, a
  display ordinal, or empty for the caller’s own review.
* body application/json review: A [review object](#tag-reviews) sent as a raw JSON body.

    * oneof body
* body text/plain string: An offline review form, parsed as if uploaded from the review page.

    * oneof body
* param ?=json string: A review object supplied in a `json` request parameter.

    * oneof body
* param ?upload upload_token: An [upload token](#post-upload) for a previously-uploaded JSON or text file.

    * oneof body
* param ?dry_run boolean: True checks input for errors, but does not save changes.
* param ?override boolean: Administrators only: bypass deadline and other soft checks.
* param ?if_vtag_match integer: Reject the modification unless the review’s
  current version tag equals this value. `0` matches only a review that does not
  yet exist, so `r=new` implies `if_vtag_match=0`.
* param ?if_unmodified_since string: Reject the modification if the review has
  been modified since this time (a Unix timestamp, or `0`).
* param ?notify boolean: False disables email notifications.

    * default true
    * badge admin
* response ?dry_run boolean: True for `dry_run` requests.
* response ?+valid boolean: True if and only if the modification was valid.

    For a non-dry-run request, `"valid": true` also means the database changes
    were committed.

* response ?+change_list [string]: Names of the review fields the request
  attempted to modify.

    `change_list` reflects what the request *attempted* to change, so
    successful, failed, and dry-run requests can all return a nonempty list. If
    the review is new, the `change_list` begins with `"new"`.

* response ?conflict boolean: True when the modification was rejected by an
  `if_vtag_match` or `if_unmodified_since` edit-conflict check.
* response ?rid rid: Numeric ID of the modified or newly created review.

    * condition valid
    * condition !dry_run
* response ?review review: The modified [review object](#tag-reviews).

    * condition valid
    * condition !dry_run
* badge featured


# delete /{p}/review

> Delete a review

Delete the review on submission `p` selected by `r` (a numeric review ID or a
display ordinal; an empty `r` addresses the caller’s own review). Only
administrators may delete reviews.

To test without deleting, supply `dry_run=1`. The edit-conflict preconditions
`if_vtag_match` and `if_unmodified_since` behave as for [`POST /review`](#post-review).

* param ?r rid: Review to delete: a numeric review ID or display ordinal, or
  empty for the caller’s own review.
* param ?dry_run boolean: True checks the request but does not delete.
* param ?if_vtag_match integer: Reject the delete unless the review’s current
  version tag equals this value.
* param ?if_unmodified_since string: Reject the delete if the review has been
  modified since this time (a Unix timestamp, or `0`).
* param ?forceShow
* response ?dry_run boolean: True for `dry_run` requests.
* response ?+valid boolean: True if the delete was valid; for a non-dry-run request, it was also committed.
* response ?+change_list [string]: Always `["delete"]`.
* response ?conflict boolean: True when the delete was rejected by an
  `if_vtag_match` or `if_unmodified_since` edit-conflict check.
* response ?rid rid: The deleted review’s ID.
* badge admin
* badge featured


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

* param ?q search_string: Search selecting submissions whose reviews to return. Required unless `p` is given.
* param ?t search_scope: Scope of search; defaults to the submissions the caller can view.

    * default viewable
* param ?p pid: Return reviews of this single submission instead of running a search.
* param ?rq string: Review search expression limiting which reviews are returned.
* param ?u email: Return only reviews written by this user. Mutually exclusive with `rq`.
* param ?reviewer search_reviewer: Reviewer viewpoint used to evaluate `rq`.
* param ?forceShow
* response reviews [review]: Matching [review objects](#tag-reviews).
* badge featured


# post /reviews

> Create or modify multiple reviews

Create or modify many reviews in one request. Unlike [`review`
POST](#post-review), this endpoint is not tied to a single submission: each
review names its own submission, and the batch may span any number of
submissions. The modification may be supplied as:

1. A JSON array of [review objects](#tag-reviews) (as a raw `application/json`
   body, in the `json` form field, or via an [upload token](#post-upload)). Each
   object names its submission with `pid` and, optionally, its review with `rid`
   (`"rid": "new"` requires a freshly-created review). An object may also carry
   its own `if_vtag_match` or `if_unmodified_since` precondition to guard that
   item’s edit (see [`review` POST](#post-review)).
2. An offline review form in plain text (a `text/plain` body, a `file` upload,
   or an [upload token](#post-upload)) containing one or more `==+== Paper`
   sections. Every section is applied to the submission it names; there is no
   single-submission restriction.

Each review is processed independently and best-effort: one item’s failure does
not roll back the others. The response reports one entry per input review, in
order, in `status_list`; the parallel `reviews` array holds the resulting
[review object](#tag-reviews) for each committed item (and `null` for items that
were invalid or skipped). A `dry_run=1` request validates every item but commits
nothing.

The `if_vtag_match` and `if_unmodified_since` request parameters set batch-wide
default preconditions, applied to every item that does not specify its own. In
particular, `if_vtag_match=0` requires that every saved review be newly created.

* body application/json [review]: An array of [review objects](#tag-reviews),
  each naming its submission with `pid`.

    * oneof body
* body text/plain string: An offline review form with one or more submission sections.

    * oneof body
* param ?=json string: The review array supplied in a `json` request parameter.

    * oneof body
* param ?upload upload_token: An [upload token](#post-upload) for a previously-uploaded JSON or text file.

    * oneof body
* param ?dry_run boolean: True checks every item for errors, but does not save changes.
* param ?override boolean: Administrators only: bypass deadline and other soft checks.
* param ?if_vtag_match integer: Batch-wide default version-tag precondition,
  overridable by a review object’s own `if_vtag_match`. `if_vtag_match=0`
  requires that every saved review be newly created.
* param ?if_unmodified_since string: Batch-wide default edit-conflict precondition
  (a Unix timestamp, or `0`), overridable by a review object’s own
  `if_unmodified_since`.
* param ?notify boolean: False disables email notifications.

    * default true
    * badge admin
* response ?dry_run boolean: True for `dry_run` requests.
* response ?+status_list [review_update_status]: Per-review results, one entry per
  input object (same length and order as the input). Entry *i* reports `valid`,
  `change_list` (beginning with `"new"` for a created review), the submission’s
  `pid`, the review’s `rid` (when saved), and `conflict` for an edit-conflict
  rejection.
* response ?reviews [review]: One entry per input review, in order: the resulting
  [review object](#tag-reviews), or `null` for items that were not saved.

    * condition !dry_run
* badge featured


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
