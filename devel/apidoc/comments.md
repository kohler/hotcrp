# Comments

These endpoints fetch and modify submission comments.

Each comment has a *visibility* and a *topic* (which in the UI is called a
*thread*). These values control who can see the comment.

The default comment visibility is `"rev"`, which makes the comment visible to
PC and external reviewers. Other values are `"admin"` (visible only to
the submission’s managers and the comment author), `"pc"` (visible to PC
reviewers, but not external reviewers), and `"au"` (visible to authors and
reviewers).

The default comment topic is `"rev"`, the review thread. Comments on the
review thread are visible to users who can see reviews; if you can’t see
reviews, you can’t see the review thread. Other comment topics are `"paper"`,
the submission thread (visible to anyone who can see the submission), and
`"dec"`, the decision thread (visible to users who can see the submission’s
decision).

## Comment objects

A comment is returned as a JSON **comment object** (the [`comment`](#tag-comments)
schema) with an `object` field equal to `"comment"`. Every comment object also
carries a numeric `cid` (comment ID) and the `pid` of the submission it belongs
to. Beyond that, the fields present depend on the comment and on what the caller
is allowed to see:

* **Placement**—`visibility` and `topic` (see above), and `ordinal` (a display
  label like `A1` or `cA2`, assigned once the comment is visible).
* **Content**—`text` (the comment body), `format` (the default text format for
  rendering it), `tags`, `docs` (attachments), and `word_count`. Content is
  omitted when the request passes `content=false`.
* **Authorship**—`author`, `author_email`, `by_author` (true for a comment
  written by a submission author), and `by_shepherd`. On anonymized comments the
  identity fields are replaced by `author_pseudonym`/`author_pseudonymous`, or
  hidden entirely (`author_hidden`).
* **State**—`draft` (true for an unsubmitted response), `blind`, `collapsed`,
  `response` (the response round name, for response comments), `modified_at`
  (and `modified_at_text`), and `review_token`.
* **Permissions**—`editable` (the caller may edit this comment),
  `author_editable`, and `viewer_owned` (the caller wrote it).

## Identifying a comment

The `c` parameter selects a comment on submission `p`. It may be:

* a numeric comment ID (for example `42`);
* `new`, to create a comment (POST only);
* `response`, to select or create the unnamed response; or
* a named response selector such as `R2response` (or, equivalently, set
  `c=response` and add a `response=R2` parameter).

On [`POST /{p}/comment`](#post-comment), omitting `c` defaults to `new`.


# get /{p}/comment

> Retrieve comment

Return one comment of submission `p`, selected by `c` (see [Identifying a
comment](#tag-comments)). The comment is returned in the `comment` response
field as a [comment object](#tag-comments); if it does not exist or the caller
may not see it, an error is returned.

* param c string: The comment to return (a numeric comment ID or a response
  selector).
* param ?response string: Response-round name, when selecting a named response.
* param ?content boolean: Set to `false` to omit comment content (`text`, `docs`)
  from the response, returning only metadata. Defaults to `true`.

    * default true
* response ?comment comment: The requested comment.
* badge featured


# post /{p}/comment

> Create, modify, or delete comment

Create, modify, or delete a comment on submission `p`. Select the comment with
`c` (see [Identifying a comment](#tag-comments)): a numeric comment ID modifies
or deletes an existing comment, `new` creates an ordinary comment, and
`response`/`R2response` creates or updates a response.

A request must either supply `text` (the new comment body) or set `delete=1`;
otherwise it is rejected. When `delete=1`, the comment is removed and the
response carries no `comment` field. Otherwise the comment is created or
modified, and the saved [comment object](#tag-comments) is returned in
`comment`.

Saving with `text` empty and no attachments is refused for a new comment, but
deletes an existing one (it is treated as `delete=1`).

## JSON upload

Instead of form parameters, the comment may be supplied as a JSON **comment
object**—the same shape returned in the `comment` response field. It may be sent
four ways, matching [`POST /paper`](#post-paper):

* as an `application/json` request body;
* in a `json` form field (which lets file attachments ride along in the same
  request);
* as an `application/zip` archive whose `data.json` (or `*-data.json`) member is
  the object and whose other members are referenced attachments; or
* via an [upload token](#post-upload) in the `upload` field, pointing at a
  previously-uploaded JSON or ZIP file.

The object’s `cid` (or `response`) selects the target comment; its `text`,
`visibility`, `topic`, `tags`, `blind`, `draft`, and `delete` fields carry the
update. (A one-element array holding a single object is also accepted.) The
`dry_run` and `if_unmodified_since` controls are still read from the query
string.

Attachments are given as a `docs` array, each entry either retaining an existing
attachment by `docid` or uploading a new one: inline via `content` (raw text) or
`content_base64`, or by `content_file`, which names a file in the ZIP archive or
an uploaded file field in a `json` form request. An omitted `docs` key keeps the
comment’s current attachments.

## Concurrency

An edit conflict returns `"ok": false`, `"valid": false`, and `"conflict": true`,
with the `comment` field holding the server’s current version so the client can
reconcile. Conflicts arise two ways:

* Editing a response can collide with a concurrent edit automatically.
* Any comment edit can be guarded explicitly with `if_unmodified_since`: pass the
  comment’s last-known `modified_at`, and the edit is rejected—with a message
  keyed to `if_unmodified_since`—if the comment has changed since. Pass
  `if_unmodified_since=0` to require that the comment not already exist.

## Attachments

Comment attachments may be uploaded as files (requiring a request body in
`multipart/form-data` encoding), or using the [upload API](#post-upload).
To upload a single new attachment:

* Set the `attachment:1` body parameter to `new`
* Either:
	* Set `attachment:1:file` as a uploaded file containing the relevant data
	* Or use the [upload API](#post-upload) to upload the file,
	  and supply the upload token in the `attachment:1:upload` body parameter

To upload multiple attachments, number them sequentially (`attachment:2`,
`attachment:3`, and so forth). To delete an existing attachment, supply its
`docid` as an `attachment:N` parameter, and set `attachment:N:delete` to 1.

* body application/json comment: A comment object supplied as a raw JSON body (see [JSON upload](#tag-comments)).
* body application/zip: A ZIP archive whose `data.json` is a comment object (see [JSON upload](#tag-comments)).
* param ?=json string: A comment object supplied in the `json` form field (see [JSON upload](#tag-comments)).
* param ?upload upload_token: An [upload token](#post-upload) for a previously-uploaded JSON or ZIP comment file.
* param ?c string: The comment to create, modify, or delete. Defaults to `new`.

    * default new
* param ?=text string: The comment body. Required unless `delete=1`.
* param ?delete boolean: Set to `1` to delete the selected comment.
* param ?visibility comment_visibility: Who can see the comment: `admin`, `pc`, `rev` (default), or `au`.

    * default rev
* param ?topic comment_topic: The comment thread: `rev` (default), `paper`, or `dec`.

    * default rev
* param ?=tags string: Space-separated tags for the comment (ordinary comments only).
* param ?response string: Response-round name, when creating or editing a named response.
* param ?draft boolean: For responses, set to `1` to save as a draft instead of submitting.
* param ?blind boolean: Whether the comment is anonymous, where the configuration allows a choice.
* param ?=:attachment string: Structured attachment fields, `attachment:<n>` (see above).
* param ?review_token string: Review token authorizing the edit, when acting through one.
* param ?if_unmodified_since string: Reject the edit if the comment has been modified since this time (a Unix timestamp, matching the comment’s `modified_at`, or `0`). See [Concurrency](#tag-comments).
* param ?dry_run boolean: True checks input for errors, but does not save changes.
* param ?notify boolean: False disables all email notifications for the change (mention and follower notifications). Ignored unless the caller administers the submission.

    * default true
    * badge admin
* response ?dry_run boolean: True for `dry_run` requests.
* response ?+valid boolean: True if and only if the modification was valid.

    For a non-dry-run request, `"valid": true` also means the change was committed
    to the database.

* response ?+change_list [string]: Names of the fields the request attempted to
  change (`text`, `visibility`, `tags`, and/or `attachments`), or `["delete"]`
  for a delete.

    Creating a comment reports an empty list, since a new comment is not a set of
    field changes. `change_list` reflects what the request *attempted* to change,
    so successful, failed, and dry-run requests can all return a nonempty list.

* response ?conflict boolean: True when the edit was rejected by a concurrency check (see [Concurrency](#tag-comments)).
* response ?cid cid: The affected comment’s ID: the new ID for a created comment, or the existing ID for an edit. Absent when deleting, or on a dry-run creation.
* response ?comment comment: The saved comment, absent on delete or `dry_run`.
* badge featured


# get /comments

> Retrieve multiple comments

Retrieve every visible comment on the submissions matching a search.

The search is specified in the `q` parameter (and other search parameters,
such as `t`). All comments the caller may see, across all matching
submissions, are returned as an array of [comment objects](#tag-comments) in
the response field `comments`.

As a shorthand for a single submission, supply its ID in `p` instead of `q`;
this returns that submission’s visible comments. Supplying both `q` and `p` is
an error.

* param ?q search_string: The search expression.
* param ?p pid: A single submission, as an alternative to `q`.
* param ?t

    * default viewable
    * group Search modifiers
* param ?content boolean: Set to `false` to omit comment content (`text`, `docs`)
  from each returned comment, returning only metadata. Defaults to `true`.

    * default true
* response ?comments [comment]: The matching comment objects.
* badge featured


# post /comments

> Create, modify, or delete multiple comments

Create, modify, or delete comments on multiple submissions in one request.

Unlike [`POST /{p}/comment`](#post-comment), this endpoint is not scoped to a
single submission: each comment object carries its own `pid`, so a batch may span
submissions. There is no site-chair restriction—each comment is authorized
independently, exactly as for `POST /{p}/comment`.

## Modify comments independently

The request body is an *array* of [comment objects](#tag-comments) (the same
shape accepted by `POST /{p}/comment`), supplied as an `application/json` body, a
`json` form field, an `application/zip` archive whose `data.json` holds the
array, or an [upload token](#post-upload). A ZIP or `json`-form request may carry
attachment files shared across items: each object’s `docs[].content_file`
resolves against the one archive.

Each object identifies its submission with `pid` and its target comment with
`cid` (or `response`, or neither to create a new comment), plus the `text`,
`visibility`, `topic`, `tags`, `delete`, … fields carrying the update. A
per-object `if_unmodified_since` guards that item’s edit (the query-string
`if_unmodified_since` is a batch-wide default).

Processing is **best-effort**: valid items are saved and invalid ones are
reported without aborting the batch. The per-item results are returned in the
`status_list` field, and the saved comments in `comments`—both the same length
and order as the input. Messages in `message_list` carry a `landmark` field set
to the integer index of the item they concern.

* body application/json [comment]: An array of comment objects sent as a raw JSON body.

    * oneof body
* body application/zip: A ZIP archive whose `data.json` is an array of comment objects (and any files it references).

    * oneof body
* param ?=json string: Comment objects supplied in the `json` form field.

    * oneof body
* param ?upload upload_token: An [upload token](#post-upload) for a previously-uploaded JSON or ZIP file.

    * oneof body
* param ?dry_run boolean: True checks input for errors, but does not save changes.
* param ?notify boolean: False disables notifications; honored per item only when the caller administers that submission.

    * default true
* param ?if_unmodified_since string: A batch-wide default precondition, overridable by a comment object’s own `if_unmodified_since`.
* response ?dry_run boolean: True for `dry_run` requests.
* response ?+status_list [comment_update_status]: Per-comment results, one entry per input object (same length and order as the input). Entry *i* reports `valid`, `change_list`, `pid`, and `cid`, plus `conflict` for an edit-conflict rejection.
* response ?comments [comment]: The saved comments, one per input object (`null` for a failed item); omitted entirely for `dry_run`.
* badge featured


# get /mentioncompletion

> List mention completions

Return the people the caller may **@-mention** in a comment, in the format used
by HotCRP’s autocompleter. The comment editor calls this when the user types `@`
to populate the mention dropdown.

Pass the submission in `p`; the candidate set—authors, reviewers, and PC
members—depends on that submission and on what the caller is allowed to see. The
list also reflects comment-mention visibility, so it never reveals a participant
the caller could not otherwise discover.

Each entry in `mentioncompletion` is one candidate, given as an autocompleter
item. The candidate’s display name appears in either `s` or `sm1` (exactly one is
present): `s` is offered immediately, while `sm1` is offered only once the user
has typed at least one character. PC members use `sm1`, so the entire committee
is not listed unprompted; authors and other direct participants use `s`.

* param ?p pid: Submission whose participants may be mentioned.
* response mentioncompletion [object]: Mention candidates, as autocompleter items.

    Each item carries the candidate’s name in `s` or `sm1` (see above) and may
    also include:

    * `au` (boolean)—true for the submission’s authors;
    * `pri` (integer)—match priority; `1` for non-PC candidates, ranking them
      above PC members;
    * `admin` (boolean)—true for PC members who administer the submission (or,
      when no submission is given, site chairs).
