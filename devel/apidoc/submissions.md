# Submissions

These endpoints query and modify HotCRP submissions. They deal with **submission
objects**, which are JSON representations of submissions.

Each submission object has an `object` field (set to the constant string
`"paper"`), a `pid` field, and a `status` field. Complete submission
objects also include every submission field, such as `title`,
`abstract`, `authors`, `topics`, and `pc_conflicts`. However, methods that
return submissions only fill in fields that exist and that the accessing user is
allowed to see.

Submission endpoints always return complete submission objects. To select
specific fields of submissions, or to return computed fields, use the
`/search` or `/searchaction` endpoints.


# get /{p}/paper

> Retrieve submission

Retrieve a submission object specified by `p`, a submission ID. The submission
object is returned in the `paper` response field. Error messages—for
instance, about permission errors or nonexistent submissions—are returned in
`message_list`.

* badge featured
* param ?forceShow boolean: Whether administrators override their own conflicts. Defaults to `true`; set `forceShow=false` to respect conflicts instead.
* response ?paper paper: The requested submission object.


# post /paper

> Create or modify submission

Create or modify a submission specified by `p`, a submission ID.

Setting `p=new` will create a new submission; the response will contain the
chosen submission ID.

The modification may be specified:

1. As a JSON request body (when the request body has content-type
   `application/json`).
2. As a ZIP archive (when the request body has content-type
   `application/zip`). The archive must contain a file named `data.json`; it
   may contain other files too.
3. As a JSON-formatted request parameter named `json` (when the request body
   has content-type `application/x-www-form-urlencoded` or
   `multipart/form-data`).
4. As a previously-uploaded JSON or ZIP file, represented by an upload token in
   the `upload` parameter.

In all of these, the modification is defined by a JSON submission object. The
fields of this object define the modifications applied to the submission.
The object need not specify all submission fields; absent fields
remain unchanged.

The `p` request parameter is optional. If it is unset, HotCRP uses the `pid`
from the supplied JSON. If both the `p` parameter and the JSON `pid` field
are present, then they must match.

To test a modification, supply a `dry_run=1` parameter. This will test the
uploaded JSON but make no changes to the database.


### ZIP and form uploads

A ZIP upload should contain a file named `data.json` (`PREFIX-data.json` is
also acceptable). This file’s content is parsed as JSON. Submission fields in
the JSON can refer to other files in the ZIP. For instance, this shell session
uploads a new submission with content `paper.pdf`:

```
$ cat data.json
{
	"object": "paper",
	"pid": "new",
	"title": "Aught: A Methodology for the Visualization of Scheme",
	"authors": [{"name": "Nevaeh Gomez", "email": "ngomez@example.edu"}],
	"submission": {"content_file": "paper.pdf"},
	"status": "submitted"
}
$ zip upload.zip data.json paper.pdf
$ curl -H "Authorization: bearer hct_XXX" --data-binary @upload.zip -H "Content-Type: application/zip" SITEURL/api/paper
```

This shell session does the same, but using `multipart/form-data`.

```
$ curl -H "Authorization: bearer hct_XXX" -F "json=<data.json" -F paper.pdf=@paper.pdf SITEURL/api/paper
```


### Administrator use

Administrators can use this endpoint to set some submission fields, such
as `decision`, that have other endpoints as well.

Administrators can choose specific IDs for new submissions by setting `p` (or
JSON `pid`) to the chosen ID. Such a request will either modify an existing
submission or create a new submission with that ID. To avoid overwriting an
existing submission, set the submission JSON’s `status`.`if_unmodified_since`
to `0`.

* badge featured
* body application/json paper: A submission object sent as a raw JSON body.

    * oneof body
* body application/zip: A ZIP archive containing `data.json` (and any files it references).

    * oneof body
* param ?=json string: A submission object supplied in the `json` form field.

    * oneof body
* param ?upload upload_token: An upload token for a previously-uploaded JSON or ZIP file.

    * oneof body
* param dry_run boolean: True checks input for errors, but does not save changes
* param disable_users boolean: True disables any newly-created users.

  When an administrator creates submissions on behalf of other people, HotCRP
  normally creates accounts for any new contacts named in the input. Set
  `disable_users=1` to create those accounts as *disabled*: the new users cannot
  sign in or receive email until an administrator explicitly enables them. This
  is useful when importing submissions in bulk and you don’t yet want to notify
  the people involved.

  * badge site-admin
* param add_topics boolean: True automatically adds topics from input papers to
  the conference’s topics list.

  * badge site-admin
* param reason string: Optional text included in notification emails
* param notify boolean: False disables all email notifications

  * badge site-admin
* param notify_authors boolean: False disables email notifications to authors

  * badge paper-admin
* response ?dry_run boolean: True for `dry_run` requests.
* response ?pid pid: ID of the modified or newly created submission.
* response ?+valid boolean: True if and only if the modification was valid.

    For a non-dry-run request, `"valid": true` also means the database changes
    were committed.

* response ?+change_list [string]: Names of the fields the request attempted to modify.

    New submissions list `pid` first. `change_list` reflects what the request
    *attempted* to change, so successful, failed, and dry-run requests can all
    return a nonempty list.

* response ?paper paper: The modified submission object.

    * condition valid
    * condition !dry_run

# delete /{p}/paper

> Delete submission

Delete the submission specified by `p`, a submission ID.

* badge featured
* param ?if_unmodified_since string: Don’t delete if modified since this time
* param ?forceShow boolean: Whether administrators override their own conflicts. Defaults to `true`; set `forceShow=false` to respect conflicts instead.
* param ?dry_run boolean: True checks input for errors, but does not save changes
* param ?notify boolean: False disables all email notifications

  * badge site-admin
* param ?notify_authors boolean: False disables email notifications to authors

  * badge paper-admin
* param ?reason string: Optional text included in notification emails
* response ?dry_run boolean: True for `dry_run` requests
* response valid boolean: True if the delete request was valid
* response change_list [string]: Always `["delete"]`.
* badge admin


# get /papers

> Retrieve multiple submissions

Retrieve submission objects matching a search.

The search is specified in the `q` parameter (and other search parameters,
such as `t` and `qt`). All matching visible submissions are returned, as an
array of submission objects, in the response field `papers`.

Since searches silently filter out non-viewable submissions, `/papers?q=1010`
and `/paper?p=1010` can return different error messages. The `/paper` request
might return an error like “Submission #1010 does not exist” or “You aren’t
allowed to view submission #1010”, whereas the `/papers` request will return
no errors. To obtain warnings for missing submissions that were explicitly
listed in a query, supply a `warn_missing=1` parameter.

* badge featured
* param q search_string: The search expression.
* param t

    * group Search modifiers
* param qt

    * group Search modifiers
* param sort

    * group Search modifiers
* param scoresort

    * group Search modifiers
* param reviewer

    * group Search modifiers
* param warn_missing boolean: Get warnings for missing submissions
* response ?papers [paper]: The matching submission objects.


# post /papers

> Create or modify multiple submissions

Create or modify multiple submissions.

This administrator-only endpoint modifies multiple submissions at once. Its
request formats are similar to that of `POST /{p}/paper`: it can accept a
JSON, ZIP, or form-encoded request body with a `json` parameter, and ZIP and
form-encoded requests can also include attached files.

### Modify submissions independently

The JSON provided for `/papers` should be an *array* of JSON objects; each
object is applied independently. The per-submission results are returned in the
`status_list` response field (described below).

The response `message_list` contains messages relating to all modified
submissions. To filter out the messages for a single submission, use the
messages’ `landmark` fields. `landmark` is set to the integer index of the
relevant submission in the input JSON.

### Modify all matching submissions

Alternately, you can provide a `q` search query parameter and a *single* JSON
modification object lacking the `pid` field. The JSON modification will be
applied to all papers returned by the `q` search query.


* badge featured
* body application/json [paper]: An array of submission objects sent as a raw JSON body.

    * oneof body
* body application/zip: A ZIP archive containing `data.json` (and any files it references).

    * oneof body
* param ?=json string: Submission objects supplied in the `json` form field.

    * oneof body
* param ?upload upload_token: An upload token for a previously-uploaded JSON or ZIP file.

    * oneof body
* param dry_run boolean: True checks input for errors, but does not save changes
* param disable_users boolean: True disables any newly-created users

  * badge site-admin
* param add_topics boolean: True automatically adds topics from input papers

  * badge site-admin
* param notify boolean: False does not notify contacts of changes

  * badge site-admin
* param ?q search_string: Search query for match requests
* param t

    * group Search modifiers
* param qt

    * group Search modifiers
* param sort

    * group Search modifiers
* param scoresort

    * group Search modifiers
* param reviewer

    * group Search modifiers
* response ?dry_run boolean: True for `dry_run` requests.
* response ?papers [paper]: The modified submission objects.
* response ?+status_list [update_status]: Per-submission results, one entry per input object.

    For array input, `status_list` has the same length and order as the input:
    entry *i* reports the `valid` flag, `change_list`, and `pid` of update *i*.

* badge admin


# get /potentialconflicts

> Compute potential PC conflicts

Return the program-committee members who potentially conflict with a submission —
either because they are an author of it, or because their name, affiliation, or
collaborators overlap with the submission’s. Authors use this while preparing a
submission to see who is conflicted; administrators use it to audit declared
conflicts. It is available to a submission’s authors and to administrators, and
only when the submission’s PC-conflicts field is present and visible to the
caller.

Identify the submission with `p`, or pass `p=new` (with an optional `sclass`) to
analyze a not-yet-created submission.

By default conflicts are computed from the submission’s saved authors and
collaborators. To preview conflicts for *unsaved* data — as the submission form
does while an author edits — supply prospective values instead, in one of two
ways:

* a `json` object with `authors` and/or `collaborators` members; or
* submission-form fields: `collaborators` text, and author entries as
  `authors:<n>:<field>` together with `has_authors=1`.

Each entry in `potential_conflicts` carries the conflicting PC member’s `uid` and
`email` and a `type`: `author` when they author the submission, or
`potentialconflict` for a name/affiliation/collaborator match. A
`potentialconflict` entry also includes a `description` (plain-text explanation)
and a `tooltip` (HTML).

* param ?p pid: Submission to analyze; use `new` for an unsaved submission.
* param ?sclass string: Submission class, used when `p=new`.
* param ?json string: JSON object of prospective `authors` and/or `collaborators` to analyze instead of the saved submission.
* param ?collaborators string: Prospective collaborators text (a form-field alternative to `json`).
* param ?:authors string: Prospective author fields, `authors:<n>:<field>` (a form-field alternative to `json`).
* param ?has_authors boolean: Set when supplying `authors:<n>:<field>` fields.
* response potential_conflicts [object]: The potentially-conflicted PC members.


# get /{p}/share

> Retrieve share link

Retrieve the share link for a submission. This link can be accessed by users not
signed in to HotCRP; it grants view-only access to the submission and its
documents. Only authors and administrators can fetch the share link.

* response ?url: The share link
* response ?token author_view_token: Token for this share link
* response ?token_type string: `"author_view"`
* response ?expires_at integer


# post /{p}/share

> Create, modify, or remove share link

Change the share link for a submission. The `share` parameter determines
whether a link should be created; it must be one of:

`no`
: Delete the current share link, if any.

`yes`
: Update a share link or create one if necessary. The expiration time of a
  current share link may be extended if `expires_in` requests it.

`reset`
: Reset the expiration time of an existing share link, if one exists.

`new`
: Delete any existing share link and create a new one.

Only authors and administrators can modify a share link.

* param !share share_action
* param ?expires_in integer
* response !token ?author_view_token
* response ?token_type string
* response ?expires_at integer
* response ?url


# delete /{p}/share

> Remove share link

Delete the share link for a submission, if any has been created.

* response !token null