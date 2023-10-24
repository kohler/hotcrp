# HotCRP page construction

HotCRP uses [components][] to handle requests and render pages. You can add
new pages to HotCRP or selectively override existing pages by extending
`$Opt["pages"]`.

## Page flow

When a request arrives, HotCRP parses the requested URL into the *site path*,
which the base URL for this conference; the *page*, which is the name of the
page to render; and the *path*, which is anything following the page. For
example:

    https://yoursite.edu/hotcrpprefix/paper/3/edit
                        --------------     *******
                          SITE PATH   ===== PATH
                                      PAGE

The site path may end with a user identifier, such as `/u/0/`, that’s present
when a single browser logs in to multiple accounts.

HotCRP looks up the page component named `PAGE`. If the page component does
not exist, or if its name starts with two underscores, HotCRP reports a
page-not-found error. If the user is disabled, then HotCRP checks the page
component’s `allow_disabled` property and returns an error to the user unless
`allow_disabled` is true.

Otherwise, the page component will have a `group` member, which defaults to
`PAGE` but need not equal it (for instance, if one page is an alias for
another). The value of this `group` member is called the **page group**.

## Processing the request

HotCRP next obtains a list of the page group’s components, including (1) the
page component itself, and then (2) any components that are direct members of
that group, ordered by their `order` properties.

In the first stage, HotCRP scans the component list and calls any allowed
`request_function`s.

A `request_function` defines a PHP callback that processes the request before
page rendering starts. Parameters to `request_function` are (1) the viewing
user (a `Contact` object), (2) the request being processed (a `Qrequest`
object), (3) the set of page components (a `ComponentSet` object), and (4) the
page component itself (a generic object). `request_function` may have the
format `CLASSNAME::FUNCTIONNAME` or `*CLASSNAME::FUNCTIONNAME`. In the first
form, HotCRP calls the named function statically. In the second form (with
`*`), HotCRP constructs an object of type `CLASSNAME` (constructor parameters
`Contact, Qrequest, ComponentSet`) and then calls the relevant `FUNCTIONNAME`
on that object. All calls with the same `*CLASSNAME` will use the same object.

A component’s `request_function` may be blocked by the `allow_if` property.
For instance, `"allow_if": "req.clearbug"` allows the `request_function` only
if the request has a `clearbug` parameter.

If a `request_function` explicitly returns `false` (as opposed to `null`, or
by simply falling off the end), then HotCRP quits calling `request_function`s
and terminates request processing. A `request_function` may also throw a value
of type `Redirection` to force HotCRP to redirect the user’s browser to
another URI.

## Rendering the result

Assuming the `request_function`s do not throw a redirection, HotCRP next
re-scans the component list and prints the corresponding components.

To print a component, HotCRP checks for:

1. A `print_function` property. If present, this calls the corresponding PHP
   callback, using the same syntax and arguments as `request_function`, above.

2. Otherwise, an `html_content` property. If present, this is copied to the output.

3. In either case, if the component has a `print_members` property, HotCRP
   next prints the members of the group with the component’s name.

A `print_function` may cancel further rendering by returning explicit `false`,
or by throwing a `Redirection` or `PageCompletion` exception.

Many HotCRP pages do not have separate `request_function`s, instead handling
all request parsing as part of the first `print_function` for a page.

## Shorthand

Page components may be defined using an array shorthand. The notation `[NAME,
ORDER, PRINT_FUNCTION]` or `[NAME, ORDER, PRINT_FUNCTION, PRIORITY]` is the
same as an object with the corresponding properties:

```
{
    "name": NAME, "order": ORDER, "print_function": PRINT_FUNCTION [, "priority": PRIORITY]
}
```

## Allowance conditions

In addition to the global `allow_if` conditions, such as `"admin"`, page
components support conditions relating to the current request.

* `post`: The current request is a POST with a valid CSRF token
* `anypost`: The current request is a POST, whether or not it has a valid CSRF
  token
* `getpost`: The current request is a GET, POST, or HEAD with a valid CSRF
  token
* `get`, `head`: The current request has the specified method
* `req.XXX`: The current request defines the `XXX` parameter to some value,
  possibly the empty string


[components]: ./components.md
