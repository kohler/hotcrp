# Notifications

These endpoints cover reviewing **activity** — the feed of recent reviews and
comments — and the per-submission **follow** settings that determine who is
emailed when that activity happens.


# get /events

> Retrieve recent activity

Return a page of recent reviewing activity visible to the caller — newly
submitted reviews and comments — most recent first. Available to reviewers.

Results are paged in blocks of up to 10 events. Each request returns events
strictly before the `from` timestamp (a UNIX time, defaulting to now). To fetch
the next, older page, pass the response’s `to` value as the next request’s
`from`; the `more` flag indicates whether further events exist before `to`.

Each entry in `rows` is a pre-rendered HTML activity item used by HotCRP’s feed
UI. As with other HTML the API returns, its markup and class names are internal
to HotCRP and may change; applications needing structured data should read
reviews and comments through their own endpoints.

* param ?from integer: Return events before this UNIX time. Defaults to now.
* response from integer: The cutoff time this page was computed from.
* response to integer: Cutoff for the next (older) page; pass it back as `from`.
* response rows [string]: Recent activity entries, as HTML, newest first.
* response more boolean: Whether more events exist before `to`.


# post /{p}/follow

> Set submission follow status

Set whether a user **follows** submission `p`. A follower is emailed when reviews
or comments are added to the submission. By default the setting applies to the
caller; administrators may change another user’s setting with `u`.

* param =following boolean: Whether the user should follow the submission.
* param ?u string: User whose setting to change (email or user ID); administrators only. Defaults to the caller.
* response following boolean: The follow state after the change.
