# Submissions

These endpoints query and modify HotCRP submissions.


# get /paper

> Retrieve submission

This endpoint retrieves JSON-formatted information about a submission. All
visible information about submission fields, tags, and overall status are
returned in as the response’s `paper` property. Error messages—for instance,
about permission errors or nonexistent submissions—are returned in
`message_list`.

The returned `paper` property is a submission object. Submission objects are
formatted based on the submission form. Every paper object has an `object`
property set to `"paper"`, a `pid`, and a `status`. Other properties are
provided based on which submission fields exist and whether the accessing user
can see them.

* param forceShow boolean: False to not override administrator conflict
* response ?paper paper


# post /paper

> Create or modify submission

This endpoint modifies the submission specified by the `p` parameter. Setting
`p=new` will create a new submission; the response will contain the chosen
submission ID.

Modifications are specified using a JSON object. There are three ways to
provide that JSON, depending on the content-type of the request:

1. As a JSON request body with content-type `application/json`.
2. In a ZIP archive request body with content-type `application/zip`, as a
   file named `data.json`.
3. As a parameter named `json` in a normal `application/x-www-form-urlencoded`
   or `multipart/form-data` body.

However it is provided, the JSON must define an object interpretable as a
submission (or a subset of a submission). The properties of this object define
the modifications to be applied to the submission.

The `p` parameter is optional; if unset, HotCRP uses the `pid` from the
supplied JSON. If the `p` parameter and the JSON `pid` property are both
present, then they must match.

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

If the modification succeeds, the response’s `paper` property contains the
modified submission object.

The `change_list` property is a list of names of the modified fields. New
submissions have `"pid"` as the first item in the list. `change_list` contains
fields that the request *attempted* to modify; successful requests, erroneous
requests, and dry-run requests can all return nonempty `change_list`s.

The `valid` property is `true` if and only if the modification was valid. In
non-dry-run requests, `valid: true` indicates that database changes were
committed.

Dry-run requests return `change_list` and `valid` properties, but not `paper`
properties, since no modifications are performed.


### Administrator use

Administrators can use this endpoint to set some submission properties, such
as `decision`, that have other endpoints as well.

Administrators can choose specific IDs for new submissions; just set `p` (or
JSON `pid`) to the chosen ID. Such a request will either modify an existing
submission or create a new submission with that ID. To avoid overwriting an
existing submission, set the JSON’s `status`.`if_unmodified_since` to `0`.

* param dry_run boolean: True checks input for errors, but does not save changes
* param disable_users boolean: True disables any newly-created users (administrators only)
* param add_topics boolean: True automatically adds topics from input papers (administrators only)
* param notify boolean: False does not notify contacts of changes (administrators only)
* param ?json string
* response ?dry_run boolean: True for `dry_run` requests
* response ?paper paper: JSON version of modified paper
* response ?+change_list [string]: List of changed fields
* response ?+valid boolean: True if the modification was valid


# get /papers

> Retrieve multiple submissions

This endpoint retrieves JSON-formatted information about multiple submissions
based on a search. The search is specified in the `q` parameter; all matching
visible papers are returned. Other search parameters (`t`, `qt`) are accepted
too. The response property `papers` is an array of matching submission objects.

Since searches silently filter out non-viewable submissions, `/papers?q=1010`
and `/paper?p=1010` can return different error messages. The `/paper` request
might return an error like `Submission #1010 does not exist` or `You aren’t
allowed to view submission #10110`, whereas the `/papers` request will return
no errors. To obtain warnings for missing submissions that were explicitly
listed in a query, supply a `warn_missing=1` parameter.

* param warn_missing boolean: Get warnings for missing submissions
* response ?papers [paper]


# post /papers

> Create or modify multiple submissions

This administrator-only endpoint modifies multiple submissions at once. Its
request formats are similar to that of `POST /{p}/paper`: it can accept a
JSON, ZIP, or form-encoded request body with a `json` parameter, and ZIP and
form-encoded requests can also include attached files.

The JSON provided for `/papers` should be an *array* of JSON objects. Response
properties `papers`, `change_lists`, and `valid` are arrays with the same
number of elements as the input JSON; component *i* of each response property
contains the result for the *i*th submission object in the input JSON.

Alternately, you can provide a `q` search query parameter and a *single* JSON
object. The JSON object must not have a `pid` property. The JSON modification
will be applied to all papers returned by the `q` search query.

The response `message_list` contains messages relating to all modified
submissions. To filter out the messages for a single submission, use the
messages’ `landmark` properties. `landmark` is set to the integer index of the
relevant submission in the input JSON.


* param dry_run boolean: True checks input for errors, but does not save changes
* param disable_users boolean: True disables any newly-created users (administrators only)
* param add_topics boolean: True automatically adds topics from input papers (administrators only)
* param notify boolean: False does not notify contacts of changes (administrators only)
* param json string
* param ?q search_string: Search query for match requests
* param ?t search_collection: Collection to search; defaults to `viewable`
* response ?dry_run boolean: True for `dry_run` requests
* response ?papers [paper]: List of JSON versions of modified papers
* response ?+change_lists [[string]]: List of lists of changed fields
* response ?+valid [boolean]: List of validity checks
