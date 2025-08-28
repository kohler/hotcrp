# Submission administration

These endpoints perform HotCRP assignments, including review assignments,
review preference settings, tags, and anything else that can be modified by
HotCRP’s bulk assignments interface. The general-purpose `/assign` endpoint is
most useful; using `/assign`, a user can perform any assignment for which they
have permission. Endpoints for specific kinds of assignment, such as decision,
discussion lead, and shepherd, are provided for the HotCRP web application’s
convenience.


# post /assign

> Assignments

Perform assignments, specified either as a JSON array or as an uploaded CSV
file.

The assignments should be compatible with HotCRP’s bulk assignments format.
They may be specified:

1. As a JSON request body (when the request body has content-type
   `application/json`).
2. As a CSV file (when the request body has content-type
   `text/csv`).
3. As a JSON-formatted request parameter named `assignments` (when the request
   body has content-type `application/x-www-form-urlencoded` or
   `multipart/form-data`).
4. As a previously-uploaded JSON or CSV file, represented by a upload token in
   the `upload` parameter.

JSON requests should parse to arrays of objects. Each object should contain at
least a `pid` property and an `action` property, where `action` determines
what kind of assignment to run. Similarly, CSV uploads should contain at least
`pid` and `action` columns.

If the optional `p` request parameter is set, HotCRP will only implement
assignments that affect that submission.

To test an assignment, supply a `dry_run=1` parameter. This will parse the
uploaded assignment and report any errors, but make no changes to the
database.

The `valid` response property is `true` if and only if the assignments were
valid (had no errors). In non-dry-run requests, `"valid": true` indicates that
any database changes were committed.

The response includes an `assignments` property, which is an array of the
specific assignments performed (or, for dry-run requests, the specific
assignments that would be performed). Each entry in `assignments` represents a
single action applied to a single submission. (This differs from input
`assignments` entries, each of which might apply to many submissions specified
by a search.) If you’re not interested in the `assignments` property, supply a
parameter of either `summary=1` (to get summary `assignment_actions` and
`assignment_pids` properties) or `quiet=1` (to get nothing).


* param dry_run boolean: True checks input for errors, but does not save changes
* param ?assignments string: JSON
* param ?upload upload_token: Upload token for large input file
* param ?quiet
* param ?summary
* param ?forceShow
* param ?search
* response ?dry_run
* response valid
* response ?assignments
* response ?assignments_header
* response ?assignment_actions
* response ?assignment_pids
* response ?papers
* response ?ids
* response ?groups
* response ?search_params


# get /{p}/decision

> Fetch submission decision


# post /{p}/decision

> Change submission decision


# get /{p}/lead

> Fetch submission discussion lead


# post /{p}/lead

> Change submission discussion lead


# get /{p}/manager

> Fetch submission administrator


# post /{p}/manager

> Fetch submission administrator


# post /{p}/reviewround

> Change review round


# get /{p}/shepherd

> Fetch submission shepherd


# post /{p}/shepherd

> Change submission shepherd
