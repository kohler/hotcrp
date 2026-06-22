# Account

These endpoints record small interactions a signed-in user has with their own
account in the HotCRP interface: acknowledging a required **clickthrough**
agreement, and dismissing a UI **alert**.


# post /clickthrough

> Accept a clickthrough agreement

Record that the user has accepted a clickthrough agreement—terms that HotCRP
can require someone to accept before proceeding, for example before entering
reviews. Identify the agreement with `clickthrough_id` and set `accept=1`;
acceptance is recorded only when `accept` is true. A successful call returns
`{"ok": true}` with no additional fields.

The acceptance normally applies to the signed-in user. When the caller is acting
through a review token rather than a signed-in account, supply the relevant
submission in `p` so the acceptance is attributed to the associated reviewer.

* param =clickthrough_id string: Identifier of the agreement being accepted.
* param ?=accept boolean: Set true to record acceptance; nothing is recorded otherwise.
* param =clickthrough_type string: The agreement’s type.
* param =clickthrough_time integer: Time of acceptance, as a UNIX timestamp.
* param ?p pid: Submission to attribute the acceptance to when acting through a review token.


# post /dismissalert

> Dismiss an alert

Dismiss the alert named by `alertid` for the calling user, so the interface stops
showing it. Alerts are the advisory banners HotCRP displays in its own UI.

Dismissing an unknown alert returns a `404`; some alerts cannot be dismissed (a
permission error), and alerts marked sensitive cannot be dismissed while acting
as another user.

* param alertid string: Identifier of the alert to dismiss.
