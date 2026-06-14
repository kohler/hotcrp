# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

HotCRP is conference-review software: paper submission, reviewing, comments, rebuttals, PC meetings. It is a PHP application (supports PHP 7.3–8.5) backed by MariaDB/MySQL, with vanilla-JS/CSS front-end assets. There is no build step for PHP; JS/CSS in `scripts/` and `stylesheets/` are served as-is.

## Commands

Tests use a dedicated database (`hotcrp_testdb`, defined in `test/options.php`) plus an optional contact database (`test/cdb-options.php`). Create them once with `lib/createdb.sh -c test/options.php` and `lib/createdb.sh -c test/cdb-options.php --no-dbuser`.

```sh
php test/run.php            # run default test collections
php test/run.php --all      # run everything (what CI runs)
php test/run.php -V         # verbose (prints each test name)
php test/run.php -s         # stop on first failure
php test/run.php --no-cdb   # run without the contact database

# Run one test class (can drop the `_Tester` suffix; CamelCase is fine):
php test/run.php Login

# Run one test METHOD: the filter is fnmatch'd against the method name
php test/run.php 'Login::test_reset_request_with_email'
php test/run.php 'Login::test_reset*'      # wildcard works
```

Test classes live in `test/t_*.php` as `*_Tester` classes; any public method named `test*` is a test. Collections (`test01`…`test09`, `default`, `all`) and the run harness are defined in `test/setup.php` (`class TestRunner`). Tests assert with `xassert*` helpers (`xassert`, `xassert_eqq`, `xassert_str_contains`, …) and construct requests via `TestQreq::get/post`. `fresh_db` / `no_cdb` are pseudo-tests in a collection that reset state mid-run.

Static analysis (not run in CI, but the codebase is heavily phan-annotated — preserve `@param`/`@return`/`@phan-*` docblocks when editing):

```sh
vendor/bin/phan          # config in .phan/config.php
npx eslint scripts/      # JS lint; config in .eslintrc.json
```

## Architecture

### Request dispatch

Every web request enters `index.php`, which parses the URL via `Navigation` (`lib/navigation.php`) into a page name + path, then `require`s `src/init.php` and calls `handle_request()`. The top-level `*.php` files in the repo root (`paper.php`, `signin.php`, `review.php`, …) are thin stubs that exist only so URLs like `/paper.php` resolve; the real handlers are `src/pages/p_*.php` classes (e.g. `Paper_Page`, `Signin_Page`).

`handle_request()` builds a `ComponentSet` via `$conf->page_components($user, $qreq)`, looks up the page component, runs its `request_function`(s), then renders via `print_*` functions. Control flow uses exceptions: `Redirection`, `JsonCompletion` (API results), and `PageCompletion` are caught at the top of `handle_request()` — code deep in the call stack throws these rather than returning. `Qrequest::redirect()` and friends throw `Redirection`.

The `/api` page is special-cased to `API_Page::go_nav()` before normal dispatch; `images`/`scripts`/`stylesheets`/`cacheable` are served as static assets.

### The component ("Xt") system — read `devel/manual/components.md`

This is the central extensibility mechanism and the thing most worth understanding before making changes. Nearly every user-facing feature — pages, API endpoints, search keywords, paper-list columns, submission-field types, review-field types, settings, mail templates, formula functions, help topics, list actions — is declared as **JSON component fragments** in `etc/*.json`, then merged and dispatched at runtime.

- Each `etc/<feature>.json` (e.g. `pages.json`, `apifunctions.json`, `papercolumns.json`, `settinginfo.json`, `optiontypes.json`, `searchkeywords.json`) has a matching `$Opt[...]` override key so a conference can add/replace/disable components via `conf/options.php` without touching core code.
- A component (commonly called a `gj`, "group JSON") is looked up by `name`, optionally hierarchical with `/` (e.g. `home/content`). `ComponentSet` (`src/componentset.php`) **expands** the config + overrides into fragments, **searches** by name, and **merges** matches into one object.
- Components name PHP callbacks (`request_function`, `print_function`, `*_function`). `ComponentSet::call_function()` invokes them; a `ClassName::method` callback constructs the class on demand (and `$conf->call_function`/`callable()` cache instances). This is why page logic is split across many small functions keyed by component name rather than one big controller.
- Visibility is controlled by an `allow_if` expression evaluated by `XtParams` (`src/xtparams.php`) against the viewer — terms like `chair`, `pc`, `reviewer`, `author`, `tag:X`, `setting.X`, plus `&`/`|`/`!`. Some feature tables also support `match` (pattern-expanded components, e.g. one column def covering all review scores).

When adding a feature of an existing kind, the pattern is: add a JSON fragment in `etc/`, and add an implementation class in the corresponding `src/` subdirectory (below).

### `src/` subdirectory conventions

Implementation classes for each component kind live in dedicated subdirectories, one class per file with a consistent prefix:

- `src/pages/` — `p_*.php` page handlers (`Paper_Page`, `Search_Page`, …)
- `src/papercolumns/` — `pc_*.php` paper-list columns (subclasses of `PaperColumn`, `src/papercolumn.php`)
- `src/options/` — `o_*.php` submission-field types (subclasses of `PaperOption`)
- `src/reviewfields/` — `rf_*.php` review-field types (subclasses of `ReviewField`)
- `src/settings/` — `s_*.php` settings parsers/renderers (driven by `SettingValues`)
- `src/search/` — `st_*.php` search-term implementations
- `src/api/` — REST API endpoint handlers
- `src/assigners/`, `src/autoassigners/` — bulk- and auto-assignment strategies
- `src/capabilities/`, `src/formulas/`, `src/help/`, `src/listactions/`, `src/userinfo/` — same one-class-per-feature pattern

`lib/` holds domain-independent utilities (`dbl.php` DB layer, `qrequest.php`, `navigation.php`, `tagger.php`, `csv.php`, `mimetype.php`, …). `src/` holds HotCRP-domain classes.

### Core domain objects

- **`Conf`** (`src/conference.php`) — the conference singleton (`Conf::$main`): DB handle, settings, and caches for users/options/review form/search. Almost everything takes a `Conf`. `page_components()`, `setting()`, `fetch_*`, `hoturl*()` live here.
- **`Contact`** (`src/contact.php`) — a user, including roles bitmask and the bulk of the **permission system** (`can_view_*`, `can_edit_*`, `is_pc_member`, …). Permission checks are pervasive; respect them.
- **`PaperInfo`** (`src/paperinfo.php`) — a submission, lazily joined with reviews/options/conflicts/tags. Loaded in "slices" for performance.
- **`PaperOption` / `PaperValue`** (`src/paperoption.php`) — a configurable submission field and its value on a paper.
- **`ReviewInfo`** / **`ReviewField`** (`src/reviewinfo.php`, `src/reviewfield.php`) — a review and a configurable review-form field.
- **`PaperSearch`** (`src/papersearch.php`) — parses search strings into a term tree (the `st_*` classes) and produces matching paper IDs; the backbone of every paper list.
- **`Tagger`** (`lib/tagger.php`) — tag parsing/permissions (votes, rankings, colors).
- **`AssignmentSet`** (`src/assignmentset.php`) — applies bulk review/conflict/tag/etc. assignments from CSV or API.
- **`SettingValues`** (`src/settingvalues.php`) — validates and commits conference settings.

### Database & schema

`src/schema.sql` is the canonical schema. Schema migrations live in `src/updateschema.php`: it compares the stored schema version (`sversion` setting) against the code and runs ordered upgrade steps. When changing the schema, update **both** `schema.sql` (for fresh installs) and add an `updateschema.php` step (for existing databases).

Most conference configuration lives in the DB `Settings` table, not in code. A `Settings` row named `opt.XXX` overrides `$Opt["XXX"]` from `conf/options.php`.

### Contact database (cdb)

Optionally, multiple HotCRP instances share a **contact database** (configured by `$Opt["contactdbDsn"]`) so a person has one cross-conference identity. Code paths frequently reconcile a local `Contact` with its cdb counterpart (`cdb_user()`, `contactDbId`). Tests run both with and without it; the `--no-cdb` flag / `no_cdb` pseudo-test exercise the cdb-absent path. When touching account/login/user-merge logic, consider both modes.

### Batch / CLI scripts

`batch/*.php` are standalone command-line tools (`php batch/<name>.php [opts]`), e.g. `backupdb.php`, `db.php` (run SQL), `apispec.php` (regenerate the OpenAPI spec), `autoassign.php`. Each guards execution with a "invoked directly?" check and bootstraps `Conf` itself, so the same file can also be `require`d as a library.

## Conventions

- Match the surrounding code: HotCRP uses no namespaces, `snake_case` methods, abundant phan docblocks, and 4-space indent.
- User-visible message strings are prefixed with a format sigil (e.g. `"<0>plain text"`, `"<5>html"`) consumed by the `Ftext`/`MessageSet` system — preserve the prefix.
- Prefer adding a JSON component + one implementation class over editing a central dispatcher.
- When fixing a bug, add a regression test to the relevant `test/t_*.php` and verify it fails before the fix and passes after.
