# HotCRP threat model

## What this project does

HotCRP is conference review software: authors submit papers, a program committee
(PC) and/or external reviewers review them, PC members discuss, chairs make
decisions, and authors read reviews and respond. It is a PHP web application
backed by MariaDB/MySQL with a vanilla-JS front end. Many conferences run it,
including a large hosted service at hotcrp.com. It has also been adapted for
other use, such as grad student or faculty application review.

Its core security job is **confidentiality and integrity of the review process**
among mutually distrustful users sharing the site. A typical conference has
hundreds to thousands of authors, many of whom are also reviewers or PC members.
When review is double-blind (the normal case), reviewers must not be able to
identify authors, or vice versa. Many PC members are also authors, and for the
submissions they wrote, they lose PC privilege. Confidentiality and integrity
settings are configurable, and large HotCRP deployments often have complex
permission structures, such as many tracks. Unusual setting combinations are
worth exploring.

## Principals and trust

From least to most privileged:

- **Unauthenticated visitor.** Can create an account, sign in, reset a
  password, and view the public parts of a site.
- **Signed-in user with no role.** Typically a prospective author.
- **Author/contact of a submission.** Sees their own submission and, once the
  chairs release them, its reviews and decision. Must not learn reviewer
  identities (unless the conference makes reviews open), other submissions,
  PC-only or admin-only comments, tags, or decisions before release. Contact
  authorship is a privilege: only existing contacts and administrators may
  change a submission's contacts.
- **External reviewer.** Sees papers they are assigned, but usually not
  authorship (most conferences implement double-blind review). Other reviews
  become visible only as settings allow.
- **PC member.** Generally broad visibility, except where there is a conflict of
  interest (such as authorship over a submission). Conflicted PC members must
  not see reviews, reviewer identities, discussions, decisions, or hidden tags.
  Also, conferences can configure **tracks**, controlled by paper and user tags,
  which limit PC member access rights to subsets of submissions. Conflicts and
  tracks are important security boundaries.
- **Track manager/paper manager/administrator of a subset of papers.**
  Administrative over a subset only.
- **Chair/site administrator (`privChair`).** Generally trusted. See "Out of
  scope".
- **Site operator.** Fully trusted: the filesystem, `conf/options.php`,
  the database, and the command-line tools in `batch/`.

Beyond reviews, authorship, and decisions, these are also confidential:
commenter identities on anonymous comments, review preferences, review types
(e.g., primary vs. external), review rounds and unsubmitted assignments,
explicit notification choices, the action log (including mail sent about
papers a manager does not manage), and the list of site accounts and their
email addresses (other than accounts the viewer can legitimately see, such as
the PC).

Other principals:

- **API bearer tokens** (`hct_…`/`hcT_…`, created by `batch/apitoken.php` or the
  Profile page) are associated with a user and carry a **scope**. A token must
  never exceed its scope (e.g., a `submission:read` token must not write, a
  `read#2` token must not see paper #3), **even when the token belongs to a
  chair**.
- **Capability link holders.** Links mailed to users, such as review-accept
  links, author-view links, and review tokens, grant access without (or in
  addition to) an account (`src/capabilities/`, `src/tokeninfo.php`). A link
  must grant only what it names, must respect conflicts, and must not leak to
  other principals (e.g., through an API response to a scoped token).
- **OAuth/OpenID Connect/LDAP.** HotCRP can be an OAuth authorization server
  for MCP and other clients and can sign users in through external OAuth/OIDC
  providers or LDAP (`lib/ldaplogin.php`).
- **Contact database (cdb).** Optionally, many conferences on one server
  share a contact database for cross-conference identity. Actions in one
  conference must not grant privileges in another.

Untrusted input enters through every web page and API endpoint, uploaded
documents (PDFs, ZIPs, videos, CSV/JSON bulk uploads), search queries and
formulas, tag strings, email addresses and names (which flow into outgoing
mail), comment @mentions, and OAuth/OIDC/LDAP protocol messages.

## Components that matter most

- Permission logic: `src/contact.php` (`can_view_*`, `can_edit_*`,
  `rights()`), `src/paperinfo.php`, `src/reviewinfo.php`,
  `src/commentinfo.php`, conflict handling.
- Search and formulas (`src/papersearch.php`, `src/search/`, `src/formula*.php`,
  `src/formulas/`): every paper list runs through them, and they are prone to
  **oracles**—a query whose result, error, or sort order reveals a value the
  viewer could not see directly. Formulas compile to PHP code
  (`src/formula.php`, `src/formulaparser.php`); injection there is remote code
  execution. Users can also define named searches and formulas that other
  users, including chairs, later evaluate (e.g., in automatic tags); such
  definitions must not run with, or be able to shadow names under, a more
  privileged user's rights.
- **Oracles in general.** Any observable difference in error text, status code,
  response shape, or no-op vs. permission error that depends on something the
  viewer cannot see is a potential leak. (Timing is different; see "Severity
  ratings".) This applies well beyond search.
- API endpoints (`src/api/`, `etc/apifunctions.json`) and their token scopes.
- Bulk assignment (`src/assignmentset.php`, `src/assigners/`) and list actions
  (`src/listactions/`). Every user can access assignment, so assigners must
  take care with permissions and avoid error oracles.
- Tags (`lib/tagger.php`, `src/assigners/a_tag.php`): private `~tags`,
  chair-only `~~tags`, hidden/conflicted tags, votes and allotments.
- Submission editing (`src/paperstatus.php`, `src/options/`,
  `src/api/api_paper.php`): which fields each principal may change, including
  contacts.
- Review and comment editing and display (`src/reviewvalues.php`,
  `src/reviewform.php`, `src/reviewfields/`, `src/commentinfo.php`,
  `src/api/api_comment.php`): field visibility (PC-only, admin-only,
  conditional fields), offline review forms, comment attachments.
- Paper lists (`src/paperlist.php`, `src/papercolumns/`): columns, sorting,
  and statistics must not reveal values hidden from the viewer.
- Mentions, completion, and the meeting tracker (`src/mentionlister.php`,
  `src/mentionparser.php`, `src/meetingtracker.php`): suggestions and
  notifications must not reveal conflicts or identities.
- Author matching and conflict detection (`src/authormatcher.php`).
- Sign-in, password reset, sessions, CSRF protection, OAuth, LDAP
  (`src/pages/p_signin.php`, `src/pages/p_authorize.php`,
  `src/pages/p_oauth.php`, `lib/login.php`, `lib/ldaplogin.php`,
  `lib/qsession.php`, `src/init.php`). State changes must require a valid CSRF
  token or bearer token regardless of HTTP method.
- Outgoing mail (`lib/mailer.php`, `src/hotcrpmailer.php`, `src/mailsender.php`,
  `src/mailrecipients.php`, `lib/mailpreparation.php`, `lib/mimetext.php`):
  recipient and header construction from user data; mail-template keyword
  expansion (user-controlled names and text must not inject keywords or
  headers); and which review, comment, and decision content reaches which
  recipients.
- Document upload, storage, and download (`src/documentinfo.php`,
  `src/documentrequest.php`, `lib/downloader.php`, `lib/mimetype.php`, format
  checking via `pdftohtml`): access checks on download, `Content-Type` and
  `Content-Disposition` (uploaded HTML or SVG must never render inline),
  byte-range requests, and consistency between a document's recorded hash and
  its stored content.
- HTML output: everything should be escaped; `lib/cleanhtml.php` sanitizes
  the limited HTML that chairs may enter.

Less important: `batch/` scripts (run by the trusted operator; only matters
when they process data from untrusted users), `devel/`, `test/` (test
harness, out of scope), static assets.

## How to exercise it

The image has no network and no running services. Start them with:

```sh
hotcrp-scanctl start      # MariaDB, php-fpm, and nginx on http://localhost:8080/
hotcrp-scanctl help       # all commands
```

nginx serves two conferences from one HotCRP installation, as a production
multiconference site would. They share a contact database, so an account in
both has one password and one identity. **Every account's password is
`test1234`.**

- **`http://localhost:8080/conf1/`** is the test fixture `test/db.json`: 30
  papers, reviews, conflicts, tags, and accounts.
  - `chair@_.com` — chair/administrator
  - `chair2@z.edu` — a second chair, conflicted with paper 20, which has its
    own paper administrator (`marina@poema.ru`)
  - `marina@poema.ru`, `estrin@usc.edu`, `floyd@ee.lbl.gov`, … — PC members
  - `vern@ee.lbl.gov`, `anja@research.att.com`, … — authors without PC roles
- **`http://localhost:8080/conf2/`** has two papers (authors include
  `estrin@usc.edu` and `floyd@ee.lbl.gov`) and one review.
  - `vern@ee.lbl.gov` — chair/administrator (only an author in conf1)
  - `marina@poema.ru`, `anja@research.att.com` — PC members

conf2's chair attacking conf1 exercises the cross-conference boundary.

The conferences also store documents differently. conf1 keeps document
contents only in a filesystem document store (`$Opt["docstore"]` under
`/var/lib/hotcrp/docs/conf1`, with `$Opt["dbNoPapers"]`), which also enables
the chunked upload API (`/api/upload`). conf2 keeps documents in
the database, the default for a new installation, so it has no upload API.

Both conferences are open for submissions and reviewing, and one sign-in
covers both. By default, conf1 is populated as if mid-review:

- 40 submitted reviews, some drafts, and external reviews (one requested by a
  PC member); reviews and decisions are not yet visible to authors
- comments of every visibility (admin-only, PC-only, reviewers, authors),
  including comments anonymous to authors, and author responses on papers 1
  and 2
- decisions on papers 1, 2, 5, 9, and 13
- tags: public (`discuss`), private (`~toread`), chair-only (`~~chairnote`),
  hidden (`secret`), allotment votes (`vote`), and review preferences
- a **track**: papers 3, 7, and 12 are tagged `red-track`, and only PC members
  tagged `red` (e.g., `estrin@usc.edu`, `floyd@ee.lbl.gov`) may view them;
  `marina@poema.ru` may not

These are sample settings, not the only supported configuration; feel free
to change settings to reach other states. `hotcrp-scanctl reset` restores
this state, and `hotcrp-scanctl reset --minimal` (or `start --minimal`)
restores a minimal one with only accounts, papers, and review assignments.

Signing in takes two POSTs: the first creates a session and returns a form
whose hidden `post` field is the CSRF token for the second.

```python
B = "http://localhost:8080/conf1"
s = requests.Session()
r = s.post(B + "/signin", data={"email": e, "password": "test1234", "post": ".empty"})
tok = re.search(r'name="post" value="([^"]+)"', r.text).group(1)
s.post(B + "/signin", data={"email": e, "password": "test1234", "post": tok})
s.get(B + "/api/whoami").json()      # {"ok": true, "email": e, ...}
```

Session-authenticated POSTs need that token as a `post` parameter (query
string or body); bearer-token requests do not. Use `localhost` (not
`127.0.0.1`) consistently within a session.

Captured outgoing mail is in `/var/spool/hotcrp-mail`, one `.eml` file per
message, with links under `to/ADDRESS/` for every address that appears in a
message's To, Cc, or Bcc header (`hotcrp-scanctl mail` lists them).
`hotcrp-scanctl reset` restores both conferences; `hotcrp-scanctl db
[conf1|conf2]` opens a SQL shell on one. Batch scripts select a conference with
`-n`. Create a bearer token with:

```sh
php batch/apitoken.php -n conf1 create -u marina@poema.ru --scope 'submission:read'
curl -H "Authorization: Bearer hct_…" 'http://localhost:8080/conf1/api/paper?p=1'
```

Logs are in `/var/log/hotcrp-scanctl`; PHP warnings appear in `php-fpm.log`.

API documentation is in `devel/apidoc/`; component architecture is in
`devel/manual/components.md` and `CLAUDE.md`.

The unit tests use a separate database and are the fastest way to exercise
permission logic in-process:

```sh
hotcrp-scanctl test                  # default collections
hotcrp-scanctl test Comments         # one *_Tester class (test/t_comments.php)
hotcrp-scanctl test 'Login::test_*'  # methods matching a pattern
```

Tests build on shared database state; a method-filtered run does not reset
the database first.

## Severity ratings

- **Critical:** unauthenticated account takeover, authentication bypass, remote
  code execution, SQL injection, arbitrary file read or write on the server,
  mail header/recipient injection that lets an outsider receive another user's
  password-reset or sign-in mail, bulk exfiltration of many papers' reviews or
  blind-submission author identities, control of settings by a non-chair,
  privilege escalation by a non-chair.
- **High:** a low-privilege principal (author, external reviewer, ordinary or
  conflicted PC member, scoped API token, OAuth client) breaks review
  confidentiality or integrity wholesale (e.g., reads all reviews of a paper,
  learns all reviewer identities for a paper, accesses a paper they should not
  know about, edits another user's review, changes a decision) or escalates to
  administrator, or to another conference via the contact database; stored XSS
  that an author or reviewer can plant and a chair or PC member will execute in
  normal use.
- **Medium:** a bounded leak (one decision, one tag value, one reviewer's
  identity, which PC members are conflicted), an oracle that needs many queries
  or foreknowledge of settings, a timing channel that exposes valuable
  information with few requests, a limited unauthorized write, single-request
  resource exhaustion with large amplification (seconds of CPU or large memory
  from a small request, e.g., regular-expression backtracking or quadratic
  parsing in search, CSV, JSON, diff, or document-format code), and stored XSS
  that needs an unusual victim action.
- **Low:** sort-order or count oracles that reveal little, leaks of information
  the viewer can already obtain another way, leaks visible only to PC members
  about non-sensitive metadata, and timing channels that require many requests.

Timing channels are expected and inevitable, and HotCRP does not aim to close
them all: for example, search uses SQL prefilters that only approximate
permission checks, so response times can depend on hidden data. Timing reports
are welcome, rated as above, but should not be the only focus.

Leaks of reviewer identity to authors or conflicted PC members and violations of
track permissions for PC members are centrally important; please err toward
reporting them.

## Out of scope / leave alone

- **Chairs and site administrators are generally trusted.** Chairs can see and
  change everything, override conflicts, enter HTML (settings, field
  descriptions, banners, mail templates), "act as" other users, and write
  formulas and searches over all conference data. Issues that require chair
  privileges are out of scope. **Exceptions:** (1) Chairs have privilege only
  for conferences they chair; a chair must not be able to take over an account
  on another conference (e.g. in a cdb setting). (2) Conflicted chairs have
  limited rights to papers that have a specific paper administrator assigned;
  only removing the administrator will regrant the usual rights. (3) Chairs
  should not be able to deanonymize *review tokens*, which designate reviews
  unassociated with any account.
- Administrative actions by track managers and paper administrators over the
  subset of papers they administer. (Administrative access *outside* the subset
  is in scope.)
- Behavior a conference deliberately enables in settings (open review,
  reviewer identities visible to authors, PC sees all reviews, etc.).
- Self-XSS and attacks in which the attacker only harms their own session.
- Volumetric DoS, brute force, and anything that needs many concurrent
  connections; lack of rate limiting. (Race conditions that a few concurrent
  requests can trigger, such as exceeding a vote allotment or shedding a
  conflict, are in scope.)
- The site operator's configuration and command-line tools invoked with
  operator-chosen arguments.
- The nginx, php-fpm, and MariaDB configuration used in this image.
- `test/`, `devel/`, and generated or third-party code.

## Reports and patches

- Show the principal, the boundary crossed, and the call chain. A reproducer
  against the sample conference (HTTP, or an in-process PHP test) is ideal.
- Patches should be minimal and match the surrounding code (4-space indent,
  `snake_case`, phan docblocks, no namespaces; see `CLAUDE.md`). Short commit
  messages are preferred.
- Include a regression test in the relevant `test/t_*.php` that fails before
  the fix and passes after. Name tests after the behavior they check, not after
  a report identifier.
