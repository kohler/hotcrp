// settings.js -- HotCRP JavaScript library for settings
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

"use strict";

handle_ui.on("js-settings-au-seerev-tag", function (event) {
    $("#review_visibility_author_3").click(); // AUSEEREV_TAGS
});

handle_ui.on("js-settings-sub-nopapers", function (event) {
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
    } else {
        var edit = document.getElementById(elt.id + "/edit") || elt;
        $(edit).children().addClass("hidden");
        $(edit).append('<div class="feedback is-warning" id="'.concat(elt.id, '/delete_message">', message, '</div>'));
        $(edit).find("input").each(function () {
            if (this.type !== "hidden" && !hasClass(this, "hidden")) {
                this.disabled = true;
                addClass(this, "text-decoration-line-through");
                var parent = this.closest(".entryi");
                if (parent) {
                    removeClass(parent, "hidden");
                    removeClass(parent, "mb-3");
                }
                return false;
            }
        });
        return true;
    }
}

function settings_field_unfold() {
    var ch = this.parentElement.firstChild;
    for (; ch; ch = ch.nextSibling) {
        if (ch !== this && hasClass(ch, "fold2o") && !form_differs(ch))
            fold(ch, true, 2);
    }
    $(this).find("textarea").css("height", "auto").autogrow();
    $(this).find("input[type=text]").autogrow();
    $(this).scrollIntoView();
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


// BEGIN SUBMISSION FIELD SETTINGS
(function () {
var type_properties, type_name_placeholders;

function settings_sf_order() {
    var i = 0, n, pos,
        form = document.getElementById("settingsform"),
        c = document.getElementById("settings-sform");
    $(c).find(".moveup, .movedown").prop("disabled", false);
    $(c).find(".settings-sf:first-child .moveup").prop("disabled", true);
    $(c).find(".settings-sf:last-child .movedown").prop("disabled", true);
    for (n = c.firstChild; n; n = n.nextSibling) {
        pos = hasClass(n, "deleted") ? 0 : ++i;
        form.elements[n.id + "/order"].value = pos;
    }
    form_highlight("#settingsform");
}

handle_ui.on("js-settings-sf-type", function (event) {
    var props, e, name;
    if (!type_properties) {
        e = document.getElementById("settings-sform");
        type_properties = JSON.parse(e.getAttribute("data-type-properties"));
        type_name_placeholders = JSON.parse(e.getAttribute("data-type-name-placeholders"));
    }
    if ((props = (type_properties || {})[this.value])) {
        for (e = this.closest(".settings-sf-edit").firstChild;
             e; e = e.nextSibling) {
            if (e.nodeType === 1 && e.hasAttribute("data-property"))
                toggleClass(e, "hidden", !props.includes(e.getAttribute("data-property")));
        }
    }
    e = this.form.elements[this.name.replace("/type", "/name")];
    if (e) {
        e.placeholder = (type_name_placeholders || {})[this.value] || "Field name";
    }
});

handle_ui.on("js-settings-sf-move", function (event) {
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
        foldup.call(sf, event, {n: 2, f: false});
    }
    settings_sf_order();
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
    function submit(event) {
        var opt = cur_option(),
            samp = samps[opt.value | 0],
            h = $$("settings-sf-new").innerHTML,
            next = 1, odiv;
        while ($$("sf/" + next + "/name"))
            ++next;
        h = h.replace(/\/\$/g, "/" + next);
        odiv = $(h).removeClass("hidden").appendTo("#settings-sform").awaken();
        odiv.find(".js-settings-sf-type").val(samp.getAttribute("data-name")).change();
        $$("sf/" + next + "/name").focus();
        settings_sf_order();
        $d.close();
        event.preventDefault();
    }
    function create() {
        var hc = popup_skeleton(), i;
        hc.push('<h2>Add field</h2>');
        hc.push('<p>Choose a template for the new field.</p>');
        hc.push('<select name="sf_template" class="w-100 want-focus" size="5">', '</select>');
        for (i = 0; samps[i]; ++i) {
            hc.push('<option value="'.concat(i, i ? '">' : '" selected>', escape_html(samps[i].getAttribute("data-title")), '</option>'));
        }
        hc.pop();
        hc.push('<fieldset class="settings-sf-template-view mt-4" style="min-width:500px;max-width:90%;min-height:10em"><legend>Example</legend></fieldset>');
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

$(document).on("hotcrpsettingssf", ".settings-sf", function (evt) {
    var view = document.getElementById(this.id + "/view"),
        edit = document.getElementById(this.id + "/edit"),
        type = document.getElementById(this.id + "/type");
    settings_disable_children(view);
    if (edit
        && !form_differs(edit)
        && !$(edit).find(".is-warning, .is-error, .has-warning, .has-error").length) {
        fold(this, true, 2);
    }
    if (type)
        $(type).trigger("change");
    removeClass(this, "hidden");
    settings_sf_order();
});

handle_ui.on("unfold.settings-sf", settings_field_unfold, -1);

tooltip.add_builder("settings-sf", function (info) {
    var x = "#settings-sf-caption-values";
    if (/\/name$/.test(this.name))
        x = "#settings-sf-caption-name";
    else if (/\/condition$/.test(this.name))
        x = "#settings-sf-caption-condition";
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


handle_ui.on("js-settings-decision-add", function (event) {
    var form = this.form, ctr = 1;
    while (form.elements["decision/" + ctr + "/id"])
        ++ctr;
    $("#settings-decision-type-notes").removeClass("hidden");
    var h = $("#settings-new-decision-type").html().replace(/\/\$/g, "/" + ctr),
        $r = $(h).appendTo("#settings-decision-types");
    $r.find("input[type=text]").autogrow();
    form.elements["decision/" + ctr + "/name"].focus();
    form_highlight(form);
});

handle_ui.on("js-settings-decision-delete", function (event) {
    var dec = this.closest(".settings-decision"),
        ne = this.form.elements[dec.id + "/name"],
        sc = ne.getAttribute("data-exists-count")|0;
    settings_delete(dec, "This decision will be removed"
        + (sc ? ' and <a href="'.concat(hoturl_html("search", {q: "dec:\"" + ne.defaultValue + "\""}), '" target="_blank">', plural(sc, "submission"), '</a> set to undecided') : '')
        + '.');
    form_highlight(this.form);
});

handle_ui.on("js-settings-automatic-tag-new", function (event) {
    var odiv = this.closest(".settings-automatic-tag"), h, ctr = 1;
    while ($$("automatic_tag/" + ctr))
        ++ctr;
    h = $("#settings-new-automatic-tag").html().replace(/\/\$/g, "/" + ctr);
    odiv = $(h).appendTo("#settings-automatic-tags");
    odiv.find("input[type=text]").autogrow();
    $$("automatic_tag/".concat(ctr, "/tag")).focus();
});

handle_ui.on("js-settings-automatic-tag-delete", function (event) {
    var ne = this.form.elements[this.closest(".settings-automatic-tag").id + "/tag"];
    settings_delete(this.closest(".settings-automatic-tag"),
        "This automatic tag will be removed from settings and from <a href=\"".concat(hoturl_html("search", {q: "#" + ne.defaultValue, t: "all"}), '" target="_blank">any matching submissions</a>.'));
    form_highlight(this.form);
});

handle_ui.on("js-settings-track-add", function () {
    for (var i = 1; $$("track/" + i); ++i) {
    }
    var trhtml = $("#settings-track-new").html().replace(/\/\$/g, "/" + i);
    $("#track\\/" + (i - 1)).after(trhtml);
    var $j = $("#track\\/" + i).awaken();
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
    var a = [], ch, i = 1, form = $$("settingsform");
    for (ch = $("#settings-review-rounds")[0].firstChild; ch; ch = ch.nextSibling) {
        if (!hasClass(ch, "deleted")) {
            var ne = form.elements[ch.id + "/name"],
                n = ne ? ne.value.trim() : "(unknown)";
            if (n.toLowerCase() === "unnamed") {
                n = "";
            }
            if (n === "" && hasClass(ch, "is-new")) {
                n = "(new round)";
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
                if (opt.textContent !== a[j].name)
                    opt.textContent = a[j].name;
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
                var opt = document.createElement("option");
                opt.value = a[j].value;
                opt.textContent = a[j].name;
                this.insertBefore(opt, cur);
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
        settings_delete(div, "This review round will be deleted and <a href=\"".concat(hoturl_html("search", {q: "re:\"" + (ne ? ne.defaultValue : "<invalid>") + "\""}), '" target="_blank">', plural(n, "review"), '</a> assigned to another round.'));
    }
    form_highlight(this.form);
    settings_review_round_selectors(this.form);
});


window.review_form_settings = (function () {
var fieldorder = [], original, samples, stemplate, ttemplate,
    colors = ["sv", "Red to green", "svr", "Green to red",
              "blpu", "Blue to purple", "publ", "Purple to blue",
              "rdpk", "Red to pink", "pkrd", "Pink to red",
              "viridisr", "Yellow to purple", "viridis", "Purple to yellow",
              "orbu", "Orange to blue", "buor", "Blue to orange",
              "turbo", "Turbo", "turbor", "Turbo reversed",
              "catx", "Category10", "none", "None"];

function get_fid(elt) {
    return elt.id.replace(/^.*_/, "");
}

function unparse_value(fieldj, idx) {
    if (fieldj.start && fieldj.start !== 1) {
        var cc = fieldj.start.charCodeAt(0);
        return String.fromCharCode(cc + fieldj.values.length - idx);
    } else
        return idx.toString();
}

function values_to_text(fieldj) {
    var i, t = [];
    if (!fieldj.values)
        return "";
    for (i = 0; i !== fieldj.values.length; ++i)
        t.push(unparse_value(fieldj, i + 1) + ". " + fieldj.values[i]);
    if (fieldj.start && fieldj.start !== 1)
        t.reverse();
    if (t.length)
        t.push(""); // get a trailing newline
    return t.join("\n");
}

function rf_order() {
    var i = 0, n, pos,
        form = document.getElementById("settingsform"),
        c = document.getElementById("settings-rform");
    $(c).find(".moveup, .movedown").prop("disabled", false);
    $(c).find(".settings-rf:first-child .moveup").prop("disabled", true);
    $(c).find(".settings-rf:last-child .movedown").prop("disabled", true);
    for (n = c.firstChild; n; n = n.nextSibling) {
        pos = hasClass(n, "deleted") ? 0 : ++i;
        form.elements[n.id + "/order"].value = pos;
    }
    form_highlight("#settingsform");
}

function rf_fill_control(form, name, value, setdefault) {
    var elt = form.elements[name];
    elt && $(elt).val(value);
    elt && setdefault && elt.setAttribute("data-default-value", value);
}

function rf_color() {
    var c = this, sv = $(this).val(), i, scanal = make_score_info(9, false, sv);
    hasClass(c.parentElement, "select") && (c = c.parentElement);
    while (c && !hasClass(c, "rf-scheme-example")) {
        c = c.nextSibling;
    }
    for (i = 1; i <= scanal.max && c; ++i) {
        if (c.children.length < i)
            $(c).append('<svg width="0.5em" height="0.75em" viewBox="0 0 1 1"><path d="M0 0h1v1h-1z" fill="currentColor" /></svg>');
        c.children[i - 1].setAttribute("class", scanal.className(i));
    }
    while (c && i <= c.children.length) {
        c.removeChild(c.lastChild);
    }
}

handle_ui.on("change.rf-scheme", rf_color);

function rf_fill(pos, fieldj, setdefault) {
    var form = document.getElementById("settingsform"),
        rfid = "rf/" + pos,
        fid = form.elements[rfid + "/id"].value;
    fieldj = fieldj || original[fid] || {};
    rf_fill_control(form, rfid + "/name", fieldj.name || "", setdefault);
    rf_fill_control(form, rfid + "/description", fieldj.description || "", setdefault);
    rf_fill_control(form, rfid + "/visibility", fieldj.visibility || "re", setdefault);
    rf_fill_control(form, rfid + "/values_text", values_to_text(fieldj), setdefault);
    rf_fill_control(form, rfid + "/required", fieldj.required ? "1" : "0", setdefault);
    var colors = form.elements[rfid + "/scheme"];
    if (colors) {
        fieldj.scheme = fieldj.scheme || "sv";
        rf_fill_control(form, rfid + "/scheme", fieldj.scheme, setdefault);
        rf_color.call(colors);
    }
    var ec, ecs = fieldj.exists_if != null ? fieldj.exists_if : "";
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
    rf_fill_control(form, rfid + "/id", fid, true);
    $("#rf\\/" + pos + " textarea").trigger("change");
    $("#rf\\/" + pos + "\\/view").html(rf_render_view(fieldj));
    $("#rf\\/" + pos + "\\/delete").attr("aria-label", "Delete from form");
    if (setdefault) {
        rf_fill_control(form, rfid + "/order", fieldj.order || 0, setdefault);
    }
    if (fieldj.search_keyword) {
        $("#rf\\/" + pos).attr("data-rf", fieldj.search_keyword);
    }
    return false;
}

function rf_delete() {
    var rf = this.closest(".settings-rf");
    if (settings_delete(rf, "This field will be deleted from the review form.")) {
        if (rf.hasAttribute("data-rf")) {
            var search = {q: "has:" + rf.getAttribute("data-rf"), t: "all", forceShow: 1};
            $.get(hoturl("api/search", search), null, function (v) {
                var t;
                if (v && v.ok && v.ids && v.ids.length)
                    t = 'This field will be deleted from the review form and from reviews on <a href="'.concat(hoturl_html("search", search), '" target="_blank">', plural(v.ids.length, "submission"), "</a>.");
                else if (v && v.ok)
                    t = "This field will be deleted from the review form. No reviews have used the field.";
                else
                    t = "This field will be deleted from the review form and possibly from some reviews.";
                $$(rf.id + "/delete_message").innerHTML = t;
            });
        }
        foldup.call(rf, event, {n: 2, f: false});
    }
    rf_order();
}

tooltip.add_builder("settings-rf", function (info) {
    var m = this.name.match(/^rf\/\d+\/(.*)$/);
    return $.extend({
        anchor: "w", content: $("#settings-rf-caption-" + m[1]).html(), className: "gray"
    }, info);
});

function option_value_html(fieldj, value) {
    if (!value || value < 0)
        return ["", "No entry"];
    else
        return [make_score_info(fieldj.values.length, fieldj.start, fieldj.scheme).unparse_revnum(value), escape_html(fieldj.values[value - 1] || "Unknown")];
}

function rf_visibility_text(visibility) {
    if ((visibility || "re") === "re")
        return "(hidden from authors)";
    else if (visibility === "admin")
        return "(administrators only)";
    else if (visibility === "secret")
        return "(secret)";
    else if (visibility === "audec")
        return "(hidden from authors until decision)";
    else if (visibility === "pconly")
        return "(hidden from authors and external reviewers)";
    else
        return "";
}

function rf_render_view(fieldj) {
    var hc = new HtmlCollector;

    hc.push('<h3 class="rfehead">', '</h3>');
    hc.push('<label class="revfn'.concat(fieldj.required ? " field-required" : "", '">', escape_html(fieldj.name || "<unnamed>"), '</label>'));
    var t = rf_visibility_text(fieldj.visibility), i;
    if (t)
        hc.push('<div class="field-visibility">'.concat(t, '</div>'));
    hc.pop();

    if (fieldj.exists_if && /^round:[a-zA-Z][-_a-zA-Z0-9]*$/.test(fieldj.exists_if)) {
        hc.push('<p class="feedback is-warning-note">Present on ' + fieldj.exists_if.substring(6) + ' reviews</p>');
    } else if (fieldj.exists_if) {
        hc.push('<p class="feedback is-warning-note">Present on reviews matching “' + escape_html(fieldj.exists_if) + '”</p>');
    }

    if (fieldj.description)
        hc.push('<div class="field-d">'.concat(fieldj.description, '</div>'));

    hc.push('<div class="revev">', '</div>');
    if (fieldj.values) {
        for (i = 0; i !== fieldj.values.length; ++i) {
            var n = fieldj.start && fieldj.start !== 1 ? fieldj.values.length - i : i + 1;
            hc.push('<label class="checki"><span class="checkc"><input type="radio" disabled></span>'.concat(option_value_html(fieldj, n).join(" "), '</label>'));
        }
        if (!fieldj.required) {
            hc.push('<label class="checki g"><span class="checkc"><input type="radio" disabled></span>No entry</label>');
        }
    } else
        hc.push('<textarea class="w-text" rows="' + Math.max(fieldj.display_space || 0, 3) + '" disabled>Text field</textarea>');

    return hc.render();
}

function rf_move(event) {
    var isup = $(this).hasClass("moveup"),
        $f = $(this).closest(".settings-rf").detach(),
        pos = $f.find(".rf-order").val() | 0,
        $c = $("#settings-rform")[0], $n, i;
    for (i = 1, $n = $c.firstChild;
         $n && i < (isup ? pos - 1 : pos + 1);
         ++i, $n = $n.nextSibling) {
    }
    $c.insertBefore($f[0], $n);
    rf_order();
}

function rf_append(fid) {
    var pos = fieldorder.length + 1, $f, i, $j, $tmpl = $("#rf_template");
    if (document.getElementById("rf/" + pos + "/id")
        || !/^[st]\d\d$/.test(fid)
        || fieldorder.indexOf(fid) >= 0) {
        throw new Error("rf_append error on " + fid + " " + (document.getElementById("rf/" + pos + "/id") ? "1 " : "0 ") + fieldorder.join(","));
    }
    fieldorder.push(fid);
    var has_options = fid.charAt(0) === "s";
    original[fid] = original[fid] || Object.assign({}, has_options ? stemplate : ttemplate, {id: fid});
    $f = $($tmpl.html().replace(/\$/g, pos));
    if (has_options) {
        $j = $f.find("select.rf-scheme");
        for (i = 0; i < colors.length; i += 2)
            $j.append("<option value=\"" + colors[i] + "\">" + colors[i+1] + "</option>");
    } else
        $f.find(".is-property-values").remove();
    $f.find(".js-settings-rf-delete").on("click", rf_delete);
    $f.find(".js-settings-rf-move").on("click", rf_move);
    $f.find(".rf-id").val(fid);
    $f.appendTo("#settings-rform");
    rf_fill(pos, original[fid], true);
    $f.awaken();
}

function rf_add(fid) {
    var pos = fieldorder.length + 1;
    rf_append(fid);
    var rf = document.getElementById("rf/" + pos);
    addClass(rf, "is-new");
    foldup.call(rf, null, {n: 2, f: false});
    var ordere = document.getElementById("rf/" + pos + "/order");
    ordere.setAttribute("data-default-value", "0");
    ordere.value = pos;
    form_highlight("#settingsform");
    return true;
}

function rfs(data) {
    var i, fid, forder, mi, e, m, entryi;
    original = {};
    samples = data.samples;
    stemplate = data.stemplate;
    ttemplate = data.ttemplate;

    // construct form for original fields
    forder = [];
    for (i in data.fields) {
        fid = data.fields[i].id || i;
        original[fid] = data.fields[i];
        if (original[fid].order)
            forder.push(fid);
    }
    forder.sort(function (a, b) {
        return original[a].order - original[b].order;
    });
    while (fieldorder.length < forder.length) {
        rf_append(forder[fieldorder.length]);
    }

    // amend form for new fields
    while ((data.req || {})["rf/" + (fieldorder.length + 1) + "/id"]) {
        rf_add(data.req["rf/" + (fieldorder.length + 1) + "/id"]);
    }

    $("#settings-rform").on("unfold", ".settings-rf", settings_field_unfold);

    // highlight errors, apply request
    for (i in data.req || {}) {
        if (/^rf\/\d+\//.test(i)
            && (e = document.getElementById(i))
            && !text_eq($(e).val(), data.req[i])) {
            $(e).val(data.req[i]);
            foldup.call(e, null, {n: 2, f: false});
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
                foldup.call(entryi, null, {n: 2, f: false});
            }
        }
    }

    rf_order();
    form_highlight("#settingsform");
};

function add_dialog() {
    var $d, sel;
    function cur_sample() {
        return samples[sel.options[sel.selectedIndex].value | 0] || samples[0];
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
    function submit(event) {
        var sample = cur_sample(),
            has_options = !!sample.values,
            ffmt = has_options ? "s%02d" : "t%02d",
            i, fid;
        for (i = 1; ; ++i) {
            fid = sprintf(ffmt, i);
            if ($.inArray(fid, fieldorder) < 0)
                break;
        }
        original[fid] = Object.assign({}, has_options ? stemplate : ttemplate, {id: fid});
        rf_add(fid);
        if (sample.is_example)
            sample = Object.assign({}, sample, {name: ""});
        rf_fill(fieldorder.length, sample, false);
        document.getElementById("rf/" + fieldorder.length + "/name").focus();
        $d.close();
        event.preventDefault();
    }
    function create() {
        var hc = popup_skeleton(), i;
        hc.push('<h2>Add field</h2>');
        hc.push('<p>Choose a template for the new field.</p>');
        hc.push('<select name="rf_template" class="w-99 want-focus" size="5">', '</select>');
        for (i = 0; i !== samples.length; ++i)
            hc.push('<option value="'.concat(i, i ? '">' : '" selected>', escape_html(samples[i].selector), '</option>'));
        hc.pop();
        hc.push('<fieldset class="settings-rf-template-view mt-4" style="min-width:500px;max-width:90%;min-height:10em"><legend>Example</legend></fieldset>');
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


handle_ui.on("js-settings-resp-active", function (event) {
    $(".if-response-active").toggleClass("hidden", !this.checked);
});

$(function () { $(".js-settings-resp-active").trigger("change"); });

handle_ui.on("js-settings-response-new", function () {
    var i, $rx, $rt = $("#new_response"), t;
    for (i = 1; $$("response/" + i); ++i) {
    }
    $rt.before($rt.html().replace(/\/\$/g, "/" + i));
    $rx = $("#response\\/" + i);
    $rx.find("textarea").css({height: "auto"}).autogrow();
    $rx.awaken();
    form_highlight(this.form);
    return false;
});

handle_ui.on("js-settings-response-delete", function () {
    var rr = this.closest(".settings-response");
    settings_delete(rr, "This response will be deleted.");
    form_highlight(this.form);
    return false;
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
    } else if (/^(?:none|any|all|default|unnamed|.*response|response.*|draft.*|pri(?:mary)|sec(?:ondary)|opt(?:ional)|pc(?:review)|ext(?:ernal)|meta(?:review))$/i.test(s)) {
        helt.replaceChildren(render_feedback_list([{status: 2, message: "<0>Round name ‘".concat(s, "’ is reserved")}]));
    } else {
        helt.replaceChildren("Example display: ‘", s, " Response’; example search: ‘has:", s, "response’");
    }
});

handle_ui.on("js-settings-decision-new-name", function () {
    var d = this.closest(".settings-decision");
    if (/accept/i.test(this.value)) {
        this.form.elements[d.id + "/category"].selectedIndex = 0;
    } else if (/reject/i.test(this.value)) {
        this.form.elements[d.id + "/category"].selectedIndex = 1;
    } else if (/revis/i.test(this.value)) {
        this.form.elements[d.id + "/category"].selectedIndex = 0;
    }
});


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

// Test if `node` is a descendent of `ancestor`
function is_descendent(node, ancestor) {
    var parent = ancestor.parentElement;
    while (node && node !== ancestor && node !== parent) {
        node = node.parentElement;
    }
    return node === ancestor;
}

// Test if `ch` is a character code for JSON whitespace
function isspace(ch) {
    return ch === 9 || ch === 10 || ch === 13 || ch === 32;
}

// Test if `ch` is a character code for a JSON delimiter ([\s",:\[\]\{\}])
function isdelim(ch) {
    return isspace(ch) || ch === 34 || ch === 44 || ch === 45
        || ch === 58 || ch === 91 || ch === 93 || ch == 123 || ch === 125;
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
        selm = [is_descendent(sel.anchorNode, el), is_descendent(sel.focusNode, el)],
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
            var ch = selx[i].childNodes[selx[i + 1]];
            if (ch && ch.nodeType === 1 && ch.tagName === "BR") {
                break;
            }
            reset(i, ch, 0);
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

// Normalize the contents of `mainel` to a sensible format for editing.
// * Text only.
// * Every line in a separate <div>.
// * No trailing newlines within these <div>s.
// * Blank <div> lines contain <br>.
function normalize_content_editable(mainel, firstel, lastel) {
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
            if (ch.nodeType === 3 && (nl = ch.data.indexOf("\n")) !== -1) {
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

    ch = firstel || mainel.firstChild;
    while (ch && ch !== lastel) {
        if (ch.nodeType !== 1
            || ch.tagName !== "DIV"
            || ch.hasAttribute("style")) {
            var line = document.createElement("div"), first = true;
            mainel.insertBefore(line, ch);
            while (ch && (first || is_text_or_inline(ch))) {
                line.appendChild(ch);
                ch = line.nextSibling;
                first = false;
            }
            if (ch && is_br(ch)) {
                line.firstChild ? ch.remove() : line.appendChild(ch);
            }
            ch === firstel && (firstel = line);
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
function jsonhl_install(lineel, errors) {
    if (lineel.firstChild
        && lineel.firstChild === lineel.lastChild
        && lineel.firstChild.nodeType === 1
        && lineel.firstChild.tagName === "BR") {
        lineel.firstChild.removeAttribute("class");
        lineel.firstChild.removeAttribute("style");
        return;
    }

    var ei = 0, ch = lineel.firstChild,
        nsel = window_selection_inside(lineel);

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

    nsel.refresh();
}

function make_utf16tf(text) {
    var ipos = 0, opos = 0, delta = 0, map = [0, 0],
        re = /[\u0080-\uDBFF\uE000-\uFFFF]/g, m, i, ch;
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

    var ei = 0, ri = 0, tp = 0, len;
    el = mainel.firstChild;
    while (el && ei !== errors.length) {
        while (ei !== errors.length && errors[ei + 2] < tp) {
            ei += 2;
        }
        len = el.textContent.length + 1;

        var rs = [];
        while (ei !== errors.length && errors[ei] <= tp + len) {
            if (errors[ei + 1]) {
                var p1 = Math.max(0, errors[ei] - tp),
                    p2 = Math.min(len, (errors[ei + 2] || Infinity) - tp);
                rs.push("".concat(p1, "-", p2, ":", errors[ei + 1]));
            }
            ei += 2;
        }
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

function jsonhl_line(lineel, st) {
    var t = lineel.textContent, p = 0, n = t.length, ch,
        cst = st.length ? st.charCodeAt(st.length - 1) - 97 : 1,
        errors = [0, 0], node;
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
        var p0 = p, ch;
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
        var p0 = p, p1, c, c0, ok;
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
        var p0 = p, ch;
        for (++p; p !== n && !isdelim(t.charCodeAt(p)); ++p) {
        }
        jsonhl_add_error(errors, p0, p, 2);
    }


    main_loop:
    while (true) {
        while (p !== n && isspace((ch = t.charCodeAt(p)))) {
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
    jsonhl_install(lineel, errors);

    if (cst === 0) {
        return "a";
    } else {
        return st + String.fromCharCode(cst + 97);
    }
}


function make_json_validate() {
    var mainel = this,
        states = [""], lineels = [null], texts = [""],
        state_redisplay = null, normalization, rehighlight_queued,
        api_timer = null, api_value = null, msgbub = null;

    function node_lineno(node) {
        while (node && node.parentElement !== mainel) {
            node = node.parentElement;
        }
        return node ? Array.prototype.indexOf.call(mainel.childNodes, node) : -1;
    }

    function rehighlight() {
        try {
            var i, end_index, lineel, k, st, st0, x;
            if (normalization !== 0) {
                normalize_content_editable(mainel);
                if (normalization < 0
                    && mainel.hasAttribute("data-highlight-ranges")) {
                    jsonhl_transfer_ranges(mainel);
                }
                lineel = mainel.firstChild;
                state_redisplay = null;
            }
            state_redisplay = state_redisplay || [0, Infinity];
            i = state_redisplay[0];
            end_index = state_redisplay[1];
            lineel = mainel.childNodes[i];
            st = st0 = states[i];
            while (lineel !== null
                   && (i < end_index
                       || states[i] !== st
                       || lineels[i] !== lineel)) {
                if (msgbub && msgbub.span.parentElement === lineel) {
                    clear_msgbub();
                }
                if (lineel.nodeType !== 1 || lineel.tagName !== "DIV") {
                    lineel = normalize_content_editable(mainel, lineel, lineel.nextSibling);
                }
                if (normalization >= 0) {
                    lineel.removeAttribute("data-highlight-ranges");
                    lineel.removeAttribute("data-highlight-tips");
                }
                if (lineels[i] !== lineel && lineels[i]) {
                    // incremental line insertions and deletions
                    if ((k = node_lineno(lineels[i])) > i) {
                        x = new Array(k - i);
                        states.splice(i, 0, ...x);
                        lineels.splice(i, 0, ...x);
                        texts.splice(i, 0, ...x);
                    } else if ((k = lineels.indexOf(lineel)) > i) {
                        states.splice(i, k - i);
                        lineels.splice(i, k - i);
                        texts.splice(i, k - i);
                    }
                }
                states[i] = st;
                lineels[i] = lineel;
                st = jsonhl_line(lineel, st);
                texts[i] = lineel.textContent + "\n";
                ++i;
                lineel = lineel.nextSibling;
            }
            if (lineel == null) {
                states.splice(i);
                lineels.splice(i);
                texts.splice(i);
            }
            //state_redisplay.push(i, st, states[i - 1]);
            //console.log(state_redisplay);
            if (mainel.hasAttribute("data-reflect-text")
                || mainel.hasAttribute("data-reflect-highlight-api")) {
                handle_reflection(texts.join(""));
            }
            state_redisplay = null;
            rehighlight_queued = false;
            normalization = 0;
        } catch (err) {
            console.trace(err);
        }
    }

    function handle_reflection(text) {
        var s, el;
        if ((s = mainel.getAttribute("data-reflect-text"))
            && (el = document.getElementById(s))
            && !text_eq(text, el.value)) {
            el.value = text;
            form_highlight(el.form, el);
        }
        if (mainel.hasAttribute("data-reflect-highlight-api")) {
            if (api_value === null) {
                api_value = text;
            } else if (!text_eq(text, api_value)) {
                api_timer && clearTimeout(api_timer);
                api_timer = setTimeout(handle_reflect_api, 750);
                api_value = text;
            }
        }
    }

    function handle_reflect_api() {
        var text = api_value,
            m = mainel.getAttribute("data-reflect-highlight-api").split(/\s+/);
        api_timer = null;
        $.post(hoturl(m[0]), {[m[1]]: text}, function (rv) {
            var i, mi, ranges = [], tips = [], utf16tf;
            if (!rv || !rv.message_list || api_value !== text)
                return;
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
                rehighlight_queued || queueMicrotask(rehighlight);
                rehighlight_queued = true;
            }
        });
    }

    function redisplay_ranges(ranges) {
        var i, ln;
        state_redisplay = state_redisplay || [Infinity, 0];
        for (i = 0; i !== ranges.length; ++i) {
            if ((ln = node_lineno(ranges[i].startContainer)) >= 0)
                state_redisplay[0] = Math.min(state_redisplay[0], ln);
            if ((ln = node_lineno(ranges[i].endContainer)) >= 0)
                state_redisplay[1] = Math.max(state_redisplay[1], ln + 1);
        }
    }

    function beforeinput(e) {
        if (e.inputType.startsWith("format") || e.inputType.startsWith("history") /*🙁*/) {
            e.preventDefault();
            return;
        }
        if (e.dataTransfer) {
            normalization = 1;
        }
        redisplay_ranges(e.getTargetRanges());
    }

    function input(e) {
        redisplay_ranges(e.getTargetRanges());
        if (!rehighlight_queued) {
            queueMicrotask(rehighlight);
            rehighlight_queued = true;
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

    function selectionchange(e) {
        var sel = window.getSelection(), lineno, st;
        if (!sel.isCollapsed
            || !sel.anchorNode
            || (lineno = node_lineno(sel.anchorNode)) < 0) {
            clear_msgbub();
            return;
        }

        // set data-caret-path
        var path = [], i, s, m, lim;
        for (i = lineno; i > 0 && (st = states[i]) !== "j"; --i) {
            s = st.endsWith("f") || st.endsWith("g") ? texts[i].trim() : "";
            if (s === "") {
                continue;
            }
            m = s.match(/^\"(?:[^\\\"]|\\[\/\\bfnrt\"]|\\u[0-9a-fA-F]{4})+\"/);
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
        if (lineel && lineel.hasAttribute("data-highlight-tips")) {
            set_msgbub(lineel, sel);
        } else {
            clear_msgbub();
        }
    }

    mainel.addEventListener("beforeinput", beforeinput);
    mainel.addEventListener("input", input);
    document.addEventListener("selectionchange", selectionchange);
    normalization = -1;
    rehighlight_queued = true;
    queueMicrotask(rehighlight);
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
            $(".settings-json-panel-info").empty();
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
    var $i = $(".settings-json-panel-info"), e, es, i, sep;
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
        var table, tbody, trs = [], tr, th, td, div, span, comp;
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

$(function () {
    $(".js-settings-json").each(function () {
        make_json_validate.call(this);
        this.addEventListener("jsonpathchange", settings_jsonpathchange);
    });
});

})();


hotcrp.settings = {
    review_form: review_form_settings
};
