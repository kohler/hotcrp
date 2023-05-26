// settings.js -- HotCRP JavaScript library for settings
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

"use strict";

(function () {

handle_ui.on("hashjump.js-hash", function (hashc, focus) {
    var e, fx, fp;
    if (hashc.length === 1
        && (e = document.getElementById(decodeURIComponent(hashc[0])))
        && (fx = e.closest(".fx2"))
        && (fp = fx.closest(".fold2c"))) {
        fold(fp, false, 2);
    }
});

handle_ui.on("js-settings-radioitem-click", function () {
    let e = this.closest(".settings-radioitem"), re;
    if (e
        && (re = e.firstElementChild)
        && (re = re.firstElementChild)
        && re.tagName === "INPUT")
        re.click();
});

handle_ui.on("js-settings-sub-nopapers", function () {
    var v = $(this).val();
    hotcrp.fold("pdfupload", v == 1, 2);
    hotcrp.fold("pdfupload", v != 0, 3);
});

$(function () { $(".js-settings-sub-nopapers").trigger("change"); });

handle_ui.on("js-settings-show-property", function () {
    var prop = this.getAttribute("data-property"),
        $j = $(this).closest(".settings-sf, .settings-rf").find(".is-property-" + prop);
    $j.removeClass("hidden");
    addClass(this, "btn-disabled");
    tooltip.erase.call(this);
    if (document.activeElement === this || document.activeElement === document.body) {
        var $jx = $j.find("input, select, textarea").not("[type=hidden], :disabled");
        $jx.length && setTimeout(function () { focus_at($jx[0]); }, 0);
    }
});

function make_option_element(value, text) {
    const opt = document.createElement("option");
    opt.value = value;
    opt.append(text);
    return opt;
}


function settings_delete(elt, message) {
    var form = elt.closest("form");
    addClass(elt, "deleted");
    var deleted = form.elements[elt.id + "/delete"];
    if (!deleted) {
        deleted = hidden_input(elt.id + "/delete", "");
        deleted.setAttribute("data-default-value", "");
        elt.appendChild(deleted);
    }
    deleted.value = "1";
    var deleter = form.elements[elt.id + "/deleter"];
    if (deleter && deleter.tagName === "BUTTON") {
        deleter.disabled = true;
        addClass(deleter, "btn-danger");
        tooltip.erase.call(deleter);
    }
    if (hasClass(elt, "is-new")) {
        addClass(elt, "hidden");
        $(elt).find("input, select, textarea").addClass("ignore-diff");
        return false;
    }
    var edit = document.getElementById(elt.id + "/edit") || elt,
        msg = '<div class="feedback is-warning" id="'.concat(elt.id, '/delete_message">', message, '</div>');
    $(edit).children().addClass("hidden");
    $(elt).find(".want-delete-marker").each(function () {
        this.disabled = true;
        addClass(this, "text-decoration-line-through");
        var parent = this.closest(".entryi, .entryg");
        if (parent) {
            removeClass(parent, "hidden");
            removeClass(parent, "mb-3");
            $(parent).after(msg);
            msg = null;
        }
        return false;
    });
    msg && $(edit).append(msg);
    return true;
}

function settings_field_unfold(evt) {
    if (evt.which.open && evt.which.n === 2) {
        var ch = this.parentElement.firstChild;
        for (; ch; ch = ch.nextSibling) {
            if (ch !== this && hasClass(ch, "fold2o") && !form_differs(ch))
                fold(ch, true, 2);
        }
        $(this).find("textarea").css("height", "auto").autogrow();
        $(this).find("input[type=text]").autogrow();
        $(this).scrollIntoView();
    }
}

function settings_disable_children(e) {
    $(e).find("input, select, textarea, button").each(function () {
        this.removeAttribute("name"); // do not submit with form
        if (this.type === "checkbox" || this.type === "radio" || this.type === "button")
            this.disabled = true;
        else if (this.type !== "select")
            this.readonly = true;
        removeClass(this, "ui");
    });
}

function settings_field_order(parentid) {
    var i = 0, curorder, defaultorder, orde, n,
        form = document.getElementById("settingsform"),
        c = document.getElementById(parentid),
        moveup = null, movedown = null;
    for (n = c.firstChild; n; n = n.nextSibling) {
        orde = form.elements[n.id + "/order"];
        if (hasClass(n, "deleted")) {
            orde.value = 0;
            continue;
        }
        ++i;
        moveup = n.querySelector(".moveup");
        moveup.disabled = movedown === null;
        movedown = n.querySelector(".movedown");
        movedown.disabled = false;
        curorder = +orde.value;
        defaultorder = +input_default_value(orde);
        if (defaultorder > 0 && defaultorder < curorder) {
            curorder = defaultorder;
        }
        if (i === 1 || curorder !== curorder || curorder < i) {
            curorder = i;
        }
        orde.value = i = curorder;
    }
    movedown && (movedown.disabled = true);
    form_highlight(form);
}

function field_find(ftypes, name) {
    for (const ft of ftypes) {
        if (ft.name === name)
            return ft;
    }
    return null;
}

function field_instantiate_type(ee, ftypes, ftype) {
    const select = ee.querySelector("select");
    select.replaceChildren();
    if (ftype.convertible_to.length === 1) {
        select.closest(".entry").replaceChildren(ftype.title, hidden_input(select.name, ftype.name, {id: select.id}));
    } else {
        for (const ct of ftype.convertible_to) {
            select.add(make_option_element(ct, field_find(ftypes, ct).title));
        }
        select.value = ftype.name;
        select.setAttribute("data-default-value", ftype.name);
    }
}

function field_instantiate(ee, ftypes, tname, instantiators) {
    if (!tname && ee.id.endsWith("/edit")) {
        tname = ee.closest("form").elements[ee.id.substring(0, ee.id.length - 4) + "type"].value;
    }
    const ftype = field_find(ftypes, tname);
    for (let pe = ee.firstElementChild; pe; ) {
        const npe = pe.nextElementSibling,
            prop = pe.getAttribute("data-property");
        if (prop === "type") {
            field_instantiate_type(pe, ftypes, ftype);
        } else if (hasClass(pe, "property-optional")
                   ? (ftype.properties || {})[prop]
                   : (ftype.properties || {})[prop] !== false) {
            if (instantiators && instantiators[prop])
                instantiators[prop](pe, ftype);
            if ((ftype.placeholders || {})[prop])
                pe.querySelector("input, textarea").placeholder = ftype.placeholders[prop];
        } else {
            pe.remove();
        }
        pe = npe;
    }
    let ifprop = ee.querySelectorAll(".if-property");
    for (let e of ifprop) {
        if (!(ftype.properties || {})[e.getAttribute("data-property")])
            e.remove();
    }
}


// BEGIN SUBMISSION FIELD SETTINGS
(function () {
var sftypes;

function sf_order() {
    settings_field_order("settings-sform");
}

function sf_instantiate(ee) {
    sftypes = sftypes || JSON.parse($$("settings-sform").getAttribute("data-sf-types"));
    field_instantiate(ee, sftypes, null, null);
}

handle_ui.on("js-settings-sf-move", function (evt) {
    var sf = this.closest(".settings-sf");
    if (hasClass(this, "moveup") && sf.previousSibling) {
        sf.parentNode.insertBefore(sf, sf.previousSibling);
    } else if (hasClass(this, "movedown") && sf.nextSibling) {
        sf.parentNode.insertBefore(sf, sf.nextSibling.nextSibling);
    } else if (hasClass(this, "delete")) {
        var msg, x;
        if ((x = this.getAttribute("data-exists-count")|0))
            msg = 'This field will be deleted from the submission form and from ' + plural(x, 'submission') + '.';
        else
            msg = 'This field will be deleted from the submission form. It is not used on any submissions.';
        settings_delete(sf, msg);
        foldup.call(sf, evt, {n: 2, open: true});
    }
    tooltip.erase(this);
    sf_order();
});


function add_dialog() {
    var $d, sel, samps = $$("settings-sf-samples").content.childNodes;
    function cur_option() {
        return sel.options[sel.selectedIndex] || sel.options[0];
    }
    function render_template() {
        var opt = cur_option(), sft = $d.find(".settings-sf-template-view")[0];
        if (hasClass(sft.lastChild, "settings-sf-example"))
            sft.lastChild.remove();
        sft.appendChild(samps[opt.value | 0].cloneNode(true));
        settings_disable_children(sft);
    }
    function submit(evt) {
        var opt = cur_option(),
            samp = samps[opt.value | 0],
            h = $$("settings-sf-new").innerHTML,
            next = 1, odiv;
        while ($$("sf/" + next + "/name")) {
            ++next;
        }
        h = h.replace(/\/\$/g, "/" + next);
        odiv = $(h).removeClass("hidden").appendTo("#settings-sform").awaken();
        $$("sf/" + next + "/type").options[0].value = samp.getAttribute("data-name");
        sf_instantiate($$("sf/" + next + "/edit"));
        $$("sf/" + next + "/name").focus();
        sf_order();
        $d.close();
        evt.preventDefault();
    }
    function create() {
        var hc = popup_skeleton({className: "modal-dialog-w40"}), i;
        hc.push('<h2>Add field</h2>');
        hc.push('<p>Choose a template for the new field.</p>');
        hc.push('<select name="sf_template" class="w-100 want-focus" size="5">', '</select>');
        for (i = 0; samps[i]; ++i) {
            hc.push('<option value="'.concat(i, i ? '">' : '" selected>', escape_html(samps[i].getAttribute("data-title")), '</option>'));
        }
        hc.pop();
        hc.push('<fieldset class="settings-sf-template-view mt-4 modal-demo-fieldset"><legend>Example</legend></fieldset>');
        hc.push_actions(['<button type="submit" name="add" class="btn-primary">Add field</button>',
            '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show();
        sel = $d.find("select")[0];
        render_template();
        $(sel).on("input", render_template);
        $d.find("form").on("submit", submit);
    }
    create();
}

handle_ui.on("js-settings-sf-add", add_dialog);

$(document).on("hotcrpsettingssf", ".settings-sf", function () {
    var view = document.getElementById(this.id + "/view"),
        edit = document.getElementById(this.id + "/edit");
    settings_disable_children(view);
    if (edit) {
        sf_instantiate(edit);
        if (!form_differs(edit)
            && !$(edit).find(".is-error, .has-error").length)
            fold(this, true, 2);
    }
    removeClass(this, "hidden");
    sf_order();
});

handle_ui.on("foldtoggle.settings-sf", settings_field_unfold, -1);

tooltip.add_builder("settings-sf", function (info) {
    var x = "#settings-sf-caption-values";
    if (this.name.endsWith("/name"))
        x = "#settings-sf-caption-name";
    else if (this.name.endsWith("/condition"))
        x = "#settings-sf-caption-condition";
    else if (this.name.endsWith("/description"))
        x = "#settings-sf-caption-description";
    return $.extend({anchor: "h", content: $(x).html(), className: "gray"}, info);
});

})();
// END SUBMISSION FIELD SETTINGS


handle_ui.on("js-settings-banal-pagelimit", function (evt) {
    var s = $.trim(this.value),
        empty = s === "" || s.toUpperCase() === "N/A",
        $ur = $(this).closest(".has-fold").find(".settings-banal-unlimitedref");
    $ur.find("label").toggleClass("dim", empty);
    $ur.find("input").prop("disabled", empty);
    if (evt && evt.type === "change" && empty)
        $ur.find("input").prop("checked", false);
});


handle_ui.on("js-settings-automatic-tag-new", function () {
    var odiv = this.closest(".settings-automatic-tag"), h, ctr = 1;
    while ($$("automatic_tag/" + ctr))
        ++ctr;
    h = $("#settings-new-automatic-tag").html().replace(/\/\$/g, "/" + ctr);
    odiv = $(h).appendTo("#settings-automatic-tags");
    odiv.find("input[type=text]").autogrow();
    $$("automatic_tag/".concat(ctr, "/tag")).focus();
});

handle_ui.on("js-settings-automatic-tag-delete", function () {
    var ne = this.form.elements[this.closest(".settings-automatic-tag").id + "/tag"];
    settings_delete(this.closest(".settings-automatic-tag"),
        "This automatic tag will be removed from settings and from <a href=\"".concat(hoturl_html("search", {q: "#" + ne.defaultValue, t: "all"}), '" target="_blank" rel="noopener">any matching submissions</a>.'));
    form_highlight(this.form);
});

handle_ui.on("js-settings-track-add", function () {
    for (var i = 1; $$("track/" + i); ++i) {
    }
    var trhtml = $("#settings-track-new").html().replace(/\/\$/g, "/" + i);
    $("#track\\/" + (i - 1)).after(trhtml);
    $("#track\\/" + i).awaken();
    this.form.elements["track/".concat(i, "/tag")].focus();
});

handle_ui.on("js-settings-topics-copy", function () {
    var topics = [];
    $(this).closest(".has-copy-topics").find("input").each(function () {
        if (this.type === "text"
            && this.name.startsWith("topic/")
            && this.name.endsWith("/name")
            && this.defaultValue.trim() !== "")
            topics.push(this.defaultValue.trim());
    });
    var node = $("<textarea></textarea>").appendTo(document.body);
    node[0].value = topics.join("\r");
    node[0].select();
    document.execCommand("copy");
    node.remove();
});


function settings_review_round_selectors() {
    var a = [], ch, form = $$("settingsform");
    for (ch = $$("settings-review-rounds").firstChild; ch; ch = ch.nextSibling) {
        if (!hasClass(ch, "deleted")) {
            var ne = form.elements[ch.id + "/name"],
                n = ne ? ne.value.trim() : "(unknown)";
            if (/^(?:|unnamed)$/i.test(n)) {
                n = hasClass(ch, "is-new") ? "(new round)" : "unnamed";
            }
            a.push({value: ch.id.substring(7)|0, name: n});
        }
    }
    $(form).find(".settings-review-round-selector").each(function () {
        var cur = this.firstChild, j = 0, selidx = this.selectedIndex;
        while (cur || j < a.length) {
            if (cur && cur.value === "0") {
                cur = cur.nextSibling;
            } else if (cur && a[j] && cur.value === a[j].value) {
                if (cur.textContent !== a[j].name)
                    cur.textContent = a[j].name;
                cur = cur.nextSibling;
                ++j;
            } else if (cur && (!a[j] || (cur.value|0) < a[j].value)) {
                if (selidx >= cur.index) {
                    --selidx;
                }
                var last = cur;
                cur = cur.nextSibling;
                last.remove();
            } else {
                if (cur && selidx >= cur.index) {
                    ++selidx;
                }
                this.insertBefore(make_option_element(a[j].value, a[j].name), cur);
                ++j;
            }
        }
        this.selectedIndex = selidx;
    });
}

handle_ui.on("change.js-settings-review-round-name", settings_review_round_selectors);
handle_ui.on("input.js-settings-review-round-name", settings_review_round_selectors);

handle_ui.on("js-settings-review-round-new", function () {
    var i, h = $$("settings-review-round-new").innerHTML, $n;
    for (i = 1; $$("review/" + i); ++i) {
    }
    $n = $(h.replace(/\/\$/g, "/" + i));
    $("#settings-review-rounds").append($n);
    $n.find("textarea").css({height: "auto"}).autogrow();
    $n.awaken();
    form_highlight(this.form);
    settings_review_round_selectors(this.form);
});

handle_ui.on("js-settings-review-round-delete", function () {
    var div = this.closest(".js-settings-review-round"),
        ne = this.form.elements[div.id + "/name"],
        n = div.getAttribute("data-exists-count")|0;
    if (!n) {
        settings_delete(div, "This review round will be deleted.");
    } else {
        settings_delete(div, "This review round will be deleted and <a href=\"".concat(hoturl_html("search", {q: "re:\"" + (ne ? ne.defaultValue : "<invalid>") + "\""}), '" target="_blank" rel="noopener">', plural(n, "review"), '</a> assigned to another round.'));
    }
    form_highlight(this.form);
    settings_review_round_selectors(this.form);
});


handle_ui.on("js-settings-submission-round-new", function () {
    var i, h = $$("settings-submission-round-new").innerHTML, $n;
    for (i = 1; $$("submission/" + i); ++i) {
    }
    $n = $(h.replace(/\/\$/g, "/" + i));
    $("#settings-submission-rounds").append($n);
    $n.awaken();
    form_highlight(this.form);
    this.form.elements["submission/" + i + "/tag"].focus();
});

handle_ui.on("js-settings-submission-round-delete", function () {
    var div = this.closest(".js-settings-submission-round"),
        ne = this.form.elements[div.id + "/tag"];
    if (settings_delete(div, "This submission class will be removed.")
        && ne) {
        var search = {q: "sclass:" + ne.defaultValue, t: "all", forceShow: 1};
        $.get(hoturl("api/search", search), null, function (v) {
            if (v && v.ok && v.ids && v.ids.length)
                $$(div.id + '/delete_message').innerHTML = 'This submission class will be removed. The <a href="'.concat(hoturl_html("search", {q: "sclass:" + ne.defaultValue, t: "all"}), '" target="_blank" rel="noopener">', plural(v.ids.length, "submission"), '</a> associated with this class will remain in the system, and will still have the #', escape_html(ne.defaultValue), ' tag, but will be reassigned to other submission classes.');
        });
    }
    form_highlight(this.form);
});


var review_form_settings = (function () {

var fieldorder = [], samples, rftypes,
    colors = ["sv", "Red to green", "svr", "Green to red",
              "bupu", "Blue to purple", "pubu", "Purple to blue",
              "rdpk", "Red to pink", "pkrd", "Pink to red",
              "viridisr", "Yellow to purple", "viridis", "Purple to yellow",
              "orbu", "Orange to blue", "buor", "Blue to orange",
              "turbo", "Turbo", "turbor", "Turbo reversed",
              "catx", "Category10", "none", "None"];

function unparse_value(fld, idx) {
    if (fld.start && fld.start !== 1) {
        var cc = fld.start.charCodeAt(0);
        return String.fromCharCode(cc + fld.values.length - idx);
    } else
        return idx.toString();
}

function values_to_text(fld) {
    var i, t = [];
    if (!fld.values)
        return "";
    for (i = 0; i !== fld.values.length; ++i)
        t.push(unparse_value(fld, i + 1) + ". " + fld.values[i]);
    if (fld.start && fld.start !== 1)
        t.reverse();
    if (t.length)
        t.push(""); // get a trailing newline
    return t.join("\n");
}

function rf_order() {
    settings_field_order("settings-rform");
}

function rf_fill_control(form, name, value, setdefault) {
    var elt = form.elements[name];
    elt && $(elt).val(value);
    elt && setdefault && elt.setAttribute("data-default-value", value);
}

function rf_color() {
    var c = this, sv = $(this).val(), i, scheme = make_color_scheme(9, sv, false);
    hasClass(c.parentElement, "select") && (c = c.parentElement);
    while (c && !hasClass(c, "rf-scheme-example")) {
        c = c.nextSibling;
    }
    for (i = 1; i <= scheme.max && c; ++i) {
        if (c.children.length < i)
            $(c).append('<svg width="0.5em" height="0.75em" viewBox="0 0 1 1"><path d="M0 0h1v1h-1z" fill="currentColor" /></svg>');
        c.children[i - 1].setAttribute("class", scheme.className(i));
    }
    while (c && i <= c.children.length) {
        c.removeChild(c.lastChild);
    }
}

handle_ui.on("change.rf-scheme", rf_color);

function rf_fill(pos, fld, setdefault) {
    var form = document.getElementById("settingsform"),
        rfid = "rf/" + pos;
    rf_fill_control(form, rfid + "/name", fld.name || "", setdefault);
    rf_fill_control(form, rfid + "/type", fld.type, setdefault);
    rf_fill_control(form, rfid + "/description", fld.description || "", setdefault);
    rf_fill_control(form, rfid + "/visibility", fld.visibility || "re", setdefault);
    rf_fill_control(form, rfid + "/values_text", values_to_text(fld), setdefault);
    rf_fill_control(form, rfid + "/required", fld.required ? "1" : "0", setdefault);
    var colors = form.elements[rfid + "/scheme"];
    if (colors) {
        fld.scheme = fld.scheme || "sv";
        rf_fill_control(form, rfid + "/scheme", fld.scheme, setdefault);
        rf_color.call(colors);
    }
    var ec, ecs = fld.exists_if != null ? fld.exists_if : "";
    if (ecs === "" || ecs.toLowerCase() === "all") {
        ec = "all";
    } else {
        ec = "custom";
        if (/^round:[a-zA-Z][-_a-zA-Z0-9]*$/.test(ecs)) {
            var ecelt = form.elements[rfid + "/presence"];
            if (ecelt.querySelector("option[value=\"" + ecs + "\"]"))
                ec = ecs;
        }
    }
    rf_fill_control(form, rfid + "/presence", ec, setdefault);
    rf_fill_control(form, rfid + "/condition", ecs, setdefault);
    rf_fill_control(form, rfid + "/id", fld.id, true);
    $("#rf\\/" + pos + " textarea").trigger("change");
    $("#rf\\/" + pos + "\\/view").html(rf_render_view(fld));
    $("#rf\\/" + pos + "\\/delete").attr("aria-label", "Delete from form");
    if (setdefault) {
        rf_fill_control(form, rfid + "/order", fld.order || 0, setdefault);
    }
    if (fld.uid) {
        $("#rf\\/" + pos).attr("data-rf", fld.uid);
    }
    if (fld.configurable === false) {
        $("#rf\\/" + pos + " .settings-draghandle, #rf\\/" + pos + " .settings-rf-actions").addClass("hidden");
        $$("rf/" + pos + "/edit").disabled = true;
    }
    return false;
}

function rf_delete(evt) {
    var rf = this.closest(".settings-rf");
    if (settings_delete(rf, "This field will be deleted from the review form.")) {
        if (rf.hasAttribute("data-rf")) {
            var search = {q: "has:" + rf.getAttribute("data-rf"), t: "all", forceShow: 1};
            $.get(hoturl("api/search", search), null, function (v) {
                var t;
                if (v && v.ok && v.ids && v.ids.length)
                    t = 'This field will be deleted from the review form and from reviews on <a href="'.concat(hoturl_html("search", search), '" target="_blank" rel="noopener">', plural(v.ids.length, "submission"), "</a>.");
                else if (v && v.ok)
                    t = "This field will be deleted from the review form. No reviews have used the field.";
                else
                    t = "This field will be deleted from the review form and possibly from some reviews.";
                $$(rf.id + "/delete_message").innerHTML = t;
            });
        }
        foldup.call(rf, evt, {n: 2, open: true});
    }
    rf_order();
}

tooltip.add_builder("settings-rf", function (info) {
    var m = this.name.match(/^rf\/\d+\/(.*?)(?:_text|)$/);
    return $.extend({
        anchor: "w", content: $("#settings-rf-caption-" + m[1]).html(), className: "gray"
    }, info);
});

function rf_visibility_text(visibility) {
    if ((visibility || "re") === "re")
        return "(hidden from authors)";
    else if (visibility === "admin")
        return "(shown only to administrators)";
    else if (visibility === "secret")
        return "(secret)";
    else if (visibility === "audec")
        return "(hidden from authors until decision)";
    else if (visibility === "pconly")
        return "(hidden from authors and external reviewers)";
    else
        return "";
}

function rf_render_view(fld) {
    var hc = new HtmlCollector;

    hc.push('<h3 class="rfehead">', '</h3>');
    if (fld.type === "checkbox") {
        hc.push('<label class="revfn checki'.concat(fld.required ? " field-required" : "", '"><span class="checkc"><input type="checkbox" disabled></span>'), '</label>');
    } else {
        hc.push('<label class="revfn'.concat(fld.required ? " field-required" : "", '">'), '</label>');
    }
    hc.push_pop(fld.name_html || "&lt;unnamed&gt;");
    var t = rf_visibility_text(fld.visibility);
    if (t)
        hc.push('<div class="field-visibility">'.concat(t, '</div>'));
    hc.pop();

    hc.push('<ul class="feedback-list">', '</ul>');
    if (fld.exists_if && /^round:[a-zA-Z][-_a-zA-Z0-9]*$/.test(fld.exists_if)) {
        hc.push('<li class="is-diagnostic format-inline is-warning-note">Present on ' + fld.exists_if.substring(6) + ' reviews</li>');
    } else if (fld.exists_if) {
        hc.push('<li class="is-diagnostic format-inline is-warning-note">Present on reviews matching “' + escape_html(fld.exists_if) + '”</li>');
    }
    hc.pop();

    if (fld.description)
        hc.push('<div class="field-d">'.concat(fld.description, '</div>'));

    if (fld.type === "dropdown") {
        hc.push('<div class="revev"><span class="select"><select>', '</select></span></div>');
        hc.push('<option value="0">(Choose one)</option>');
        fld.each_value(function (fv) {
            hc.push('<option>'.concat(fv.symbol, fv.sp1, fv.sp2, escape_html(fv.title), '</option>'));
        });
        if (!fld.required) {
            hc.push('<option value="none">N/A</option>');
        }
        hc.pop();
    } else if (fld.type === "radio") {
        hc.push('<div class="revev">', '</div>');
        fld.each_value(function (fv) {
            hc.push('<label class="checki svline"><span class="checkc"><input type="radio" disabled></span><span class="rev_num sv '.concat(fv.className, '">', fv.symbol, fv.sp1, '</span>', fv.sp2, escape_html(fv.title), '</label>'));
        });
        if (!fld.required) {
            hc.push('<label class="checki svline"><span class="checkc"><input type="radio" disabled></span>None of the above</label>');
        }
    } else if (fld.type === "text") {
        hc.push('<div class="revev"><textarea class="w-text" rows="' + Math.max(fld.display_space || 0, 3) + '" disabled>Text field</textarea></div>');
    } else if (fld.type === "checkboxes") {
        hc.push('<div class="revev">', '</div>');
        fld.each_value(function (fv) {
            hc.push('<label class="checki svline"><span class="checkc"><input type="checkbox" disabled></span><span class="rev_num sv '.concat(fv.className, '">', fv.symbol, fv.sp1, '</span>', fv.sp2, escape_html(fv.title), '</label>'));
        });
    }

    return hc.render();
}

function rf_move() {
    var rf = this.closest(".settings-rf");
    if (hasClass(this, "moveup") && rf.previousSibling) {
        rf.parentNode.insertBefore(rf, rf.previousSibling);
    } else if (hasClass(this, "movedown") && rf.nextSibling) {
        rf.parentNode.insertBefore(rf, rf.nextSibling.nextSibling);
    }
    tooltip.erase(this);
    rf_order();
}

var rfproperties = {
    scheme: function (e) {
        const select = e.querySelector("select");
        for (let i = 0; i < colors.length; i += 2) {
            select.add(make_option_element(colors[i], colors[i + 1]));
        }
    }
}

function rf_make(fj) {
    var fld = hotcrp.make_review_field(fj);
    if (fj.id != null)
        fld.id = fj.id;
    if (fj.selector != null)
        fld.selector = fj.selector;
    if (fj.instantiate != null)
        fld.instantiate = fj.instantiate;
    if (fj.configurable != null)
        fld.configurable = fj.configurable;
    return fld;
}

function rf_append(fld) {
    var pos = fieldorder.length + 1, $f, i, e, ne, prop, $j,
        rftype = field_find(rftypes, fld.type || "radio");
    if (!fld.id) {
        var pat = /text/.test(rftype.name) ? "t%02d" : "s%02d";
        for (i = 0; i === 0 || fieldorder.indexOf(fld.id) >= 0; )
            fld.id = sprintf(pat, ++i);
    }
    if (document.getElementById("rf/" + pos + "/id")
        || !/^[st]\d\d$/.test(fld.id)
        || fieldorder.indexOf(fld.id) >= 0) {
        throw new Error("rf_append error on " + fld.id + " " + (document.getElementById("rf/" + pos + "/id") ? "1 " : "0 ") + fieldorder.join(","));
    }
    fld = rf_make(fld);
    fieldorder.push(fld.id);
    $f = $($("#rf_template").html().replace(/\$/g, pos));
    field_instantiate($f.children(".settings-rf-edit")[0], rftypes, rftype.name, rfproperties);
    $f.find(".js-settings-rf-delete").on("click", rf_delete);
    $f.find(".js-settings-rf-move").on("click", rf_move);
    $f.find(".rf-id").val(fld.id);
    $f.appendTo("#settings-rform");
    rf_fill(pos, fld, true);
    $f.awaken();
}

function rf_add(fld) {
    var pos = fieldorder.length + 1;
    rf_append(fld);
    var rf = document.getElementById("rf/" + pos);
    addClass(rf, "is-new");
    foldup.call(rf, null, {n: 2, open: true});
    var ordere = document.getElementById("rf/" + pos + "/order");
    ordere.setAttribute("data-default-value", "0");
    ordere.value = pos;
}

function rfs(data) {
    var i, t, fld, mi, pfx, e, m, entryi;
    samples = data.samples;
    rftypes = data.types;

    // construct form for original fields
    data.fields.sort(function (a, b) {
        return a.order - b.order;
    });
    for (fld of data.fields) {
        rf_append(fld);
    }

    // amend form for new fields
    while (data.req) {
        pfx = "rf/".concat(fieldorder.length + 1);
        i = data.req[pfx + "/id"];
        if (!i || !/^[st]\d+$/.test(i)) {
            break;
        }
        t = data.req[pfx + "/type"];
        if (t === "radio" || t === "dropdown" || t === "checkboxes")
            rf_add({id: i, type: t, name: "", values: []});
        else
            rf_add({id: i, type: t, name: ""});
    }

    $("#settings-rform").on("foldtoggle", ".settings-rf", settings_field_unfold);

    // highlight errors, apply request
    for (i in data.req || {}) {
        if (/^rf\/\d+\//.test(i)
            && (e = document.getElementById(i))
            && !text_eq($(e).val(), data.req[i])) {
            $(e).val(data.req[i]).change();
            foldup.call(e, null, {n: 2, open: true});
        }
    }
    for (i in data.message_list || []) {
        mi = data.message_list[i];
        if (mi.field) {
            e = document.getElementById(mi.field);
            if (!e && (m = mi.field.match(/^(.*)\/values(?:$|\/)/))) {
                e = document.getElementById(m[1] + "/values_text");
            }
            if (e && (entryi = e.closest(".entryi"))) {
                append_feedback_near(entryi, mi);
                if (mi.status > 1)
                    foldup.call(entryi, null, {n: 2, open: true});
            }
            if ((m = mi.field.match(/^([^/]*\/\d+)(?=$|\/)/))
                && (e = document.getElementById(m[1] + "/view"))
                && (e = e.querySelector("ul.feedback-list"))) {
                append_feedback_to(e, mi);
            }
        }
    }

    rf_order();
    form_highlight("#settingsform");
}

function add_dialog() {
    var $d, sel;
    function cur_sample() {
        var i = sel.options[sel.selectedIndex].value | 0;
        i = (samples[i] ? i : 0);
        if (!samples[i].parse_value)
            samples[i] = rf_make(samples[i]);
        return samples[i];
    }
    function render_template() {
        var rft = $d.find(".settings-rf-template-view")[0], ex;
        if (hasClass(rft.lastChild, "settings-rf-example"))
            rft.lastChild.remove();
        ex = document.createElement("div");
        ex.className = "settings-rf-example";
        ex.innerHTML = rf_render_view(cur_sample());
        rft.appendChild(ex);
    }
    function submit(evt) {
        var fld = Object.assign({}, cur_sample());
        delete fld.id;
        for (const i of Object.keys(fld.instantiate || {})) {
            fld[i] = fld.instantiate[i];
        }
        rf_add(fld);
        document.getElementById("rf/" + fieldorder.length + "/name").focus();
        $d.close();
        rf_order();
        form_highlight("#settingsform");
        evt.preventDefault();
    }
    function create() {
        var hc = popup_skeleton({className: "modal-dialog-w40"}), i;
        hc.push('<h2>Add field</h2>');
        hc.push('<p>Choose a template for the new field.</p>');
        hc.push('<select name="rf_template" class="w-99 want-focus" size="5">', '</select>');
        for (i = 0; i !== samples.length; ++i) {
            hc.push('<option value="'.concat(i, i ? '">' : '" selected>', escape_html(samples[i].selector), '</option>'));
        }
        hc.pop();
        hc.push('<fieldset class="settings-rf-template-view mt-4 modal-demo-fieldset"><legend>Example</legend></fieldset>');
        hc.push_actions(['<button type="submit" name="add" class="btn-primary">Add field</button>',
            '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show();
        sel = $d.find("select")[0];
        render_template();
        $(sel).on("input", render_template);
        $d.find("form").on("submit", submit);
    }
    create();
}

handle_ui.on("js-settings-rf-add", add_dialog);

return rfs;
})();


handle_ui.on("js-settings-resp-active", function () {
    $(".if-response-active").toggleClass("hidden", !this.checked);
});

$(function () { $(".js-settings-resp-active").trigger("change"); });

handle_ui.on("js-settings-response-new", function () {
    var i, $rx, $rt = $("#new_response");
    for (i = 1; $$("response/" + i); ++i) {
    }
    $rt.before($rt.html().replace(/\/\$/g, "/" + i));
    $rx = $("#response\\/" + i);
    $rx.find("textarea").css({height: "auto"}).autogrow();
    $rx.awaken();
    form_highlight(this.form);
});

handle_ui.on("js-settings-response-delete", function () {
    var rr = this.closest(".settings-response"),
        sc = rr.getAttribute("data-exists-count")|0;
    if (sc) {
        settings_delete(rr, "This response round will be removed and ".concat(plural(sc, "response"), " permanently changed to frozen comments that only administrators can see."));
    } else {
        settings_delete(rr, "This response round will be removed.");
    }
    form_highlight(this.form);
});

handle_ui.on("input.js-settings-response-name", function () {
    if (this.closest(".has-error")) {
        return;
    }
    var helt = this.parentElement.lastChild, s = this.value.trim();
    if (helt.nodeType !== 1 || helt.className !== "f-h") {
        helt = document.createElement("div");
        helt.className = "f-h";
        this.parentElement.appendChild(helt);
    }
    if (s === "") {
        helt.replaceChildren("Example display: ‘Response’; example search: ‘has:response’");
    } else if (!/^[A-Za-z][-_A-Za-z0-9]*$/.test(s)) {
        helt.replaceChildren(render_feedback_list([{status: 2, message: "<0>Round names must start with a letter and can contain only letters, numbers, and dashes"}]));
    } else if (/^(?:none|any|all|default|undefined|unnamed|.*response|response.*|draft.*|pri(?:mary)|sec(?:ondary)|opt(?:ional)|pc(?:review)|ext(?:ernal)|meta(?:review))$/i.test(s)) {
        helt.replaceChildren(render_feedback_list([{status: 2, message: "<0>Round name ‘".concat(s, "’ is reserved")}]));
    } else {
        helt.replaceChildren("Example display: ‘", s, " Response’; example search: ‘has:", s, "response’");
    }
});

handle_ui.on("js-settings-decision-add", function () {
    var form = this.form, ctr = 1;
    while (form.elements["decision/" + ctr + "/id"])
        ++ctr;
    $("#settings-decision-type-notes").removeClass("hidden");
    var h = $("#settings-new-decision-type").html().replace(/\/\$/g, "/" + ctr),
        $r = $(h).appendTo("#settings-decision-types");
    $r.find("input[type=text]").autogrow();
    $(form.elements["decision/" + ctr + "/category"]).trigger("change");
    form.elements["decision/" + ctr + "/name"].focus();
    form_highlight(form);
});

handle_ui.on("js-settings-decision-delete", function () {
    var dec = this.closest(".settings-decision"),
        ne = this.form.elements[dec.id + "/name"],
        sc = ne.getAttribute("data-exists-count")|0;
    if (settings_delete(dec, "This decision will be removed"
            + (sc ? ' and <a href="'.concat(hoturl_html("search", {q: "dec:\"" + ne.defaultValue + "\""}), '" target="_blank" rel="noopener">', plural(sc, "submission"), '</a> set to undecided.') : '.'))) {
        addClass(this.closest("div"), "hidden");
    }
    form_highlight(this.form);
});

handle_ui.on("js-settings-decision-new-name", function () {
    var d = this.closest(".settings-decision"), w, e;
    if (/accept/i.test(this.value)) {
        w = "accept";
    } else if (/reject/i.test(this.value)) {
        w = "reject";
    } else if (/revis/i.test(this.value)) {
        w = "accept";
    } else {
        return;
    }
    e = this.form.elements[d.id + "/category"];
    if ((w === "accept" ? /reject/ : /accept/).test(w.value)) {
        e.value = w;
    }
});

handle_ui.on("change.js-settings-decision-category", function () {
    var k = "dec-maybe";
    if (/accept/.test(this.value)) {
        k = "dec-yes";
    } else if (/reject/.test(this.value)) {
        k = "dec-no";
    }
    removeClass(this, "dec-maybe");
    removeClass(this, "dec-yes");
    removeClass(this, "dec-no");
    addClass(this, k);
    if (this.value === "deskreject") {
        $(".if-settings-decision-desk-reject").removeClass("hidden");
    }
});

$(function () { $(".js-settings-decision-category").trigger("change"); });


// JSON contenteditable support

(function () {

// Test if `node` is a BR element
function is_br(node) {
    return node.nodeType === 1 && node.tagName === "BR";
}

// Test if `node` is a text node or inline element
function is_text_or_inline(node) {
    return node.nodeType !== 1
        || node.tagName === "SPAN"
        || (node.tagName !== "BR" && node.childWidth === 0 && node.childHeight === 0);
}

// Test if `ch` is a character code for JSON whitespace **or NBSP**
function isspace(ch) {
    return ch === 9 || ch === 10 || ch === 13 || ch === 32 || ch === 160;
}

// Test if `ch` is a character code for a JSON delimiter ([\s",:\[\]\{\}])
function isdelim(ch) {
    return isspace(ch) || ch === 34 || ch === 44 || ch === 45
        || ch === 58 || ch === 91 || ch === 93 || ch == 123 || ch === 125;
}

// Test if `ch` is alphanumeric ([0-9A-Za-z])
function isalnum(ch) {
    return (ch >= 48 && ch < 58)
        || ((ch | 32) >= 97 && (ch | 32) < 123);
}

// Test if `ch` could begin a JSON value
function isjsonvaluestart(ch) {
    return ch === 34 || ch === 45 || (ch >= 48 && ch < 58)
        || ch === 91 || ch === 102 || ch === 110 || ch === 116 || ch === 123;
}

// Return undo-canonical inputType.
function canonical_input_type(it) {
    if (["insertText", "insertReplacementText", "insertLineBreak",
         "insertParagraph", "insertTranspose", "insertCompositionText",
         "insertFromComposition",
         "deleteWordBackward", "deleteWordForward", "deleteContent",
         "deleteContentBackward", "deleteContentForward"].indexOf(it) >= 0) {
        return "typing";
    } else if (it.startsWith("delete") && it.indexOf("Line") >= 0) {
        return "deleteLine";
    } else {
        return it;
    }
}

// Return an object `wsel` representing the current Selection, specialized for
// element `el`.
// * Move selection endpoints within `el` down into Text nodes if possible.
// * `nsel.transfer_text(dst, src, src_min_offset, delta)` shifts pending
//   selection endpoints from `src` to `dst`, depending on offset. Useful when
//   splitting or combining Text nodes.
// * `nsel.trim_newline(el)` trims trailing newlines from `el`, changing the
//   pending selection as appropriate.
// * `nsel.refresh()` installs the pending selection.
function window_selection_inside(el) {
    var sel = window.getSelection(),
        selm = [el.contains(sel.anchorNode), el.contains(sel.focusNode)],
        selmx = selm[0] || selm[1],
        selx = [sel.anchorNode, sel.anchorOffset, sel.focusNode, sel.focusOffset];
    function reset(i, el, offset) {
        selx[i] = el;
        selx[i + 1] = offset;
    }
    function normalize_edge(i) {
        if (!selm[i >> 1]) {
            return false;
        }
        var changed = false;
        while (selx[i] && selx[i].nodeType === 1 && selx[i].tagName !== "BR") {
            var end = selx[i + 1] === selx[i].childNodes.length,
                ch = selx[i].childNodes[selx[i + 1] - (end ? 1 : 0)];
            if (ch && ch.nodeType === 1 && ch.tagName === "BR") {
                break;
            } else if (end) {
                reset(i, ch, ch.nodeType === 3 ? ch.length : ch.childNodes.length);
            } else  {
                reset(i, ch, 0);
            }
            changed = true;
        }
        return changed;
    }
    function refresh() {
        selmx && sel.setBaseAndExtent(selx[0], selx[1], selx[2], selx[3]);
    }
    if (normalize_edge(0) || normalize_edge(2)) {
        refresh();
    }
    function transfer_text(dst, src, src_min_offset, offset_delta) {
        for (var i = selmx ? 0 : 4; i !== 4; i += 2) {
            if (selx[i] === src && selx[i + 1] >= src_min_offset) {
                selx[i] = dst;
                selx[i + 1] += offset_delta;
            }
        }
    }
    function render_sel(el, offset) {
        if (!el) {
            return "";
        } else if (el.nodeType === 3) {
            return el.data.substring(0, offset).concat("⭐️", el.data.substring(offset));
        } else {
            var t = ["<", el.tagName.toLowerCase(), ">"], i, n;
            for (i = 0; i !== el.childNodes.length; ++i) {
                i === offset && t.push("⭐️");
                n = el.childNodes[i];
                if (n.nodeType === 3) {
                    t.push(n.data);
                } else if (n.nodeType === 1) {
                    t.push(n.outerHTML);
                }
            }
            t.push("</", t[1], ">");
            return t.join("");
        }
    }
    return {
        modified: selmx,
        transfer_text: transfer_text,
        trim_newline: function (el) {
            var i = el.length - 1;
            while (i >= 0 && el.data[i] === "\n") {
                el.deleteData(i, 1);
                transfer_text(el, el, i + 1, -1);
                --i;
            }
        },
        reset_modified: function (el, offset) {
            selm[0] && reset(0, el, offset);
            selm[1] && reset(2, el, offset);
        },
        refresh: refresh,
        log_anchor: function () {
            console.log(JSON.stringify(render_sel(selx[0], selx[1])));
        }
    };
}

function make_content_editable(mainel) {
    var texts = [""],
        posd = [], posb = 0,
        repl = null,
        reflectors = [];

    // Normalize the contents of `mainel` to a sensible format for editing.
    // * Text only.
    // * Every line in a separate <div>.
    // * No trailing newlines within these <div>s.
    // * Blank <div> lines contain <br>.
    function normalizer(firstel, lastel) {
        var ch, next, fix1, fixfresh, nsel = window_selection_inside(mainel);

        function append_line() {
            var line = document.createElement("div");
            mainel.insertBefore(line, fix1.nextSibling);
            fix1 = line;
            fixfresh = true;
        }

        function fix_div(ch) {
            var next, nl;
            while (ch) {
                next = ch.nextSibling;
                if (ch.nodeType !== 1 && ch.nodeType !== 3) {
                    ch.remove();
                } else if (ch.nodeType === 3 && (nl = ch.data.indexOf("\n")) !== -1) {
                    if (nl !== ch.length - 1) {
                        next = ch.splitText(nl + 1);
                        nsel.transfer_text(next, ch, nl + 1, -nl - 1);
                    }
                    nsel.trim_newline(ch);
                    if (fix1 !== ch.parentElement) {
                        fix1.appendChild(ch);
                    }
                    append_line();
                } else if (is_text_or_inline(ch)) {
                    if (fix1 !== ch.parentElement) {
                        fix1.appendChild(ch);
                        fixfresh = false;
                    }
                } else if (is_br(ch)) {
                    if (fix1.firstChild && fix1.firstChild !== ch) {
                        ch.remove();
                    } else if (fix1 !== ch.parentElement) {
                        fix1.appendChild(ch);
                    }
                    append_line();
                } else {
                    fixfresh || append_line();
                    ch.remove();
                    fix_div(ch.firstChild);
                    fixfresh || append_line();
                }
                ch = next;
            }
        }

        ch = firstel = firstel || mainel.firstChild;
        while (ch && ch !== lastel) {
            if (ch.nodeType !== 1
                || ch.tagName !== "DIV"
                || ch.hasAttribute("style")) {
                var line = document.createElement("div"),
                    line1 = ch === firstel, child1 = true;
                mainel.insertBefore(line, ch);
                while (ch && (child1 || is_text_or_inline(ch))) {
                    line.appendChild(ch);
                    ch = line.nextSibling;
                    child1 = false;
                }
                if (ch && is_br(ch)) {
                    line.firstChild ? ch.remove() : line.appendChild(ch);
                }
                line1 && (firstel = line);
                ch = line;
            }
            next = ch.nextSibling;
            fix1 = ch;
            fixfresh = false;
            fix_div(ch.firstChild);
            ch.firstChild || ch.remove();
            fixfresh && fix1.remove();
            ch = next;
        }

        nsel.refresh();
        return firstel;
    }

    function length() {
        return texts.length;
    }

    function lineno(node, offset) {
        if (node === mainel) {
            return offset;
        }
        while (node && node.parentElement !== mainel) {
            node = node.parentElement;
        }
        return node ? Array.prototype.indexOf.call(mainel.childNodes, node) : -1;
    }

    function slice(...rest) {
        return texts.slice(...rest);
    }

    function mark_replace(lineno, charsdel, s) {
        if (reflectors.length) {
            let p = lp2p(lineno, 0);
            if (repl !== null && repl.expected !== p) {
                reflect();
            }
            if (repl === null) {
                repl = {first: p, last: p + charsdel, expected: p + s.length, text: s};
            } else {
                repl.last += charsdel;
                repl.expected += s.length;
                repl.text += s;
            }
        }
    }

    function splice(start, ...rest) {
        if (posd.length > start) {
            posd.splice(start);
        }
        for (let i = 1; i < rest.length; ++i) {
            rest[i] = rest[i] || "";
        }
        let deleted = texts.splice(start, ...rest);
        if (reflectors.length !== 0) {
            let n = 0;
            for (let i = 0; i !== deleted.length; ++i) {
                n += deleted[i].length;
            }
            mark_replace(start, n, rest.length > 1 ? rest.slice(1).join("") : "");
        }
        return deleted;
    }

    function line(lineno) {
        return texts[lineno];
    }

    function set_line(lineno, text) {
        if (lineno > texts.length) {
            throw new Error(`bad ${lineno} on texts[${texts.length}]`);
        }
        let oldtext = texts[lineno] || "";
        if (lineno < posd.length) {
            update_posd(lineno, text.length - oldtext.length);
        }
        texts[lineno] = text;
        mark_replace(lineno, oldtext.length, text);
    }

    function reflect() {
        if (repl !== null) {
            for (let el of reflectors) {
                el.setRangeText(repl.text, repl.first, repl.last);
            }
            repl = null;
        }
    }

    function join() {
        return texts.join("");
    }

    function boff2lp(base, off) {
        var lp = 0, parent;
        if (base.nodeType === 3) {
            lp += off;
            base = base.parentElement;
            parent = base.parentElement;
        } else {
            parent = base;
            base = base.childNodes[off];
        }
        while (parent !== mainel) {
            if (base) {
                base = base.previousSibling;
            } else {
                base = parent.lastChild;
            }
            if (base) {
                lp += base.textContent.length;
            } else {
                base = parent;
                parent = parent.parentElement;
            }
        }
        return [lineno(base), lp];
    }

    function lp2boff(ln, lp) {
        var le = mainel.childNodes[ln], e, down = true;
        if (!le) {
            le = mainel.lastChild;
            lp = -1;
        }
        if (lp < 0) {
            lp = le.textContent.length + 1 + lp;
        }
        e = le.firstChild;
        while (e) {
            if (e.nodeType === 1) {
                if (down && e.firstChild) {
                    e = e.firstChild;
                } else if (down) {
                    down = false;
                } else if (e.nextSibling) {
                    e = e.nextSibling;
                    down = true;
                } else if (e.parentElement !== le) {
                    e = e.parentElement;
                } else {
                    e = null;
                }
            } else {
                if (lp <= e.length) {
                    return [e, lp];
                } else {
                    lp -= e.length;
                    if (e.nextSibling) {
                        e = e.nextSibling;
                    } else {
                        e = e.parentElement;
                        down = false;
                    }
                }
            }
        }
        return [le, le.childNodes.length];
    }

    function make_posd() {
        // Preconditions:
        // * posd[i] is valid for all i < posd.length
        // * posd.length < texts.length
        // Postconditions:
        // * posd[i] is valid for all i < texts.length
        // * posd.length === texts.length
        posb = 1 << Math.ceil(Math.log2(Math.max(texts.length, 1)));
        // fill in tail of `posd` with zeros
        let ln = posd.length;
        posd.length = texts.length;
        posd.fill(0, ln);
        // update tail with information about `texts.slice(0, ln)`
        // (`log2(ln)` updates)
        if (ln !== 0) {
            let x = ln - 1, n = texts[x].length;
            for (let a = 1; (x | a) < texts.length; x &= ~a, a <<= 1) {
                (x | a) >= ln && (posd[x | a] = n);
                let xx = x & ~a;
                x !== xx && (n += posd[x]);
                x = xx;
            }
        }
        // update tail with information about `texts.slice(ln)`
        while (ln !== texts.length) {
            update_posd(ln, texts[ln].length);
            ++ln;
        }
    }

    function update_posd(ln, delta) {
        let max = texts.length;
        for (let a = 1, y = ln; y + 1 < max; y |= a, a <<= 1) {
            (y & a) === 0 && (posd[y + 1] += delta);
        }
    }

    function lp2p(ln, lp) {
        posd.length > ln || make_posd();
        if (ln >= texts.length) {
            ln = texts.length - 1;
            lp = ln >= 0 ? texts[ln].length : 0;
        }
        let lsp = 0;
        for (let a = 1, y = ln; y !== 0; a <<= 1) {
            if ((y & a) !== 0) {
                lsp += posd[y];
                y ^= a;
            }
        }
        return lsp + lp;
    }

    function p2lp(p) {
        posd.length === texts.length || make_posd();
        let max = texts.length, ln = 0;
        for (let a = posb; a !== 0; a >>= 1) {
            if ((ln | a) < max && posd[ln | a] <= p) {
                ln |= a;
                p -= posd[ln];
            }
        }
        return [ln, p];
    }

// Testing code:
//    function check_posd() {
//        posd.length === texts.length || make_posd();
//        for (let i = 1; i !== texts.length; ++i) {
//            let k = 1;
//            while ((k << 1) < texts.length && (i & k) === 0) {
//                k <<= 1;
//            }
//            //console.log(`${k} vs ${i} @${texts.length}`);
//            let p = 0;
//            for (let x = 0; x !== k; ++x) {
//                p += texts[i - k + x].length;
//            }
//            if (p !== posd[i]) {
//                throw new Error(`expected posd[${i}] === ${p}, got ${posd[i]}`);
//            }
//        }
//    }
//
//    function lp2p_base(ln, lp) {
//        let p = 0;
//        while (ln > 0) {
//            --ln;
//            p += texts[ln].length;
//        }
//        return p + lp;
//    }
//
//    function test_posd() {
//        posd.length === texts.length || make_posd();
//        for (let i = 0; i !== 100; ++i) {
//            let ln = Math.floor(Math.random() * texts.length),
//                lp = Math.floor(Math.random() * (texts[ln].length + 1)),
//                p = lp2p(ln, lp),
//                [ln1, lp1] = p2lp(p);
//            if (p !== lp2p_base(ln, lp))
//                throw new Error(`[${ln},${lp}] -> ${p} !<- ${lp2p_base(ln, lp)}`);
//            if ((ln1 !== ln || lp1 !== lp)
//                && (ln1 !== ln + 1 || lp1 !== 0 || lp !== texts[ln].length))
//                throw new Error(`[${ln},${lp}] -> ${p} !-> [${ln1},${lp1}]`);
//        }
//    }
//
//    function check_reflectors() {
//        for (let el of reflectors) {
//            if (el.value !== texts.join(""))
//                throw new Error(`fuck ${JSON.stringify([el.value, texts.join("")])}`);
//        }
//    }

    function add_reflector(el) {
        reflectors.push(el);
        el.value = texts.join("");
    }

    return {
        length: length,
        lineno: lineno,
        line: line,
        set_line: set_line,
        reflect: reflect,
        normalize: normalizer,
        slice: slice,
        splice: splice,
        join: join,
        boff2lp: boff2lp,
        lp2boff: lp2boff,
        lp2p: lp2p,
        p2lp: p2lp,
        add_reflector: add_reflector
    };
}

let json_string_re = /"(?:[^\\"\x00-\x1F]|\\[/\\bfnrt"]|\\u[0-9a-fA-F]{4})+"/y;


// Mark the range [p0, p1) as erroneous with `flags` in `errors`,
// which is a list of points:
// [0, flag0, p1, flag1, p2, flag2, ...]
function jsonhl_add_error(errors, p0, p1, flags) {
    var i, j, x, y;
    for (j = errors.length - 2; errors[j] > p1; j -= 2) {
    }
    if (errors[j] < p1) {
        j += 2;
        errors.splice(j, 0, p1, errors[j - 1]);
    }
    for (i = j; errors[i] > p0; i -= 2) {
    }
    if (errors[i] < p0) {
        i += 2;
        j += 2;
        errors.splice(i, 0, p0, errors[i - 1]);
    }
    for (x = i; x !== j; x += 2) {
        errors[x + 1] |= flags;
    }
    for (x = Math.max(i - 2, 0); x < j; x += 2) {
        y = x;
        while (y + 2 !== errors.length && errors[x + 1] === errors[y + 3]) {
            y += 2;
        }
        if (y !== x) {
            errors.splice(x + 1, y - x);
            j -= y - x;
        }
    }
}

// Move all text nodes from inside `src` to be children of `dst`, inserted before `reference`.
function jsonhl_move_text(dst, src, reference) {
    var ch, next;
    for (ch = src.firstChild; ch; ) {
        if (ch.nodeType === 1) {
            jsonhl_move_text(dst, ch, reference);
            ch = ch.nextSibling;
        } else if (ch.nodeType === 3) {
            next = ch.nextSibling;
            dst.insertBefore(ch, reference);
            ch = next;
        }
    }
}

// Transform the contents of `lineel` according to `errors`, and fix up selection.
function jsonhl_install(lineel, errors, nsel) {
    if (lineel.firstChild
        && lineel.firstChild === lineel.lastChild
        && lineel.firstChild.nodeType === 1
        && lineel.firstChild.tagName === "BR") {
        lineel.firstChild.removeAttribute("class");
        lineel.firstChild.removeAttribute("style");
        return;
    }

    var ei = 0, ch = lineel.firstChild;

    function ensure_text_length(len) {
        var sib;
        if (ch.nodeType !== 3) {
            jsonhl_move_text(lineel, ch, ch.nextSibling);
            sib = ch.nextSibling;
            ch.remove();
            ch = sib;
        }
        while (ch && (sib = ch.nextSibling) && ch.length < len) {
            if (sib.nodeType !== 3) {
                jsonhl_move_text(lineel, sib, sib.nextSibling);
            } else {
                nsel.transfer_text(ch, sib, 0, ch.length);
                ch.appendData(sib.data);
            }
            sib.remove();
        }
        if (ch && ch.length > len) {
            sib = ch.splitText(len);
            nsel.transfer_text(sib, ch, len, -len);
        }
    }

    // split & combine text nodes, add error spans
    while (ch) {
        var tp = errors[ei],
            ep = errors[ei + 2] || Infinity,
            wc = "";
        if (errors[ei + 1]) {
            wc = errors[ei + 1] & 2 ? "is-error-part" : "is-warning-part";
        }
        if (ch.nodeType === 1
            && ch.tagName === "SPAN"
            && ch.childNodes.length === 1
            && ch.firstChild.nodeType === 3
            && ch.firstChild.length === ep - tp
            && !ch.hasAttribute("style")
            && wc !== "") {
            ch.className = wc;
        } else {
            ensure_text_length(ep - tp);
            if (wc !== "") {
                var es = document.createElement("span");
                es.className = wc;
                lineel.insertBefore(es, ch);
                es.appendChild(ch);
                ch = es;
            }
        }
        ei += 2;
        ch = ch ? ch.nextSibling : null;
    }

    if (lineel.firstChild === null) {
        lineel.appendChild(document.createElement("br"));
        nsel.reset_modified(lineel, 0);
    }
}

function log_line_summary(lineno, lineel) {
    var x = [], i = 0, ch,
        sel = window.getSelection(), sub = lineel.contains(sel.anchorNode);
    for (ch = lineel.firstChild; ch; ch = ch.nextSibling, ++i) {
        if (sel.anchorNode === lineel && sel.anchorOffset === i) {
            x.push("*");
        }
        if (ch.nodeType === 3 && sel.anchorNode === ch) {
            x.push("T".concat(ch.length, "@", sel.anchorOffset));
        } else if (ch.nodeType === 3) {
            x.push("T".concat(ch.length));
        } else if (sub && ch.contains(sel.anchorNode)) {
            x.push(ch.tagName.concat(ch.childNodes.length, "@", sel.anchorOffset));
        } else {
            x.push(ch.tagName.concat(ch.childNodes.length));
        }
    }
    if (sel.anchorNode === lineel && sel.anchorOffset === i) {
        x.push("*");
    }
    console.log("".concat(lineno, ". ", x.join(" ")));
}

function make_utf16tf(text) {
    var ipos = 0, opos = 0, delta = 0, map = [0, 0],
        re = /[\u0080-\uDBFF\uE000-\uFFFF]/g, m, ch;
    while ((m = re.exec(text))) {
        ipos += re.lastIndex - 1 - opos;
        opos = re.lastIndex;
        ch = m[0].charCodeAt(0)
        if (ch < 0x800) { // two bytes = one character
            --delta;
            map.push(ipos + 2, delta);
            ipos += 2;
        } else if (ch < 0xD800 || ch >= 0xE000) { // three bytes = one character
            delta -= 2;
            map.push(ipos + 3, delta);
            ipos += 3;
        } else { // four bytes = two characters
            delta -= 2;
            map.push(ipos + 4, delta);
            ipos += 4;
            ++opos;
        }
    }
    return make_utf16tf_finish(map);
}

function make_utf16tf_finish(map) {
    return function (pos) {
        var ei = 0, n = map.length;
        while (ei !== n && map[ei] < pos) {
            ei += 2;
        }
        return pos + map[ei - 1];
    };
}

function jsonhl_add_error_ranges(errors, ranges, utf16tf) {
    if ((ranges || "") === "") {
        return;
    }
    var regex = /(\d+)-(\d+):(\d+)/g, m;
    while ((m = regex.exec(ranges)) !== null) {
        var first = utf16tf ? utf16tf(+m[1]) : +m[1],
            last = utf16tf ? utf16tf(+m[2]) : +m[2],
            status = +m[3];
        jsonhl_add_error(errors, first, last, status);
    }
}

function jsonhl_highlight_tips(str, utf16tf) {
    var tips, i;
    if (str === null || !str.startsWith("[")) {
        return null;
    }
    try {
        tips = JSON.parse(str);
    } catch (err) {
        return null;
    }
    if (!tips || !Array.isArray(tips)) {
        return null;
    }
    if (utf16tf) {
        for (i = 0; i !== tips.length; ++i) {
            tips[i].pos1 = utf16tf(tips[i].pos1);
            tips[i].pos2 = utf16tf(tips[i].pos2);
        }
    }
    tips.sort(function (a, b) {
        if (a.pos1 !== b.pos1) {
            return a.pos1 < b.pos1 ? -1 : 1;
        } else if (a.pos2 !== b.pos2) {
            return a.pos2 < b.pos2 ? -1 : 1;
        } else {
            return 0;
        }
    });
    return tips;
}

function jsonhl_transfer_ranges(mainel) {
    var utf16tf = null, el, x;
    if (mainel.hasAttribute("data-highlight-utf8-pos")) {
        x = [];
        for (el = mainel.firstChild; el; el = el.nextSibling) {
            x.push(el.textContent, "\n");
        }
        utf16tf = make_utf16tf(x.join(""));
        mainel.removeAttribute("data-highlight-utf8-pos");
    }

    var errors = [0, 0], str;
    if ((str = mainel.getAttribute("data-highlight-ranges")) !== null
        && str !== "") {
        jsonhl_add_error_ranges(errors, str, utf16tf);
        mainel.removeAttribute("data-highlight-ranges");
    }

    var tips = jsonhl_highlight_tips(mainel.getAttribute("data-highlight-tips"), utf16tf) || [];
    tips && mainel.removeAttribute("data-highlight-tips");

    function add(ei, tp, len) {
        var p0 = Math.max(0, errors[ei] - tp),
            p1 = Math.min(len, (errors[ei + 2] || Infinity) - tp);
        p0 < p1 && rs.push("".concat(p0, "-", p1, ":", errors[ei + 1]));
    }

    var ei = 0, ri = 0, tp = 0, len;
    el = mainel.firstChild;
    while (el && ei !== errors.length) {
        while (ei !== errors.length && errors[ei + 2] < tp) {
            ei += 2;
        }
        len = el.textContent.length + 1;

        var rs = [];
        while (ei !== errors.length && errors[ei] < tp + len) {
            errors[ei + 1] && add(ei, tp, len);
            ei += 2;
        }
        errors[ei + 1] && add(ei, tp, len);
        if (rs.length) {
            el.setAttribute("data-highlight-ranges", rs.join(" "));
        } else {
            el.removeAttribute("data-highlight-ranges");
        }

        rs = [];
        while (ri !== tips.length && tips[ri].pos1 < tp + len) {
            if (tips[ri].pos2 > tp) {
                x = $.extend({}, tips[ri]);
                x.pos1 = Math.max(0, x.pos1 - tp);
                x.pos2 = Math.min(len, x.pos2 - tp);
                rs.push(x);
            }
            ++ri;
        }
        if (rs.length) {
            el.setAttribute("data-highlight-tips", JSON.stringify(rs));
        } else {
            el.removeAttribute("data-highlight-tips");
        }

        tp += len;
        el = el.nextSibling;
    }
}


/* 0/a -- end
   1/b -- initial         -- V
   2/c -- after `[`       -- V or ]
   3/d -- after `[V,`     -- V
   4/e -- after `{"":`    -- V
   5/f -- after `{"":V,`  -- ""
   6/g -- after `{`       -- "" or }
   7/h -- after `[V`      -- , or ] (=== 3 + 4)
   8/i -- after `{""`     -- :
   9/j -- after `{"":V`   -- , or } (=== 5 + 4) */

var jsonhl_nextcst = [0, 0, 7, 7, 9, 8, 8, 7, 9, 9];

function jsonhl_line(lineel, st, nsel) {
    var t = lineel.textContent, p = 0, n = t.length, ch,
        cst = st.length ? st.charCodeAt(st.length - 1) - 97 : 1,
        errors = [0, 0];
    st = st.length ? st.substring(0, st.length - 1) : "";

    function push_state(stx) {
        if (st.length !== 0 || stx !== 0) {
            st += String.fromCharCode(stx + 97);
        }
    }

    function pop_state_until(s0, s1) {
        var i = st.length - 1, ch, ok = cst === s0 || cst === s1;
        if (!ok) {
            --i;
            while (i >= 0
                   && i >= st.length - 3
                   && (ch = st.charCodeAt(i) - 97) !== s0
                   && ch !== s1) {
                --i;
            }
            if (i < 0 || i < st.length - 3) {
                i = 0;
            }
        }
        if (i >= 0 && st !== "") {
            cst = st.charCodeAt(i) - 97;
            st = st.substring(0, i);
        } else {
            cst = 9;
            st = "";
        }
        return ok;
    }

    function check_identifier(id) {
        var p0 = p;
        for (++p; p !== n && t.charCodeAt(p) === id.charCodeAt(p - p0); ++p) {
        }
        if (p - p0 < id.length
            || (p !== n && !isdelim(t.charCodeAt(p)))
            || cst < 1
            || cst > 4) {
            jsonhl_add_error(errors, p0, p, 2);
        } else {
            cst = jsonhl_nextcst[cst];
        }
    }

    function check_number() {
        var p0 = p, c, c0, ok;
        c = t.charCodeAt(p);
        ok = cst >= 1 && cst <= 4 && c !== 46;
        if (c == 45) { // `-`
            ++p;
            c = t.charCodeAt(p);
        }
        if (c >= 48 && c <= 57) { // `0`-`9`
            c0 = c;
            for (++p; (c = t.charCodeAt(p)) >= 48 && c <= 57; ++p) {
                if (c0 === 48)
                    ok = false;
            }
        } else {
            ok = false;
        }
        if (c === 46) { // `.`
            ++p;
            c = t.charCodeAt(p);
            if (c >= 48 && c <= 57) {
                for (++p; (c = t.charCodeAt(p)) >= 48 && c <= 57; ++p) {
                }
            } else {
                ok = false;
            }
        }
        if (c === 69 || c === 101) { // `E` `e`
            ++p;
            c = t.charCodeAt(p);
            if (c === 43 || c === 45) { // `+` `-`
                ++p;
                c = t.charCodeAt(p);
            }
            if (c >= 48 && c <= 57) {
                for (++p; (c = t.charCodeAt(p)) >= 48 && c <= 57; ++p) {
                }
            } else {
                ok = false;
            }
        }
        if (!ok) {
            jsonhl_add_error(errors, p0, p, 2);
        }
        cst = jsonhl_nextcst[cst];
    }

    function check_string() {
        var p0 = p, i, c, ok = false;
        for (++p; p !== n; ++p) {
            c = t.charCodeAt(p);
            if (c === 34) { // `"`
                ++p;
                ok = true;
                break;
            } else if (c === 92 && p + 1 !== n) { // `\`
                ++p;
                c = t.charCodeAt(p);
                if (c === 117) { // `u`
                    for (i = 0; i !== 4 && p + 1 !== n; ++i) {
                        ++p;
                        c = t.charCodeAt(p) | 0x20;
                        if (c < 48
                            || (c > 57 && c < 97)
                            || c > 102) {
                            jsonhl_add_error(errors, p - i - 2, p, 2);
                            --p;
                            break;
                        }
                    }
                } else if (c !== 34 && c !== 47 && c !== 92       // `"` `/` `\`
                           && c !== 98 && c !== 102 && c !== 110  // `b` `f` `n`
                           && c !== 114 && c !== 116) {           // `r` `t`
                    jsonhl_add_error(errors, p - 1, p + 1, 2);
                }
            } else if (c < 32 || c === 0x2028 || c === 0x2029) { // ctl/LT
                jsonhl_add_error(errors, p, p + 1, 2);
            }
        }
        if (!ok || cst < 1 || cst > 6) {
            jsonhl_add_error(errors, p0, p, 2);
        }
        cst = jsonhl_nextcst[cst];
    }

    function check_invalid() {
        var p0 = p;
        for (++p; p !== n && !isdelim(t.charCodeAt(p)); ++p) {
        }
        jsonhl_add_error(errors, p0, p, 2);
    }


    while (true) {
        while (p !== n && (ch = t.charCodeAt(p), isspace(ch))) {
            ++p;
        }
        if (p === n) {
            break;
        } else if (ch === 34) { // `"`
            check_string();
        } else if (ch >= 45 && ch <= 57 && ch !== 47) { // `-`, `.`, `0`-`9`
            check_number();
        } else if (ch === 44) { // `,`
            if (cst === 7 || cst === 9) {
                cst -= 4;
            } else {
                jsonhl_add_error(errors, p, p + 1, 2);
            }
            ++p;
        } else if (ch === 58) { // `:`
            if (cst !== 8) {
                jsonhl_add_error(errors, p, p + 1, 2);
            }
            if (cst === 4 || cst === 5 || cst === 6 || cst === 8 || cst === 9) {
                cst = 4;
            }
            ++p;
        } else if (ch === 91) { // `[`
            if (cst >= 1 && cst <= 4) {
                push_state(jsonhl_nextcst[cst]);
            } else {
                jsonhl_add_error(errors, p, p + 1, 2);
                push_state(cst);
            }
            cst = 2;
            ++p;
        } else if (ch === 93) { // `]`
            pop_state_until(2, 7) || jsonhl_add_error(errors, p, p + 1, 2);
            ++p;
        } else if (ch === 123) { // `{`
            if (cst >= 1 && cst <= 4) {
                push_state(jsonhl_nextcst[cst]);
            } else {
                jsonhl_add_error(errors, p, p + 1, 2);
                push_state(cst);
            }
            cst = 6;
            ++p;
        } else if (ch === 125) { // `}`
            pop_state_until(6, 9) || jsonhl_add_error(errors, p, p + 1, 2);
            ++p;
        } else if (ch === 102) { // `f`
            check_identifier("false");
        } else if (ch === 110) { // `n`
            check_identifier("null");
        } else if (ch === 116) { // `t`
            check_identifier("true");
        } else { // invalid
            check_invalid();
        }
    }


    jsonhl_add_error_ranges(errors, lineel.getAttribute("data-highlight-ranges"));
    jsonhl_install(lineel, errors, nsel);

    if (cst === 0) {
        return "a";
    } else {
        return st + String.fromCharCode(cst + 97);
    }
}

function json_skip_potential_string(s, pos) {
    let len = s.length, nl, dq;
    if ((nl = s.indexOf("\n", pos)) < 0) {
        nl = len;
    }
    dq = pos;
    while (true) {
        if ((dq = s.indexOf("\"", dq + 1)) < 0) {
            dq = len;
        }
        if (dq >= nl) {
            break;
        }
        let bs = dq - 1;
        while (bs > pos && s.charCodeAt(bs) === 92) { // `\`
            --bs;
        }
        if ((dq - bs) % 2 === 1) {
            break;
        }
    }
    return dq + 1 < len ? dq + 1 : len;
}

function json_skip(s, pos) {
    let len = s.length, depth = 0, ch;
    while (true) {
        while (pos !== len && (ch = s.charCodeAt(pos), isspace(ch))) {
            ++pos;
        }
        if (pos === len) {
            return pos;
        } else if (ch === 34) { // `"`
            pos = json_skip_potential_string(s, pos);
        } else if (ch === 123 || ch === 91) { // `{`, `[`
            ++depth;
            ++pos;
        } else if (isalnum(ch) || ch === 45) { // `-`
            while (pos !== len
                   && (isalnum(ch) || ch === 45 || ch === 43 || ch === 46)) { // `-+.`
                ++pos;
                if (pos !== len) {
                    ch = s.charCodeAt(pos);
                }
            }
        } else if (depth !== 0 && (ch === 125 || ch === 93)) { // `}`, `]`
            --depth;
            ++pos;
        } else {
            ++pos;
        }
        if (depth === 0) {
            return pos;
        }
    }
}

function json_decode_string(s, pos1, pos2) {
    try {
        return JSON.parse(s.substring(pos1, pos2));
    } catch (err) {
        return null;
    }
}

function JsonParserPosition(key, kpos1, kpos2, vpos1, vpos2) {
    this.key = key;
    this.kpos1 = kpos1;
    this.kpos2 = kpos2;
    this.vpos1 = vpos1;
    this.vpos2 = vpos2;
}

function* json_member_positions(s, pos) {
    let len = s.length;
    while (pos !== len && isspace(s.charCodeAt(pos))) {
        ++pos;
    }
    if (pos === len) {
        return;
    }
    let ch = s.charCodeAt(pos);
    if (ch === 123) { // `{`
        ++pos;
        while (true) {
            while (pos !== len
                   && (ch = s.charCodeAt(pos), isspace(ch) || ch === 44)) { // `,`
                ++pos;
            }
            if (pos === len || ch === 125) { // `}`
                break;
            } else if (ch !== 34) { // `"`
                pos = json_skip(s, pos);
                continue;
            }
            const kpos1 = pos, kpos2 = pos = json_skip_potential_string(s, kpos1);
            while (pos !== len
                   && (ch = s.charCodeAt(pos), isspace(ch) || ch === 58)) { // `:`
                ++pos;
            }
            if (pos !== len && isjsonvaluestart(ch)) {
                const vpos1 = pos;
                pos = json_skip(s, pos)
                yield new JsonParserPosition(json_decode_string(s, kpos1, kpos2), kpos1, kpos2, vpos1, pos);
            } else {
                pos = json_skip(s, pos);
            }
        }
    } else if (ch === 91) { // `[`
        ++pos;
        let key = 0;
        while (true) {
            while (pos !== len
                   && (ch = s.charCodeAt(pos), isspace(ch) || ch === 44)) { // `,`
                ++pos;
            }
            if (pos === len || ch === 93) { // `]`
                break;
            }
            if (isjsonvaluestart(ch)) {
                const vpos1 = pos;
                pos = json_skip(s, pos);
                yield new JsonParserPosition(key, null, null, vpos1, pos);
                ++key;
            } else {
                pos = json_skip(s, pos)
            }
        }
    } else if (ch === 34) { // `"`
        const vpos1 = pos;
        pos = json_skip_potential_string(pos);
        yield new JsonParserPosition(null, null, null, vpos1, pos);
    } else if (ch >= 97 && ch < 123) { // `a`-`z`
        const vpos1 = pos;
        for (++pos; pos !== len && (ch = s.charCodeAt(pos), ch >= 97 && ch < 123); ++pos) {
        }
        yield new JsonParserPosition(null, null, null, vpos1, pos);
    } else if (ch >= 45 && ch < 58) { // `-`-`9`` {
        const vpos1 = pos;
        for (++pos; pos !== len && (ch = s.charCodeAt(pos), isalnum(ch) || ch === 45 || ch === 43 || ch === 46); ++pos) {
        }
        yield new JsonParserPosition(null, null, null, vpos1, pos);
    }
}

function json_path_split(path) {
    let ppos = 0, plen = (path || "").length, a = [];
    while (ppos !== plen) {
        let ch = path.charCodeAt(ppos);
        if (isspace(ch)) {
            for (++ppos; ppos !== plen && (ch = path.charCodeAt(ppos), isspace(ch)); ++ppos) {
            }
        } else if (isalnum(ch) || ch === 95) { // `_`
            const ppos1 = ppos;
            let digit = ch >= 48 && ch < 58;
            for (++ppos;
                 ppos !== plen &&
                     (ch = path.charCodeAt(ppos), isalnum(ch) || ch === 95 || ch === 36);
                 ++ppos) {
                digit = digit && ch >= 48 && ch < 58;
            }
            const comp = path.substring(ppos1, ppos);
            a.push(digit ? parseInt(comp) : comp);
        } else if (ch === 34) { // `"`
            const ppos1 = ppos;
            ppos = json_skip_potential_string(path, ppos);
            a.push(json_decode_string(path, ppos1, ppos));
        } else if (ch === 46 || ch === 91 || ch === 93 || (ch === 36 && a.length === 0)) {
            ++ppos;
        } else {
            throw new Error(`bad JSON path ${path}`);
        }
    }
    return a;
}

function json_path_position(s, path) {
    let ipos = 0, jpp = null;
    for (let key of json_path_split(path)) {
        jpp = null;
        for (let memp of json_member_positions(s, ipos)) {
            if (memp.key !== null && memp.key.toString() === key.toString()) {
                jpp = memp;
                break;
            }
        }
        if (!jpp) {
            return null;
        }
        ipos = jpp.vpos1;
    }
    if (jpp === null && ipos === 0) {
        let ilen = s.length, ch;
        while (ipos !== ilen && (ch = s.charCodeAt(ipos), isspace(ch))) {
            ++ipos;
        }
        return new JsonParserPosition(null, null, null, ipos, json_skip(s, ipos));
    } else {
        return jpp;
    }
}

function make_json_validate() {
    var mainel = this, reflectel = null,
        maince = make_content_editable(mainel),
        states = [""], lineels = [null],
        redisplay_ln0 = 0, redisplay_el1 = null,
        normalization, rehighlight_queued,
        api_timer = null, api_value = null, msgbub = null,
        commands = [], commandPos = 0,
        undo_time, redo_time;

    function rehighlight() {
        try {
            var i, saw_line1 = false, lineel, k, st, x, cmd, nsel;
            if (normalization !== 0) {
                maince.normalize();
                if (normalization < 0
                    && mainel.hasAttribute("data-highlight-ranges")) {
                    jsonhl_transfer_ranges(mainel);
                }
            }
            i = redisplay_ln0;
            lineel = mainel.childNodes[i];
            st = states[i];
            cmd = commands[commandPos - 1];
            nsel = window_selection_inside(mainel);
            while (lineel !== null
                   && (!saw_line1
                       || states[i] !== st
                       || lineels[i] !== lineel)) {
                if (msgbub && msgbub.span.parentElement === lineel) {
                    clear_msgbub();
                }
                if (lineel.nodeType !== 1 || lineel.tagName !== "DIV") {
                    lineel = maince.normalize(lineel, lineel.nextSibling);
                }
                if (normalization >= 0) {
                    lineel.removeAttribute("data-highlight-ranges");
                    lineel.removeAttribute("data-highlight-tips");
                }
                if (lineel === redisplay_el1) {
                    saw_line1 = true;
                    cmd && cmd.afterRange[1] < 0 && (cmd.afterRange[1] = i);
                }
                if (lineels[i] !== lineel && lineels[i]) {
                    // incremental line insertions and deletions
                    if ((k = maince.lineno(lineels[i])) > i) {
                        x = new Array(k - i);
                        states.splice(i, 0, ...x);
                        lineels.splice(i, 0, ...x);
                        maince.splice(i, 0, ...x);
                    } else if ((k = lineels.indexOf(lineel)) > i) {
                        states.splice(i, k - i);
                        lineels.splice(i, k - i);
                        maince.splice(i, k - i);
                    }
                }
                states[i] = st;
                lineels[i] = lineel;
                st = jsonhl_line(lineel, st, nsel);
                maince.set_line(i, lineel.textContent + "\n");
                ++i;
                lineel = lineel.nextSibling;
            }
            if (!lineel) {
                states.splice(i);
                lineels.splice(i);
                maince.splice(i);
            }
            maince.reflect();
            nsel.refresh();
            //state_redisplay.push(i, st, states[i - 1]);
            //console.log(state_redisplay);
            if (reflectel) {
                handle_reflection();
            }
            redisplay_ln0 = 0;
            redisplay_el1 = null;
            rehighlight_queued = false;
            normalization = 0;
        } catch (err) {
            console.trace(err);
        }
    }

    function queue_rehighlight() {
        if (!rehighlight_queued) {
            queueMicrotask(rehighlight);
            rehighlight_queued = true;
        }
    }

    function handle_reflection() {
        form_highlight(reflectel.form, reflectel);
        if (mainel.hasAttribute("data-reflect-highlight-api")) {
            api_timer && clearTimeout(api_timer);
            api_timer = setTimeout(handle_reflect_api, 500);
        }
    }

    function handle_reflect_api() {
        api_timer = null;
        let text = reflectel.value;
        if (text === api_value)
            return;
        let m = mainel.getAttribute("data-reflect-highlight-api").split(/\s+/);
        $.post(hoturl(m[0]), {[m[1]]: text}, function (rv) {
            var i, mi, ranges = [], tips = [], utf16tf;
            if (!rv || !rv.message_list || text !== reflectel.value)
                return;
            api_value = text;
            for (i = 0; i !== rv.message_list.length; ++i) {
                mi = rv.message_list[i];
                if (mi.pos1 != null && mi.context == null && mi.status >= 1) {
                    utf16tf = utf16tf || make_utf16tf(text);
                    mi.pos1 = utf16tf(mi.pos1);
                    mi.pos2 = utf16tf(mi.pos2);
                    ranges.push("".concat(mi.pos1, "-", mi.pos2, ":", mi.status > 1 ? 2 : 1));
                    tips.push(mi);
                }
            }
            if (ranges.length) {
                mainel.setAttribute("data-highlight-ranges", ranges.join(" "));
                mainel.setAttribute("data-highlight-tips", JSON.stringify(tips));
                normalization = -1;
                queue_rehighlight();
            }
        });
    }

    function union_range(r1, r2) {
        r1 = r1 || [Infinity, 0];
        if (r2[0] >= 0 && r1[0] > r2[0])
            r1 = [r2[0], r1[1]];
        if (r2[1] >= 0 && r1[1] < r2[1])
            r1 = [r1[0], r2[1]];
        return r1;
    }

    function event_range(evt) {
        var i, r = null, ranges = evt.getTargetRanges();
        for (i = 0; i !== ranges.length; ++i) {
            var ln0 = maince.lineno(ranges[i].startContainer, ranges[i].startOffset),
                ln1 = maince.lineno(ranges[i].endContainer, ranges[i].endOffset);
            r = union_range(r, [ln0, ln1 < 0 ? -1 : ln1 + 1]);
        }
        return r;
    }

    function prepare_undo(editRange, evt) {
        if (commandPos < commands.length) {
            commands.splice(commandPos);
        }
        var c = commands[commandPos - 1], t = canonical_input_type(evt.inputType);
        if (!(c
              && c.type === t
              && evt.timeStamp <= c.recentTime + 250
              && evt.timeStamp <= c.startTime + 3000)) {
            if (commandPos > 2000) {
                commands.splice(0, 1000);
            }
            c = {
                startTime: evt.timeStamp, recentTime: evt.timeStamp, type: t,
                beforeRange: [editRange[0], editRange[0]], beforeLines: [],
                afterRange: [editRange[0], editRange[0]], afterLines: null
            };
            commands.push(c);
            commandPos = commands.length;
        }
        if (editRange[0] < c.beforeRange[0]) {
            c.beforeLines.splice(0, 0, ...maince.slice(editRange[0], c.beforeRange[0]));
            c.beforeRange[0] = editRange[1];
        }
        if (c.afterRange[1] < editRange[1]) {
            c.beforeLines.splice(c.beforeLines.length, 0, ...maince.slice(c.afterRange[1], editRange[1]));
            c.beforeRange[1] += editRange[1] - c.afterRange[1];
        }
        if (c.afterRange[1] <= editRange[1]) {
            c.afterRange[1] = -1; // recompute end of range
        }
        c.recentTime = evt.timeStamp;
    }

    function handle_swap(dstRange, dstTexts, srcRange, srcTexts) {
        var i, n = srcRange[1], elx = mainel.childNodes[n], el;
        while (n > dstRange[1]) {
            --n;
            el = elx ? elx.previousSibling : mainel.lastChild;
            el.remove();
        }
        while (n < dstRange[1]) {
            el = document.createElement("div");
            el.append(document.createElement("br"));
            mainel.insertBefore(el, elx);
            ++n;
        }
        el = mainel.childNodes[dstRange[0]];
        for (i = 0; el !== elx; el = el.nextSibling, ++i) {
            el.replaceChildren(dstTexts[i].length <= 1 ? document.createElement("br") : dstTexts[i].substring(0, dstTexts[i].length - 1));
        }
        redisplay_ln0 = dstRange[0];
        redisplay_el1 = elx;
        queue_rehighlight();
        // place selection at end of difference
        var dt = dstTexts[dstTexts.length - 1] || "",
            dl = dt.length,
            st = srcTexts[srcTexts.length - 1] || "",
            sl = st.length;
        i = -1;
        while (st.charCodeAt(sl + i) === dt.charCodeAt(dl + i)) {
            --i;
        }
        let [b, off] = maince.lp2boff(dstRange[1] - 1, i + 1);
        window.getSelection().setBaseAndExtent(b, off, b, off);
    }

    function handle_undo(time) {
        var c = commands[commandPos - 1];
        if (c && (undo_time === null || time - undo_time > 3)) {
            undo_time = undo_time || time;
            c.recentTime = 0;
            c.afterLines = c.afterLines || maince.slice(c.afterRange[0], c.afterRange[1]);
            handle_swap(c.beforeRange, c.beforeLines, c.afterRange, c.afterLines);
            --commandPos;
            commandPos > 0 && (commands[commandPos - 1].recentTime = 0);
        }
    }

    function handle_redo(time) {
        var c = commands[commandPos];
        if (c && (redo_time === null || time - redo_time > 3)) {
            redo_time = redo_time || time;
            handle_swap(c.afterRange, c.afterLines, c.beforeRange, c.beforeLines);
            ++commandPos;
        }
    }

    function beforeinput(evt) {
        var t = evt.inputType;
        if (t !== "historyUndo") {
            undo_time = null;
        }
        if (t !== "historyRedo") {
            redo_time = null;
        }
        if (t.startsWith("format")) {
            evt.preventDefault();
        } else if (t === "historyUndo") {
            evt.preventDefault();
            handle_undo(evt.timeStamp);
        } else if (t === "historyRedo") {
            evt.preventDefault();
            handle_redo(evt.timeStamp);
        } else {
            var r = event_range(evt);
            redisplay_ln0 = r[0];
            redisplay_el1 = r[1] >= 0 ? mainel.childNodes[r[1]] : null;
            prepare_undo(r, evt);
            evt.dataTransfer && (normalization = 1);
        }
    }

    function clear_msgbub() {
        if (msgbub) {
            msgbub.remove();
            msgbub = null;
        }
    }

    function set_msgbub(lineel, sel) {
        // only highlighted spans get bubbles
        var node = sel.anchorNode;
        if (node.nodeType === 3 && node.parentElement === lineel) {
            if (sel.anchorOffset === 0) {
                node = node.previousSibling;
            } else if (sel.anchorOffset === node.length) {
                node = node.nextSibling;
            }
        }
        if (node && node.nodeType === 3) {
            node = node.parentElement;
        }
        if (msgbub && msgbub.span === node) { // same span
            return;
        }
        clear_msgbub();
        if (!node
            || node.nodeType !== 1
            || node.tagName !== "SPAN"
            || node.parentElement !== lineel) {
            return;
        }

        // find offset
        var pos = 0, ch, i;
        for (ch = node.previousSibling; ch; ch = ch.previousSibling) {
            pos += ch.textContent.length;
        }

        // find tooltip
        var tips = jsonhl_highlight_tips(lineel.getAttribute("data-highlight-tips")) || [];
        for (i = 0; i !== tips.length; ++i) {
            if (tips[i].pos1 <= pos && pos <= tips[i].pos2) {
                msgbub = make_bubble({anchor: "nw", color: "feedback"})
                    .html(render_feedback_list([tips[i]]))
                    .near(node);
                msgbub.span = node;
                return;
            }
        }
    }

    function selectionchange() {
        var sel = window.getSelection(), lineno, st;
        if (!sel.anchorNode
            || (lineno = maince.lineno(sel.anchorNode)) < 0
            || (!sel.isCollapsed
                && maince.lineno(sel.focusNode) !== lineno)) {
            clear_msgbub();
            return;
        }

        // set data-caret-path
        var path = [], i, s, m, lim;
        for (i = lineno; i > 0 && (st = states[i] || "") !== "j"; --i) {
            s = st.endsWith("f") || st.endsWith("g") ? maince.line(i).trim() : "";
            if (s === "") {
                continue;
            }
            json_string_re.lastIndex = 0;
            m = json_string_re.exec(s);
            if (!m) {
                return;
            }
            path.push(JSON.parse(m[0]));
            if (st.length === 1) {
                break;
            } else if (st[st.length - 2] === "h") {
                // in a list
                path.push("#");
                lim = st.length - 1;
            } else {
                lim = st.length;
            }
            while (i > 1 && states[i - 1].length >= lim) {
                --i;
            }
        }
        path.reverse();
        var spath = JSON.stringify(path);
        if (mainel.getAttribute("data-caret-path") !== spath) {
            mainel.setAttribute("data-caret-path", spath);
            mainel.dispatchEvent(new CustomEvent("jsonpathchange", {detail: path}));
        }

        // display tooltip
        var lineel = mainel.childNodes[lineno];
        if (lineel && lineel.nodeType === 1 && lineel.hasAttribute("data-highlight-tips")) {
            set_msgbub(lineel, sel);
        } else {
            if (lineel && lineel.nodeType !== 1) {
                log_jserror("bad lineel in json settings [".concat(lineel, "///", lineel.nodeType === 3 ? lineel.data + "///" : "", lineno, "/", mainel.childNodes.length, "]"));
            }
            clear_msgbub();
        }
    }

    mainel.addEventListener("beforeinput", beforeinput);
    mainel.addEventListener("input", queue_rehighlight);
    document.addEventListener("selectionchange", selectionchange);
    if (mainel.hasAttribute("data-reflect-text")
        && (reflectel = document.getElementById(mainel.getAttribute("data-reflect-text")))) {
        maince.add_reflector(reflectel);
        reflectel.hotcrp_ce = maince;
    }

    normalization = -1;
    rehighlight_queued = true;
    rehighlight();
}


demand_load.settingdescriptions = demand_load.make(function (resolve, reject) {
    $.get(hoturl("api/settingdescriptions"), null, function (v) {
        var sd = null, i, e;
        if (v && v.ok && v.setting_descriptions) {
            sd = {"$order": []};
            for (i = 0; i !== v.setting_descriptions.length; ++i) {
                e = v.setting_descriptions[i];
                sd[e.name] = e;
                sd.$order.push(e);
            }
        }
        (sd ? resolve : reject)(sd);
    });
});

function settings_jpath_head(el) {
    var path = el.getAttribute("data-caret-path");
    try {
        if (path
            && (path = JSON.parse(path))
            && path.length > 0
            && !path[0].startsWith("$"))
            return path[0];
    } catch (err) {
    }
    return null;
}

function settings_jsonpathchange(evt) {
    var head = (evt.detail || [])[0];
    if (!head || head.startsWith("$")) {
        if (this.hasAttribute("data-caret-path-head")) {
            this.removeAttribute("data-caret-path-head");
            $(".settings-json-info").empty();
        }
    } else if (head !== this.getAttribute("data-caret-path-head")) {
        this.setAttribute("data-caret-path-head", head);
        demand_load.settingdescriptions().then(make_settings_descriptor(this, head));
    }
}

function make_settings_descriptor(mainel, head) {
    return function (sd) {
        if (settings_jpath_head(mainel) === head)
            settings_describe(sd[head]);
    };
}

function append_rendered_values(es, values) {
    var sep, e, i;
    if (Array.isArray(values)) {
        sep = values.length <= 2 ? "" : ", ";
        for (i = 0; i !== values.length; ++i) {
            e = document.createElement("samp");
            e.append(JSON.stringify(values[i]));
            i === 0 || es.push(sep);
            i === values.length - 1 && es.push(" or ");
            es.push(e);
        }
    } else {
        es.push(values);
    }
}

function settings_describe(d) {
    var $i = $(".settings-json-info"), e, es, i;
    $i.empty();
    if (!d) {
        return;
    }
    if (d.title) {
        e = document.createElement("h3");
        e.className = "form-h mb-1";
        render_text.ftext_onto(e, d.title, 0);
        $i.append(e);
    }
    if (d.summary) {
        e = document.createElement("h4");
        e.className = "form-h n mb-1";
        render_text.ftext_onto(e, d.summary, 0);
        $i.append(e);
    }
    e = document.createElement("h4");
    e.className = "form-h settings-jpath";
    e.append(d.name);
    $i.append(e);

    if (d.values) {
        es = ["Value: "];
        append_rendered_values(es, d.values);
    } else if (d.type === "oblist") {
        es = ["Value: list of objects"];
    } else if (d.type === "object") {
        es = ["Value: object"];
    } else {
        es = [];
    }
    if (es.length > 0) {
        e = document.createElement("p");
        e.className = "p-sqz";
        e.append(...es);
        $i.append(e);
    }

    if (d.components && d.components.length > 1) {
        var table, tbody, tr, th, td, div, span, comp;
        $i.append((e = document.createElement("div")));
        e.className = "p-sqz";
        e.append(d.type === "oblist" ? "Object components:" : "Components:",
            (table = document.createElement("table")));
        table.className = "key-value";
        table.append((tbody = document.createElement("tbody")));
        for (i = 0; i !== d.components.length; ++i) {
            comp = d.components[i];
            tbody.append((tr = document.createElement("tr")));
            tr.append((th = document.createElement("th")),
                      (td = document.createElement("td")));
            th.append(comp.name);
            if (comp.title || comp.values) {
                td.append((div = document.createElement("div")));
                if (comp.title) {
                    div.append((span = document.createElement("span")));
                    render_text.ftext_onto(span, comp.title, 0);
                }
                es = [];
                if (comp.values) {
                    append_rendered_values(es, comp.values);
                }
                if (es.length !== 0) {
                    comp.title && div.append(" ");
                    div.append((span = document.createElement("span")));
                    span.className = "dim";
                    span.append("(", ...es, ")");
                }
            }
            if (comp.summary) {
                td.append((div = document.createElement("div")));
                render_text.ftext_onto(div, comp.summary, 0);
            }
        }
    }

    if (d.default_value != null && d.default_value !== "") {
        es = ["Default: "];
        if (typeof d.default_value !== "string"
            || (d.values && Array.isArray(d.values) && d.values.indexOf(d.default_value) >= 0)) {
            e = document.createElement("samp");
            e.append(JSON.stringify(d.default_value));
            es.push(e);
        } else {
            es.push("“", d.default_value, "”");
        }
        e = document.createElement("p");
        e.className = "pw p-sqz";
        e.append(...es);
        $i.append(e);
    }
    if (d.description) {
        e = document.createElement("div");
        e.className = "w-text mb-4";
        render_text.ftext_onto(e, d.description, 0);
        $i.append(e);
    }

    $i.find(".taghh, .badge").each(ensure_pattern_here);
}

function settings_path_jump(el, path, use_key) {
    const jpp = json_path_position(el.value, path);
    if (jpp) {
        const pos1 = use_key ? jpp.kpos1 : jpp.vpos1,
            pos2 = use_key ? jpp.kpos2 : jpp.vpos2,
            [ln1, lp1] = el.hotcrp_ce.p2lp(pos1 != null ? pos1 : jpp.vpos1),
            [ln2, lp2] = el.hotcrp_ce.p2lp(pos2 != null ? pos2 : jpp.vpos2),
            [le1, lo1] = el.hotcrp_ce.lp2boff(ln1, lp1),
            [le2, lo2] = el.hotcrp_ce.lp2boff(ln2, lp2);
        $(le1).scrollIntoView({marginTop: 24, atTop: true});
        if (use_key && jpp.kpos1 == null) {
            window.getSelection().setBaseAndExtent(le2, lo2, le2, lo2);
        } else {
            window.getSelection().setBaseAndExtent(le1, lo1, le2, lo2);
        }
    }
}

function json_settings_presubmit(settingse) {
    return function () {
        let lines = [], e;
        for (let ch = settingse.firstChild; ch; ch = ch.nextSibling) {
            lines.push(ch.textContent, "\n");
        }
        e = this.elements["json_settings:copy"];
        e || this.appendChild((e = hidden_input("json_settings:copy", "")));
        e.value = lines.join("");
    };
}

function initialize_json_settings() {
    $(".need-settings-json").each(function () {
        make_json_validate.call(this);
        this.addEventListener("jsonpathchange", settings_jsonpathchange);
        removeClass(this, "need-settings-json");
        addClass(this, "js-settings-json");
        this.closest("form").addEventListener("submit", json_settings_presubmit(this));
    });
}

handle_ui.on("click.js-settings-jpath", function () {
    let path = this.querySelector("code.settings-jpath"),
        el = document.getElementById("json_settings");
    if (path && el) {
        settings_path_jump(el, path.textContent, hasClass(this, "use-key"));
    }
});

handle_ui.on("hashjump.js-hash", function (c) {
    let el = document.getElementById("json_settings");
    if (el) {
        initialize_json_settings();
        for (let i = 0; i !== c.length; ++i) {
            if (typeof c[i] === "object" && c[i][0] === "path") {
                settings_path_jump(el, c[i][1], true);
                return true;
            }
        }
    }
});

$(initialize_json_settings);

})();


handle_ui.on("dragstart.js-settings-drag", function (evt) {
    var id = this.parentElement.id;
    if (id.startsWith("sf/")) {
        settings_drag(this, this.parentElement, $$("settings-sform"), evt);
    } else if (id.startsWith("rf/")) {
        settings_drag(this, this.parentElement, $$("settings-rform"), evt);
    }
});

function settings_drag(draghandle, draggable, group, evt) {
    var pos, posy0, posy1, contains = 0, sep, changed = false, scrollt = null;
    function drag(evt) {
        evt.preventDefault();
        evt.dropEffect = "move";

        if (contains === 0) {
            sep && sep.remove();
            pos = posy0 = posy1 = sep = null;
            return;
        }

        var wh = window.innerHeight,
            tsb = Math.min(wh * 0.125, 200), bsb = wh - tsb,
            g, x;
        if ((scrollt === null || evt.timeStamp > scrollt + 100)
            && (evt.clientY < tsb || evt.clientY > bsb)) {
            g = group.getBoundingClientRect();
            if (evt.clientY < tsb && g.top < tsb / 2) {
                x = evt.clientY < 20 ? 1 : Math.pow((tsb - evt.clientY) / tsb, 2.5);
                window.scrollBy({left: 0, top: -x * 32, behavior: "smooth"});
            } else if (evt.clientY > bsb && g.bottom > bsb + tsb / 2) {
                x = evt.clientY > wh - 20 ? 1 : Math.pow((evt.clientY - bsb) / tsb, 2.5);
                window.scrollBy({left: 0, top: x * 32, behavior: "smooth"});
            }
        }
        if (posy0 !== null && evt.clientY >= posy0 && evt.clientY < posy1) {
            return;
        }

        posy0 = null;
        posy1 = 0;
        for (pos = group.firstChild; pos; pos = pos.nextSibling) {
            if (hasClass(pos, "dropmark")
                || (g = pos.getBoundingClientRect()).height === 0) {
                continue;
            }
            posy0 = posy1;
            if (hasClass(pos, "dragging")) {
                posy1 = posy0;
            } else {
                posy1 = (g.top + g.bottom) / 2;
                if (evt.clientY >= posy0 && evt.clientY < posy1) {
                    break;
                }
            }
        }
        if (pos === null) {
            posy0 = posy1;
            posy1 = Infinity;
        }

        changed = pos !== draggable.nextSibling;
        if (changed) {
            if (!sep) {
                sep = document.createElement("hr");
                sep.className = "dropmark";
            }
            group.insertBefore(sep, pos);
        } else {
            sep && sep.remove();
            sep = null;
        }
        toggleClass(draggable, "drag-would-move", changed);
        toggleClass(draggable, "drag-would-keep", !changed);
    }
    function drop() {
        if (contains !== 0) {
            changed && group.insertBefore(draggable, sep);
            var ch, e, i = 1;
            for (ch = group.firstChild; ch; ch = ch.nextSibling) {
                if ((e = ch.querySelector(".is-order"))) {
                    if (e.value != i) {
                        e.value = i;
                        $(e).trigger("change");
                    }
                    ++i;
                }
            }
        }
    }
    function dragstart(evt) {
        var g = draggable.getBoundingClientRect();
        evt.dataTransfer.setDragImage(draggable, evt.clientX - g.left, evt.clientY - g.top);
        evt.dataTransfer.effectAllowed = "move";
        addClass(draggable, "dragging");
    }
    function dragend() {
        sep && sep.remove();
        removeClass(draggable, "dragging");
        removeClass(draggable, "drag-would-move");
        removeClass(draggable, "drag-would-keep");
        window.removeEventListener("dragover", drag);
        draghandle.removeEventListener("dragend", dragend);
        group.removeEventListener("drop", drop);
        group.removeEventListener("dragenter", dragenter);
        group.removeEventListener("dragleave", dragenter);
        window.removeEventListener("scroll", scroll);
        window.removeEventListener("resize", scroll);
    }
    function dragenter(evt) {
        if (group.contains(evt.target)) {
            var delta = evt.type === "dragenter" ? 1 : -1;
            contains += delta;
            if (contains === (delta === 1 ? 1 : 0)) {
                drag(evt);
            }
        }
    }
    function scroll() {
        posy0 = posy1 = null;
    }

    dragstart(evt);
    evt = null;
    window.addEventListener("dragover", drag);
    draghandle.addEventListener("dragend", dragend);
    group.addEventListener("drop", drop);
    group.addEventListener("dragenter", dragenter);
    group.addEventListener("dragleave", dragenter);
    window.addEventListener("scroll", scroll);
    window.addEventListener("resize", scroll);
}


hotcrp.settings = {
    review_form: review_form_settings
};

})();
