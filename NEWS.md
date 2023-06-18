HotCRP NEWS
===========

## Version 3.0xx

* Upgrade notes

    * PHP 8.2 and 8.3 are supported, PHP 7.0 is not.
    * Remove support for `#perm` tags; the experiment failed.

* Batch scripts

    * `php batch/backupdb.php` backs up the database (not `lib/backupdb.sh`).
    * `php batch/autoassign.php` runs the autoassigner.

* Submissions

    * Add support for multiple submission classes! Different deadlines for
      different kinds of submission.

* Reviews

    * Add more review field types: dropdowns, checkboxes, and multiple
      checkboxes.
    * Accepting or declining a review does not use magic links, since Outlook
      and other systems may “click” those links automatically.
    * Fix longstanding bug with accepting a review with clickthrough terms.
      (Infinite recursion.)

* Search

    * Deprecated shorthand score searches `ovemer:X...Y`, `ovemer:X-Y`.
      Instead be explicit: `ovemer:all:X-Y` and `ovemer:span:X-Y`.

* Navigation: Support deployment within Apache, proxied or not.

* Support decision variants: “Desk-reject” decisions are immediately visible
  to authors, regardless of other settings. There is also support for “other”
  decisions, which are neither acceptish nor rejectish.

* More internal improvements; for example, the autoassigner is extensible.


## Version 3.0b3 – 30.Aug.2022

* Fix bugs in v3.0b2.

* Add dropdown menu for help, settings, and accounts.


## Version 3.0b2 - 22.Aug.2022

* Upgrade notes

    * Search change: Search for `re:me:R1`, not `re:me round:R1`.
    * PHP 8.0 and 8.1 are supported.
    * If you’re upgrading a very old installation, make sure your options
      are in `conf/options.php`, not `conf/options.inc`.

* Continued overhaul of HotCRP’s internals. All pages are rendered through
  accumulated partials configured in JSON. Remove global variables. Better
  behavior on HEAD methods. Introduce “formatted texts” for messages; these
  are strings that start with either `<0>`, meaning what follows is plaintext,
  or `<5>`, meaning what follows is HTML.

* Better appearance for many error messages.

* Home

    * Report to the PC any scores that are selected by default.
    * Highlight when viewing the site as another user.
    * Add back-end support for OAuth signin.

* Submissions

    * Support fields that become visible when reviews are visible.
    * Support real-number submission fields.
    * Improve format checker robustness.
    * Check authors’ collaborators against PC members.

* Search

    * Add `re:user:R1` (remove `re:user round:R1`).
    * Add `rate:good:user` (remove `re:user rate:good`).
    * Add `proposal:user`.

* Reviews

    * Add support for review fields that appear only on subsets of reviews.
    * More color schemes for options.
    * Improve accept/decline workflow; send mail on accept as well as decline.
    * Support assigning reviews to draft papers.
    * Record deltas between review versions.

* Comments

    * Support `@mentions` in comments.
    * Support comment topic, namely “submission” or “review”. Comments about a
      submission are visible to users who can see the submission, whether or
      not they can see the reviews.

* Meeting tracker

    * IDs of conflicted papers are hidden from a user’s tracker by default.

* Profile

    * Better user experience for bulk update.
    * Send user notifications more reliably.
    * Add UI for disabling a user on their profile page.

* Action log

    * Include review unsubmission.
    * Include decision settings.

* Settings

    * Better display of submission and review fields.
    * Internal overhaul. Rename setting patterns for readability.
    * Reordering choices in a submission or review field should update existing
      submissions (or reviews) accordingly.
    * Support settings export and modification through JSON.
    * Support arbitrary tag colors and badges.

* Many other bug fixes, tests, and improvements.

    * Use `force index` to improve MariaDB performance.
    * Add support for bearer-token API access.


## Version 3.0b1 - 12.Nov.2020

* Upgrade notes

    * Mail templates are now defined in JSON, not PHP. See
      `etc/mailtemplates.json`. Uses of `$Opt["mailtemplate_include"]` should
      be modified to use JSON and `$Opt["mailTemplates"]`.
    * Remove `src/messages.csv` in favor of `etc/msgs.json` and IntlMsgSet.
    * Some old abbreviations for fields will no longer work thanks to
      improvements to the abbreviation subsystem. Most abbreviations, such as
      `OveMer` for “Overall merit”, should work unchanged.
    * Search terms that match more than one submission field will now report a
      warning, and match nothing, unless they include a `*`.
    * PHP 7.3 and 7.4 are supported. PHP 5.6 is no longer supported.

* Significant overhaul of HotCRP’s internals. [Phan][] type signatures are
  added to many methods. New classes are added. Built-in submission fields,
  such as title and author, are expressed through the PaperOption framework,
  and are theoretically extensible. PaperOption subclasses are in
  `src/options`; Fexpr subclasses in `src/formulas`. Many pages are rendered
  through accumulated partials defined in JSON.

* Submissions

    * Support required fields.
    * Support topic groups, as in `Systems: Networking`.
    * Withdrawal is prevented if a user has already seen reviews.
    * Banal improvements and bug fixes. Detect reference pages.
    * Submission field titles can be configured using `etc/msgs.json`.
    * Support long abstracts and collaborators.
    * Support pinned conflicts of many types, including pinned non-conflicts.

* Assignments

    * Add new assignment types such as `follow` and `error`.
    * Bulk-assignment files can specify tag values using a formula.
    * New UI for review requests and refusals on paper assignment page.
    * Better warnings for bulk-assignment problems, such as assigning a
      conflicted user to review.

* Reviews

    * Add new review approval settings, review approval/adoption UI, and
      improve display of approvable reviews (which are called “subreviews”).
    * Support score fields with more than 9 options.
    * Allow preview of the mail sent to newly requested external reviewers.
    * Chairs receive mail about newly-proposed external reviewers.
    * Add capabilities for accepting and declining reviews.

* Comments

    * Support attachments.
    * Overhaul response editing. Fix bugs around submitting, deleting, then
      re-entering responses; improve workflow.

* Visual

    * Flexible spacing for many pages.
    * Better presentation warnings and errors near fields.
    * Mark required fields.
    * Home page highlights reviews that are pending approval.
    * Sticky left-hand in-page navigation on many pages.
    * Highlight headers when traversing some links.
    * More use of HTML5 elements like `<nav>`.
    * Improve change detection on settings.
    * Profile page has sub-pages.

* Signin and signout

    * Never send plaintext passwords in email, not even initial,
      randomly-chosen plaintext passwords. Instead send reset links.
    * $Opt["safePasswords"] is removed. HotCRP refuses to store passwords in
      plaintext. (It has stored passwords encrypted *by default* for years.)
    * Separate signin, signout, newaccount, forgotpassword, and resetpassword
      pages.
    * Address some session fixation vulnerabilities.
    * Allow a single session to represent multiple signed-in accounts. URL
      prefixes like `/u/0` and `/u/2` differentiate the accounts.

* Action log

    * Log paper downloads.
    * Log what changed when papers/reviews/profile are updated.
    * Log record coalescing never overflows database record size.
    * Reword many log messages for consistency.

* Search

    * Support `column[decorator]` syntax, as in `sort:lead[last]` (sort by
      lead last name) or `show:allpref[topics]` (include topic scores in the
      preference list) or `show:lead[column]` (show the lead as a column, not
      a row).
    * Support loading entire fields on demand, rather than requiring fields be
      partially rendered to Javascript even when not shown.
    * Generate ZIP files internally, rather than with a PHP ZIP library or an
      external `zip` binary. Support range requests to ZIP files.
    * Add XOR operator.

* Mail

    * Use quoted-printable encoding and format=flowed.

* Tags and formulas

    * Formula aggregates can be computed over specific sets of users, as in
      `sum.pc(...)` or `sum.re(...)`.
    * In an aggregate, a formula like `#_~foo` returns the tag value of the
      indexed user’s `#~foo` tag. Use this functionality to compute allotment
      votes and approval votes.
    * Formulas support `let VAR = VAL in BODY`.
    * EXPERIMENTAL: Support a `#perm:` namespace. Tag a paper
      `#perm:author-read-review#1` and that paper’s authors can read its
      reviews, regardless of other settings; tag it
      `#perm:author-read-review#-1` and authors *cannot* read reviews. Also
      `#perm:author-write`.

* Formula graphs

    * Add highlighting support (search for a paper and the relevant data
      points are highlighted).
    * Add support to reorder the X axis according to a formula.
    * Improved error messages.

* Rights and information exposure

    * Avoid exposing reviewer names to other reviewers via `Cc:` mail fields.
    * Avoid exposing information via HTTP status codes.
    * The user-list page counts leads, shepherds, authorship, and reviews in
      ways that reliably match visibility policies.

* Tracker and buzzer

    * Support multiple simultaneous trackers.
    * Support autoplay.

* Conference options

    * Support $Opt["disableNewUsers"].
    * If you have a large PC, $Opt["largePC"] may improve performance; it
      causes the JSON describing the PC to be loaded only on demand.

* Batch scripts

    * `batch/assign.php` runs a CSV assignment file.
    * `batch/paperjson.php` exports JSON for papers.
    * `batch/reviewcsv.php` exports CSV for reviews.
    * `batch/search.php` exports CSV for papers.

* Many other bug fixes, tests, and improvements.


## Version 2.102 - 9.Aug.2018

* Support integration with Lutz Prechelt’s [Review Quality Collector][].

* Support anonymous PC discussion (comments are identified as by, e.g.,
  “Reviewer A”).

* Add a new conflict assigner that lists *all* potential conflicts, with
  helpful information.

    * Improve affiliation matching.
    * Standardize collaborators storage format.
    * External reviews can be requested with delegation to the chair only if
      there’s an apparent conflict.

* Many bug fixes and usability improvements. Important bugs include one where
  reviews looked ugly, several concerning paper list display and
  override-conflicts mode (e.g., sort order, statistics), some search bugs
  (e.g., with ranges of scores), and some where complex Unicode strings would
  cause breakage. Support emoji names.

* Streamline some settings, including track permissions and submission fields.
  Some features are removed from open-source HotCRP.

* Faster performance for some DB queries and for some graphs. Support
  simultaneous download from S3.

* Support very long session-based paper lists that would otherwise overflow
  cookie size limits.

* Improve review rating UI.

* Improve assignment page UI.

* Support CDF graphs by *review* rather than paper.

* Use the term “submission” in preference to “paper” (in many places).

* Internal refactoring continues. Remove `$_REQUEST`; reduce reliance on
  inline Javascript handlers; simplify database schema. Further introduction
  of expansion plans, such as for mail keywords.

* Support PHP 7.2; stop supporting PHP 5.5.


## Version 2.101 - 18.Oct.2017

* Support metareviewers.

* Track administrators: PC users with a specific tag can be administrators for
  papers with a specific tag.

* Support delegated reviews that must be “approved” by the delegating PC
  members, for STOC-like workflow.

* Large improvements to conflict handling and conflict matching UI. Warn
  authors when they have likely conflicts; show that information to chairs.

* Support indefinite numbers of review fields.

* Support emoji tags like #:smile: and #:poop:.

* Tag patterns: you can make a set of tags chair-only using syntax like
  “chair:\*”.

* Graphs: Support multiple CDFs, support graphs by tag.

* Search: Support searches for textual and numeric option values, add
  `revtype:USER` and some other generalizations.

* Improve topic matching by scaling topic interest scores by sqrt(#topics).

* Visually distinguish PDFs with serious formatting errors.

* Show archive listings for archive uploads.

* Improve printed page style.

* Download > CSV.

* Add `show:graph:FORMULA` support.

* Bulk assignment: Add “contact” assignment type (change paper contacts),
  and “submit”, “unsubmit”, “withdraw”, “revive”.

* Internals: Make HotCRP much more extensible. Paper columns, paper search
  keywords, formula functions, API functions, assignment instructions, and
  some UI messages are extensible using JSON.

* Internals: Readability refactoring, especially for PaperInfo; reduce
  database load.

* Default to SHA-256 checksums.

* Many bug fixes.

* Support PHP 7.1; stop supporting PHP 5.4.

* Thanks for feature requests and bug reports to many users.


## Version 2.100 - 15.Jun.2016

* Sort reviews & comments by post time, rather than putting all the reviews
  first and all the comments later.

* Support comments with >32768 bytes.

* Search: New keywords including `pref:USER`, `edit:tags`.

* Support annotated tag orders.

* Graphs: `boxplot` graphs, better tooltips, more colors.

* Styles: Badges! A tag can appear after the paper title in a wee lozenge. I
  like this, but I'm not sure anyone else cares.

* Styles: If you tag a paper with multiple colors, you will get a **RAINBOW**,
  because we dynamically create the fill pattern.

* Completion: Nicer UI.

* Formulas: New expressions including `argmin`, `argmax`, `reviewer`,
  `reviewer:#pc`, `quantile`, `median`, `sqrt`, `exp`, `pow`. Fix comparisons
  of letter scores.

* Autoassigner: Provide checkboxes so only a subset of the assignment can be
  applied.

* Bulk assignment: Add “Unsubmit review” assignment type; support changing
  review rounds.

* Settings: Add a track permission for “can see reviewer names.”

* User pages: Tag actions.

* Internal: Performance improvements. Reduce query load; render paper lists
  more in Javascript.

* Internal: Major refactoring of the settings system, search actions, format
  checking, and paper options. Support external plugins for all these.

* Many bug fixes. Work with newer MySQLs; fix review delegation; improve
  tracker; bug fixes in min-cost max-flow assignment; fix an
  information-exposure bug where enterprising PC members could discover tags
  for conflicted papers.

* Thanks for feature requests and bug reports to many users including Emery
  Berger, Michele Nelson, Shriram Krishnamurthi, Chris Kanich, Don Porter,
  Oleg Vaskevich, Eijiro Sumii, Marcos Aguilera.


## Version 2.99 - 21.Nov.2015

* Support real-valued tag indexes and tag indexes for PC members.

* Fix some bugs in 2.98.


## Version 2.98 - 19.Nov.2015

* MySQL improvements: Use InnoDB; set the connection charset to binary, which
  is required on newer MySQL instances; support emoji in reviews.

* Fix problem when there are more than 26 reviews (Emery Berger report).

* Fix problem with Apache+mod_rewrite installations.

* Use more HTML5 attributes, improve Firefox rendering speed, more bug fixes
  and improvements.


## Version 2.97 - 28.Sep.2015

* Add `re:words` search term and formula term.

* Fix bug where the response word counting feature could break some browsers
  (a regular expression's backtracking went exponential).


## Version 2.96 - 24.Sep.2015

* New improved look.

* Support per-review-round review fields: a review in round R2 can look
  different from one in round R1, for instance. Note that each reviewer can
  enter at most one review per paper (not one review per round).

* Add `HIGHLIGHT` search keyword.

* Improved autocompletion for searches.

* Add glyphs for when you're a lead and/or shepherd to paper lists.

* The settings UI support configuring whether PDF submissions are required.

* Add an autoassigner that chooses a sensible discussion order, reducing
  conflict churn.

* Formulas support dec:, re:REVIEWERNAME, re:REVIEWERTAG.

* Score fields on review forms can be configured to allow "No entry".

* Fixes to password reset, autoassigner, many others.

* Thanks to all contributors and users, especially John Wilkes and Jeff Mogul.
  Emery Berger also suggested a feature or two.


## Version 2.95 - 19.Jun.2015

* Graphs!!!!!

* Globally-optimal autoassignment through min-cost max-flow!!!!!

* Paper administrators can access the autoassigner and bulk assigner for those
  papers they administer.

* Keyboard shortcuts on paper pages: c, enter new comment; s+d, set decision;
  s+l, set discussion lead; s+s, set shepherd; s+t, set tags.

* Add approval voting.

* Review scores can have different color scales.

* Reviews can be made visible only for papers with a certain tag.

* Add `pre:` searches for partially-complete reviews.

* Add searches for attachment options: number of attachments, attachment
  filename.

* Hundreds of bug fixes and minor improvements, and some performance work.


## Version 2.94 - 15.Mar.2015

* Add buzzer, a discussion status page based on the tracker. Many
  tracker stability improvements.

* Support multiple response rounds.

* Support massive bulk assignments.

* Formulas can refer to both preferences and reviews, e.g.,
  `any(pref>0 && ovemer=2)`.

* Hundreds of bug fixes and minor improvements.

* Thanks to Nickolai Zeldovich, Stephen Murdoch, Umut Acar, Jan Vitek,
  John Tang Boyland, Sandhya Dwarkadas, Steve Blackburn, Erez Zadok,
  Dan Tsafrir, Peter Sewell, Gail Murphy, George Candea, and others.


## Version 2.93 - 2.Oct.2014

* Improve autoassigner to spread out user unhappiness.

* Improve appearance and behavior. For example, edit comments in place
  via Javascript.

* Improve search. Add many new search keywords. Allow `show:FORMULA`
  to show a new formula; for example `show:max(ovemer)`.

* Per-round review deadlines.

* Reviewer preference expertise is available for formulas.

* Support clickthrough reviewing terms.

* Fix bugs.

* Support Comet tracker: https://github.com/kohler/hotcrp-comet

* Add some batch scripts. E.g., `php batch/s3transfer.php` uploads
  documents to S3.

* Render score charts in Javascript via canvas.

* Download JSON information about papers, then upload it via `php
  batch/savepapers.php`.

* Support NGINX/php-fpm.

* Allow sharing a session variable among conferences on the same
  server.

* Thanks to Aaron Gember, Todd Millstein, Dan Wallach, Vivek Pai,
  Hyojin Sung, Rakesh Komuravelli, David Walker, Shriram
  Krishnamurthi, Nickolai Zeldovich, Michele Nelson, `mutax`, Fred
  Douglis, and others.


## Version 2.92 - 13.May.2014

* Bug fixes for bugs reported by Shriram Krishnamurthi, Aditya Akella,
  Yoshi Kohno, Garth Gibson.


## Version 2.91 - 1.May.2014

* Bug fixes to profile editing and submission options problems
  reported by Lars Eggert and Kevin Fu.


## Version 2.90 - 25.Apr.2014

* Major refactoring release.

* Add meeting tracker. Chairs can click a button which will broadcast
  their position in a discussion list to all PC members.

* Support encrypted passwords, and encrypt passwords by default.

* Improve bulk assignment: allow more kinds of assignment upload,
  including tags, and give users a chance to confirm uploaded
  assignments.

* Add `has:final`, `has:paper`, `has:comment`, `has:response`, and
  `has:OPTION` search options.

* Support multiple `sort:` keywords, such as `sort:overall-merit
  sort:title`; and support complex sorters, such as `sort:"overall
  merit by variance"`.

* Add support for a "timestamp" column, hidden by default. So you can
  search for `sort:timestamp` and `show:timestamp`.

* Add a test suite.

* Support running under nginx.

* Many other bug fixes and improvements, including a SQL injection fix
  in offline review uploads and MySQL 5.6 compatibility.

* Thanks to Nickolai Zeldovich, Todd Millstein, Peter Sewell, Garth
  Gibson, Colin Scott, Chris Kanich, Adrian Sampson, Fred Douglis,
  Kevin Fu, Soheil Hassas Yeganeh, Robby Findler, and Johannes Dahse.


## Version 2.61 - 14.Aug.2013

* Correct some XSS errors and one SQL injection error reported by
  Johannes Dahse using a static checking tool of his design. The XSS
  errors are not serious. The SQL injection is potentially serious,
  but it is only exploitable on conferences that include a "radio
  buttons" paper option. You can avoid the problem without upgrading
  by switching the paper option to "selector".

* Other small bug fixes, including fixes to the packaging of 2.60.
  Thanks to Anil Madhavapeddy and Peter Sewell.


## Version 2.60 - 19.Jul.2013

* Major new feature: Paper managers. Administrators can assign PC
  members to "manage" individual papers. These PC members gain admin
  rights over those papers, and can, for example, assign reviewers as
  usual. This required extensive rearchitecting of system internals to
  (hopefully) avoid information leaks.

* Frequently requested new feature: Attachment-style paper options,
  into which users can upload arbitrarily many files of arbitrary
  type.

* Add Search > Download > PC review preferences and Search > Download
  \> ACM CMS report.

* Add multiline text entry options.

* Tags and search keywords are not case sensitive.

* Many bug fixes, including to score searches like `ovemer:AC`.

* Thanks to Peter Sewell, Sarita Adve, Josh Simons, John Heidemann,
  and Jeff Mogul.


## Version 2.59 - 14.Jun.2013

* Bug fix: "Monitor external reviews" works. Reported by Peter Sewell.

* Information leak fixes: During response periods, don't notify
  authors of changes in PC-only fields. Don't allow searches on review
  rounds for conflicted papers. Don't show accept status via "Accepted
  papers" searches. Reported by Nickolai Zeldovich and Jeff Mogul.


## Version 2.58 - 23.Mar.2013

* More information leak plugging: explicit search for review fields
  that should be hidden from authors, and review rounds. Reported by
  John Heidemann.


## Version 2.57 - 16.Mar.2013

* Bug fix: The search page's score graphs exposed score values for
  authored papers during the rebuttal phase. This is normally OK, but
  it's not OK if authors aren't supposed to see the scores. Reported
  by Jitu Padhye and Srini Seshan.

* Bug fix: `au:` searches work for non-chairs. Broken since 2011!

* Add a random-walk-based paper ranking method (John Douceur).


## Version 2.56 - 29.Jan.2013

* This is a major refactoring release. Internals, particularly for
  paper list display, are cleaner and more extensible. But bugs are
  likely.

* New drag-and-drop mode for setting tag orders. Search for
  `editsort:#TAGNAME`. This mode is suggested for paper ranks.

* New popup help for setting tags and searching for tags.

* Search for `show:#TAGNAME` to show a particular tag. Search for
  `edit:#TAGNAME` to edit tag values. Search for `edit:tag:TAGNAME` to
  edit a tag with checkboxes.

* Search for `show:SCORE` or `show:FORMULANAME` to add a score or
  formula to the display, or `hide:` to remove it from the display.
  You may also `show:` or `hide:` title, status, statusfull, revtype,
  revstat, revsubmitted, revdelegation, assrev, topicscore, topics,
  revpref, allrevpref, desirability, reviewers, authors, collab, tags,
  abstract, lead, shepherd, pcconf (depending on your access rights).
  These should be documented. You may also search for `edit:revpref`
  to edit review preferences.

* Improvements to paper search for accented names. E.g., searching for
  "Crap" will match "Cráp".

* Improvements to database creation.

* Add `Code/runsql.sh` script to run MySQL on the paper database.

* Several bug fixes, including that search respects the "PC can view
  decisions" setting.

* Thanks to Jeff Mogul and John Douceur.


## Version 2.55 - 31.Dec.2012

* Minor bugfix release.


## Version 2.54 - 30.Dec.2012

* Fix bug in 2.53 where long papers could not be uploaded. Kamin
  Whitehouse report.

* Responses: Show a words-left count.

* Some other bug fixes.


## Version 2.53 - 26.Dec.2012

* Support sending mail to PC members about their new review assignments.

* Add HTTP authentication option: `$Opt["httpAuthLogin"]`.

* Bug fixes to bulk account creation, among others.

* Thanks to Adam Allred, Lujo Bauer, John Douceur, Gernot Heiser,
  Petros Maniatis, Jeff Mogul, Antoine Picard, and Anthony Riley.


## Version 2.52 - 23.Jul.2012

* Allow chairs to change all PC conflicts on papers' Edit screens.

* Other bug fixes and improvements.


## Version 2.51 - 22.Jun.2012

* Fix bug with setting tags on per-paper pages (caused by cross-site
  request forgery protection).

* Other fixes and improvements.


## Version 2.50 - 10.May.2012

* Fix database error on response submissions (a problem since v2.48).
  Problem reported by Robby Findler.

* Cross-site request forgery protection.

* Other fixes and improvements.

* Thanks to Dan Tsafrir, Wilson Hsieh, Giuliano Casale, and Geoff Voelker.


## Version 2.49 - 29.Mar.2012

* Add update notification. Chairs' browsers contact an updates server,
  hotcrp.lcdf.org/updates, to check whether the HotCRP installation should
  be updated. If you don't want chairs' browsers to contact hotcrp.lcdf.org
  with version information, set `$Opt["updatesSite"] = false`.


## Version 2.48 - 28.Mar.2012

* Correct major information exposure with author-view capabilities.
  Author-view capability URLs, when entered by users not otherwise logged
  in, gave access to comments meant only for PC members and reviewers.
  Comment identities were not exposed. Apologies.

* Support video submissions.

* Columnar search display. Try `1-10 THEN 2-20 VIEW:compactcolumns`.

* Other bug fixes and improvements.

* Thanks to Muli Ben-Yahuda, Dan Tsafrir, Erez Zadok, Robby Findler, Wilson
  Hsieh, Gernot Heiser, Jeff Mogul, George Candea, John Regehr, of course
  Jane-Ellen Long, and others.


## Version 2.47 - 14.Dec.2011

* Add author-view capabilities. These parameters, when appended to any
  HotCRP URL, grant the client the right to view a paper like an author.

  Capabilities will let us start removing passwords from emails, something
  long desired. They are cryptographically hashed from information
  including a per-conference secret, so a paper's capabilities are
  unguessable without database access.

* Support PowerPoint uploads.

* Add a new PC review assignment: "optional" PC reviews (PC members are
  asked to accept or decline).

* Comments are numbered as @1, @2, @3, @4 (and @A1, @A2, ...). This will
  hopefully facilitate cross-references among comments.

* UI improvements and bug fixes for review preferences, bulk account
  creation, mail tool, the "PC chairs must approve external reviewers"
  setting, Chrome compatibility, offline reviewing, mod_rewrite
  configuration bugs, and paper URLs. And `#tagname` is a valid search
  string for `tag:tagname`.

* Information leak fixes: accept author lists, PC chair approvals for
  external reviewers.

* Thanks to Lars Eggert, Michael Hicks, John Wilkes, Jane-Ellen Long, Jeff
  Mogul, Clay Shepard, Gareth Gale, and Amit Sahai.


## Version 2.46 - 5.Aug.2011

* Support multiple final-version uploads.

* Usability improvements: allow uploading conflict assignments; new tag
  colors: "bold", "italic", "big", "small"; more consistent reviewer
  searches, e.g. `lead:me`; etc.

* Other bug fixes.


## Version 2.45 - 24.Apr.2011

* New, improved visual appearance for paper pages.

* Keyboard shortcuts on paper pages: press "j" to go to the previous paper
  in the list, "k" to go to the next.

* Search improvements: Add review preference search, reviewer conflict
  search, saved searches, and "tag reports" display options.

* Usability improvements in manual conflict detection and assignment.

* Speed improvements: Allow a user to open new pages while downloading a
  large file.

* New blindness settings: authors can be blind until reviewer submits
  review; and comments can be made visible only to PC chairs.

* Log mail bodies sent by the mail tool.

* Several bug fixes (including to IRV assignment) and usability fixes.

* Thanks especially to Jeff Mogul and John Wilkes, and to Paarijaat Aditya,
  Stefan Savage, Jane-Ellen Long, Philippe Bonet, Christoph Mayer, Manolis
  Stamatogiannakis, and Michael Hicks.


## Version 2.44 - 8.Feb.2011

* Correct recent bugs: improve Ajax return values (which lacked "b"
  characters due to a quoting mishap); do not ask authors for responses
  when responses are not open; don't include HTML in textual email.

* Other small improvements: add conflict types to PC conflict reports; CSV
  reports; fix searches for terms like `re:heavy=0`; fix negated search
  terms; add paper option display type.

* Thanks especially to Jeff Mogul and John Byers.


## Version 2.43 - 3.Jan.2011

* Correct 2.41 bug that could cause SQL errors on the home page when users
  had many comments to view.  Double ouch!  Apologies to Tony Del Porto and
  Usenix.


## Version 2.42 - 2.Jan.2011

* Correct 2.41 bug that broke `ovemer:3` searches (ouch).

* Add searches like `ovemer:pc>3`, which check scores given by subsets of
  reviewers.

* Style nits (paragraph breaks in abstracts, reviewer icon alignment).


## Version 2.41 - 13.Dec.2010

* The "Recent activity" on the home page includes information about
  submitted reviews as well as submitted comments (frequent request,
  including from Ratul Mahajan).

* Manual assignment nasty bug fix: As of 2.40 the manual assignment page
  would always show the CHAIR'S preferences & conflicts.  Now it shows the
  selected PC member's preferences & conflicts.

* Deadlines: Replace the previous "the server's time is XXX" display with a
  new countdown to the submission deadline.  Use Javascript and Ajax loads
  to keep the countdown up to date.

* Search improvements

  * Add support for `cmt:REVIEWERNAME`.

  * Add support for `re:me`, `re:pc`, and `re:-PCTAG`.

  * Improve `conflict:` support.

  * `tag:FOO*BAR` searches for any tag that matches FOO*BAR, using glob
    matching.

  * Allow comma- as well as space-separated paper lists.

  * Always show all display options ("More>>" distracted).

* Assignment bug fixes and improvements: autoassign lead/shepherd no longer
  resets existing assignments; autoassign lead/shepherd gains more options.

* Display improvements: better HTML formatting on mail page (IE6 was
  broken); paper list authors are grouped by affiliation in search lists;
  remove the low-value "Welcome, YOUR NAME!" home page section; Web forms
  show review field visibility more clearly; better name abbreviation.

* Usability improvements: don't lose comments if user's session expires.
  Wording improvements and additional dialog boxes avoid confusing users
  about paper "versions" and prevent discourage turning a paper from ready
  to not-ready-for-review.  Warn users who have turned off Javascript.
  Show the chair a warning if PC members have -100 preferences that don't
  correspond to conflicts.

* Security improvements: reduce scope for IE XSS attacks, don't leak
  contact emails to unauthorized users, don't leak shepherds & leads to
  chairs who are conflicted.

* Thanks to Jeff Mogul, Casey Henderson, Juan Caballero, Adam Barth,
  Jane-Ellen Long, Andrew Hume, Mooly Sagiv, David Schultz, David Evans,
  Petros Efstathopoulos, Stephanie Weinrich, David A. Padua, and David
  Andersen.


## Version 2.40 - 30.Jul.2010

* Search expression improvements: Allow parenthesized expressions, `AND`
  keywords, and `THEN` searches.  `THEN` is the lowest precedence operator.
  It is like `OR`, but can only appear at the top level, and also affects
  the sort order -- in a search like `a THEN b`, the papers matching `a`
  will appear in the list before the papers matching `b`.

* New features: PC member tags appear in user search; PC member tags work
  in `conflict:` searches; allow searches like `conflict>2`; `conflict:me`
  searches for your own conflicts; allow searches like `5-1`.

* Bug fixes: Support Postfix mailers on UNIX; fix formulas (previously,
  adding a formula appeared to do nothing); paper list sort order does not
  expose accept/reject status to PC members.

* PC conflict visibility: PC members can see a paper's conflicts if they
  can see its authors.

* Thanks to Geoff Voelker, Stephanie Weirich, Umesh Shankar, Jane-Ellen
  Long, and Dana Randall.


## Version 2.39 - 20.May.2010

* PC member tags.  Each PC member can be associated with a list of tags,
  which use the same format as paper tags.  This list is only set by
  administrators.  PC tags can be associated with colors, searched, and act
  selectively as mail destinations.  This feature may be useful for (for
  example) heavy vs. light PCs.

* Add "PC can see all reviews" > "Yes, once they've completed all their
  assigned reviews" option.

* Support score range searches like `ovemer:BC` and `ovemer:1-3`.

* Various bug fixes and tweaks to avoid misleading hurried users.

* Thanks especially to Jeff Mogul and Ian Goldberg.


## Version 2.38 - 27.Jan.2010

* Add "Recent comments" section to the home page for PC members.  This
  lists recent viewable comments, newest comments first.

* Detect and compensate for invalid UTF-8 in uploaded review files (assume
  invalid UTF-8 means Windows 1252/ISO-8859-1).

* Many bug fixes, including SQL errors when saving all-zero preferences,
  searches for letter scores (i.e. `revexp:X`), manual conflict
  assignments, sending email to contact authors when a chair withdraws a
  paper, and actually sending email to users "watching" a paper's comments.

* PC members can elect to receive email on ALL papers' comment updates
  (except for conflicts).

* Thanks especially to Alex Aiken, and to David Evans, John Ousterhout,
  Tony Del Porto, Jane-Ellen Long, and Casey Henderson.


## Version 2.37 - 19.Dec.2009

* Bug-fix release.


## Version 2.36 - 17.Dec.2009

* Formulas

  * PC members and administrators can define formula columns for search
    results, which might show, for example, the sum of a paper's overall merit
    scores, or average overall merit weighted by reviewer expertise. See help
    for more details.

* Paper ranking improvements

  * Improve Schulze-method rank calculation by weighting preferences
    differently.  Specifically, if few voters specified any preference
    involving paper A, then weight those preferences heavily.  This deflates
    the margins for frequently-reviewed papers and, as a result, preserves
    preferences for infrequently-reviewed papers.  Without a weighting like
    this, multi-round conferences might see papers eliminated in early rounds
    unexpectedly rise to the top.  Based on observations from SOSP.

  * Hugely faster rank calculation.

  * Report incremental progress for rank calculation.

  * Add options for calculating ranks: select which ranking method you want to
    use using the UI.  Also, you can define a gapless order or calculate a
    rank using a different source tag.

* Rename "Define sequential" to "Define gapless order."

* Improve paper search displays by shrinking typically-narrow columns.

* Bug fix: Submitting a final copy doesn't reset PC conflicts.

* Support reordering paper options on the submission form.

* Allow explicit account creation even when LDAP logins are on.

* Other bug fixes, including two minor information leaks.

* Thanks to Laurent Réveillère, John Heidemann, Ratul Mahajan, S. Keshav,
  Gareth Gale, Alex Aiken, Benjamin Pierce, Tom Anderson, Mike Freedman,
  and John P. John.


## Version 2.35 - 7.Oct.2009

* Paper options: Support numeric values, text values, and PDF uploads.

* Account display/profile page: Usability improvements, add links between
  people, support bulk upload of many users at once.

* `conflict:pc` search returns all PC conflict papers.

* Web review forms default to "ready for others to see."

* Chair paper lists: Sorting doesn't expose conflicted scores by default;
  an "override conflicts" checkbox shows conflicted scores.

* Fix information leak: Final copy upload support doesn't expose paper
  acceptance.

* New settings: soft deadline for final copy collection; "PC members can
  edit external reviews they requested"; format checker understands
  fractional point sizes; "Visible if authors are visible" setting for
  paper options.

* Bug fixes and improvements to createdb.sh, quicklinks, default conference
  settings, decision type settings, mailer.

* Thanks to John Heidemann, John P. John, Jane-Ellen Long, Tadayoshi Kohno,
  Mark Gebhart, Moses Charikar, Tony Del Porto, Ian Goldberg, Adam
  Moskowitz, Tom Anderson, Jeff Mogul, David Wagner, John Wilkes, and Alex
  Aiken.  Special thanks to John Heidemann for providing patches! in
  addition to bug reports and feature requests.


## Version 2.34 - 21.Mar.2009

* Tag colors!  After a Dan Wallach suggestion.  Tag a paper "red" and it
  shows up as red in paper lists.  Or instruct the system that "reject"
  means red and papers tagged "reject" show up as red in paper lists.  Also
  orange, yellow, green, blue, purple, and grey.

* Score columns appear as soon as any scores can be displayed.  (They are
  still empty and hidden when no scores can be displayed.)

* Add help for paper rankings.

* Add `<label>` elements for all checkboxes and radio buttons.

* Translate HTML in review descriptions to text for offline forms.  (Only
  simple cases like `<ul>` lists.)

* Include `[%CONFSHORTNAME%]` prefix in paper registration emails.

* Bug fixes to per-paper tag setting, account creation, "Override conflict"
  links, conference titles containing slashes, review viewing, and XHTML.

* Thanks to Stefan Lorenz and John Wilkes.


## Version 2.33 - 15.Feb.2009

* Re-fix "Don't assign (X) and (Y) to the same paper."


## Version 2.32 - 15.Feb.2009

* Add `au:pc` search, which returns papers whose contact authors contain at
  least one PC member.

* Bug fixes: Correctly quote passwords sent in mail URLs, and fix "Don't
  assign (X) and (Y) to the same paper."

* Other nits, including sending more emails when authors withdraw papers
  during the review period.

* Thanks to John Wilkes, Jeff Mogul, Stefan Lorenz, and Benjamin Pierce.


## Version 2.31 - 26.Jan.2009

* Administrators can delete users.

* LDAP improvements: Autocreate new LDAP users, and allow emailing LDAP
  users.  Thanks to David Ames of the Linux Foundation.

* Bug fixes: Allow chairs to remove double-twiddle tags.  Avoid error
  message when adding the first voting tag.  Display improvements.
  Slightly better support for browsers without Javascript.


## Version 2.30 - 7.Jan.2009

* Add chair-only tags: double-twiddle tags, like `~~tag`, are only visible
  to and changeable by chairs and administrators.  Andrew Myers idea.

* Bug fix: Advanced search > With *any* of the words works.  Reported by
  John Wilkes.

* Other UI tweaks.  Additional options `$Opt["extraFooter"]` (Jeff Mogul) and
  `$Opt["noPapers"]` (C. Craig Ross).


## Version 2.29 - 1.Jan.2009

* Bug fix release.  Fixes bugs in tag search and tag setting, some reported
  by John Wilkes.


## Version 2.28 - 20.Dec.2008

* Allow periods in email addresses (Jeff Mogul).


## Version 2.27 - 16.Dec.2008

* Search results: Add tons of Display options, load them all by Ajax, and
  chairs gain a "Make these options the default" link.

* Search: Fix searches that mix letters and non-letters.

* Mail tool: Add "Cc" and "Reply-To" fields accessible to chairs.

* Preliminary multiconference support: run multiple conference sites from a
  single installation.  See README.

* Paper view improvements.

* Support older pdftohtml 0.36 programs (Anton Cohen).

* You can choose the time zone and request 24-hour time.

* Thanks to Anton Cohen, Benjamin Pierce, Alan Parry, Margo Seltzer, Mark
  Gebhart, Paolo Faraboschi, John Wilkes, Dina Papagiannaki, and others.


## Version 2.26 - 27.Oct.2008

* Submitters can be forced to define what type of conflict a PC member has.
  Requested by Dina Papagiannaki.

* Administrator search results can display reviewers.  Requested by
  Benjamin Pierce and Mark Gebhart.

* Improve review ratings.

* Bug fixes: Avoid infinite doc/ URL redirections, correctly track review
  tokens (Mike Marty requests).

* Ordered tagging improvements: "Add ordered" preserves old order values;
  "Add ordered" can insert papers into an existing tag order at a specific
  point.

* Search bug fixes: `1-10 OR foo` works correctly, as does `1-10 -6`.

* Search list PDF icons link to final papers when they are available (Fred
  Douglis request).

* Many help and usability improvements inspired by Benjamin Pierce requests.


## Version 2.25 - 22.Sep.2008

* Many bug fixes for new-style paper views.

* Comments: Now ALL comments are "tied to the reviews."  Comment entry
  gives you three visibility choices, as radio buttons: "PC reviewers
  only," "PC and external reviewers," and "Authors and reviewers."
  Previous "tied to reviews" plan considered confusing, and external
  reviewers selection requested, by Benjamin Pierce.

* Comment notifications: Limit emails to once every 3 hours, to avoid
  comment notification storms when people edit authors' responses.
  Requested by Benjamin Pierce.

* Ranks: "Calculate rank" tag action for chairs uses Schulze's algorithm (a
  Condorcet method) to calculate a global rank tag from users' local ranks.
  Code also supports the CIVS-Ranked Pairs algorithm developed by Andrew
  Myers for his CIVS (Condorcet Internet Voting Service), as well as range
  voting and IRV.  Special thanks to Andrew Myers for answering questions
  and providing a useful test case.

* Search for specific submission option values with `opt:name=value`.

* External reviewer request emails include "accept review" and "refuse
  review" links that, when clicked, record the reviewer's choice.  ("Accept
  review" marks a review as "in progress", rather than "not started";
  "refuse review" refuses it.)  Benjamin Pierce request.

* Bug fixes and improvements to settings pages, search, review tokens,
  comment display, database interaction, "ASCII art" detection in reviews,
  wording.

* Thanks also to Benjamin Pierce, Richard Gass, Michael Vrable, and others.


## Version 2.24 - 22.Aug.2008

* Major changes

  * New paper display.  Paper, review, and comment views are unified into a
    single display format.  The paper view shows initial words of abstract and
    compressed author list; both are easily unfoldable.  Tags, discussion
    leads, shepherds, review preferences, PC conflicts, and other PC-type
    information appear in a strip down the left hand side.  Paper views
    summarize comment counts and comment authorship.  I think this is a huge
    improvement.

  * Voting tags.  Chairs can define tags used for voting, with vote
    allotments, as in `vote#20`.  PC members vote for papers by assigning the
    corresponding twiddle tag, as in `~vote#1`.  The system prevents users
    from going over their allotments, and automagically maintains a public
    `vote` tag that sums users' votes.

  * Ranking tag.  Preliminary support for paper rankings via the tags system.

  * Review ratings are searchable and gain more options.  The current set of
    ratings is "Average, Very helpful, Not complete, Not convincing, Not
    constructive, Not correct."  (Is this too many?)  The home page reports a
    user's rated reviews.  Searching for `rate:+` finds positively rated
    reviews, `rate:-` negatively rated reviews, and e.g. `rate:convincing`
    finds "not convincing" rated reviews.  Robbert van Renesse feedback was
    very helpful.

* New features and new behavior

  * New search syntax: Support partial word matches, as in `foo*` or `bl*ah`.
    Search for a tag in reverse order with `rorder:tag`.  Search within tag
    orders with, for instance, `tag:pcrating#2` or `tag:pcrating#>2` (Rich
    Draves request).  Search for incomplete reviews with `ire:whatever` (Rich
    Draves request).  Chairs and administrators can search other users'
    twiddle tags, as in `tag:frank~vote`.

  * Ordered tags: The "define ordered" and "add ordered" tag actions skip
    order steps; for example, they might assign order 1, 3, 4, 7, 8, 10. This
    hides information from conflicted PC members, since they can no longer
    infer conflicted papers' positions by looking for gaps.  New "Define
    sequential" and "Add sequential" actions do the old sequential style.

  * Search highlight improvements: Searching only highlights terms in the
    relevant fields; for example, `au:john` won't highlight "john" in the
    title.  Also automagically unfold any field that contains a highlight.

  * Offline reviewing improvements.  Blank review forms ignored on upload,
    rather than causing warnings (Rebecca Isaacs request).  Supposedly "ready"
    review forms that lack required fields are saved anyway, they're just not
    marked as "ready" (Rebecca Isaacs request).  The system detects and
    rejects attempts to upload an offline form after a review is edited online
    (Fred Douglis report).  Clarify where numeric scores are entered (Benjamin
    Pierce request).

  * Paper assignment UI improvements.  Reorder fields, make submission
    behavior clearer.  Automatic assignment: can shift-click on PC member
    ranges.  Show topic interest scores and preferences as "Txxx Pyyy".
    Automatic assignment can clear existing assignments.  Bug fixes. (Benjamin
    Pierce and Jeff Mogul requests)

  * Comment visibility changes.  Users can mark a comment as "tied to
    reviews," which means that PC members who haven't read the reviews can't
    see the comment either.  This is more useful, arguably, than hiding
    comments from external reviewers.  (Robbert van Renesse request)

  * A tweak to Minshall score improves its behavior when papers have different
    numbers of reviews (Terence Kelly report).

  * External reviewers can use review tokens, since owning a review token
    confers the right to view the corresponding paper.

* Bug fixes and minor UI improvements

  * The "Reviewers can see decisions" setting also applies to the PC.

  * "Merge accounts" bug fixes.

  * PC members always count as reviewers, even if they haven't had any reviews
    assigned yet (Laurel Krieger report).

  * Paper lists never show conflicted PC members counts of reviews (Fred
    Douglis report).

  * Mail tool: Include `%COMMENTS%` in mails even when sending mail before
    reviews are visible in the site.  This is the same as the `%REVIEWS%`
    behavior.  (Jeff Mogul report)  Also, issue a warning about sending mail
    with `%REVIEWS%` or `%COMMENTS%` when reviews aren't visible on the site.

  * Mail tool: If authors can see reviews only after finishing their own, then
    the mail tool will hide reviews and comments from authors who have not
    finished their own reviews.  And include a warning.

  * Mail tool: JavaScript discourages users from clicking on the "Send" button
    too early (Robbert van Renesse, Rich Draves report).

  * Mail tool: Report how many emails remain to be sent.

  * Mail tool: Add "Discussion leads" and "Shepherds" recipient types (Jeff
    Mogul request).

  * Mail tool: The action log tracks sent mail (Jeff Mogul request).

  * "Refuse review" reason field is bigger (Benjamin Pierce report).

  * Search bug fix: `order:~privatetag` works (Rich Draves report).

  * Search: Add Download > Discussion leads and Download > Shepherds (Jeff
    Mogul request).

  * Search: Add Display options > Row numbers (Rich Draves request).

  * Search: Tag order searches gain an explicit search column heading so that
    the sort order can be reversed.  Request and UI ideas from Rich Draves.

  * Settings: Validate XHTML, preventing cross-site scripting bugs.

* Special thanks to Robbert van Renesse.


## Version 2.23 - 22.Jul.2008

* Do not infinite loop when sending mail to non-ASCII names associated with
  long email addresses.  Reported by Robbert van Renesse and Rich Draves.

* Report correct number of reviews on the program committee page, even if
  review ratings are enabled.  Reported by Robbert van Renesse.

* Correct PHP warnings and make compatible with older PHPs.  Reported by
  Jeonghee Shin.


## Version 2.22 - 15.Jul.2008

* Appearance fixes: use default controls in most cases.

* Aggregated information about review ratings are provided on the PC
  details page.

* Allow searches of review fields and scores.  For example, `ove-mer:2>=2`
  searches for papers that have at least 2 overall merit scores that are
  greater than or equal to 2.  Requested by Rich Draves and Robbert van
  Renesse.

* Support multiple-choice paper options (rather than checkbox).  Requested
  by Jeff Mogul.

* Improve some messages and help text.


## Version 2.21 - 11.May.2008

* Further improve validation and Internet Explorer 6 compatibility.


## Version 2.20 - 11.May.2008

* Improve Internet Explorer 6 compatibility.  Reported by Terence Kelly.
  Includes Drew McLellan's supersleight for transparent PNG support
  (http://24ways.org/2007/supersleight-transparent-png-in-ie6).

* Some validation fixes.

* Set MySQL's max_allowed_packet on a per-session basis based on PHP's
  upload_max_filesize, rather than relying on users to set
  max_allowed_packet correctly.  A problem was reported by Sourabh Jain.

* Bug fixes to preference list, English, and createdb script.


## Version 2.19 - 6.May.2008

* Provide visible feedback on Ajax forms.

* Improve manual assignments page with better conflict listings (Rebecca
  Isaacs).


## Version 2.18 - 5.May.2008

* Record PC feedback about whether reviews were helpful.  PC members and,
  optionally, external reviewers can rate one another's reviews.  Hopefully
  this will help improve the quality of reviews.  "Was this review helpful
  for you?" appears above each visible review.  HotCRP reports the number
  of ratings for each review and how many of those ratings were
  positive. It does not report who gave the ratings, and it never shows
  rating counts to authors.

* The `$Opt["emailSender"]` option lets you set the envelope sender in sent
  mail (Robbert van Renesse).

* Add review tokens, which allow reviewers to edit reviews anonymously.

* Prettier styles.

* Bug fix: Correct commenter identities in comment emails.

* Finish (?) information exposure fixes (David Andersen).

* Banal works with pdftohtml 0.36 (Robbert van Renesse).

* When a paper is withdrawn, its reviewers no longer need to complete their
  reviews (Stefan Savage).


## Version 2.17 - 23.Apr.2008

* IMPORTANT: Continue reviewer identity leak fix via search rewrite.

* Rewrite search again.  Search now works like Google search.  `-word`
  excludes `word` matches from the search.  `word1 OR word2` searches for
  either `word1` or `word2` (the OR must be uppercase).  The default search
  box returns papers that match ALL the words.  Searches in title,
  abstract, and authors match whole words, not portions of words.  The
  process of building up and executing a query is cleaner and comes closer
  to the ideal of returning all visible information.

* Paper lists report "0" reviews for papers that never got a review (rather
  than "0/1").


## Version 2.16 - 21.Apr.2008

* IMPORTANT: Reviewer identity leak fix.

* Improve usability with tooltips and appearance improvements (inspired by
  John Wilkes).


## Version 2.15 - 9.Apr.2008

* Improve homepage with a right-hand sidebar.

* PC members can download any PDF that is "ready for review," even if the
  submission period has not closed.  A warning informs them that authors
  can still update.

* Show SHA-1 checksums for paper submissions (Dave Andersen).

* Download: Review forms (zip) returns a .zip with a separate review form
  file for each form (Jeff Mogul).

* Display "Your discussion leads" link even before "PC can view all
  reviews" is set (Brad Beckmann).

* Bug fix: Fix manual chair assignment page (Mark Gebhart).

* Bug fix: Fix review form download from the review page (Mark Gebhart).

* Bug fix: Do not reveal author names to reviewers when authors withdraw a
  paper already under review (Stefan Savage).

* Bug fix: "Reviewers" user search returns reviewers that completed reviews
  for papers that have since been withdrawn.

* Bug fix: Authors can see review form guidance.

* Bug fix: Searching for `cre:>0`, etc. works.


## Version 2.14 - 12.Mar.2008

* Review field options can take lettered values, such as A-D or X-Z, as
  well as numeric values.

* Add support for bulk upload of review assignments, PC or external
  (requested by Matthew Frank).

* Review preference UI improvements, including uploadable preference files
  (requested by Greg Minshall).

* Add "private" and "secret" review fields.

* Review table UI improvements.

* Fix installations that set zlib.output_compression by default (Elliott
  Karpilovsky).

* Soft limit on the number of concurrent paper format checker processes
  should reduce system load at submission time.

* Add a setting where PC members can see reviews but not reviewer
  identities (requested by Matthew Frank).

* Other bug fixes, UI improvements (particularly for paper submission), and
  documentation improvements.

* Thanks to Michael Vrable, Stefan Savage, and Scott Rose.


## Version 2.13 - 22.Jan.2008

* Add support for paper format checking with Geoff Voelker's banal script.
  Thanks to Geoff for the script and debugging support, and to Harald
  Schiöberg for providing an initial implementation.

* Fix "Monitor external reviews" (requested by Matthew Frank).

* Add two more textual review fields (requested by Matthew Frank).

* Set the message used to invite external reviewers via the UI (requested
  by Matthew Frank).

* Hide comments from reviewers that should not be seen by reviewers.

* URL improvements.  Remove .php suffix with mod_rewrite; replace
  `paperId=` with `p=`, `reviewId=` with `r=`, `commentId=` with `c=`.

* Bug fixes and memory reduction fixes.  Especially speed up first-time
  loads by reporting the correct Content-Length for gzipped content
  (problem reported by Elliott Karpilovsky).

* Thanks also to Matthew Frank, Joseph Tucek, and Bernhard Ager.


## Version 2.12 - 30.Dec.2007

* Introduce "twiddle tags", such as `~tag`, which are visible only to the
  PC members that created them.  Based on a request from Matthew Frank.

* Add an optional note to the reviewer that PC members can supply with
  review requests.

* Support completely anonymous reviewers.

* Automatic paper assignment can avoid assigning two PC members to the same
  paper.  Based on a request from Matthew Frank.

* Add `%SHEPHERD%`, etc. to the mail tool (Jon Crowcroft).

* UI improvements.  Especially including a one-page signin process that
  allows people who haven't yet logged in to see public conference
  information such as deadlines and PC members.

* Mail improvements to MIME encoding.

* Bug fixes.

* Thanks to Matthew Frank, Mike Colagrasso, David Black-Schaffer, Ken
  Birman, and Jon Crowcroft.


## Version 2.11 - 27.Oct.2007

* Mail tool allows sending mail to contact authors or reviewers for
  selected papers.

* UI fixes for conflict of interest wording and home page (thanks, Matthew
  Frank), autoassignment page, search help, search page.

* Some fixes to MIME support, PHP uninitialized variable warnings, and
  paper downloads when submissions are closed (bug report from V. Arun).


## Version 2.10 - 24.Oct.2007

* Add some support for MIME extensions; message bodies are marked UTF-8,
  and message headers containing UTF-8 characters are quoted according to
  RFC2047.

* Fix a couple bugs in 2.9 having to do with sending email, entering
  unrequested reviews, and other things.


## Version 2.9 - 20.Oct.2007

* Add a setting allowing PC members to see tags even for conflicted papers.

* Tag names generally link to the corresponding search.

* Multiple independent paper lists will improve quicklink navigation.

* Add `notag:` searches.

* Setting description improvements.


## Version 2.8 - 11.Oct.2007

* Bug fix: Do not reveal authors' identities via responses.

* Bug fix: Avoid losing the "open for responses" setting when updating other
  setting groups.

* Authors' responses are hidden from the PC until they are ready.

* Improve review pretty printing for tabular-like text.

* Add a setting allowing comments even if reviewing is closed.

* Final copy display improvements.

* Correlate soft and hard deadlines: if a hard deadline is set, but not a
  soft deadline, show the hard deadline to relevant users.

* Other behavior improvements.


## Version 2.7 - 23.Aug.2007

* Email notification for comments.  Authors, reviewers, and PC members can
  request email notification when comments are added to a paper they are
  interested in.  The system tracks a global preference and per-paper
  preferences, so one can say "no notifications in general, but notify me
  about paper 4".  Notification is on by default.  Requires schema changes;
  see the file `Code/updateschema.sql`.

* PC members and reviewers can view a paper's comments before they finish
  their own reviews for that paper.

* Reviews have a "The review is ready for others to see." checkbox, instead
  of "Submit" vs. "Save changes" pushbuttons.  The checkbox is a better UI.

* Offline reviewing improvements and text review download fixes.

* Support "External reviewers" mail class (Jim Larus).

* Add `Code/updateschema.sql`.

* Fix database-creation bugs introduced in Version 2.4 (!), plus some old
  bugs.


## Version 2.6 - 20.Aug.2007

* New way to collect author information.  Author information is entered
  using separate text fields for Name, Email, and Affiliation.  If a user's
  email is listed in the Email field of a paper's author information, that
  user becomes a contact author for the paper and can edit the paper.

* XHTML 1.0 Strict conformance.

* Performance improvements: better cacheability for images, gzip JavaScript
  and CSS.

* Style changes, especially on settings pages.


## Version 2.5 - 12.Aug.2007

* Optionally collect users' addresses and phone numbers.


## Version 2.4 - 12.Aug.2007

* Allow setting an info message that appears on the homepage.

* Add an "Abstracts" display option to search screens, filled in by Ajax.

* Fix several IE problems, "Authors" checkbox on search display, "withdraw"
  popup dialog, and others.

* Style changes.


## Version 2.3 - 16.Jul.2007

* New action log display includes search.

* Validate XHTML.

* Other fixes.


## Version 2.2 - 11.Jul.2007

* Download a text file with reviewer names and emails (Frans).

* Better offline reviewing.


## Version 2.1 - 10.Jul.2007

* IE compatibility.


## Version 2.0 - 9.Jul.2007

* New mail system.

* Popup help on review scores.

* A secondary reviewer, having delegated her own review, can view other
  reviews as soon as the delegatee submits HER review.

* Visual improvements, especially to the front page.

* Other fixes.

* Thanks to Akos Ledeczi.


## Version 2.0b9 - 16.Jun.2007

* More Ajax.

* Make images, JavaScript, and CSS cacheable.

* Allow updates to a submitted paper without first unsubmitting the paper.

* Optionally allow authors to update their submissions until the submission
  deadline.

* Add "system administrator" role.

* Tags are optionally visible on paper lists.

* Email template improvements.

* Visual improvements, including the search page.

* Combine "View" and "Edit" paper tabs into a single "Paper" tab.

* Other fixes.

* Thanks to Bernhard Ager, Frans Kaashoek, and Fernando Pereira.


## Version 2.0b8 - 11.Mar.2007

* Fix policy leak: Do not reveal reviewer identities if reviews are always
  anonymous!

* Other fixes.

* Thanks to Jeff Chase.


## Version 2.0b7 - 3.Mar.2007

* Fix policy leak: When sending email, include only information the
  recipient can see.

* Selectable score diagrams and score-based sort orders in paper lists.

* Add "PC members can see all registered papers until submission deadline"
  setting.

* Account list improvements.

* Allow submitting a paper in one step.

* Improve help.

* Other fixes.

* Thanks to Bernhard Ager, Jeff Chase, Frans Kaashoek, and Andrew Myers.


## Version 2.0b6 - 1.Feb.2007

* Fix policy leak: PC members cannot see PC-only fields on review forms for
  their authored papers.

* Fix policy leak: External reviewers can't see reviewer identities unless
  the policy allows it.

* Other fixes.


## Version 2.0b5 - 27.Jan.2007

* Improve tags and help.

* Ajax review preferences.

* Line numbers for uploaded review form error messages.

* Other fixes.


## Version 2.0b4 - 13.Jan.2007

* Add automatic assignments.

* Add review form templates.

* Add paper options.

* Improve IE compatibility.

* Fix login from email links.

* Fix grace period.

* Other fixes.


## Version 2.0b3 - 10.Dec.2006

* Move to Conference Settings pages from deadline settings.

* New search settings, such as `re:<4`.

* More help.

* Remove database abstraction layer.

* Other fixes.


## Version 2.0b2 - 1.Dec.2006

* Internal updates.


## Version 2.0b1 - 28.Nov.2006

* Initial release.


[Review Quality Collector]: https://reviewqualitycollector.org
[Phan]: https://github.com/phan/phan
