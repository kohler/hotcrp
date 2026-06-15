# Submission administration

These endpoints set per-submission administrative assignments — the **decision**,
the discussion **lead**, the **shepherd**, and the submission **administrator
(manager)** — one submission at a time, and move a review between rounds. They
exist for the HotCRP web application’s convenience; each is a thin wrapper around
the [assignment](#post-assign) machinery, so external integrations changing many
submissions should prefer the general-purpose [`/assign`](#post-assign) endpoint.

The four assignment roles:

* **Decision** — the submission’s accept/reject outcome.
* **Lead** — the PC member who leads discussion of the submission.
* **Shepherd** — the PC member who oversees the submission toward its final
  version.
* **Manager** — the administrator responsible for the submission.

Each role has a matching `GET` (read the current value) and `POST` (change it).
The `POST` endpoints require the appropriate administrative permission and report
the updated value in the same shape as the corresponding `GET`. All of these
operations are visible to, and editable by, administrators only.


# get /{p}/decision

> Retrieve submission decision

Return the decision recorded for submission `p`. `decision` is the decision’s
numeric ID: `0` means no decision, a positive ID is an accept-class decision,
and a negative ID is a non-accept decision (reject-class or indeterminate).
`decision_html` is its configured name as HTML. `editable` is present when the
caller may change the decision.

* response decision integer: Decision ID; `0` means no decision.
* response decision_html string: Decision name, as HTML.
* response ?editable boolean: Present when the caller may change the decision.


# post /{p}/decision

> Change submission decision

Set the decision for submission `p`. Supply `decision` as either a decision ID or
a decision name. The caller’s conflicts are overridden. The response reports the
new decision as [`decision` GET](#get-decision) does.

* param =decision string: New decision, given as a decision ID or name.
* response decision integer: Decision ID; `0` means no decision.
* response decision_html string: Decision name, as HTML.
* response ?editable boolean: Present when the caller may change the decision.


# get /{p}/lead

> Retrieve submission discussion lead

Return the discussion lead of submission `p`. `lead` is the lead’s email, or
`none` if unset; `lead_html` is their name as HTML (`None` when unset);
`color_classes` carries any style classes implied by the lead’s user tags.

* response lead string: Discussion lead’s email, or `none`.
* response lead_html string: Discussion lead’s name as HTML, or `None`.
* response ?color_classes style_classes: Style classes from the lead’s user tags.


# post /{p}/lead

> Change submission discussion lead

Set the discussion lead of submission `p`. Supply `lead` as a PC member’s email,
or `none` to clear it. The response reports the new lead as
[`lead` GET](#get-lead) does.

* param =lead string: New discussion lead’s email, or `none` to clear.
* response lead string: Discussion lead’s email, or `none`.
* response lead_html string: Discussion lead’s name as HTML, or `None`.
* response ?color_classes style_classes: Style classes from the lead’s user tags.


# get /{p}/manager

> Retrieve submission administrator

Return the administrator (manager) of submission `p`. `manager` is their email,
or `none` if unset; `manager_html` is their name as HTML (`None` when unset);
`color_classes` carries any style classes implied by the manager’s user tags.

* response manager string: Administrator’s email, or `none`.
* response manager_html string: Administrator’s name as HTML, or `None`.
* response ?color_classes style_classes: Style classes from the administrator’s user tags.


# post /{p}/manager

> Change submission administrator

Set the administrator (manager) of submission `p`. Supply `manager` as a PC
member’s email, or `none` to clear it. The response reports the new
administrator as [`manager` GET](#get-manager) does.

* param =manager string: New administrator’s email, or `none` to clear.
* response manager string: Administrator’s email, or `none`.
* response manager_html string: Administrator’s name as HTML, or `None`.
* response ?color_classes style_classes: Style classes from the administrator’s user tags.


# get /{p}/shepherd

> Retrieve submission shepherd

Return the shepherd of submission `p`. `shepherd` is their email, or `none` if
unset; `shepherd_html` is their name as HTML (`None` when unset); `color_classes`
carries any style classes implied by the shepherd’s user tags.

* response shepherd string: Shepherd’s email, or `none`.
* response shepherd_html string: Shepherd’s name as HTML, or `None`.
* response ?color_classes style_classes: Style classes from the shepherd’s user tags.


# post /{p}/shepherd

> Change submission shepherd

Set the shepherd of submission `p`. Supply `shepherd` as a PC member’s email, or
`none` to clear it. The response reports the new shepherd as
[`shepherd` GET](#get-shepherd) does.

* param =shepherd string: New shepherd’s email, or `none` to clear.
* response shepherd string: Shepherd’s email, or `none`.
* response shepherd_html string: Shepherd’s name as HTML, or `None`.
* response ?color_classes style_classes: Style classes from the shepherd’s user tags.


# post /{p}/reviewround

> Change a review’s round

Move one review of submission `p` into a different review round. Restricted to
administrators of the submission. Identify the review with `r` and the
destination round by name in `round`.

* param r rid: Review to move, as a numeric review ID or ordinal.
* param round string: Name of the destination review round.
