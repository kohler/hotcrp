# Meeting tracker

The **meeting tracker** coordinates a live PC meeting. While it runs, it
broadcasts to PC members’ browsers which submission is currently under
discussion, advancing through a shared list so everyone stays on the same page.
These endpoints back HotCRP’s own meeting-tracker UI; they are not typically
used by external integrations.

A *tracker* is identified by a numeric `trackerid` and walks an ordered list of
submissions (a saved search / “hotlist”). More than one tracker can run at once.
Trackers are controlled by **track managers** — chairs, and PC members who
administer the relevant track. A tracker also has a visibility
setting (the whole PC, or only holders of a given PC tag) and may hide
conflicted submissions.

## Polling and real-time updates

Clients keep in sync by polling [`trackerstatus`](#get-trackerstatus), which is
deliberately cheap: it returns only `tracker_status` (a compact token of the
form `<trackerid>@<position>`, or `off` when nothing is running) and
`tracker_eventid` (an integer that increases on every change). A client compares
these against what it last saw and fetches full details only when they change.
Where a [Comet](https://github.com/kohler/hotcrp-comet) server is configured,
its URL is reported as `tracker_site` and changes are pushed in real time
instead of polled.

## Tracker status fields

The [`track`](#post-track) and [`trackerconfig`](#post-trackerconfig) responses
report the current state through a common set of properties (each present only
when applicable):

* `tracker` — the full tracker state object: its `trackerid`, list position, the
  submissions in view (each with `pid`/`title` and the caller’s relationship to
  it), and, when several trackers run at once, a `ts` array of them. Present
  only when a tracker is running and visible to the caller.
* `tracker_status` — the compact status token, as for `trackerstatus`.
* `tracker_eventid` — the change counter, as for `trackerstatus`.
* `tracker_recent` — the time of the most recent tracker update, when recent.
* `tracker_site` — the Comet server URL, when one is configured.
* `now` — the server’s current time (seconds, with a fractional part), for clock
  synchronization.


# get /trackerstatus

> Poll meeting tracker status

Return a lightweight snapshot of the meeting tracker for change detection. This
endpoint requires no authentication, so the poller (and read-only kiosk
displays) can call it without a session.

* response tracker_status string: Compact status token — `<trackerid>@<position>`, or `off` when no tracker is running.
* response tracker_eventid integer: Counter that increases whenever the tracker changes.


# post /track

> Control the meeting tracker

Start, stop, or advance a tracker. Restricted to track managers. On success the
response reports the updated tracker status (above).

The `track` parameter is the command:

* `stop` — stop all trackers (chairs only).
* `<trackerid> <position>` — point the tracker `<trackerid>` (or `new` to start
  one) at a list position.
* `<trackerid> stop` — stop that one tracker.

The list the tracker walks is supplied in `hotlist-info` (an encoded submission
list, the same form produced elsewhere in the UI). `p` and `tracker_start_at`
optionally pin a specific submission and start time.

* param track string: Tracker command (see above).
* param ?=hotlist-info string: Encoded submission list the tracker walks through.
* param ?p pid: Submission to position the tracker at.
* param ?tracker_start_at integer: Tracker start time.
* response ?tracker object: Full tracker state, when a tracker is running and visible.
* response ?tracker_status string: Compact status token.

    * condition tracker

* response ?tracker_eventid integer: Tracker change counter.
* response ?tracker_recent integer: Time of the most recent tracker update.
* response ?tracker_site string: Comet server URL, when configured.
* response ?now number: Server time, for clock synchronization.

    * condition tracker


# post /trackerconfig

> Configure meeting trackers

Create, modify, or stop trackers and set their display options (name, logo,
visibility, conflict hiding). Restricted to track managers. On success the
response reports the updated tracker status (above), plus
`new_trackerid` for any tracker newly created.

Trackers are configured as a numbered list of structured parameters
`tr/<n>/<field>`, one group per tracker. The fields are:

* `id` — the tracker’s ID, or `new` to create one.
* `name`, `logo` — display label and logo.
* `visibility`, `visibility_type` — who may see the tracker (the whole PC, or a
  specific PC tag).
* `hideconflicts` — whether to hide conflicted submissions (paired with a
  `has_tr/<n>/hideconflicts` presence marker).
* `listinfo` — the encoded submission list the tracker walks.
* `p` — the submission to position at.
* `stop` — stop this tracker.

Set `stopall` to stop every tracker at once. (A legacy flat parameter form,
`tr<n>-<field>`, is also accepted and translated.)

* param ?=stopall boolean: Stop all trackers.
* param ?=:tr string: Structured per-tracker configuration, `tr/<n>/<field>` (see above).
* param ?=:has_tr string: Presence markers for checkbox fields, `has_tr/<n>/<field>`.
* response ?new_trackerid integer: ID of a newly created tracker.
* response ?tracker object: Full tracker state, when a tracker is running and visible.
* response ?tracker_status string: Compact status token.

    * condition tracker

* response ?tracker_eventid integer: Tracker change counter.
* response ?tracker_recent integer: Time of the most recent tracker update.
* response ?tracker_site string: Comet server URL, when configured.
* response ?now number: Server time, for clock synchronization.

    * condition tracker
