# Assignments

These endpoints perform HotCRP assignments, including review assignments,
review preference settings, tags, and anything else that can be modified by
HotCRP’s bulk assignments interface. `/assign` lets users perform any
assignment for which they have permission. `/autoassign` lets administrators
compute assignments automatically.


# post /assign

> Perform assignments

Perform assignments specified as JSON or CSV.

Assignments use HotCRP’s bulk assignments format, and can be provided in several
ways:

1. As a JSON request body (content-type `application/json`).
2. As a CSV-formatted request body (content-type `text/csv`).
3. As an `assignments` parameter containing JSON or CSV (content-type
   `application/x-www-form-urlencoded` or `multipart/form-data`).
4. As an `upload` parameter containing an upload token for a previously-uploaded
   JSON or CSV file.

JSON assignments should parse to arrays of objects, each with `pid` and `action`
properties (and possibly others). CSV assignments must contain a header with at
least `pid` and `action` columns. The `action` determines the assignment type;
the `/assigners` API endpoint lists the available actions.

To test assignments without making changes, set `dry_run=1`. This will parses
the assignment and reports errors without modifying the database.

The `valid` response property is `true` if the assignments had no errors. In
non-dry-run requests, `"valid": true` indicates any database changes were
committed.

For valid assignments, the response includes information about the performed
assignments (or, for `dry_run` requests, the requested assignments).
`assignment_count` indicates the number of atomic assignments, where an atomic
assignment is a single action applied to a single entity (e.g., a submission or
review). The `format` parameter can request additional detail. When
`format=none` (default), only `assignment_count` is provided. When
`format=summary`, the response includes `assignment_actions` (distinct actions
performed) and `assignment_pids` (affected submission IDs). When `format=csv`,
the `output` property contains the atomic assignments as a CSV string. When
`format=json`, the `assignments` property contains the atomic assignments as a
list of JSON objects.

When the optional `p` parameter is set, only assignments affecting that
submission are performed; all other assignments are ignored.


* param dry_run boolean: True checks input for errors, but does not save changes
* param ?assignments string: JSON or CSV assignments
* param ?upload upload_token: Upload token for JSON or CSV assignments
* param ?format string: `none`, `summary`, `csv`, or `json`
* param ?forceShow boolean: Explicit false means chair conflicts are not overridden
* param ?search search_parameter_specification
* response ?dry_run boolean: True if request was a dry run
* response valid boolean: True if the assignments were valid
* response ?assignment_count integer: Number of individual assignments performed
* response ?assignment_actions [string]: List of distinct action names in performed assignment (`format=summary`)
* response ?assignment_pids [pid]: List of submission IDs in performed assignment (`format=summary`)
* response ?output string: Performed assignments as CSV with header (`format=csv`)
* response ?output_mimetype string: `text/csv` (`format=csv`)
* response ?output_size integer: Length of `output` (`format=csv`)
* response ?assignment_header [string]: CSV header (`format=json`)
* response ?assignments [object]: Performed assignments (`format=json`)
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

Specify the autoassignment action with the `autoassigner` parameter and the
submissions to assign with the `q` parameter. The `/autoassigners` endpoint
lists the available autoassignment actions.

Most autoassignment actions take additional parameters. `u` defines the PC
members to assign; it defaults to all PC members. `disjoint` defines classes of
PC members that should not be co-assigned to the same submission. `param`
defines additional autoassigner parameters, such as the number of assignments to
make or the type of review to create.

To supply multiple values for these parameters, use a JSON-formatted array or
multiple parameters with `[]` appended to the name. For instance,
`/autoassign?u=%5B1,2%5D` and `/autoassign?u%5B%5D=1&u%5B%5D=2` both supply `u`
arguments `1` and `2`, the first as a single `u=[1,2]` parameter and the second
as separate `u[]=1` and `u[]=2` parameters.

Each `u` argument is a user search string: a user ID (`1`), email
(`kohler@g.harvard.edu`), or tag (`#heavy`). Prefix with a hyphen `-` to remove
matching users from the assignable set.

Each `disjoint` argument is a comma-separated list of users (IDs, emails, or
tags) that should not be co-assigned to the same submission.

Each `param` argument has the format `NAME=VALUE`. See `/autoassigners` for the
parameters understood by each action.

To test without making changes, set `dry_run=1`. This creates and tests an
assignment, reporting errors without modifying the database. Set
`minimal_dry_run=1` to skip additional testing—for example, `dry_run=1` reports
potential conflicts, but `minimal_dry_run=1` does not.

Autoassignment can be time-consuming, so `/autoassign` may return before
completion. An early response uses HTTP status code 202 Accepted, and its
`job` response property gives a job ID for the autoassignment. Query the `/job`
endpoint to monitor progress and retrieve the CSV output.

* param autoassigner string: Name of autoassignment action to run
* param q
* param t
* param dry_run boolean: True computes the assignment, but does not perform it
* param minimal_dry_run boolean: True computes an initial assignment, but does not validate it
* param u [string]: Array of users to consider for assignment
* param disjoint [string]: Array of user sets that should not be co-assigned
* param param [string]: Array of `NAME=VALUE` autoassignment parameter settings
* response ?dry_run boolean: True if request was a dry run
* response ?job job_id: Job ID of autoassignment job
* response ?job_url string: URL to monitor autoassignment job
* response ?status string
* response ?exit_status integer
* response ?progress string
* response ?assignment_pids [integer]
* response ?output string: CSV of computed assignment


# get /autoassigners

> List autoassignment actions

List all autoassignment actions understood by this HotCRP, including their
parameters.

* response autoassigners [autoassignment_action]
