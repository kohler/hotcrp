# HotCRP session data

This page describes the format of HotCRP session data.

HotCRP sessions contain both **global** data, relevant for all conferences
attached to a session, and **conference** data, which is relevant to a single
conference. Conferences are distinguished by their **session keys**, which are
`@` followed by the conference’s database name.


## Global keys

### Session version and expiration

* `v` (integer): Version

    The version of this session data. Currently 2.

* `expt` (integer): Expiration time

    UNIX timestamp at which this session data should expire.

* `deletedat` (integer): Deletion state

    Set when a session has been deleted (e.g., because the user has signed
    out, or because the session ID was regenerated).

### Account information

* `u` (string): Account email

    The email address of some validated account for this session. If `u` is
    not set, then no accounts are validated for this session. Usually, but not
    always, `u` corresponds to the account with session index 0.

* `us` (list of strings): All account emails

    The email addresses of all validated accounts for this session. Multiple
    accounts can sign in to a single session using “Add account”. `us[0]` is
    the email address of the first account to log in, `us[1]` the second, and
    so forth. A URL like `https://conf.hotcrp.com/u/N/` accesses the given
    conference using account `N`. If `us` is not set but `u` is, then `us`
    defaults to the one-element list `[u]`.

* `uchoice` (associative array): Account choice

    When a user is signed in to multiple accounts, HotCRP tries to remember
    the most relevant account for each conference. On receiving a request for
    a plain conference URL (e.g., `https://conf.hotcrp.com/` rather than
    `https://conf.hotcrp.com/u/1/`), HotCRP looks up the best user for that
    conference using `uchoice` and redirect appropriately.

    `uchoice` is keyed by conference session key. The value for a conference
    is a two-element list `[USERINDEX, USETIME]`. `USERINDEX` is the most
    recent user index for this conference, and `USETIME` is the UNIX timestamp
    at which this mapping was used. Mappings in `uchoice` are
    garbage-collected after 60 days.

* `usec` (list of associative arrays): Account security events

    Security information about recent login attempts and security events on
    this session. Each element of the list has the following format:

    * `u` (optional integer, default 0): Index of the relevant account in the
      `us` list.
    * `e` (optional string): Email address of the relevant account. At most
      one of `u` and `e` will be present.
    * `t` (optional integer, default 0): Type of security check. 0 is for
      HotCRP passwords, 2 for TOTP MFA requests.
    * `r` (optional integer, default 0): Reason for the security event. 0 is
      used for login attempts, 1 for security confirmations (e.g., preceding
      attempts to change password).
    * `x` (optional boolean, default false): Error status. False means the
      check succeeded, true means it failed.
    * `a` (integer timestamp): Time of the event.

### Other

* `addrs` (list of up to 5 strings): Recent IP addresses

    The 5 most recent IP addresses that have accessed this session data.

* `smsg` (list): Messages saved across API calls

* `sg` (string): Recent settings group

* `login_bounce`


## Conference keys

Session data relevant to one conference is stored in the session element named
by the conference’s session key, e.g., `@db-sigcomm23`. This element is
another associative array.

* `rev_tokens`

* `pldisplay`

* `pfdisplay`

* `scoresort`

* `uldisplay`

* `ulscoresort`

* `foldpaper`

* `foldpscollab`

* `foldhomeactivity`

* `msgs`

* `settings_highlight`

* `freshlogin`

* `profile_redirect`
