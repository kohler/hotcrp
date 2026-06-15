# Review preferences

A **review preference** records how much a PC member wants to review a particular
submission. HotCRP uses preferences to drive automatic review assignment (the
`pref` [autoassigner](#post-autoassign)) and to order the assignment UI.

A preference has two parts:

* A signed integer **preference value**: positive means the PC member wants to
  review the submission (larger is stronger), negative means they would rather
  not (more negative is stronger), and `0` or absent is neutral.
* An optional **expertise** indicator: `X` (expert), `Y` (some expertise), or
  `Z` (little expertise).

In text the two combine as the value followed by the expertise letter — for
example `10X` (strong preference, expert) or `-5` (mild aversion, no expertise
stated).

PC members set their own preferences, for any submission, regardless of whether
they can currently see it (so a preference is not lost when a submission later
becomes visible). Administrators may read and set another PC member’s preference
by naming them in `u`. Preferences are private: they are visible only to the PC
member and to administrators.

These endpoints are also reachable under the aliases `pref` and `reviewpref`.


# get /{p}/revpref

> Retrieve a review preference

Return a PC member’s review preference for submission `p` — by default the
caller’s own, or another PC member’s when `u` is given (administrators only).

`pref` is the numeric preference and `value` is its text form (number plus any
expertise letter); `value` is an empty string when no preference is set.
`prefexp` gives the expertise indicator on its own when one is set, and
`topic_score` reports how well the submission’s topics match the PC member’s
topic interests (present only when the conference uses topics).

* param ?u string: PC member whose preference to read (email or user ID); administrators only. Defaults to the caller.
* response value string: The preference in text form (e.g. `10X`), or an empty string if unset.
* response pref integer: The numeric preference value.
* response ?prefexp =X|Y|Z: Expertise indicator, when set: `X` expert, `Y` some, `Z` little.
* response ?topic_score integer: Topic-interest match score, when the conference uses topics.


# post /{p}/revpref

> Set a review preference

Set a PC member’s review preference for submission `p`, then return it as
[`revpref` GET](#get-revpref) does. By default the change applies to the caller;
administrators may set another PC member’s preference with `u`.

Supply the new preference in `pref`, in text form: a signed integer, optionally
followed by an expertise letter (`X`, `Y`, or `Z`) — for example `10`, `-5`, or
`20X`. An empty value clears the preference.

* param ?u string: PC member whose preference to set (email or user ID); administrators only. Defaults to the caller.
* param +=pref string: New preference in text form (e.g. `10X`); empty to clear.
* response value string: The stored preference in text form, or an empty string if cleared.
* response pref integer: The stored numeric preference value.
* response ?prefexp =X|Y|Z: Expertise indicator, when set.
* response ?topic_score integer: Topic-interest match score, when the conference uses topics.
