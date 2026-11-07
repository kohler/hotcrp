# HotCRP CSS

This page documents aspects of HotCRP styles not obvious from the style file
itself.

## `z-index`

Page-level stacking contexts

* `#p-tracker`: 8
* `#p-page`: 0
* `#p-footer`: 0

Values meaningful within page stacking contexts, especially `#p-page`

Generic values

* `.modal.transparent`: 10
* `#h-actas`, `.dropmenu-container`: 12 (must be > `.modal.transparent`)
* `.modal`: 14
* `.modal-dialog`: 16 (must be > `.modal`)
* `.bubble`: 20

* `body.page #p-header`: 3 (to occlude `.s-psl-nav`)
* `.pspcard`: 3 (to occlude `.s-psl-nav`)
* `.s-psl-nav`: (0)
* `.pslcard-home`: -1
* `.home-sidebar`: 1
* `button:hover`, `button:focus`, etc.: 1
* `.longtext-fader`: 1
* `.longtext-expander`: 2
* `.overlong-content`: 1
* `.overlong-collapsed > .overlong-content`: 0
* `.overlong-collapsed > .overlong-divider > .overlong-mark`: 2
* `.cmtcard.edit.popout`: 4


## `class`

* The CleanHTML sanitizer blacklists classes matching `.ui*`, `.js-*`,
  `.s-*`, `.need-*`, and `.pl*` except for `.pl-[0-9]`
* `.s-` is intended for structural classes subject to broad Javascript
  querySelectors (or `.closest` executed on cleaned HTML)


## `id`

* IDs generally have the form `<PREFIX>-<NAME>`, optionally followed by
  suffixes introduced by `:` that name parts of the element, as in
  `#s-pcconf:body` (the body of `#s-pcconf`) or `#rf-s01:d` (the
  description of review field `s01`)
    * A name inserted into an ID must not contain `:`. Names chosen by users
      are restricted further (page group names, for example, are single
      lowercase words) or, if they cannot be restricted (tags, for example),
      are replaced by numbers
* Any `id` starting with up to two lowercase letters followed by `-`, `_`, or
  a digit (`[a-z]{0,2}[-_0-9]`) is reserved for HotCRP use, as are IDs
  starting with `fold` (used by older fold containers, such as `#foldpaper`)
    * Paper and review fields cannot follow that pattern
    * Paper and review fields also must not match JSON keys used for papers
      and reviews
    * A paper field’s HTML ID is derived from its name (see
      `PaperOption::make_readable_formid`). Names that would produce a
      reserved ID, or that collide with intrinsic fields, gain the prefix
      `sf-`, so `id^=sf-` is for paper fields
    * Review fields use HotCRP-assigned short IDs, such as `s01` and `t03`,
      in the reserved space; `id^=rf-` is for review field elements, such as
      `#rf-s01:d` (a review field’s description)
* Paper, review, and comment anchors also use reserved IDs
    * `#p<PID>`: a submission’s `article.s-paper`
    * `#r<PID><ORDINAL>` (or `#r<PID>r<REVIEWID>`): a review; its body is
      `#r<…>:body`
    * `#c<ORDINAL>`, `#cx<COMMENTID>`, `#cnew`: comments; IDs for comment
      editing elements append a suffix, such as `#cnew:attachments`
    * `#response`, `#<ROUNDNAME>response`: responses
* `id^=m-` defines the page type; it is only set on the `<body>` element
* `id^=p-` is for page-level elements
    * `#p-theme` (in header)
    * `#p-banner` (optional: custom banners)
        * `#p-tracker` (optional)
    * `#p-page`
        * `#p-header`
        * `#p-body`
    * `#p-footer`
* `id^=i-` is for icons
* `id^=f-` is for forms
    * `#f-mail`
    * `#f-paper`
    * `#f-profile`
    * `#f-reauth`
    * `#f-review`
    * `#f-settings`
    * `#f-signout`
* `id^=h-` is for header elements
    * `#h-actas`
    * `#h-site`
    * `#h-page`
    * `#h-right`
    * `#h-deadline`
    * `#h-messages`
    * `#h-usermenu`
    * `#h-usermenubutton`
    * `#h-title`
    * `#h-subtitle`
* `id^=n-` is for navigation elements (quicklinks)
    * `#n-next`
    * `#n-prev`
    * `#n-search`
    * `#n-list`
* `id^=s-` is for submission elements
    * `#s-<PSID>`: a sidebar section (`.s-ps`), where `PSID` is `manager`,
      `lead`, `shepherd`, `tags`, `follow`, `pcconf`, `collaborators`,
      `decision`, `revpref`, or `tag-<N>` (a voting tag section, numbered
      in page order); plugins can add more with `PaperTable::print_ps_section`
        * `#s-<PSID>:label`: its heading (see `PaperTable::ps_label_id`)
        * `#s-<PSID>:body`: its collapsible body, if any
    * `#s-pspcard-open`: the sidebar’s full content, which narrow screens
      reveal with “More”
    * `#s-abstract:body`: the abstract
* `id^=sg-` is for submission page groups (see `PaperOption::page_group`);
  page group names are single lowercase words (`[a-z][a-z0-9]*`), since they
  are inserted into IDs as-is
    * `#sg-<GROUP>`: a collapsible page group
    * `#sg-<GROUP>:body`: its body
* `id^=d-` is for descriptions, such as tooltips (`#d-rf-<N>`,
  `#d-pcconf:<N>`)
* `id^=k-` is for inputs and for programmatically assigned IDs, e.g., elements
  that need IDs for reference by `label`
