# Site administration

These endpoints are administrative tools: inspecting and managing user accounts,
and maintaining the conference’s named formulas. The account-modifying and
formula-saving operations are restricted to chairs.


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


# get /namedformula

> List named formulas

Return the conference’s **named formulas** in `formulas`. A named formula gives a
reusable name to a [formula](#get-search) expression, so it can be referenced
from searches, scoring, and graphs. Each entry has a `name`, its `expression`,
and an `id`; `editable` marks formulas the caller may change, and a per-formula
`message_list` reports any problems evaluating the expression.

* response formulas [object]: The conference’s named formulas.


# post /namedformula

> Save named formulas

Create, edit, or delete named formulas. Chairs only. Changes are supplied as a
numbered list of structured parameters `formula/<n>/<field>`, one group per
formula; the fields are `id` (the existing formula’s ID, or `new`), `name`,
`expression`, and `delete`. On success the updated list is returned as
[`namedformula` GET](#get-namedformula) returns it.

* param ?=:formula string: Structured per-formula fields, `formula/<n>/<field>` (see above).
* response formulas [object]: The named formulas after the change.
