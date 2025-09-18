# Task management

The `/job` endpoint monitors long-running jobs.


# get /job

> Monitor job

Some HotCRP tasks, such as autoassignment, can take too long for a single API
request. Such tasks start a *job* and return its unique identifier. The `/job`
endpoint monitors the job’s progress and can return its output upon
completion.

The listed response properties are common to all job types, but specific jobs
may return other response properties. For example, a running autoassignment
job will return a string `progress` property that describes the current phase
of autoassignment, and a completed autoassignment job will report
`assigned_pids` and `incomplete_pids` properties.

* param job job_id
* param ?output boolean: True to return job output
* response status string: `"wait"` if the job has not started, `"run"` if it is running, `"done"` if it is complete, `"fail"` if it failed
* response update_at integer: UNIX time that job was last updated (if this time is far in the past, the job likely crashed)
* response output: Job output, either as a JSON object or a UTF-8 string
* response output_base64 string: Base64-encoded job output (if job output isn’t a UTF-8 string)
