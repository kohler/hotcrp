# Assignments

These endpoints perform and compute HotCRP assignments. An *assignment* is any
change expressible through HotCRP’s bulk-assignment interface: review
assignments, review preferences, conflicts, tags, decisions, lead and shepherd
designations, and more. The exact set of assignment types depends on the
conference’s configuration and the calling user’s privileges;
[`/assigners`](#get-assigners) enumerates the types
available.

[`/assign`](#post-assign) applies an explicit
assignment that the caller supplies as JSON or CSV; a user may perform any
assignment they have permission to make. [`/autoassign`](#post-autoassign)
instead *computes* an assignment automatically—for example, distributing reviews
to balance load across the PC—and is restricted to administrators.

Both endpoints can run in a *dry-run* mode that parses and checks an assignment,
reporting any errors, without touching the database. Assignments are applied
atomically: if any part of an assignment is invalid, none of it is performed.


# post /assign

> Perform assignments

Apply an explicit set of assignments supplied by the caller, in HotCRP’s
bulk-assignment format.

The assignment data can be provided in any of four ways:

1. As a JSON request body (content type `application/json`).
2. As a CSV request body (content type `text/csv`).
3. In the `assignments` parameter, holding JSON or CSV (with a form-encoded
   request, content type `application/x-www-form-urlencoded` or
   `multipart/form-data`).
4. In the `upload` parameter, holding an upload token for a previously-uploaded
   JSON or CSV file. Use this for assignment files too large to send inline; see
   the [upload API](#post-upload).

JSON assignment data is an array of objects, each with at least `pid` and
`action` fields (a lone object is also accepted and treated as a
one-element array). CSV assignment data is a table whose header includes at
least `pid` and `action` columns. In both forms the `action` selects the
assignment type and determines which other fields or columns are meaningful;
[`/assigners`](#get-assigners) lists every action and
its parameters.

The `valid` response field reports whether the assignment was free of errors;
any errors themselves are described in the standard `message_list`. For a
non-dry-run request, `valid` is also the commit signal: `true` means the changes
were saved, `false` means nothing changed.

On success the response describes the assignment that was performed (or, for a
dry run, that would be performed). The amount of detail is controlled by the
`format` parameter, ranging from a bare count up to the full list of individual
(“atomic”) assignments. An atomic assignment is a single action applied to a
single entity, such as adding one tag to one submission or assigning one
review.

When the `search` parameter is supplied, a successful non-dry-run request also
returns the result of evaluating that search *after* the assignment, together
with refreshed tag and status information for the affected submissions. This
lets a client apply an assignment and update a displayed submission list in a
single round trip.

* badge featured
* param ?assignments string

    Assignment data as JSON or CSV, for requests that do not send it as the
    body. The value is parsed as JSON if it begins with `[` or `{`, and as CSV
    otherwise.

* param ?upload upload_token

    Upload token for assignment data uploaded earlier via the [upload
    API](#post-upload). The uploaded file may be JSON or CSV.

* param ?json5 boolean

    If true, JSON assignment data may use [JSON5](https://json5.org/) syntax,
    including comments, trailing commas, and unquoted keys.

* param ?dry_run boolean

    If true, parse and validate the assignment and report any errors, but make
    no database changes. `valid` still reports whether the assignment would have
    succeeded.

* param ?p pid

    If set, restrict the operation to this submission: assignments naming any
    other submission are silently ignored. Handy for committing one submission’s
    worth of changes from a larger assignment.

* param ?format =json|csv|summary|none

    How much detail to include in the response. `json` (the default) lists each
    atomic assignment as an object (see `assignment_header` and `assignments`);
    `csv` returns the same rows as CSV text (see `output`); `summary` returns
    only the distinct actions and affected submissions (`assignment_actions`,
    `assignment_pids`); `none` returns only `assignment_count`.

    * default json
* param ?forceShow
* param ?search search_parameter_specification

    A search to evaluate after the assignment is applied. When supplied on a
    successful, non-dry-run request, the response gains the search results
    (`ids`, `groups`, `search_params`) and refreshed per-submission tag and
    status information (`papers`).

* response valid boolean

    True if the assignment parsed and validated with no errors. On a non-dry-run
    request, `true` additionally means the changes were committed; `false` means
    nothing was changed.

* response ?dry_run boolean

    Present and true when the request was a dry run.

* response ?assignment_count integer

    Number of atomic assignments performed (or, for a dry run, that would be
    performed).

    * condition valid

* response ?assignment_header [string]

    Column names describing the fields of each `assignments` row.

    * condition valid
    * condition format=json

* response ?assignments [object]

    The atomic assignments, each an object keyed by the names in
    `assignment_header`.

    * condition valid
    * condition format=json

* response ?assignment_actions [string]

    Distinct action names that were performed.

    * condition valid
    * condition format=summary

* response ?assignment_pids [pid]

    IDs of the submissions affected by the assignment.

    * condition valid
    * condition format=summary

* response ?output string

    The atomic assignments rendered as CSV text, including a header row.

    * condition valid
    * condition format=csv

* response ?output_mimetype =text/csv

    MIME type of `output`; always `text/csv`.

    * condition valid
    * condition format=csv

* response ?output_size integer

    Length of `output` in bytes.

    * condition valid
    * condition format=csv

* response_schema search_response.opt

* response ?papers [tag_response]

    Refreshed tag and status information for each submission affected by the
    assignment, suitable for updating a displayed submission list.

    * condition search


# get /assigners

> List assignment actions

List every assignment action understood by this HotCRP installation, with the
parameters each action accepts. Use this to discover the `action` values and
columns valid in an [`/assign`](#post-assign) request.
The visible set of actions reflects the calling user’s privileges.

* badge featured
* response assigners [assignment_action]: Available assignment actions


# post /autoassign

> Compute automatic assignment

Compute an assignment automatically and, unless this is a dry run, perform it.
This is an administrator operation.

Select the algorithm with the `autoassigner` parameter and the submissions to
operate on with the `q` (and optional `t`) search parameters;
[`/autoassigners`](#get-autoassigners) lists the
available algorithms and their parameters. Most algorithms take further settings
through `u`, `disjoint`, and `param`.

The `u`, `disjoint`, and `param` parameters each accept multiple values. Supply
them either as a JSON array or by repeating the parameter name with `[]`
appended. For instance, `u=%5B1,2%5D` (a single `u=[1,2]`) and
`u%5B%5D=1&u%5B%5D=2` (separate `u[]=1` and `u[]=2`) both pass the two values `1`
and `2`.

Autoassignment can be slow, so this endpoint may respond before the computation
finishes. In that case the response uses HTTP status 202 Accepted and reports a
`job` identifier (and `job_url`); poll the [`/job`](#get-job)
endpoint with that identifier to follow progress and retrieve the resulting
assignment as CSV. When the computation finishes quickly enough, the result is
returned directly instead, in the same shape as a completed `/job` response.

* badge featured
* param autoassigner string: Name of the autoassignment algorithm to run, as listed by [`/autoassigners`](#get-autoassigners).
* param q search_string: Search selecting the submissions to assign.
* param ?t search_scope

    * default alladmin
* param ?dry_run boolean: If true, compute and validate the assignment and report errors, but do not perform it. The computed assignment is still returned as `output`.
* param ?minimal_dry_run boolean: Like `dry_run`, but skips extra validation passes. For example, an ordinary dry run reports potential conflicts created by the assignment; a minimal dry run does not.
* param ?u [string]: PC members to consider for assignment; defaults to all PC members. Each value is a user search string—a user ID (`1`), an email (`kohler@g.harvard.edu`), or a tag (`#heavy`)—and may be prefixed with `-` to remove the matching users from the set.
* param ?disjoint [string]: Sets of users that must not both be assigned to the same submission. Each value is a comma-separated list of user search strings (IDs, emails, or tags).
* param ?param [string]: Additional algorithm-specific settings, each formatted `NAME=VALUE`. See [`/autoassigners`](#get-autoassigners) for the settings each algorithm understands.
* param ?count integer: Convenience setting for the common `count` algorithm parameter (for example, the number of reviews per submission); equivalent to passing `count=N` in `param`.

* response ?dry_run boolean

    Present and true when the request was a dry run.

* response status job_status

    Job status, using the same vocabulary as [`/job`](#get-job). A successful
    response carries the computed assignment and reports `done`; a job that ran
    but failed reports `failed`.

* response ?job job_id

    Identifier of the background job computing the assignment; present when the
    response is returned before completion.

    * condition status=wait|run

* response ?job_url string

    Absolute URL of the [`/job`](#get-job) endpoint for `job`.

    * condition status=wait|run

* response ?exit_status integer

    Process exit status of the completed computation; `0` on success.

    * condition status=done

* response ?assignment_pids [pid]: IDs of the submissions the computed assignment affects.

    * condition status=done

* response ?output string: The computed assignment as CSV text, ready to be replayed through [`/assign`](#post-assign).

    * condition status=done

* response ?output_mimetype =text/csv: MIME type of `output`; always `text/csv`.

    * condition status=done

* response ?output_size integer: Length of `output` in bytes.

    * condition status=done

* response ?output_at integer: UNIX time the output was produced.

    * condition status=done

* response ?progress string

    Human-readable description of the current computation phase.

* badge admin


# get /autoassigners

> List autoassignment actions

List every autoassignment algorithm understood by this HotCRP installation, with
the parameters each one accepts. Use this to discover valid `autoassigner` values
and `param` settings for [`/autoassign`](#post-autoassign).

* badge featured
* response autoassigners [autoassignment_action]: Available autoassignment algorithms
