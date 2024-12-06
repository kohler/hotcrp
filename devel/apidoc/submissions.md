# Submissions

These endpoints query and modify HotCRP submissions.


# get /paper

> Retrieve submission(s)

Use this endpoint to retrieve JSON-formatted information about submissions.

Provide either the `p` parameter or the `q` parameter. The `p` parameter
should be a submission ID; the server will return information about that
single submission in the `paper` response field. Otherwise, the `q` parameter
should be a search query (other search parameters `t`, `qt`, etc. are also
accepted); the server will return information about all matching submissions
in the `papers` response field, which is an array of paper objects.

Error messages—for instance, about permission errors—are returned in the
`message_list` component as usual.

Paper object fields depend on the submission form. Every paper has an `object`
member, a `pid`, and a `status`. Other fields are provided depending on
whether they exist and whether the accessing user can see them.


# post /paper

> Create or modify submission(s)

### Single submission

A request with a `p` parameter (as a path parameter `/{p}/paper` or a query
parameter) modifies the submission with that ID. The special ID `new` can be
used to create a submission.

Modifications are specified using a JSON object. There are three ways to
provide that JSON, depending on the content-type of the request:

1. As a request body with content-type `application/json`.
2. As a file named `data.json` in an uploaded ZIP archive, with content-type
   `application/zip`.
3. As a parameter named `json` (body type
   `application/x-www-form-urlencoded` or `multipart/form-data`).

The JSON upload must be formatted as an object.

ZIP and form uploads also support document upload. A document is referenced
via `content_file` fields in the JSON.

### Multiple submissions

A request with no `p` parameter can create or modify any number of
submissions. Upload types are the same as for single submissions, but the JSON
upload is defined as an array of objects. These objects are processed in turn.

Currently, multiple-submission upload is only allowed for administrators.

### ZIP uploads

A ZIP upload should contain a file named `data.json` (`PREFIX-data.json` is
also acceptable). This file’s content is parsed as JSON and treated a
submission object (or array of submission objects). Attachment fields in the
JSON content can refer to other files in the ZIP. For instance, this shell
session might upload a submission with content `paper.pdf`:

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

### Responses


* param dry_run boolean: True checks input for errors, but does not save changes
* param disable_users boolean: True disables any newly-created users (administrators only)
* param add_topics boolean: True automatically adds topics from input papers (administrators only)
* param notify boolean: False does not notify contacts of changes (administrators only)
