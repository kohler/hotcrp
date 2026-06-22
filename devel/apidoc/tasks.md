# Task management

The `/job` endpoint monitors long-running jobs.


# get /job

> Monitor job

The `/job` endpoint monitors the progress of long-running jobs and can return
their output upon completion. Jobs represent HotCRP tasks that take too long for
a single API request; requests such as `/autoassign` may start a job and return
its unique identifier.

The `status` response field is `"wait"` before the job starts, `"run"` while
it is running, `"done"` after completion, and `"failed"` when it is known to
have failed. The `update_at` field gives the UNIX timestamp of the most
recent job update; if it’s far in the past but `status` is still `"run"`, the
job has likely crashed. Specific jobs may return other response fields, such
as a `progress` string that describes the current phase of job execution.

Some jobs produce output when they complete. When `status` is `"done"` and
output is present, the response’s `output_mimetype` and `output_size` fields
describe this output. To fetch the output itself, supply an `output` parameter.
If `output=string`, the `output` field contains the output as a string. If
`output=json`, the `output` field contains the output as parsed JSON. If
`output=body`, then the output is returned as the response body with the proper
Content-Type header. `/job?output=body` understands range requests.

A `/job` response uses HTTP response status code 202 Accepted for in-progress
jobs, and 200 OK for completed or failed jobs. However, if a job’s output is
incompatible with the requested format (`output=string` but the output is not
UTF-8 encoded, `output=json` but the output is not JSON, or `output=body` but
the job failed), then the response uses status code 409 Conflict.

* badge featured
* param job job_id
* param ?output =string|json|body: Format for job output
* response status job_status
* response update_at integer: UNIX time job was last updated
* response ?output: Job output

    * condition status=done
    * condition output

* response ?output_mimetype mimetype: Output mimetype

    * condition status=done

* response ?output_size integer: Length of output

    * condition status=done

* response ?output_at integer: UNIX time job output was set

    * condition status=done
