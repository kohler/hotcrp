# Users

These endpoints look up individual users and list the program committee.

User identities are not generally browsable through the API: who you can see
depends on your role. PC members can see other PC members; administrators can see
more; and a site may optionally allow broader lookup. Accounts are identified by
email throughout.


# get /user

> Look up a user by email

Look up a single user by `email`. The lookup is prefix-oriented, which makes it
suitable for type-ahead completion: it returns the account whose email is the
closest match at or after the query, and `match` reports whether that email is an
*exact* match for `email` (rather than merely the next account alphabetically).

When no account is found, the response is `{"ok": true, "match": false}` with no
profile fields. When one is found, its `email`, `given_name`, `family_name`, and
`affiliation` are returned (plus `country` and `orcid` when set). Visibility
still applies — a caller who may only see PC members will only match PC members.

Give `email` as a full or nearly-complete address; very short, non-email queries
return no match. Supplying a submission `p` together with `potential_conflict=1`
adds a `potential_conflict` description when the looked-up user has a possible
conflict with that submission and the caller may see its authors.

* badge featured
* param email email: Email address to look up.
* param ?p pid: Submission to check for a potential conflict with the user.
* param ?potential_conflict boolean: With `p`, include a `potential_conflict` description if one applies.
* response match boolean: True if an account exactly matching `email` was found.
* response ?email email: The found account’s email.
* response ?given_name string: First (given) name.

    * condition email

* response ?family_name string: Last (family) name.

    * condition email

* response ?affiliation string: Affiliation.

    * condition email

* response ?country string: Country code, when set.

    * condition email

* response ?orcid string: ORCID iD, when set.

    * condition email

* response ?potential_conflict string: HTML describing a potential conflict with submission `p`, when requested and applicable.

    * condition email
    * condition potential_conflict


# get /pc

> List the program committee

Return the program committee roster in `pc`, plus related metadata. Each `pc`
entry describes one PC member; the detail included (such as email) depends on the
caller’s permissions. `sort` is `last` when the roster is ordered by last name,
and `tags` lists the user tags the caller may see.

When the request is made in the context of a submission the caller administers,
`p` carries per-submission information keyed by submission ID — notably which PC
members are `assignable` as reviewers.

Set `ui=1` to receive the richer representation HotCRP’s own interface uses.

* badge featured
* param ?ui boolean: Return the representation used by the HotCRP web interface.
* response pc [object]: The PC members.
* response ?sort string: `last` when the roster is sorted by last name.
* response ?tags tag_list: User tags visible to the caller.
* response ?p object: Per-submission assignment information, when the request has an administered submission in context.


# get /account

> Retrieve account status

Return the status of a user account: its `email`, whether it is `disabled`, and
whether it is a `placeholder` (a stub account created on someone’s behalf — for
example a requested reviewer who has not yet signed in — that is not yet a fully
activated account).

Name the account in `email`; `me` or the caller’s own email selects the caller.
PC members may query other accounts by email; non-PC callers may query only their
own.

* param email email: Account to inspect; `me` or the caller’s own email for self.
* response email email: The account’s email.
* response disabled boolean: Whether the account is disabled.
* response placeholder boolean: Whether the account is an unactivated placeholder.


# post /account

> Modify an account

Perform an administrative action on the account named by `email`. Chairs only.
Exactly one action applies per request:

* `disable=1` — disable the account, blocking sign-in. (You cannot disable your
  own account.)
* `enable=1` — re-enable a disabled account.
* `sendinfo=1` — email the account’s sign-in information to its owner.

The response reports the account’s resulting status, as for
[`account` GET](#get-account).

* param email email: Account to modify; `me` or the caller’s own email for self.
* param ?disable boolean: Disable the account.
* param ?enable boolean: Re-enable the account.
* param ?sendinfo boolean: Email account information to the owner.
* response email email: The account’s email.
* response disabled boolean: Whether the account is disabled.
* response placeholder boolean: Whether the account is an unactivated placeholder.
