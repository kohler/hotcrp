// settings.js -- HotCRP JavaScript library for settings
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
    var form = elt.closest("form"),
        sep = elt.id.indexOf("__") > 0 ? "__" : "/";
    addClass(elt, "deleted");
    var deleter = form.elements[elt.id + sep + "delete"];
    if (!deleter) {
        deleter = hidden_input(elt.id + sep + "delete", "");
        deleter.setAttribute("data-default-value", "");
        form.appendChild(deleter);
    }
    deleter.value = "1";
    if (hasClass(elt, "is-new")) {
        addClass(elt, "hidden");
        $(elt).find("input, select, textarea").addClass("ignore-diff");
        return false;
    } else {
        var edit = document.getElementById(elt.id + sep + "edit") || elt;
        $(edit).children().addClass("hidden");
        $(edit).append('<div class="f-i"><em id="'.concat(elt.id, sep, 'delete_message">', message, '</em></div>'));
        var name = form.elements[elt.id + sep + "name"];
        if (name) {
            name.disabled = true;
            addClass(name, "text-decoration-line-through");
            var parent = name.closest(".entryi");
            parent && removeClass(parent, "hidden");
        }
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
        odiv = $(h).removeClass("hidden").appendTo("#settings-sform");
        odiv.find(".need-autogrow").autogrow();
        odiv.find(".need-tooltip").each(tooltip);
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
    var x = "#settings-sf-caption-choices";
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

handle_ui.on("js-settings-new-autosearch", function (event) {
    var odiv = $(this).closest(".settings_tag_autosearch")[0],
        h = $("#settings_newtag_autosearch").html(), next = 1;
    while ($("#tag_autosearch_t_" + next).length)
        ++next;
    h = h.replace(/_0/g, "_" + next);
    odiv = $(h).appendTo("#settings_tag_autosearch");
    odiv.find("input[type=text]").autogrow();
    $("#tag_autosearch_t_" + next)[0].focus();
});

handle_ui.on("js-settings-delete-autosearch", function (event) {
    var odiv = $(this).closest(".settings_tag_autosearch")[0];
    $(odiv).find("input[name^=tag_autosearch_q_]").val("");
    $(odiv).find("input[type=text]").prop("disabled", true).addClass("text-decoration-line-through");
});

handle_ui.on("js-settings-track-add", function () {
    for (var i = 1; $$("track/" + i); ++i) {
    }
    var trhtml = $("#settings-track-new").html().replace(/\/\$/g, "/" + i);
    $("#track\\/" + (i - 1)).after(trhtml);
    var $j = $("#track\\/" + i);
    $j.find(".need-suggest").each(suggest);
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
    $n.find(".need-suggest").each(suggest);
    $n.find(".need-tooltip").each(tooltip);
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

function unparse_option(fieldj, idx) {
    if (fieldj.start && fieldj.start !== 1) {
        var cc = fieldj.start.charCodeAt(0);
        return String.fromCharCode(cc + fieldj.options.length - idx);
    } else
        return idx.toString();
}

function options_to_text(fieldj) {
    var i, t = [];
    if (!fieldj.options)
        return "";
    for (i = 0; i !== fieldj.options.length; ++i)
        t.push(unparse_option(fieldj, i + 1) + ". " + fieldj.options[i]);
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
    while (c && !hasClass(c, "rf-colors-example")) {
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

handle_ui.on("change.rf-colors", rf_color);

function rf_fill(pos, fieldj, setdefault) {
    var form = document.getElementById("settingsform"),
        rfid = "rf/" + pos,
        fid = form.elements[rfid + "/id"].value;
    fieldj = fieldj || original[fid] || {};
    rf_fill_control(form, rfid + "/name", fieldj.name || "", setdefault);
    rf_fill_control(form, rfid + "/description", fieldj.description || "", setdefault);
    rf_fill_control(form, rfid + "/visibility", fieldj.visibility || "pc", setdefault);
    rf_fill_control(form, rfid + "/choices", options_to_text(fieldj), setdefault);
    rf_fill_control(form, rfid + "/required", fieldj.required ? "1" : "0", setdefault);
    var colors = form.elements[rfid + "/colors"];
    if (colors) {
        fieldj.scheme = fieldj.scheme || "sv";
        rf_fill_control(form, rfid + "/colors", fieldj.scheme, setdefault);
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
                $("#" + rf.id + "\\/delete_message").html(t);
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
        return [make_score_info(fieldj.options.length, fieldj.start, fieldj.scheme).unparse_revnum(value), escape_html(fieldj.options[value - 1] || "Unknown")];
}

handle_ui.on("unfold.js-settings-field-unfold", function (event) {
    var f = event.target.closest(".has-fold");
    if ((hasClass(f, "fold2c") || !form_differs(f))
        && !hasClass(f, "deleted"))
        foldup.call(event.target, event, {n: 2});
});

function rf_visibility_text(visibility) {
    if ((visibility || "pc") === "pc")
        return "(hidden from authors)";
    else if (visibility === "admin")
        return "(administrators only)";
    else if (visibility === "secret")
        return "(secret)";
    else if (visibility === "audec")
        return "(hidden from authors until decision)";
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
    if (fieldj.options) {
        for (i = 0; i !== fieldj.options.length; ++i) {
            var n = fieldj.start && fieldj.start !== 1 ? fieldj.options.length - i : i + 1;
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
        $j = $f.find("select.rf-colors");
        for (i = 0; i < colors.length; i += 2)
            $j.append("<option value=\"" + colors[i] + "\">" + colors[i+1] + "</option>");
    } else
        $f.find(".is-property-options").remove();
    $f.find(".js-settings-rf-delete").on("click", rf_delete);
    $f.find(".js-settings-rf-move").on("click", rf_move);
    $f.find(".rf-id").val(fid);
    $f.appendTo("#settings-rform");
    rf_fill(pos, original[fid], true);
    $f.find(".need-tooltip").each(tooltip);
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
    var i, fid, forder, mi, e, entryi;
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
        if (mi.field
            && (e = document.getElementById(mi.field))
            && (entryi = e.closest(".entryi"))) {
            append_feedback_near(entryi, mi);
            foldup.call(entryi, null, {n: 2, f: false});
        }
    }

    $("#settings-rform").on("unfold", ".settings-rf", settings_field_unfold);
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
            has_options = !!sample.options,
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
    $rx.find(".need-suggest").each(suggest);
    $rx.find(".need-tooltip").each(tooltip);
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


hotcrp.settings = {
    review_form: review_form_settings
};
