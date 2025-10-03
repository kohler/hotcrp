# Assignments

These endpoints perform HotCRP assignments, including review assignments,
review preference settings, tags, and anything else that can be modified by
HotCRP’s bulk assignments interface. `/assign` lets users perform any
assignment for which they have permission. `/autoassign` lets administrators
compute assignments automatically.


# post /assign

> Perform assignments

Perform assignments, specified either as a JSON array or as an uploaded CSV
file.

The assignments should be compatible with HotCRP’s bulk assignments format.
They may be specified:

1. As a JSON request body (when the request body has content-type
   `application/json`).
2. As a CSV file (when the request body has content-type
   `text/csv`).
3. As a JSON- or CSV-formatted request parameter named `assignments` (when the
   request body has content-type `application/x-www-form-urlencoded` or
   `multipart/form-data`).
4. As a previously-uploaded JSON or CSV file, represented by a upload token in
   the `upload` parameter.

JSON assignments should parse to arrays of objects. Each object should contain
at least a `pid` property and an `action` property, where `action` determines
what kind of assignment to run. CSV assignments must contain a header, which
should specify at least `pid` and `action` columns. (The `/assigners` endpoint
lists the available `action`s.)

To test an assignment, supply a `dry_run=1` parameter. This will parse the
uploaded assignment and report any errors, but make no changes to the
database.

The `valid` response property is `true` if and only if the assignments were
valid (had no errors). In non-dry-run requests, `"valid": true` indicates that
any database changes were committed.

The response includes an `output` property, which is an array of the specific
assignments performed (or, for dry-run requests, the specific assignments that
*would have been* performed). Each entry represents a single action applied to a
single submission. (This differs from input `assignments` entries, each of which
might apply to many submissions.) If you’re not interested in the `output`
property, supply either `summary=1` (to get summary `assigned_actions` and
`assigned_pids` properties) or `quiet=1` (to get nothing).

If the optional `p` request parameter is set, HotCRP will only implement
assignments that affect that submission.


* param dry_run boolean: True checks input for errors, but does not save changes
* param ?assignments string: JSON or CSV assignments
* param ?upload upload_token: Upload token for JSON or CSV assignments
* param ?quiet boolean: True omits assignment information from response
* param ?summary boolean: True omits complete assignment from response
* param ?csv boolean: True uses CSV format in response
* param ?forceShow boolean: Explicit false means chair conflicts are not overridden
* param ?search search_parameter_specification
* response ?dry_run boolean: True if request was a dry run
* response valid boolean: True if the assignments were valid
* response ?assigned_actions [string]: List of action names included in eventual assignment
* response ?assigned_pids [pid]: List of submission IDs changed by assignment
* response ?output: Resulting assignments, as JSON list or CSV
* response ?output_header [string]: CSV header if `output` is JSON
* response ?papers
* response ?ids
* response ?groups
* response ?search_params


# get /assigners

> List assignment actions

List all assignment actions understood by this HotCRP, including their
parameters.

* response assigners [assignment_action]


# post /autoassign

> Compute automatic assignment

Compute and optionally perform an automatic assignment.

Specify the autoassignment action with the `autoassigner` parameter, and the
submissions to assign with the `q` parameter. (The `/autoassigners` endpoint
lists the available autoassignment actions.)

Most autoassignment actions take additional parameters and a set of PC members
to assign. Supply these in the `u`, `disjoint`, and `param` parameters. `u`
defines the assignable PC members; `disjoint` defines the classes of PC
members that should not be co-assigned; and `param` defines additional
autoassigner parameters, such as the number of assignments to make or the type
of review to create.

The `u`, `disjoint`, and `param` parameters may be supplied multiple times,
either as a single JSON-formatted array string named `PNAME` or as multiple
strings named `PNAME[]`. For instance, `/autoassign?u=%5B1,2%5D` and
`/autoassign?u%5B%5D=1&u%5B%5D=2` each supply two `u` arguments, `1` and `2`.

Each `u` argument is a search string defining a set of users. Valid strings
are user IDs (`1`), emails (`kohler@g.harvard.edu`), or tags (`#heavy`).
Prefix a string with a hyphen `-` to remove matching users from the assignable
set.

Each `disjoint` argument is a comma-separated list of users that should not be
coassigned. Again, users can be defined using IDs, emails, or tags.

Each `param` argument defines a parameter for the autoassignment action, and
should be a string with the format `NAME=VALUE`. The parameters required or
understood by each action are listed by the `/autoassigners` endpoint.

To test an assignment, supply the `dry_run=1` parameter. This will create an
assignment and test it, reporting any errors, but will make no changes to the
database. Supply `minimal_dry_run=1` to obtain the autoassignment output
without additional testing. For instance, `dry_run=1` will report warnings for
potential conflicts, but `minimal_dry_run=1` will not.

Autoassignment is often time consuming, so a successful `/autoassign` may
return early, before the autoassignment completes. The response will list a
job ID for the autoassigner. Query the `/job` endpoint to monitor the job and
obtain its eventual output.

* param autoassigner string: Name of autoassignment action to run
* param q
* param t
* param dry_run boolean: True computes the assignment, but does not perform it
* param minimal_dry_run boolean: True computes an initial assignment, but does not validate it
* param u [string]: Array of users to consider for assignment
* param disjoint [string]: Array of user sets that should not be coassigned
* param param [string]: Array of `NAME=VALUE` autoassignment parameter settings
* response ?dry_run boolean: True if request was a dry run
* response ?job job_id: Job ID of autoassignment job
* response ?job_url string: URL to monitor autoassignment job
* response ?status string
* response ?exit_status integer
* response ?progress string
* response ?assigned_pids [integer]
* response ?output string: CSV of computed assignment


# get /autoassigners

> List autoassignment actions

List all autoassignment actions understood by this HotCRP, including their
parameters.

* response autoassigners [autoassignment_action]
