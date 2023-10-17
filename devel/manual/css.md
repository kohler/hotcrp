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
* `.header-actas`, `.dropmenu-container`: 12 (must be > `.modal.transparent`)
* `.modal`: 14
* `.modal-dialog`: 16 (must be > `.modal`)
* `.bubble`: 20

* `body.page #p-header`: 3 (to occlude `.pslcard-nav`)
* `.pspcard`: 3 (to occlude `.pslcard-nav`)
* `.pslcard-nav`: (0)
* `.pslcard-home`: -1
* `.home-sidebar`: 1
* `button:hover`, `button:focus`, etc.: 1
* `.longtext-fader`: 1
* `.longtext-expander`: 2
* `.overlong-content`: 1
* `.overlong-collapsed > .overlong-content`: 0
* `.overlong-collapsed > .overlong-divider > .overlong-mark`: 2
* `.cmtcard.is-editing.popout`: 4

## `id`

* Any `id` starting with `[a-z][-_]` is reserved for HotCRP use
    * Paper and review fields cannot follow that pattern
    * Paper and review fields also must not match JSON keys used for papers
      and reviews
* `id^=t-` defines the page type; it is only set on the `<body>` element
* `id^=p-` is for page-level elements
    * `#p-tracker` (optional)
    * `#p-page`
        * `#p-header`
        * `#p-body`
    * `#p-footer`
* `id^=i-` is for icons
* `id^=f-` is for forms
* `id^=h-` is for header elements
    * `#h-site`
    * `#h-page`
    * `#h-right`
    * `#h-deadline`
    * `#h-messages`
* `id^=n-` is for navigation elements (quicklinks)
    * `#n-next`
    * `#n-prev`
    * `#n-search`
    * `#n-list`
* `id^=k-` is for inputs and for programmatically assigned IDs, e.g., elements
  that need IDs for reference by `label`
