# Submissions

These endpoints query and modify HotCRP submissions. They deal with **submission
objects**, which are JSON representations of submissions.

Each submission object has an `object` property (set to the constant string
`"paper"`), a `pid` property, and a `status` property. Complete submission
objects also have one property per submission field, such as `title`,
`abstract`, `authors`, `topics`, and `pc_conflicts`. However, methods that fetch
submissions only fill in fields that exist and that the accessing user is
allowed to see.

Submission endpoints always return complete submission objects. To select
specific properties of submissions, or to fetch computed properties, use the
`/search` or `/searchaction` endpoints.


# get /{p}/paper

> Fetch submission

Fetch a submission object specified by `p`, a submission ID. The submission
object is returned in the `paper` response property. Error messages—for
instance, about permission errors or nonexistent submissions—are returned in
`message_list`.

* param forceShow boolean: False to not override administrator conflict
* response ?paper paper


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
4. As a previously-uploaded JSON or ZIP file, represented by a upload token in
   the `upload` parameter.

In all of these, the modification is defined by a JSON submission object. The
properties of this object define the modifications applied to the submission.
The object need not specify all submission properties; absent properties
remain unchanged.

The `p` request parameter is optional. If it is unset, HotCRP uses the `pid`
from the supplied JSON. If both the `p` parameter and the JSON `pid` property
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

### Responses

The `valid` response property is `true` if and only if the modification was
valid. In non-dry-run requests, `"valid": true` indicates that database changes
were committed.

The `change_list` response property lists any modified field names. New
submissions have `"pid"` as the first item in the list. `change_list` contains
fields that the request *attempted* to modify; successful requests, erroneous
requests, and dry-run requests can all return nonempty `change_list`s.

The `paper` response property is the modified submission object.

Dry-run requests return `change_list` and `valid` properties, but not `paper`
properties, since no modifications are performed.


### Administrator use

Administrators can use this endpoint to set some submission properties, such
as `decision`, that have other endpoints as well.

Administrators can choose specific IDs for new submissions by setting `p` (or
JSON `pid`) to the chosen ID. Such a request will either modify an existing
submission or create a new submission with that ID. To avoid overwriting an
existing submission, set the submission JSON’s `status`.`if_unmodified_since`
to `0`.

* param dry_run boolean: True checks input for errors, but does not save changes
* param disable_users boolean: True disables any newly-created users (site
  administrators only)
* param add_topics boolean: True automatically adds topics from input papers
  (site administrators only)
* param notify boolean: False disables all email notifications (site
  administrators only)
* param notify_authors boolean: False disables email notifications to authors
  (paper administrators only)
* param reason string: Optional text included in notification emails
* param ?json string
* param ?upload upload_token: Upload token for large input file
* response ?dry_run boolean: True for `dry_run` requests
* response ?pid integer: ID of modified submission
* response ?paper paper: JSON of modified submission
* response ?+valid boolean: True if the modification was valid
* response ?+change_list [string]: List of changed fields


# delete /{p}/paper

> Delete submission

Delete the submission specified by `p`, a submission ID.

* param ?dry_run boolean: True checks input for errors, but does not save changes
* param ?notify boolean: False disables all email notifications (site
  administrators only)
* param ?notify_authors boolean: False disables email notifications to authors
  (paper administrators only)
* param ?reason string: Optional text included in notification emails
* param ?if_unmodified_since string: Don’t delete if modified since this time
* response ?dry_run boolean: True for `dry_run` requests
* response valid boolean: True if the delete request was valid
* response change_list [string]: `["delete"]`
* badge admin


# get /papers

> Fetch multiple submissions

Fetch submission objects matching a search.

The search is specified in the `q` parameter (and other search parameters,
such as `t` and `qt`). All matching visible submissions are returned, as an
array of submission objects, in the response property `papers`.

Since searches silently filter out non-viewable submissions, `/papers?q=1010`
and `/paper?p=1010` can return different error messages. The `/paper` request
might return an error like “Submission #1010 does not exist” or “You aren’t
allowed to view submission #1010”, whereas the `/papers` request will return
no errors. To obtain warnings for missing submissions that were explicitly
listed in a query, supply a `warn_missing=1` parameter.

* param warn_missing boolean: Get warnings for missing submissions
* response ?papers [paper]


# post /papers

> Create or modify multiple submissions

Create or modify multiple submissions.

This administrator-only endpoint modifies multiple submissions at once. Its
request formats are similar to that of `POST /{p}/paper`: it can accept a
JSON, ZIP, or form-encoded request body with a `json` parameter, and ZIP and
form-encoded requests can also include attached files.

### Modify submissions independently

The JSON provided for `/papers` should be an *array* of JSON objects. The
`status_list` response property is an array with the same number of elements
as the input JSON. Component *i* of `status_list` reports the status of update
*i* as an object with `valid`, `change_list`, and `pid` properties; these
report the validity of the update, the list of changed fields, and the
submission ID of the modified submission.

The response `message_list` contains messages relating to all modified
submissions. To filter out the messages for a single submission, use the
messages’ `landmark` properties. `landmark` is set to the integer index of the
relevant submission in the input JSON.

### Modify all matching submissions

Alternately, you can provide a `q` search query parameter and a *single* JSON
modification object lacking the `pid` property. The JSON modification will be
applied to all papers returned by the `q` search query.


* param dry_run boolean: True checks input for errors, but does not save changes
* param disable_users boolean: True disables any newly-created users (administrators only)
* param add_topics boolean: True automatically adds topics from input papers (administrators only)
* param notify boolean: False does not notify contacts of changes (administrators only)
* param ?json string
* param ?upload upload_token: Defines upload token for large input file
* param ?q search_string: Search query for match requests
* param ?t search_collection: Collection to search; defaults to `viewable`
* response ?dry_run boolean: True for `dry_run` requests
* response ?papers [paper]: List of JSON versions of modified papers
* response ?+status_list [update_status]: List of lists of changed fields
* badge admin


# get /{p}/share

> Fetch share link

Fetch the share link for a submission. This link can be accessed by users not
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