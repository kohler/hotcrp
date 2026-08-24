// graph.js -- HotCRP JavaScript library for graph drawing
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

/* global hotcrp, siteinfo */
hotcrp.graph = (function ($, d3) {
const $$ = hotcrp.$$,
    $e = hotcrp.$e,
    $frag = hotcrp.$frag,
    $popup = hotcrp.$popup,
    $svg = hotcrp.$svg,
    addClass = hotcrp.classes.add,
    ensure_pattern = hotcrp.ensure_pattern,
    feedback = hotcrp.feedback,
    handle_ui = hotcrp.handle_ui,
    hasClass = hotcrp.classes.has,
    hoturl = hotcrp.hoturl,
    log_jserror = hotcrp.log_jserror,
    make_bubble = hotcrp.make_bubble,
    removeClass = hotcrp.classes.remove,
    strftime = hotcrp.text.strftime;

// Layout constants

// Insets are distances from graph box edges to the marks that show there:
// top of Y-axis label, right end of X-axis line or of a tick label
// overhanging it, bottom of deepest X-tick label or X-axis label, left edge
// of Y-tick labels. Unless overridden by plotLeft/Right/Top/Bottom/Width/
// Height, the plot rectangle is derived from insets. These are the defaults.
const INSET_TOP = 6, INSET_RIGHT = 8, INSET_BOTTOM = 6, INSET_LEFT = 8;
// Gap between an axis label and the tick labels it sits next to
const LABEL_GAP = 4;
// Hard ceiling on tick label width, in pixels, applied whether or not the
// dimension it extends into can grow — a growable box otherwise lets one
// pathological label set the size for everything.
const MAX_LABEL_WIDTH = 180;
// Fraction of the graph box that an axis's tick labels may occupy
const MAX_X_LABEL_HEIGHT_FRACTION = 0.28;
const MAX_Y_LABEL_WIDTH_FRACTION = 0.25;
// Where tick text sits relative to the axis line. d3's axisLeft puts Y tick
// text at -(tickSize + tickPadding) = -9; the X axis matches. A tilted X tick
// label hangs from (-X_TICK_TILT_DX, X_TICK_TILT_DY) instead.
const Y_TICK_TEXT_OFFSET = 9, X_TICK_TEXT_OFFSET = 9;
const X_TICK_TILT_DX = 9, X_TICK_TILT_DY = 2;
// Length of a tick mark, on either axis
const TICK_SIZE = 6;
// X tick labels are tilted by this much when they don't fit horizontally.
const TILT_DEGREES = 65;
const TILT_RADIANS = TILT_DEGREES * Math.PI / 180;
const SINE_TILT_RADIANS = Math.sin(TILT_RADIANS);
const SINE_TILT_RADIANS_INVERSE = 1 / SINE_TILT_RADIANS;
const COSINE_TILT_RADIANS = Math.cos(TILT_RADIANS);
// A box needs at least this many points to draw interpolated quartiles and
// 1.5*IQR whiskers; formerly 4
const BOXPLOT_IQR_MIN_N = 1;

/** Depth below the axis line of the lowest ink in a tilted tick label `width`
 * px wide whose font drops `descent` px below the baseline. The label hangs
 * from (-X_TICK_TILT_DX, X_TICK_TILT_DY) and runs up and to the left, so its
 * far end is its lowest point.
 * @param {number} width
 * @param {number} descent
 * @return {number} */
function tilted_label_depth(width, descent) {
    return (X_TICK_TILT_DX + width) * SINE_TILT_RADIANS
        + (X_TICK_TILT_DY + descent) * COSINE_TILT_RADIANS;
}

/** Inverse of `tilted_label_depth`: the text width that fits in `depth`.
 * @param {number} depth
 * @param {number} descent
 * @return {number} */
function tilted_label_width(depth, descent) {
    return (depth - (X_TICK_TILT_DY + descent) * COSINE_TILT_RADIANS)
        * SINE_TILT_RADIANS_INVERSE - X_TICK_TILT_DX;
}


// Path normalization (for pathNodeMayBeNearer/closestPoint)

const PATHSEG_ARGMAP = {
    m: 2, M: 2, z: 0, Z: 0, l: 2, L: 2, h: 1, H: 1, v: 1, V: 1, c: 6, C: 6,
    s: 4, S: 4, q: 4, Q: 4, t: 2, T: 2, a: 7, A: 7, b: 1, B: 1
};
const normalized_path_cache = new Map();

function svg_path_number_of_items(s) {
    if (s instanceof SVGPathElement) {
        s = s.getAttribute("d");
    }
    if (normalized_path_cache.has(s)) {
        return normalized_path_cache.get(s).length;
    } else {
        return s.replace(/[^A-DF-Za-df-z]+/g, "").length;
    }
}

function make_svg_path_parser(s) {
    if (s instanceof SVGPathElement) {
        s = s.getAttribute("d");
    }
    s = s.split(/([a-zA-Z]|[-+]?(?:\d+\.?\d*|\.\d+)(?:[Ee][-+]?\d+)?)/);
    let i = 1, e = s.length, next_cmd;
    return function () {
        let a = null;
        while (i < e) {
            const ch = s[i];
            if (ch >= "A") {
                if (a)
                    break;
                a = [ch];
                next_cmd = ch;
                if (ch === "m" || ch === "M" || ch === "z" || ch === "Z")
                    next_cmd = (ch === "m" || ch === "z" ? "l" : "L");
            } else {
                if (!a && next_cmd)
                    a = [next_cmd];
                else if (!a || a.length === PATHSEG_ARGMAP[a[0]] + 1)
                    break;
                a.push(+ch);
            }
            i += 2;
        }
        return a;
    };
}

let normalize_path_complaint = false;
function normalize_svg_path(s) {
    if (s instanceof SVGPathElement) {
        s = s.getAttribute("d");
    }
    if (normalized_path_cache.has(s)) {
        return normalized_path_cache.get(s);
    }

    let res = [],
        cx = 0, cy = 0, cx0 = 0, cy0 = 0, copen = false,
        cb = 0, sincb = 0, coscb = 1,
        i, dx, dy,
        parser = make_svg_path_parser(s), a, ch, preva;
    while ((a = parser())) {
        ch = a[0];
        // special commands: bearing, closepath
        if (ch === "b" || ch === "B") {
            cb = ch === "b" ? cb + a[1] : a[1];
            coscb = Math.cos(cb);
            sincb = Math.sin(cb);
            continue;
        } else if (ch === "z" || ch === "Z") {
            preva = res.length ? res[res.length - 1] : null;
            if (copen) {
                if (cx != cx0 || cy != cy0)
                    res.push(["L", cx, cy, cx0, cy0]);
                res.push(["Z"]);
                copen = false;
            }
            cx = cx0, cy = cy0;
            continue;
        }

        // normalize command 1: remove horiz/vert
        if (PATHSEG_ARGMAP[ch] == 1) {
            if (a.length == 1)
                a = ["L"]; // all data is missing
            else if (ch === "h")
                a = ["l", a[1], 0];
            else if (ch === "H")
                a = ["L", a[1], cy];
            else if (ch === "v")
                a = ["l", 0, a[1]];
            else if (ch === "V")
                a = ["L", cx, a[1]];
        }

        // normalize command 2: relative -> absolute
        ch = a[0];
        if (ch >= "a" && !cb) {
            for (i = ch !== "a" ? 1 : 6; i < a.length; i += 2) {
                a[i] += cx;
                a[i+1] += cy;
            }
        } else if (ch >= "a") {
            if (ch === "a")
                a[3] += cb;
            for (i = ch !== "a" ? 1 : 6; i < a.length; i += 2) {
                dx = a[i], dy = a[i + 1];
                a[i] = cx + dx * coscb + dy * sincb;
                a[i+1] = cy + dx * sincb + dy * coscb;
            }
        }
        ch = a[0] = ch.toUpperCase();

        // normalize command 3: use cx0,cy0 for missing data
        while (a.length < PATHSEG_ARGMAP[ch] + 1)
            a.push(cx0, cy0);

        // normalize command 4: shortcut -> full
        if (ch === "S") {
            dx = dy = 0;
            if (preva && preva[0] === "C")
                dx = cx - preva[3], dy = cy - preva[4];
            a = ["C", cx + dx, cy + dy, a[1], a[2], a[3], a[4]];
            ch = "C";
        } else if (ch === "T") {
            dx = dy = 0;
            if (preva && preva[0] === "Q")
                dx = cx - preva[1], dy = cy - preva[2];
            a = ["Q", cx + dx, cy + dy, a[1], a[2]];
            ch = "Q";
        }

        // process command
        if (!copen && ch !== "M") {
            res.push(["M", cx, cy]);
            copen = true;
        }
        if (ch === "M") {
            cx0 = a[1];
            cy0 = a[2];
            copen = false;
        } else if (ch === "L") {
            res.push(["L", cx, cy, a[1], a[2]]);
        } else if (ch === "C") {
            res.push(["C", cx, cy, a[1], a[2], a[3], a[4], a[5], a[6]]);
        } else if (ch === "Q") {
            res.push(["C", cx, cy,
                      cx + 2 * (a[1] - cx) / 3, cy + 2 * (a[2] - cy) / 3,
                      a[3] + 2 * (a[1] - a[3]) / 3, a[4] + 2 * (a[2] - a[4]) / 3,
                      a[3], a[4]]);
        } else {
            // XXX should render "A" as a bezier
            if (++normalize_path_complaint == 1)
                log_jserror("bad normalize_svg_path " + ch);
            res.push(a);
        }

        preva = a;
        cx = a[a.length - 2];
        cy = a[a.length - 1];
    }

    if (normalized_path_cache.size >= 1000) {
        normalized_path_cache.clear();
    }
    normalized_path_cache.set(s, res);
    return res;
}

function pathNodeMayBeNearer(pathNode, point, dist) {
    function oob(l, t, r, b) {
        return l - point[0] >= dist || point[0] - r >= dist
            || t - point[1] >= dist || point[1] - b >= dist;
    }
    // check bounding rectangle of path
    if ("clientX" in point) {
        const bounds = pathNode.getBoundingClientRect(),
            dx = point[0] - point.clientX, dy = point[1] - point.clientY;
        if (bounds && oob(bounds.left + dx, bounds.top + dy,
                          bounds.right + dx, bounds.bottom + dy))
            return false;
    }
    // check path
    const npsl = normalize_svg_path(pathNode);
    let l, t, r, b;
    for (const item of npsl) {
        if (item[0] === "L") {
            l = Math.min(item[1], item[3]);
            t = Math.min(item[2], item[4]);
            r = Math.max(item[1], item[3]);
            b = Math.max(item[2], item[4]);
        } else if (item[0] === "C") {
            l = Math.min(item[1], item[3], item[5], item[7]);
            t = Math.min(item[2], item[4], item[6], item[8]);
            r = Math.max(item[1], item[3], item[5], item[7]);
            b = Math.max(item[2], item[4], item[6], item[8]);
        } else if (item[0] === "Z" || item[0] === "M") {
            continue;
        } else {
            return true;
        }
        if (!oob(l, t, r, b)) {
            return true;
        }
    }
    return false;
}

function closestPoint(pathNode, point, inbest) {
    // originally from Mike Bostock http://bl.ocks.org/mbostock/8027637
    if (inbest && !pathNodeMayBeNearer(pathNode, point, inbest.distance))
        return inbest;

    let pathLength = pathNode.getTotalLength(),
        precision = Math.max(pathLength / svg_path_number_of_items(pathNode) * .125, 3),
        best, bestLength, bestDistance2 = Infinity;

    function check(pLength) {
        const p = pathNode.getPointAtLength(pLength),
            dx = point[0] - p.x, dy = point[1] - p.y,
            d2 = dx * dx + dy * dy;
        if (d2 >= bestDistance2) {
            return false;
        }
        best = [p.x, p.y];
        best.pathNode = pathNode;
        bestLength = pLength;
        bestDistance2 = d2;
        return true;
    }

    // linear scan for coarse approximation
    for (let sl = 0; sl <= pathLength; sl += precision) {
        check(sl);
    }

    // binary search for precise estimate
    precision *= .5;
    while (precision > .5) {
        const bl0 = bestLength - precision;
        if (bl0 < 0 || !check(bl0)) {
            const bl1 = bestLength + precision;
            if (bl1 > pathLength || !check(bl1)) {
                precision *= 0.5;
            }
        }
    }

    best.distance = Math.sqrt(bestDistance2);
    best.pathLength = bestLength;
    return inbest && inbest.distance < best.distance + 0.01 ? inbest : best;
}

function tangentAngle(pathNode, length) {
    const length0 = Math.max(0, length - 0.25);
    if (length0 == length) {
        length += 0.25;
    }
    const p0 = pathNode.getPointAtLength(length0),
        p1 = pathNode.getPointAtLength(length);
    return Math.atan2(p1.y - p0.y, p1.x - p0.x);
}


/* CDF functions */
function seq_to_cdf(seq, flip, raw) {
    const cdf = [], n = seq.ntotal || seq.length;
    seq.sort(flip ? d3.descending : d3.ascending);
    for (let i = 0; i <= seq.length; ++i) {
        const y = raw ? i : i/n;
        if (i != 0 && (i == seq.length || seq[i-1] != seq[i]))
            cdf.push([seq[i-1], y]);
        if (i != seq.length && (i == 0 || seq[i-1] != seq[i]))
            cdf.push([seq[i], y]);
    }
    cdf.cdf = true;
    return cdf;
}


function expand_extent(e, args) {
    let l = e[0], h = e[1];
    if (l > 0 && l < h / 11) {
        l = 0;
    } else if (l > 0 && args.discrete) {
        l -= 0.5;
    }
    if (h - l < 10) {
        const delta = Math.min(1, h - l) * 0.2;
        if (args.orientation !== "y" || l > 0) {
            l -= delta;
        }
        h += delta;
    }
    if (args.discrete) {
        h += 0.5;
    }
    return [l, h];
}


/** Emit one laid-out axis into `g`. `layout_axis` has already chosen the
 * ticks, their positions, their possibly-shortened text, and whether they
 * tilt; this only draws them.
 * @param {*} g
 * @param {object} axis
 * @param {string} side
 * @param {object} args */
function draw_axis(g, axis, side, args) {
    const lay = axis.layout, xside = side === "x";
    if (lay.widelabel) {
        g.attr("class", g.attr("class") + " widelabel");
    }
    // the X axis draws a baseline; the Y axis never has
    if (xside) {
        g.append("path").attr("d", "M0,0H" + args.plotWidth);
    }
    for (const t of lay.ticks) {
        const tg = g.append("g").attr("class", "tick")
            .attr("transform", xside ? "translate(" + t.pos + ",0)"
                : "translate(0," + t.pos + ")");
        tg.append("line").attr(xside ? "y2" : "x2", xside ? TICK_SIZE : -TICK_SIZE);
        const txt = tg.append("text").text(t.text);
        if (!xside) {
            txt.attr("text-anchor", "end")
                .attr("x", -Y_TICK_TEXT_OFFSET).attr("dy", "0.32em");
        } else if (lay.tilt) {
            txt.attr("text-anchor", "end")
                .attr("dx", -X_TICK_TILT_DX).attr("dy", X_TICK_TILT_DY);
        } else {
            // baseline one ascent down, so the ink starts exactly
            // `X_TICK_TEXT_OFFSET` below the axis line
            txt.attr("text-anchor", "middle")
                .attr("y", X_TICK_TEXT_OFFSET + lay.ascent);
        }
        if (t.classes) {
            txt.attr("class", t.classes);
        }
        if (t.fill) {
            txt.style("fill", t.fill);
        }
        if (t.bg) {
            const b = txt.node().getBBox();
            tg.insert("rect", "text")
                .attr("x", b.x - 3).attr("y", b.y)
                .attr("width", b.width + 6).attr("height", b.height + 1)
                .attr("class", "glab " + t.bg)
                .style("fill", ensure_pattern(t.bg, "glab"));
        }
        if (t.text !== t.full) {
            txt.append("title").text(t.full);
        }
        if (lay.tilt) {
            tg.selectAll("text, rect").attr("transform", `rotate(${-TILT_DEGREES})`);
        }
    }
}

/** Space claimed above the plot's top edge: the top half of the topmost Y
 * tick label, which is centered on that edge, plus the Y axis label and its
 * gap where there is one. `make_axis_pair` sets a derived top edge one inset
 * below the box top plus this much; `draw_axes` hangs the label from the edge
 * by the same amount, so the two agree however the edge was arrived at.
 * @param {object} args
 * @return {number} */
function y_head_room(args) {
    const h = args.labelMetrics.height;
    return Math.ceil(h / 2) + (args.y.label ? Math.ceil(h) + LABEL_GAP : 0);
}

/** @param {*} svg
 * @param {object} args */
function draw_axes(svg, args) {
    const parent = d3.select(svg.node().parentElement);

    const xaxe = parent.append("g")
        .attr("class", "hg-axis hg-axis-x")
        .attr("transform", `translate(${args.plotLeft},${args.plotBottom})`);
    draw_axis(xaxe, args.x, "x", args);
    if (args.x.label) {
        xaxe.append("text")
            .attr("class", "label")
            .attr("x", args.plotWidth)
            .attr("y", args.height - args.plotBottom - args.insetBottom
                - args.labelMetrics.descent)
            .attr("text-anchor", "end")
            .attr("pointer-events", "none")
            .text(args.x.label)
            .append("tspan")
            .attr("class", "arrow")
            .text(" \u2192");
    }

    const yaxe = parent.append("g")
        .attr("class", "hg-axis hg-axis-y")
        .attr("transform", `translate(${args.plotLeft},${args.plotTop})`);
    draw_axis(yaxe, args.y, "y", args);
    if (args.y.label) {
        const uparrow = d3.create("svg:tspan")
            .attr("class", "arrow")
            .text("\u2191 ");
        // Placed against the plot, not the box: left-aligned with the leftmost
        // tick ink but never past the box's left inset, and hung from the
        // plot's top edge by `y_head_room`. Where those edges are derived the
        // label lands exactly on the top and left inset lines; where they are
        // pinned it follows the plot.
        yaxe.append("text")
            .attr("class", "label")
            .attr("x", Math.max(-args.y.layout.ink, args.insetLeft - args.plotLeft))
            .attr("y", args.labelMetrics.ascent - y_head_room(args))
            .attr("text-anchor", "start")
            .attr("pointer-events", "none")
            .text(args.y.label)
            .each(function () {
                this.insertBefore(uparrow.node(), this.firstChild);
            });
    }

    place_x_axis_label(xaxe, args);
}

function draw_annotations(svg, args) {
    for (const anno of args.annotations || []) {
        if (anno.type === "xline") {
            const x = args.x.scale(anno.x);
            svg.append("line")
                .attr("class", "gxline")
                .attr("x1", x)
                .attr("y1", args.y.scale(args.y.scale.domain()[0]))
                .attr("x2", x)
                .attr("y2", args.y.scale(args.y.scale.domain()[1]));
        }
    }
}

function proj0(d) {
    return d[0];
}

function proj1(d) {
    return d[1];
}

function projx(d) {
    return d.x;
}

function projy(d) {
    return d.y;
}

function id2pid(id) {
    if (typeof id === "string") {
        return parseInt(id, 10);
    }
    return id;
}

function pid_sorter(a, b) {
    if (typeof a === "object") {
        a = a.id || a[2];
    }
    if (typeof b === "object") {
        b = b.id || b[2];
    }
    const d = id2pid(a) - id2pid(b);
    return d ? d : (a < b ? -1 : (a == b ? 0 : 1));
}

function render_pid_p(ps, cc) {
    ps.sort(pid_sorter);
    const e = $e("p");
    for (let i = 0; i !== ps.length; ++i) {
        let p = ps[i], cx = cc, rest = "";
        if (typeof p === "object") {
            if (p.id) {
                rest = p.rest;
                cx = p.cc;
                p = p.id;
            } else {
                cx = p[3];
                p = p[2];
            }
        }
        const comma = i === ps.length - 1 ? "" : ",";
        let pe = "#" + p;
        if (cx) {
            ensure_pattern(cx);
            pe = $e("span", cx, pe);
        }
        i > 0 && e.append(" ");
        if (rest || cx) {
            e.append($e("span", "nw", pe, rest, comma));
        } else {
            e.append(pe + comma);
        }
    }
    e.normalize();
    return e;
}

function clicker(pids, event) {
    if (!pids) {
        return;
    }
    if (typeof pids !== "object") {
        pids = [pids];
    }
    let x = [], last_review = null;
    for (let p of pids) {
        if (typeof p === "object") {
            p = p.id;
        }
        if (typeof p === "string") {
            last_review = p;
            p = parseInt(p, 10);
        }
        x.push(p);
    }
    if (x.length === 1 && pids.length === 1 && last_review !== null) {
        clicker_go(hoturl("paper", {p: x[0], anchor: "r" + last_review}), event);
    } else if (x.length === 1) {
        clicker_go(hoturl("paper", {p: x[0]}), event);
    } else {
        x = Array.from(new Set(x).values());
        x.sort(pid_sorter);
        clicker_go(hoturl("search", {q: x.join(" ")}), event);
    }
}

function make_reviewer_clicker(email) {
    return function (event) {
        clicker_go(hoturl("search", {q: "re:" + email}), event);
    };
}

function clicker_go(url, event) {
    if (event && event.metaKey) {
        window.open(url, "_blank", "noopener");
    } else {
        window.location = url;
    }
}

const default_value_format = d3.format(",.4~f");

// An axis class supplies `tick_values(scale)` (which values get ticks, at the
// range the scale has just been given), `value_format(v)` (their text), and
// optionally `tick_decoration(v)` (classes, fill, background). Everything
// else — measuring, thinning, shortening, positioning, drawing — is generic
// and lives in `layout_axis` / `draw_axis`.
const default_axis_class = {
    scale_class: null,
    tick_values: function (scale) {
        const domain = scale.domain();
        if (Math.abs(domain[1] - domain[0]) < 0.01
            && this.value_format === default_value_format) {
            this.value_format = d3.format(",~f");
        }
        return scale.ticks();
    },
    value_format: default_value_format,
    color_classes: () => null,
    value_search: () => null,
    value_render: function (e, v) {
        e.append(this.value_format(v));
    },
    discrete: false,
    left_justify: false,
    projection: null
};

function ordinal_axis_class(ax) {
    // `ticks` is a list of {value, text, color_classes?, search?} in display
    // order; data values are mapped to display positions by `projection`
    // and `project_data` before rendering
    const projection = new Map(), ticks = ax.ticks;
    ticks.forEach(function (t, i) {
        projection.set(t.value, i + 1);
    });
    const want_tilt = ax.orientation === "x"
        && (ticks.length > 30
            || d3.max(ticks.map(function (t) { return (t.text || "").length; })) > 4),
        want_mclasses = ticks.some(function (t) { return t.color_classes; });

    function mtext(pos) {
        const t = ticks[pos - 1];
        return t ? t.text : "";
    }
    function mclasses(pos) {
        const t = ticks[pos - 1];
        return (t && t.color_classes) || "";
    }

    return {
        scale_class: "ordinal",
        // One tick per ordinal entry. `scale.ticks(n)` used to be asked for
        // these, but it returns "nice" values, so positions between entries
        // could come back and render as blank ticks.
        tick_values: function () {
            return ticks.map((t, i) => i + 1);
        },
        want_tilt: want_tilt,
        tick_decoration: want_mclasses ? function (pos) {
            const c = mclasses(pos);
            return c ? {classes: c + " taghh",
                        bg: /\btagbg\b/.test(c) ? c : null} : null;
        } : null,
        value_format: mtext,
        color_classes: mclasses,
        value_search: function (pos) {
            const t = ticks[pos - 1];
            return (t && t.search) || null;
        },
        value_render: function (e, value, include_numeric) {
            const fvalue = Math.round(value), t = ticks[fvalue - 1];
            if (Math.abs(value - fvalue) <= 0.05 && t) {
                e.append(t.color_classes ? $e("span", t.color_classes + " taghh", t.text) : t.text);
                if (include_numeric
                    && value !== fvalue
                    && typeof value === "number") {
                    e.append(" (" + value.toFixed(2) + ")");
                }
            }
        },
        discrete: true,
        projection: projection,
        left_justify: ax.left_justify ?? true
    };
}

const score_format = d3.format(",.2~f");

function score_axis_class(rf) {
    let numeric_format = score_format;
    function value_format(v) {
        let vt = rf.unparse_symbol(v);
        return typeof vt === "number" ? numeric_format(v) : v;
    }
    return {
        scale_class: "review_field",
        tick_values: function (scale) {
            const domain = scale.domain();
            let count = Math.floor(domain[1] * 2) - Math.ceil(domain[0] * 2) + 1;
            if (count > 11) {
                count = Math.floor(domain[1]) - Math.ceil(domain[0]) + 1;
            }
            if (Math.abs(domain[1] - domain[0]) < 0.1) {
                numeric_format = d3.format(",~f");
            }
            return rf.default_numeric ? scale.ticks() : scale.ticks(count);
        },
        tick_decoration: function (v) {
            return {classes: "sv " + rf.className(v), fill: rf.color(v)};
        },
        value_format: value_format,
        color_classes: function (v) {
            const k = rf.className(v);
            return k ? "sv " + k : null;
        },
        value_render: function (e, v, include_numeric) {
            let vt = value_format(v);
            const k = rf.className(v);
            e.append(k ? $e("span", "sv " + k, vt) : vt);
            if (include_numeric
                && !rf.default_numeric
                && v !== Math.round(v * 2) / 2) {
                e.append(" (" + numeric_format(v) + ")");
            }
        },
    };
}

function time_axis_class() {
    function format(value) {
        if (value < 1000000000) {
            value = Math.round(value / 8640) / 10;
            return value + "d";
        }
        const d = new Date(value * 1000);
        if (d.getHours() || d.getMinutes()) {
            return strftime("%Y-%m-%dT%R", d);
        }
        return strftime("%Y-%m-%d", d);
    }
    function fit_ticks(nscale, is_duration, range) {
        const maxticks = Math.max(2, Math.floor(Math.abs(range[1] - range[0]) / (is_duration ? 24 : 72)));
        let count = undefined;
        while (true) {
            const ticks = nscale.ticks(count);
            if (ticks.length <= maxticks || count === 1) {
                return ticks;
            }
            count = (count || maxticks) - 1;
        }
    }
    return {
        scale_class: "time",
        tick_values: function (scale) {
            const domain = scale.domain(),
                is_duration = Math.min(domain[0], domain[1]) < 1000000000,
                range = scale.range();
            if (is_duration) {
                const ddomain = [domain[0] / 86400, domain[1] / 86400],
                    nscale = d3.scaleLinear().domain(ddomain).range(range);
                return fit_ticks(nscale, is_duration, range).map(function (value) {
                    return value * 86400;
                });
            }
            const ddomain = [new Date(domain[0] * 1000), new Date(domain[1] * 1000)],
                nscale = d3.scaleTime().domain(ddomain).range(range);
            return fit_ticks(nscale, is_duration, range).map(function (value) {
                return value.getTime() / 1000;
            });
        },
        value_format: format,
        left_justify: true
    };
}

// Paper IDs: rendered as "#NNN" with no thousands separator.
function pid_axis_class() {
    function format(value) {
        return "#" + Math.round(value);
    }
    return {
        value_format: format,
        value_render: function (e, value) {
            e.append(format(value));
        }
    };
}

function instantiate_axis(ax) {
    // `ax` is a server FormulaGraphAxis JSON ({scale_class, ticks?, order?,
    // review_field?, ...}).
    const sc = ax && ax.scale_class;
    let info;
    if (ax && ax.ticks) {
        info = ordinal_axis_class(ax);
    } else if (sc === "review_field") {
        info = score_axis_class(hotcrp.make_review_field(ax.review_field));
    } else if (sc === "time") {
        info = time_axis_class();
    } else if (sc === "pid") {
        info = pid_axis_class();
    } else {
        info = {scale_class: sc || null};
    }
    const ax1 = Object.assign({}, ax, default_axis_class, info);
    if (!ax || !ax.order) {
        return ax1;
    }
    info = ordinal_axis_class({
        orientation: ax1.orientation,
        ticks: ax1.order.map(v => ({
            value: v, text: ax1.value_format(v),
            color_classes: ax1.color_classes(v)
        })),
        left_justify: ax1.left_justify ?? false
    });
    return Object.assign({}, ax, default_axis_class, info);
}

/** Choose and place the ticks for one axis at `length` px along it, shorten
 * any whose ink would reach more than `cap` px across it, and record the
 * result on the axis. Returns how far the tick ink actually reaches across
 * from the axis line — left of a Y axis, below an X axis. The caller adds the
 * inset that keeps that ink off the edge of the box.
 *
 * An upright X tick label is centered on its tick, so one near the end of the
 * axis reaches past it; `overhang_cap` is how far past a label may reach.
 * Ticks that would reach further are dropped, down to the last one, which an
 * axis keeps regardless. `axis.layout.overhang` reports how far the survivors
 * actually reach.
 *
 * Deliberately knows nothing about `expandable`: it reports what the ticks
 * need, and `make_axis_pair` decides whether to grow the box or clamp.
 * @param {object} axis
 * @param {string} side
 * @param {object} args
 * @param {*} scale
 * @param {number} length
 * @param {number} cap
 * @param {number} [overhang_cap]
 * @return {number} */
function layout_axis(axis, side, args, scale, length, cap, overhang_cap) {
    const xside = side === "x";
    scale.range(!axis.flip === !xside ? [length, 0] : [0, length]);
    axis.scale = scale;

    const values = axis.tick_values(scale),
        texts = values.map(v => axis.value_format(v));
    let widelabel = false,
        m = measure_texts(args.svg, texts, false);
    if (d3.max(m.widths) > 100) { // shrink the font before anything else
        widelabel = true;
        m = measure_texts(args.svg, texts, true);
    }
    const tilt = xside && !!axis.want_tilt;

    // Thin ticks that would collide along the axis. This has to happen before
    // the breadth is taken, or the margin reserves room for labels that are
    // then hidden.
    // `slack` is how much of a tick's room may be given up before thinning;
    // crowding a line of text vertically still reads, two labels running into
    // each other does not, so upright labels get none
    let per_tick, slack = 0.1;
    if (tilt) {
        per_tick = m.height * COSINE_TILT_RADIANS + 8;
    } else if (xside) {
        // upright labels run along the axis, so the widest one sets the room
        // every tick needs; each is centered on its tick, so neighbors stay
        // 8px apart even where two of that width meet
        per_tick = (d3.max(m.widths) || 0) + 8;
        slack = 0;
    } else {
        per_tick = m.height + 4;
    }
    const alternation =
        Math.max(1, Math.ceil(values.length * per_tick / length - slack));

    const out = [];
    for (let i = 0; i !== values.length; ++i) {
        if (alternation > 1 && i % alternation !== 1) {
            continue;
        }
        const t = {value: values[i], pos: scale(values[i]), full: texts[i],
                text: texts[i], width: m.widths[i]},
            dec = axis.tick_decoration && axis.tick_decoration(values[i]);
        if (dec) {
            t.classes = dec.classes;
            t.fill = dec.fill;
            t.bg = dec.bg;
        }
        out.push(t);
    }

    // only the survivors have to fit. A tilted label's breadth is its text
    // width projected onto the cross axis, so the cap inverts through the tilt
    let textcap;
    if (!xside) {
        textcap = cap - Y_TICK_TEXT_OFFSET;
    } else if (tilt) {
        textcap = tilted_label_width(cap, m.descent);
    } else {
        textcap = Infinity; // upright X labels collide sideways, and thinning
                            // has already spaced them out
    }
    truncate_ticks(args.svg, out, Math.min(textcap, MAX_LABEL_WIDTH), widelabel);

    // How far the labels reach past the end of the axis, once any that
    // exceed the allowance are gone. Only upright X labels do: a tilted one
    // runs up and to the left, and a Y label's width is its axis's business.
    let overhang = 0;
    if (xside && !tilt) {
        const room = overhang_cap ?? Infinity;
        for (let i = out.length - 1; i >= 0; --i) {
            const over = out[i].pos + out[i].width / 2 - length;
            // an axis keeps its last label even where there is no room for
            // it; with one tick left there is nothing for thinning to change,
            // so granting the room it asks for cannot start a cycle
            if (over > room && out.length > 1) {
                out.splice(i, 1);
            } else if (over > overhang) {
                overhang = over;
            }
        }
    }

    const maxw = out.length === 0 ? 0 : Math.max(...out.map(t => t.width)),
        ink = !xside ? Math.ceil(maxw) + Y_TICK_TEXT_OFFSET
            : (tilt ? tilted_label_depth(maxw, m.descent)
               : X_TICK_TEXT_OFFSET + m.height);
    axis.layout = {ticks: out, tilt: tilt, widelabel: widelabel,
        ascent: m.ascent, ink: ink, overhang: Math.ceil(overhang)};
    return ink;
}

/** Lay out both axes and settle the plot rectangle.
 *
 * In unexpandable plots, edges derived from insets are interdependent -- a
 * wider left margin narrows the plot, which thins the X ticks, which can
 * shorten the bottom margin and move the label the right edge makes room for,
 * which lengthens the plot, which un-thins the Y ticks, which widens the left
 * margin again -- so we can go for a couple rounds.
 * @param {object} args
 * @param {*} x
 * @param {*} y */
function make_axis_pair(args, x, y) {
    // both axis labels render in the axis font, so any string measures it
    args.labelMetrics = measure_texts(args.svg, [args.x.label || args.y.label || "0"]);
    const lm = args.labelMetrics,
        grow = args.expandable === "height",
        base_height = args.height,
        lheight = Math.ceil(lm.height),
        // the X axis label claims a band of its own below the tick labels
        // unless they tilt, in which case it tucks in beside the shorter ones
        label_room = args.x.label && !args.x.want_tilt ? lheight + LABEL_GAP : 0,
        // a growable box keeps the plot height it would have with upright tick
        // labels; anything deeper makes the box taller instead of the plot
        // shorter
        nominal_bottom = args.insetBottom + X_TICK_TEXT_OFFSET + lheight + label_room,
        pin_top = args.plotTop, pin_right = args.plotRight,
        pin_bottom = args.plotBottom, pin_left = args.plotLeft,
        // how far tick ink may reach from each axis line. A pin is a hard
        // limit on its side; an unpinned side gets the policy fraction.
        ycap = (pin_left ?? Math.floor(args.width * MAX_Y_LABEL_WIDTH_FRACTION))
            - args.insetLeft,
        xroom = grow ? Infinity
            : (pin_bottom != null ? base_height - pin_bottom
               : Math.floor(base_height * MAX_X_LABEL_HEIGHT_FRACTION)),
        xcap = xroom - args.insetBottom - label_room,
        top = pin_top ?? args.insetTop + y_head_room(args);
    let left = pin_left ?? 0,
        right = pin_right ?? args.width - args.insetRight,
        bmargin = nominal_bottom, settled = false,
        // Room granted past the end of the X axis for a label that overhangs
        // it, and the allowance `layout_axis` enforces. The room only grows
        // while the allowance is open, because giving room back widens the
        // plot, which can bring the wide label back and start the cycle over.
        // So the first round that asks for less closes the allowance at that
        // smaller figure, and from then on a label that will not fit it is
        // dropped rather than accommodated.
        overhang = 0, overhang_cap = Infinity;

    /** @param {number} b - bottom margin
     * @return {number} Y of the X axis line */
    function plot_bottom(b) {
        return pin_bottom ?? (grow ? base_height - nominal_bottom : base_height - b);
    }
    function lay_out() {
        args.plotHeight = plot_bottom(bmargin) - top;
        const yink = layout_axis(args.y, "y", args, y, args.plotHeight, ycap),
            nleft = pin_left ?? args.insetLeft + Math.min(yink, ycap);
        args.plotWidth = right - nleft;
        const nbmargin = Math.ceil(Math.min(
            layout_axis(args.x, "x", args, x, args.plotWidth, xcap, overhang_cap)
                + args.insetBottom + label_room, xroom)),
            need = args.x.layout.overhang;
        if (need > overhang) {
            overhang = need;
        } else if (need < overhang) {
            overhang = overhang_cap = need;
        }
        // the right inset measures to the rightmost ink, which is the end of
        // the axis line unless a tick label overhangs it
        const nright = pin_right ?? args.width - args.insetRight - overhang;
        settled = nleft === left && nbmargin === bmargin && nright === right;
        left = nleft;
        bmargin = nbmargin;
        right = nright;
    }

    for (let round = 0; round !== 3 && !settled; ++round) {
        lay_out();
    }
    if (!settled) {
        // no more room is on offer, so this pass drops whatever does not fit
        // and cannot ask for more
        overhang_cap = overhang;
        lay_out(); // one consistent pass at whatever it converged on
    }

    args.plotLeft = left;
    args.plotTop = top;
    args.plotRight = right;
    args.plotBottom = plot_bottom(bmargin);
    args.plotWidth = right - left;
    args.plotHeight = args.plotBottom - top;
    args.height = grow ? args.plotBottom + bmargin : base_height;
    d3.select(args.svg.node().parentElement).attr("height", args.height);
    args.svg.attr("transform", `translate(${left},${top})`);
}

function make_linear_scale(argextent, e) {
    if (argextent && argextent[0] != null) {
        e = [argextent[0], e[1]];
    }
    if (argextent && argextent[1] != null) {
        e = [e[0], argextent[1]];
    }
    return d3.scaleLinear().domain(e);
}

function render_position(aa, p, prefix) {
    const e = $e("span", "nw");
    if (prefix || aa.label) {
        e.append((prefix || "") + (aa.label ? aa.label + " " : ""));
    }
    aa.value_render(e, p, true);
    return e;
}


// args: {data: [{d: [ARRAY], label: STRING, className: STRING}],
//        x/y: {label: STRING, tickFormat: STRING}}
function graph_cdf(element, args) {
    const svg = this;

    // massage data
    let series = args.data;
    if (!series.length) {
        series = Object.values(series);
        series.sort(function (a, b) {
            return d3.ascending(a.priority || 0, b.priority || 0);
        });
    }
    series = series.filter(function (d) {
        return (d.d ? d.d : d).length > 0;
    });
    const data = series.map(function (d) {
        d = d.d ? d.d : d;
        return d.cdf ? d : seq_to_cdf(d, args.x.flip, !args.y.fraction);
    });

    // axis domains
    let xdomain = data.reduce(function (e, d) {
        e[0] = Math.min(e[0], d[0][0], d[d.length - 1][0]);
        e[1] = Math.max(e[1], d[0][0], d[d.length - 1][0]);
        return e;
    }, [Infinity, -Infinity]);
    xdomain = [xdomain[0] - (xdomain[1] - xdomain[0]) / 32,
               xdomain[1] + (xdomain[1] - xdomain[0]) / 32];
    const x = make_linear_scale(args.x.extent, xdomain),
        y = make_linear_scale(args.y.extent, [0, Math.ceil(d3.max(data, function (d) {
                return d[d.length - 1][1];
            }) * 10) / 10]);
    make_axis_pair(args, x, y);

    // lines
    const line = d3.line().x(function (d) {return x(d[0]);})
        .y(function (d) {return y(d[1]);});

    // CDF lines
    data.forEach(function (d, i) {
        var cl = series[i].className;
        if (d[d.length - 1][0] != xdomain[args.x.flip ? 0 : 1])
            d.push([xdomain[args.x.flip ? 0 : 1], d[d.length - 1][1]]);
        var p = svg.append("path").attr("data-index", i)
            .datum(d)
            .attr("class", "gcdf" + (cl ? " " + cl : ""))
            .style("stroke", ensure_pattern(series[i].className, "gcdf"))
            .attr("d", line);
        if (series[i].dashpattern)
            p.attr("stroke-dasharray", series[i].dashpattern.join(","));
    });

    svg.append("path").attr("class", "gcdf gcdf-hover0");
    svg.append("path").attr("class", "gcdf gcdf-hover1");
    const hovers = svg.selectAll(".gcdf-hover0, .gcdf-hover1");
    hovers.style("display", "none");

    draw_axes(svg, args);
    draw_annotations(svg, args);

    if (args.interactive !== false) {
        svg.append("rect")
            .attr("x", -args.plotLeft)
            .attr("width", args.plotWidth + args.plotLeft)
            .attr("height", args.height - args.plotTop)
            .attr("fill", "none")
            .attr("pointer-events", "all")
            .on("mouseover", mousemoved)
            .on("mousemove", mousemoved)
            .on("mouseout", mouseout)
            .on("click", mouseclick);
    }

    var hovered_path, hovered_series, hubble;
    function mousemoved(event) {
        var m = d3.pointer(event), p = {distance: 16};
        m.clientX = event.clientX;
        m.clientY = event.clientY;
        for (var i in data) {
            if (series[i].label || args.cdf_tooltip_position)
                p = closestPoint(svg.select("[data-index='" + i + "']").node(), m, p);
        }
        if (p.pathNode !== hovered_path) {
            if (p.pathNode) {
                i = p.pathNode.getAttribute("data-index");
                hovered_series = series[i];
                hovers.datum(data[i]).attr("d", line).style("display", null);
            } else {
                hovered_series = null;
                hovers.style("display", "none");
            }
            hovered_path = p.pathNode;
        }
        if (hovered_series && (hovered_series.label || args.cdf_tooltip_position)) {
            hubble = hubble || make_bubble({class: args.tooltip_class || "graphtip", "pointer-events": "none"});
            var dir = Math.abs(tangentAngle(p.pathNode, p.pathLength));
            if (args.cdf_tooltip_position) {
                const f = $frag();
                hovered_series.label && f.append(hovered_series.label + ": ");
                args.x.value_render(f, x.invert(p[0]), true);
                f.append(", ");
                args.y.value_render(f, y.invert(p[1]), true);
                hubble.replace_content(f);
            } else {
                hubble.text(hovered_series.label);
            }
            hubble.anchor(dir >= 0.25*Math.PI && dir <= 0.75*Math.PI ? "e" : "s")
                .at(p[0] + args.plotLeft, p[1], this);
        } else if (hubble) {
            hubble = hubble.remove() && null;
        }
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_path = hovered_series = hubble = null;
    }

    function mouseclick(evt) {
        if (hovered_series && hovered_series.click)
            hovered_series.click.call(this, evt);
    }
}

function procrastination_seq(ri) {
    const seq = [];
    for (const r of ri) {
        if (r[0] > 0)
            seq.push(r[0]);
    }
    seq.ntotal = ri.length;
    return seq;
}

function procrastination_filter(revdata) {
    const args = {gtype: "cdf", data: {}, x: {}, y: {}, tooltip_class: "graphtip-s"};

    // collect data
    const alldata = [];
    for (const cid in revdata.reviews) {
        const d = {d: revdata.reviews[cid], className: "gcdf-many"},
            u = revdata.users[cid];
        u && u.name && (d.label = u.name);
        u && u.email && (d.click = make_reviewer_clicker(u.email));
        if (cid && cid == siteinfo.user.uid) {
            d.className = "gcdf-highlight";
            d.priority = 1;
        } else if (u && u.light) {
            d.className += " gcdf-thin";
        }
        u && u.color_classes && (d.className += " " + u.color_classes);
        Array.prototype.push.apply(alldata, d.d);
        if (cid !== "conflicts") {
            args.data[cid] = d;
        }
    }
    args.data.all = {d: alldata, className: "gcdf-cumulative", priority: 2};

    // make cdfs
    for (const i in args.data) {
        args.data[i].d = seq_to_cdf(procrastination_seq(args.data[i].d));
    }

    args.x.label = "Date";
    args.x.scale_class = "time";
    args.y.label = "Fraction of assignments completed";
    args.annotations = [];
    for (const dl of revdata.deadlines) {
        if (!dl) {
            continue;
        }
        args.annotations.push({type: "xline", x: dl});
    }
    return args;
}


/* grouped quadtree */
// mark bounds of each node
function grouped_quadtree_mark_bounds(q, rf, ordinalf) {
    //ordinalf = ordinalf || (function () { var m = 0; return function () { return ++m; }; })();
    //q.ordinal = ordinalf();

    let b, p, i, n, ps;
    if (!q.length) {
        for (p = q.data, ps = []; p; p = p.next) {
            ps.push(p);
        }
        ps.sort(function (a, b) { return d3.ascending(a.n, b.n); });
        for (i = n = 0; i < ps.length; ++i) {
            ps[i].r0 = i ? ps[i-1].r : 0;
            n += ps[i].n;
            ps[i].r = rf(n);
        }
        q.maxr = ps[ps.length - 1].r;
        p = q.data;
        b = [p[1] - q.maxr, p[0] + q.maxr, p[1] + q.maxr, p[0] - q.maxr];
    } else {
        b = [Infinity, -Infinity, -Infinity, Infinity];
        for (i = 0; i < 4; ++i)
            if ((p = q[i])) {
                grouped_quadtree_mark_bounds(p, rf, ordinalf);
                b[0] = Math.min(b[0], p.bounds[0]);
                b[1] = Math.max(b[1], p.bounds[1]);
                b[2] = Math.max(b[2], p.bounds[2]);
                b[3] = Math.min(b[3], p.bounds[3]);
            }
    }
    q.bounds = b;
}

function grouped_quadtree_gfind(point, min_distance) {
    var closest = null;
    if (min_distance == null)
        min_distance = Infinity;
    function visitor(node) {
        if (node.bounds[0] > point[1] + min_distance
            || node.bounds[1] < point[0] - min_distance
            || node.bounds[2] < point[1] - min_distance
            || node.bounds[3] > point[0] + min_distance)
            return true;
        if (node.length)
            return;
        let p = node.data;
        const dx = p[0] - point[0], dy = p[1] - point[1];
        if (Math.abs(dx) - node.maxr < min_distance
            || Math.abs(dy) - node.maxr < min_distance) {
            const dd = Math.sqrt(dx * dx + dy * dy);
            for (; p; p = p.next) {
                const d = Math.max(dd - p.r, 0);
                if (d < min_distance || (d == 0 && p.r < closest.r))
                    closest = p, min_distance = d;
            }
        }
    }
    this.visit(visitor);
    return closest;
}

function grouped_quadtree(data, xs, ys, rf, expand) {
    function make_extent() {
        const xe = xs.range(), ye = ys.range();
        return [[Math.min(xe[0], xe[1]), Math.min(ye[0], ye[1])],
                [Math.max(xe[0], xe[1]), Math.max(ye[0], ye[1])]];
    }
    const q = d3.quadtree().extent(make_extent()), nd = [];

    for (const d of data) {
        if (d[0] == null || d[1] == null) {
            continue;
        }
        const vd = {
            "0": xs(d[0]),
            "1": ys(d[1]),
            d0: d[0],
            d1: d[1],
            data: [d],
            cc: d[3],
            next: null,
            head: null,
            n: expand ? d[2].length : 1,
            i: nd.length,
            r0: null,
            r: null,
            ur: null
        };
        let vp = q.find(vd[0], vd[1]);
        if (vp) {
            const dx = Math.abs(vp[0] - vd[0]),
                dy = Math.abs(vp[1] - vd[1]);
            // group points within 2px of display space,
            // and within 1 of each coordinate
            if (dx > 2
                || dy > 2
                || Math.abs(d[0] - vp.d0) >= 1
                || Math.abs(d[1] - vp.d1) >= 1
                || dx * dx + dy * dy > 4) {
                vp = null;
            }
        }
        while (vp && vp.cc != vd.cc && vp.next) {
            vp = vp.next;
        }
        if (vp && vp.cc == vd.cc) {
            vp.data.push(d);
            vp.n += vd.n;
        } else {
            if (vp) {
                vp.next = vd;
                vd.head = vp.head || vp;
            } else {
                q.add(vd);
            }
            nd.push(vd);
        }
    }

    if (rf == null) {
        rf = Math.sqrt;
    } else if (typeof rf === "number") {
        rf = (function (f) {
            return function (n) { return Math.sqrt(n) * f; };
        })(rf);
    }
    if (q.root()) {
        grouped_quadtree_mark_bounds(q.root(), rf);
    }

    delete q.add;
    q.gfind = grouped_quadtree_gfind;
    return {data: nd, quadtree: q};
}

function gqdata_ids(gqp, want_cc) {
    const a = [], cch = gqp.cc;
    for (; gqp; gqp = gqp.next) {
        for (const d of gqp.data) {
            const ids = typeof d[2] === "object" ? d[2] : [d[2]];
            if (want_cc && cch !== d[3]) {
                for (const id of ids) {
                    a.push({id: id, cc: d[3]});
                }
            } else {
                a.push(...ids);
            }
        }
    }
    return a;
}

function ungroup_data(data) {
    if ($.isArray(data)) {
        return data;
    }
    for (const style in data) {
        if (style && style !== "none") {
            data[style].forEach(d => d.push(style));
        }
    }
    return d3.merge(Object.values(data));
}

function project_axis(data, type, col, projection) {
    if (type === "cdf") {
        for (const s of data) {
            const dv = s.d;
            let n = dv.length;
            for (let i = 0; i !== n; ) {
                const nv = projection.get(dv[i]);
                if (nv == null) {
                    dv[i] = dv[n - 1];
                    dv.pop();
                    --n;
                } else {
                    dv[i] = nv;
                    ++i;
                }
            }
        }
    } else if (type === "style_xyi") {
        for (const g of Object.values(data)) {
            project_axis(g, "xyi", col, projection);
        }
    } else {
        let n = data.length;
        for (let i = 0; i !== n; ) {
            const nv = projection.get(data[i][col]);
            if (nv == null) {
                data[i] = data[n - 1];
                data.pop();
                --n;
            } else {
                data[i][col] = nv;
                ++i;
            }
        }
    }
}

// Rewrite `args.data` in place, mapping each ordinal axis's identities to
// positions. Runs once per graph before rendering; highlight refreshes call
// project_axis() directly against the original axisinfo.
function project_data(args, axes) {
    if (axes.x.projection) {
        project_axis(args.data, args.data_format, 0, axes.x.projection);
    }
    if (axes.y.projection) {
        project_axis(args.data, args.data_format, 1, axes.y.projection);
    }
}

const scatter_annulus = d3 ? d3.arc()
    .innerRadius(function (d) { return d.r0 ? d.r0 - 0.5 : 0; })
    .outerRadius(function (d) { return d.r - 0.5; })
    .startAngle(0)
    .endAngle(Math.PI * 2) : null;

const scatter_union_annulus = d3 ? d3.arc()
    .outerRadius(function (d) { return (d.ur || d.r) - 0.5; })
    .startAngle(0)
    .endAngle(Math.PI * 2) : null;

function scatter_transform(d) {
    return "translate(" + d[0] + "," + d[1] + ")";
}

function scatter_key(d) {
    return d[0] + "," + d[1] + "," + d.r;
}

function scatter_create(svg, gqdata, klass) {
    let sel = svg.selectAll(".gdot");
    if (klass)
        sel = sel.filter("." + klass);
    sel = sel.data(gqdata, scatter_key);
    sel.exit().remove();
    const pathklass = "gdot" + (klass ? " " + klass : "");
    sel.enter()
        .append("path")
        .attr("class", function (d) { return pathklass + (d.cc ? " " + d.cc : "") })
        .style("fill", function (d) { return ensure_pattern(d.cc, "gdot"); })
      .merge(sel)
        .attr("d", scatter_annulus)
        .attr("transform", scatter_transform);
    return sel;
}

function highlight_pattern() {
    if ($$("svggpat_dot_highlight")) {
        return;
    }
    $$("p-body").prepend($svg("svg", {width: 0, height: 0, "class": "position-absolute"},
        $svg("defs", null,
            $svg("radialGradient", {id: "svggpat_dot_highlight"},
                $svg("stop", {offset: "50%", "stop-opacity": 0}),
                $svg("stop", {offset: "50%", "stop-color": "#ffff00", "stop-opacity": 0.5}),
                $svg("stop", {offset: "100%", "stop-color": "#ffff00", "stop-opacity": 0})))));
}

function highlight_update(svg, data, keyfunc, klass) {
    highlight_pattern();
    let sel = svg.selectAll(".ghighlight");
    if (klass) {
        sel = sel.filter("." + klass);
    }
    sel = sel.data(data, keyfunc);
    sel.exit().remove();
    let g = sel.enter()
      .append("g")
        .attr("class", "ghighlight" + (klass ? " " + klass : ""));
    g.append("circle")
        .attr("class", "gdot-hover");
    g.append("circle")
        .style("fill", "url(#svggpat_dot_highlight)");
    return g.merge(sel).selectAll("circle");
}

function scatter_highlight(svg, data, klass) {
    highlight_update(svg, data, scatter_key, klass)
        .attr("cx", proj0)
        .attr("cy", proj1)
        .attr("r", function (d, i) {
            return i ? (d.r + 0.5) * 2 : d.r - 0.5;
        });
}

function scatter_union(p) {
    if (!p) {
        return null;
    }
    if (p.head) {
        p = p.head;
    }
    if (!p.next || p.ur != null) {
        return p;
    }
    p.ur = p.r;
    for (let pp = p.next; pp; pp = pp.next) {
        p.ur = Math.max(p.ur, pp.r);
    }
    return p;
}

function make_hover_interactor(svg, hovers, identity) {
    let data = null, over = null;
    function mouseout() {
        hovers.style("display", "none");
        if (self.bubble) {
            self.bubble.remove();
        }
        self.data = self.bubble = data = over = null;
        svg.style("cursor", null);
    }
    const self = {
        data: null,
        bubble: null,
        move: function (d) {
            if (!d && data) {
                mouseout();
            }
            if (!d || (data && (identity ? identity(d, data) : d === data))) {
                over = d;
                return false;
            }
            self.data = data = d;
            self.bubble = self.bubble || make_bubble({class: "graphtip", "pointer-events": "none"});
            svg.style("cursor", "pointer");
            return true;
        },
        mouseout: mouseout,
        mouseout_soon: function () {
            if (!data) {
                return;
            }
            const kill = data;
            over = null;
            setTimeout(function () {
                if (data === kill && !over)
                    mouseout();
            }, 10);
        }
    };
    return self;
}

function graph_scatter(element, args) {
    const svg = this;
    let data = ungroup_data(args.data);
    const x = make_linear_scale(args.x.extent, expand_extent(d3.extent(data, proj0), args.x)),
        y = make_linear_scale(args.y.extent, expand_extent(d3.extent(data, proj1), args.y));
    make_axis_pair(args, x, y);

    $(element).on("hotgraphhighlight", highlight);

    const gq = grouped_quadtree(data, x, y, 4, args.data_format === "xyis");
    data = null;
    scatter_create(svg, gq.data);

    svg.append("path").attr("class", "gdot gdot-hover");
    const hovers = svg.selectAll(".gdot-hover").style("display", "none"),
        hoverer = make_hover_interactor(svg, hovers);

    draw_axes(svg, args);
    draw_annotations(svg, args);

    if (args.interactive !== false) {
        svg.append("rect")
            .attr("x", -args.plotLeft)
            .attr("width", args.plotWidth + args.plotLeft)
            .attr("height", args.height - args.plotTop)
            .attr("fill", "none")
            .attr("pointer-events", "all")
            .on("mouseover", mousemoved)
            .on("mousemove", mousemoved)
            .on("mouseout", hoverer.mouseout_soon)
            .on("click", mouseclick);
    }

    function make_tooltip(p) {
        const pinstance = p.data[0];
        return [
            $e("p", null, render_position(args.x, pinstance[0]), ", ", render_position(args.y, pinstance[1])),
            render_pid_p(gqdata_ids(p, true), p.cc)
        ];
    }

    function mousemoved(event) {
        let m = d3.pointer(event), p = scatter_union(gq.quadtree.gfind(m, 4));
        if (!hoverer.move(p)) {
            return;
        }
        hovers.datum(p)
            .attr("d", scatter_union_annulus)
            .attr("transform", scatter_transform)
            .style("display", null);
        hoverer.bubble.replace_content(...make_tooltip(p))
            .anchor("s")
            .near(hovers.node());
    }

    function mouseclick(event) {
        clicker(hoverer.data ? gqdata_ids(hoverer.data) : null, event);
    }

    function highlight(event) {
        if (!event.ids) {
            if (event.q && event.ok) {
                $.getJSON(hoturl("api/search", {q: event.q, forceShow: 1}), null, highlight);
            }
            return;
        }
        hoverer.mouseout();
        let myd = [];
        if (event.ids.length) {
            myd = gq.data.filter(function (pd) {
                for (const d of pd.data) {
                    if (event.ids.indexOf(id2pid(d[2])) >= 0) {
                        return true;
                    }
                }
                return false;
            });
        }
        scatter_highlight(svg, myd);
    }
}

const DOT_RADIUS = 5;

function dot_highlight(svg, data, klass) {
    highlight_update(svg, data, d => d.id, klass)
        .attr("cx", projx)
        .attr("cy", projy)
        .attr("r", d => d.r - 0.5);
}

const DOT_LABEL_MAX = 6;

function dot_label_class(d) {
    // longer labels get their own class, so CSS can condense them further;
    // `gdot-label-DOT_LABEL_MAX` means "that many characters or more"
    const n = Math.min(d.label.length, DOT_LABEL_MAX);
    return n > 2 ? "gdot-label gdot-label-" + n : "gdot-label";
}

// Label each datum with its pid, then give every dot one radius: the pid is
// not data, so it must not vary a dot's visual weight. Labels of the same
// length render at the same width, so one measurement per length suffices.
function assign_dot_labels(svg, data) {
    const text = svg.append("text"),
        widths = [];
    let width = 0;
    for (const d of data) {
        d.label = "" + d.id;
        const n = d.label.length;
        if (widths[n] === undefined) {
            text.attr("class", dot_label_class(d)).text(d.label);
            widths[n] = text.node().getComputedTextLength();
        }
        width = Math.max(width, widths[n]);
    }
    text.remove();
    const r = Math.max(DOT_RADIUS, width / 2 + 3);
    for (const d of data) {
        d.r = r;
    }
}

function data_pidcode(data) {
    const p = [];
    for (const d of data) {
        if (typeof d[2] === "object") {
            p.push(...d[2]);
        } else {
            p.push(d[2]);
        }
    }
    return hotcrp.pidcode(p);
}

function load_titles(titles, pidcode, success) {
    $.getJSON(hoturl("api/search", {q: "pidcode:" + pidcode, f: "title", format: "json", forceShow: 1}), null,
        function (d) {
            for (const pd of d.papers || []) {
                titles[pd.pid] = pd.title;
            }
            success();
        });
}

function graph_dot(element, args) {
    const svg = this;
    let data = ungroup_data(args.data);
    const pidcode = data_pidcode(data),
        x = make_linear_scale(args.x.extent, expand_extent(d3.extent(data, proj0), args.x)),
        y = make_linear_scale(args.y.extent, expand_extent(d3.extent(data, proj1), args.y));
    make_axis_pair(args, x, y);
    data = data.map(d => {
        const xv = x(d[0]), yv = y(d[1]);
        return {"0": d[0], "1": d[1], x: xv, x0: xv, y: yv, y0: yv, id: d[2], cc: d[3], r: DOT_RADIUS};
    });
    const labeled = args.gtype === "ldot";
    if (labeled) {
        assign_dot_labels(svg, data);
    }

    const sim = d3.forceSimulation(data)
        .force("collide", d3.forceCollide(d => d.r + 1))
        .force("x", d3.forceX(d => d.x0).strength(0.05))
        .force("y", d3.forceY(d => d.y0).strength(0.05))
        .stop();
    sim.tick(Math.ceil(Math.log(sim.alphaMin()) / Math.log(1 - sim.alphaDecay())));

    $(element).on("hotgraphhighlight", highlight);

    svg.append("circle").attr("class", "gdot gdot-hover");
    const hovers = svg.selectAll(".gdot-hover")
            .attr("r", DOT_RADIUS)
            .style("display", "none"),
        hoverer = make_hover_interactor(svg, hovers);

    draw_axes(svg, args);
    draw_annotations(svg, args);

    // A labeled dot is two elements sharing one position, so group them and
    // place the group; a plain dot is just the circle and needs no wrapper.
    const enter = svg.selectAll(labeled ? ".gdot-g" : ".gdot:not(.gdot-hover)")
        .data(data)
        .enter();
    let target;
    if (labeled) {
        target = enter.append("g")
            .attr("class", d => "gdot-g" + (d.cc ? " " + d.cc : ""))
            .attr("transform", d => "translate(" + projx(d) + "," + projy(d) + ")");
        target.append("circle")
            .attr("r", d => d.r)
            .attr("class", d => "gdot" + (d.cc ? " " + d.cc : ""))
            .style("fill", d => ensure_pattern(d.cc, "gdot"));
        target.append("text")
            .attr("class", dot_label_class)
            .attr("dy", "0.35em")
            .text(d => d.label);
    } else {
        target = enter.append("circle")
            .attr("cx", projx)
            .attr("cy", projy)
            .attr("r", d => d.r)
            .attr("class", d => "gdot" + (d.cc ? " " + d.cc : ""))
            .style("fill", d => ensure_pattern(d.cc, "gdot"));
    }
    if (args.interactive !== false) {
        target.on("mouseover", mouseover)
            .on("mouseout", hoverer.mouseout_soon)
            .on("click", mouseclick);
    }

    let titles = null;

    function make_tooltip(p) {
        const a = [
            $e("p", null, render_position(args.x, p[0]), ", ", render_position(args.y, p[1])),
            render_pid_p([p], p.cc)
        ];
        if (titles && titles[p.id]) {
            a[1].append(" " + titles[p.id]);
        }
        return a;
    }

    function show_bubble() {
        if (hoverer.data) {
            hoverer.bubble.replace_content(...make_tooltip(hoverer.data))
                .anchor("s")
                .near(hovers.node());
        }
    }

    function mouseover(event, p) {
        if (!hoverer.move(p)) {
            return;
        }
        hovers.datum(p)
            .attr("cx", projx)
            .attr("cy", projy)
            .attr("r", d => d.r)
            .style("display", null);
        show_bubble();
        if (titles === null) {
            titles = [];
            load_titles(titles, pidcode, show_bubble);
        }
    }

    function mouseclick(event) {
        const p = d3.select(this).datum();
        clicker(p ? p.id : null, event);
    }

    function highlight(event) {
        if (!event.ids) {
            if (event.q && event.ok) {
                $.getJSON(hoturl("api/search", {q: event.q, forceShow: 1}), null, highlight);
            }
            return;
        }
        hoverer.mouseout();
        let myd = [];
        if (event.ids.length) {
            myd = data.filter(function (d) {
                return event.ids.indexOf(id2pid(d.id)) >= 0;
            });
        }
        dot_highlight(svg, myd);
    }
}

function data_quantize_x(data) {
    data = ungroup_data(data);
    if (!data.length) {
        return data;
    }
    data.sort(function (a, b) { return d3.ascending(a[0], b[0]); });
    const epsilon = (data[data.length - 1][0] - data[0][0]) / 5000;
    let active = null;
    for (const d of data) {
        if (active !== null && Math.abs(active - d[0]) <= epsilon) {
            d[0] = active;
        } else {
            active = d[0];
        }
    }
    return data;
}

function data_to_barchart(data, yaxis) {
    data = data_quantize_x(data);
    if (!data.length) {
        return data;
    }

    data.sort(function (a, b) {
        return d3.ascending(a[0], b[0])
            || d3.ascending(a[4] || 0, b[4] || 0)
            || (a[3] || "").localeCompare(b[3] || "");
    });

    let last = null;
    const ndata = [];
    for (const d of data) {
        if (d[1] == null) {
            continue;
        }
        const cur = {
            "0": d[0],
            "1": d[1],
            ids: d[2],
            yoff: 0,
            i0: ndata.length,
            cc: d[3],
            sx: d[4]
        };
        ndata.push(cur);
        if (last && cur[0] == last[0] && (cur.sx == last.sx || yaxis.fraction)) {
            cur.yoff = last.yoff + last[1];
            cur.i0 = last.i0;
        }
        last = cur;
    }

    if (!yaxis.fraction) {
        return ndata;
    }

    if (ndata.some(function (d) { return d.sx != data[0].sx; })) {
        let maxy = {};
        ndata.forEach(function (d) { maxy[d[0]] = d[1] + d.yoff; });
        ndata.forEach(function (d) { d.yoff /= maxy[d[0]]; d[1] /= maxy[d[0]]; });
    } else {
        let maxy = 0;
        ndata.forEach(function (d) { maxy += d[1]; });
        ndata.forEach(function (d) { d.yoff /= maxy; d[1] /= maxy; });
    }
    return ndata;
}

function graph_bars(element, args) {
    const svg = this,
        bdata = data_to_barchart(args.data, args.y);

    const ystart = args.y.scale_class === "review_field" ? 0.75 : 0,
        xe = d3.extent(bdata, proj0),
        ge = d3.extent(bdata, function (d) { return d.sx || 0; }),
        ye = [d3.min(bdata, function (d) { return Math.max(d.yoff, ystart); }),
              d3.max(bdata, function (d) { return d.yoff + d[1]; })],
        deltae = d3.extent(bdata, function (d, i) {
            const delta = i ? d[0] - bdata[i-1][0] : 0;
            return delta || Infinity;
        }),
        x = make_linear_scale(args.x.extent, expand_extent(xe, args.x)),
        y = make_linear_scale(args.y.extent, ye);
    make_axis_pair(args, x, y);

    const dpr = window.devicePixelRatio || 1;
    let barwidth = args.plotWidth / 20;
    if (deltae[0] != Infinity) {
        barwidth = Math.min(barwidth, Math.abs(x(xe[0] + deltae[0]) - x(xe[0])));
    }
    barwidth = Math.max(5, barwidth);
    if (ge[1]) {
        barwidth = Math.floor((barwidth - 3) * dpr) / (dpr * (ge[1] + 1));
    }
    const gdelta = -(ge[1] + 1) * barwidth / 2;

    function place(sel, close) {
        close = close || "";
        return sel.attr("d", function (d) {
            const yoff = Math.max(d.yoff, ystart),
                x0 = x(d[0]) + gdelta + (d.sx ? barwidth * d.sx : 0),
                y0 = y(yoff),
                y1 = y(d.yoff + d[1]);
            return `M${x0},${y0}V${y1}h${barwidth}V${y0}${close}`;
        });
    }

    place(svg.selectAll(".gbar").data(bdata)
          .enter().append("path")
            .attr("class", function (d) {
                return d.cc ? "gbar " + d.cc : "gbar";
            })
            .style("fill", function (d) { return ensure_pattern(d.cc, "gdot"); }));

    draw_axes(svg, args);

    svg.append("path").attr("class", "gbar gbar-hover0");
    svg.append("path").attr("class", "gbar gbar-hover1");
    const hovers = svg.selectAll(".gbar-hover0, .gbar-hover1")
            .style("display", "none")
            .attr("pointer-events", "none"),
        hoverer = make_hover_interactor(svg, hovers, function (d1, d2) {
            return d1.i0 === d2.i0;
        });

    if (args.interactive !== false) {
        svg.selectAll(".gbar")
            .on("mouseover", mouseover)
            .on("mouseout", hoverer.mouseout_soon)
            .on("click", mouseclick);
    }

    function make_tooltip(p) {
        return [
            $e("p", null, render_position(args.x, p[0]), ", ", render_position(args.y, p[1])),
            render_pid_p(p.ids, p.cc)
        ];
    }

    function make_hovered_data(p) {
        const hd = {
            "0": p[0],
            "1": 0,
            ids: [],
            yoff: 0,
            cc: "",
            sx: p.sx,
            i0: p.i0
        };
        for (let i = p.i0, any = false, q;
             i !== bdata.length && (q = bdata[i]).i0 === p.i0;
             ++i) {
            if (q.sx !== p.sx) {
                continue;
            }
            if (!any) {
                hd.yoff = q.yoff;
                any = true;
            }
            hd[1] = q[1] + q.yoff - hd.yoff;
            for (let id of q.ids) {
                hd.ids.push(q.cc ? {id: id, cc: q.cc} : id);
            }
        }
        return hd;
    }

    function mouseover() {
        const p = d3.select(this).data()[0];
        if (!hoverer.move(p)) {
            return;
        }
        hoverer.data = make_hovered_data(p);
        place(hovers.datum(hoverer.data), "Z").style("display", null);
        hoverer.bubble.replace_content(...make_tooltip(hoverer.data))
            .anchor("h")
            .near(hovers.node());
    }

    function mouseclick(event) {
        clicker(hoverer.data ? hoverer.data.ids : null, event);
    }
}

function boxplot_sort(data) {
    data.sort(function (a, b) {
        return d3.ascending(a[0], b[0])
            || d3.ascending(a[1], b[1])
            || (a[3] || "").localeCompare(b[3] || "")
            || pid_sorter(a[2], b[2]);
    });
    return data;
}

function data_to_boxplot(data, septags) {
    data = boxplot_sort(data_quantize_x(data));

    let active = null;
    data = data.reduce(function (newdata, d) {
        if (!active || active[0] != d[0] || (septags && active.cc != d[3])) {
            active = {
                "0": d[0],
                ymin: d[1],
                ymax: 0,
                cc: d[3] || "",
                ys: [],
                ids: [],
                qnt: null,
                mean: null
            };
            newdata.push(active);
        } else if (active.cc != d[3]) {
            active.cc = "";
        }
        active.ymax = d[1];
        active.ys.push(d[1]);
        active.ids.push(d[2]);
        return newdata;
    }, []);

    data.map(function (d) {
        const l = d.ys.length, med = d3.quantile(d.ys, 0.5);
        if (l < BOXPLOT_IQR_MIN_N) {
            d.qnt = [d.ys[0], d.ys[0], med, d.ys[l-1], d.ys[l-1]];
        } else {
            const q1 = d3.quantile(d.ys, 0.25),
                q3 = d3.quantile(d.ys, 0.75),
                iqr = q3 - q1;
            d.qnt = [
                Math.max(d.ys[0], q1 - 1.5 * iqr),
                q1,
                med,
                q3,
                Math.min(d.ys[l-1], q3 + 1.5 * iqr)
            ];
        }
        d.mean = d3.sum(d.ys) / d.ys.length;
    });

    return data;
}

function graph_boxplot(element, args) {
    const data = data_to_boxplot(args.data, !!args.y.fraction, true),
        svg = this;

    const xe = d3.extent(data, proj0),
        ye = [d3.min(data, function (d) { return d.ymin; }),
              d3.max(data, function (d) { return d.ymax; })],
        deltae = d3.extent(data, function (d, i) {
            var delta = i ? d[0] - data[i-1][0] : 0;
            return delta || Infinity;
        }),
        x = make_linear_scale(args.x.extent, expand_extent(xe, args.x)),
        y = make_linear_scale(args.y.extent, expand_extent(ye, args.y));
    make_axis_pair(args, x, y);

    let barwidth = args.plotWidth / 80;
    if (deltae[0] != Infinity) {
        barwidth = Math.max(Math.min(barwidth, Math.abs(x(xe[0] + deltae[0]) - x(xe[0])) * 0.5), 6);
    }

    function place_whisker(l, sel) {
        sel.attr("x1", function (d) { return x(d[0]); })
            .attr("x2", function (d) { return x(d[0]); })
            .attr("y1", function (d) { return y(d.qnt[l]); })
            .attr("y2", function (d) { return y(d.qnt[l + 1]); });
    }

    function place_box(sel) {
        sel.attr("d", function (d) {
            const x0 = x(d[0]),
                yq2 = y(d.qnt[2]);
            let yq1 = y(d.qnt[1]), yq3 = y(d.qnt[3]);
            if (yq1 < yq3) {
                const tmp = yq3;
                yq3 = yq1;
                yq1 = tmp;
            }
            if (yq1 - yq3 < 4) {
                yq3 = yq2 - 2;
                yq1 = yq3 + 4;
            }
            yq3 = Math.min(yq3, yq2 - 1);
            yq1 = Math.max(yq1, yq2 + 1);
            return `M${x0 - barwidth / 2},${yq3}h${barwidth}v${yq1 - yq3}h${-barwidth}Z`;
        });
    }

    function place_median(sel) {
        sel.attr("x1", function (d) { return x(d[0]) - barwidth / 2; })
            .attr("x2", function (d) { return x(d[0]) + barwidth / 2; })
            .attr("y1", function (d) { return y(d.qnt[2]); })
            .attr("y2", function (d) { return y(d.qnt[2]); });
    }

    function place_outlier(sel) {
        sel.attr("cx", proj0).attr("cy", proj1)
            .attr("r", function (d) { return d.r; });
    }

    function place_mean(sel) {
        sel.attr("transform", function (d) { return "translate(" + x(d[0]) + "," + y(d.mean) + ")"; })
            .attr("d", "M2.2,0L0,2.2L-2.2,0L0,-2.2Z");
    }

    const nonoutliers = data.filter(function (d) { return d.ys.length > 1; });

    place_whisker(0, svg.selectAll(".gbox.whiskerl").data(nonoutliers)
            .enter().append("line")
            .attr("class", function (d) { return "gbox whiskerl " + d.cc; }));

    place_whisker(3, svg.selectAll(".gbox.whiskerh").data(nonoutliers)
            .enter().append("line")
            .attr("class", function (d) { return "gbox whiskerh " + d.cc; }));

    place_box(svg.selectAll(".gbox.box").data(nonoutliers)
            .enter().append("path")
            .attr("class", function (d) { return "gbox box " + d.cc; })
            .style("fill", function (d) { return ensure_pattern(d.cc, "gdot"); }));

    place_median(svg.selectAll(".gbox.median").data(nonoutliers)
            .enter().append("line")
            .attr("class", function (d) { return "gbox median " + d.cc; }));

    place_mean(svg.selectAll(".gbox.mean").data(nonoutliers)
            .enter().append("path")
            .attr("class", function (d) { return "gbox mean " + d.cc; }));

    let outliers = d3.merge(data.map(function (d) {
        const nd = [], len = d.ys.length;
        for (let i = 0; i < len; ++i) {
            if (d.ys[i] < d.qnt[0] || d.ys[i] > d.qnt[4] || len <= 1)
                nd.push([d[0], d.ys[i], d.ids[i], d.cc]);
        }
        return nd;
    }));
    outliers = grouped_quadtree(outliers, x, y, 2);
    place_outlier(svg.selectAll(".gbox.outlier")
            .data(outliers.data)
            .enter()
              .append("circle")
              .attr("class", function (d) { return "gbox outlier " + d.cc; })
              .style("fill", function (d) { return ensure_pattern(d.cc, "gdot"); }));

    draw_axes(svg, args);

    svg.append("line").attr("class", "gbox whiskerl gbox-hover");
    svg.append("line").attr("class", "gbox whiskerh gbox-hover");
    svg.append("path").attr("class", "gbox box gbox-hover");
    svg.append("line").attr("class", "gbox median gbox-hover");
    svg.append("circle").attr("class", "gbox outlier gbox-hover");
    svg.append("path").attr("class", "gbox mean gbox-hover");
    const hovers = svg.selectAll(".gbox-hover")
            .style("display", "none")
            .style("ponter-events", "none"),
        hoverer = make_hover_interactor(svg, hovers);

    $(element).on("hotgraphhighlight", highlight);

    if (args.interactive !== false) {
        element.addEventListener("mouseout", hoverer.mouseout_soon, false);

        element.addEventListener("mouseover", function (event) {
            if (hasClass(event.target, "outlier")
                || hasClass(event.target, "gscatter"))
                mouseover_outlier.call(event.target);
            else if (hasClass(event.target, "gbox"))
                mouseover.call(event.target);
        }, false);

        element.addEventListener("click", function (event) {
            if (hasClass(event.target, "gbox")
                || hasClass(event.target, "gscatter"))
                mouseclick.call(event.target, event);
        }, false);
    }

    function make_tooltip(p) {
        const posd = p.qnt ? p : p.data[0],
            pe = $e("p", null, render_position(args.x, posd[0]), ", ");
        let ids;
        if (p.qnt) {
            pe.append(render_position(args.y, p.qnt[2], "median "));
            ids = [];
            for (let i = 0; i !== p.ys.length; ++i) {
                const rest = $frag(" (");
                args.y.value_render(rest, p.ys[i]);
                rest.append(")");
                ids.push({id: p.ids[i], rest: rest});
            }
        } else {
            pe.append(render_position(args.y, posd[1]));
            ids = gqdata_ids(p, true);
        }
        return [pe, render_pid_p(ids, p.cc)];
    }

    function mouseover() {
        const p = d3.select(this).data()[0];
        if (!hoverer.move(p)) {
            return;
        }
        hovers.style("display", "none");
        hovers.filter(":not(.outlier)").style("display", null).datum(p);
        place_whisker(0, hovers.filter(".whiskerl"));
        place_whisker(3, hovers.filter(".whiskerh"));
        place_box(hovers.filter(".box"));
        place_median(hovers.filter(".median"));
        place_mean(hovers.filter(".mean"));
        hoverer.bubble.replace_content(...make_tooltip(p))
            .anchor("h")
            .near(hovers.filter(".box").node());
    }

    function mouseover_outlier() {
        const po = d3.select(this).data()[0];
        if (!hoverer.move(po)) {
            return;
        }
        hovers.style("display", "none");
        place_outlier(hovers.filter(".outlier").style("display", null).datum(po));
        hoverer.bubble.replace_content(...make_tooltip(po))
            .anchor("h")
            .near(hovers.filter(".outlier").node());
    }

    function mouseclick(event) {
        let s;
        if (!hoverer.data) {
            clicker(null, event);
        } else if (!hoverer.data.qnt) {
            clicker(gqdata_ids(hoverer.data), event);
        } else if ((s = args.x.value_search(hoverer.data[0]))) {
            clicker_go(hoturl("search", {q: s}), event);
        } else {
            clicker(hoverer.data.ids, event);
        }
    }

    function highlight(event) {
        hoverer.mouseout();
        if (event.ids && !event.ids.length) {
            svg.selectAll(".gscatter").remove();
            return;
        }
        $.getJSON(hoturl("api/graphdata"), {
            x: element.getAttribute("data-graph-fx"),
            y: element.getAttribute("data-graph-fy"),
            q: event.q
        }, function (rv) {
            if (!rv.ok) {
                return;
            }
            project_data(rv, args);
            const data = ungroup_data(rv.data);
            const gq = grouped_quadtree(data, x, y, 4);
            scatter_create(svg, gq.data, "gscatter");
            scatter_highlight(svg, gq.data, "gscatter");
        });
    }
}

handle_ui.on("js-hotgraph-highlight", function () {
    const s = $.trim(this.value),
        $g = $(this).closest(".has-hotgraph").find(".hotgraph");
    function fire(ids) {
        const e = $.Event("hotgraphhighlight");
        e.ok = true;
        e.q = s;
        e.ids = ids;
        $g.trigger(e);
    }
    // Resolve the query to a pid list once, so every listener (graph marks and
    // data table) consumes the same resolved ids rather than each searching.
    if (s === "") {
        fire([]);
    } else if (/^[1-9][0-9]*$/.test(s)) {
        fire([+s]);
    } else {
        $.getJSON(hoturl("api/search", {q: s, forceShow: 1}), null, function (data) {
            fire(data && data.ids ? data.ids : []);
        });
    }
});

const graphers = {
    procrastination: {filter: true, function: procrastination_filter},
    scatter: {function: graph_scatter},
    dot: {function: graph_dot},
    ldot: {function: graph_dot},
    cdf: {function: graph_cdf},
    cumfreq: {function: graph_cdf},
    bar: {function: graph_bars},
    fraction: {function: graph_bars},
    box: {function: graph_boxplot}
};

/** `expandable` names the dimensions of the graph box that may grow to fit
 * tick labels. Only height growth is implemented, so "width" degrades to
 * false and true to "height".
 * @param {null|boolean|string} e
 * @return {false|"height"} */
function normalize_expandable(e) {
    return e == null || e === true || e === "height" ? "height" : false;
}

/** Move the X axis label up to sit just under the tick labels it is actually
 * beside. `draw_axes` places it against the bottom inset line, which the
 * deepest tick label *anywhere* on the axis sets; a tilted label's depth below
 * the axis is its own text width, so wherever the nearby labels are shorter —
 * and the label is right-anchored, so that is most of the time — that leaves a
 * gap. Overlapping nothing, the label follows the deepest label on the axis,
 * which matters when a pinned bottom edge leaves the margin deeper than the
 * ticks need. Only ever moves the label up, so it cannot leave the box.
 * @param {*} xaxe
 * @param {object} args */
function place_x_axis_label(xaxe, args) {
    const lab = xaxe.select("text.label");
    if (!args.x.label || lab.empty()) {
        return;
    }
    const lr = lab.node().getBoundingClientRect(),
        axr = xaxe.select("path").node().getBoundingClientRect();
    let beside = null, deepest = axr.bottom + TICK_SIZE;
    xaxe.selectAll("g.tick").each(function () {
        const t = this.style.display === "none" ? null : this.querySelector("text");
        if (!t) {
            return;
        }
        const r = t.getBoundingClientRect();
        if (r.height > 0) {
            deepest = Math.max(deepest, r.bottom);
            if (r.right > lr.left && r.left < lr.right) {
                beside = beside === null ? r.bottom : Math.max(beside, r.bottom);
            }
        }
    });
    const shift = Math.min(0, (beside ?? deepest) + LABEL_GAP - lr.top);
    if (shift < 0) {
        lab.attr("y", +lab.attr("y") + shift);
    }
}

/** Measure `texts` as the axis will render them, returning each width and the
 * common line height. The scratch nodes mirror the real structure
 * (`g.hg-axis[.widelabel] > g.tick > text`) so that `hg-axis`'s relative
 * `font-size: smaller` and the `.widelabel > .tick` shrink both resolve the
 * same way, and they live inside the graph's own SVG so `smaller` resolves
 * against the same parent.
 *
 * Every node is created before any is measured: a `getComputedTextLength`
 * that follows a write forces its own layout, so writing and reading one
 * string at a time costs a flush apiece. `visibility: hidden` keeps layout
 * while skipping paint. Together, 253 reviewer names measure in ~2ms rather
 * than ~10ms.
 * @param {*} svg
 * @param {list<string>} texts
 * @param {boolean} [widelabel]
 * @return {{widths: list<number>, height: number, ascent: number, descent: number}} */
function measure_texts(svg, texts, widelabel) {
    if (texts.length === 0) {
        return {widths: [], height: 0, ascent: 0, descent: 0};
    }
    const g = $svg("g", {class: "hg-axis" + (widelabel ? " widelabel" : ""),
            transform: "translate(-10000,-10000)"}),
        nodes = texts.map(function (t) {
            const e = $svg("text", null, t);
            g.append($svg("g", "tick", e));
            return e;
        });
    g.style.visibility = "hidden";
    svg.node().appendChild(g);
    const widths = nodes.map(e => e.getComputedTextLength()),
        box = nodes[0].getBBox();
    g.remove();
    return {widths: widths, height: box.height,
        ascent: -box.y, descent: box.y + box.height};
}

/** Shorten every tick in `ticks` wider than `maxwidth`, appending an ellipsis
 * and keeping the full string for a `<title>`.
 *
 * Character count gives a good first guess at how much fits, so start there
 * and shrink until it does. Each round measures every outstanding candidate in
 * one batch, which is what keeps this off the per-string layout-flush path — a
 * per-label binary search would be a flush per step per label.
 * @param {*} svg
 * @param {list<object>} ticks
 * @param {number} maxwidth
 * @param {boolean} widelabel */
function truncate_ticks(svg, ticks, maxwidth, widelabel) {
    if (!(maxwidth > 0)) {
        return;
    }
    let pending = ticks.filter(t => t.width > maxwidth);
    for (const t of pending) {
        t.n = Math.max(0, Math.floor(t.full.length * maxwidth / t.width) - 1);
    }
    for (let round = 0; round < 8 && pending.length !== 0; ++round) {
        const cand = pending.map(t => t.full.substring(0, t.n) + "\u2026"),
            m = measure_texts(svg, cand, widelabel),
            next = [];
        for (let i = 0; i !== pending.length; ++i) {
            const t = pending[i];
            t.text = cand[i];
            t.width = m.widths[i];
            if (t.width > maxwidth && t.n > 0) {
                t.n -= 1;
                next.push(t);
            }
        }
        pending = next;
    }
}

// `make_axis_pair` derives the plot rectangle from the measured ticks and
// labels plus the insets, except on any edge the caller pins.
function make_args(element, args) {
    args.width = $(element).width();
    if (args.height == null) {
        args.height = 540;
    }
    args.expandable = normalize_expandable(args.expandable);
    args.insetTop = args.insetTop ?? INSET_TOP;
    args.insetRight = args.insetRight ?? INSET_RIGHT;
    args.insetBottom = args.insetBottom ?? INSET_BOTTOM;
    args.insetLeft = args.insetLeft ?? INSET_LEFT;
    args.x = instantiate_axis(args.x || {});
    args.y = instantiate_axis(args.y || {});
    if (args.xorder) {
        args.xorder = instantiate_axis(args.xorder);
    }
    project_data(args, args);
    // Other arguments:
    // args.height: box height; grows if `expandable` allows it
    // args.expandable: false or "height" (see `normalize_expandable`)
    // args.interactive: Set to false to disable pointer interaction
    // args.insetTop, args.insetRight, args.insetBottom, args.insetLeft:
    //   override the INSET_* defaults for this graph
    // args.plotTop, args.plotRight, args.plotBottom, args.plotLeft: pin an
    //   edge of the plot rectangle, in box coordinates, so that a grid of
    //   graphs can share one plotting area; a pin supersedes its inset, and
    //   the ink that inset located follows the plot edge instead. See
    //   `make_axis_pair`.
    return args;
}

function make_graph(selector, args) {
    const element = $(selector)[0];
    if (!element) {
        return null;
    }
    if (!d3) {
        const erre = $e("div", "msg-error");
        feedback.append_item_near(erre, {message: "<0>Graphs are not supported on this browser", status: 2});
        if (document.documentMode) {
            feedback.append_item_near(erre, {message: "<5>You appear to be using a version of Internet Explorer, which is no longer supported. <a href=\"https://browsehappy.com\">Edge, Firefox, Chrome, and Safari</a> are supported, among others.", status: -5 /*MessageSet::INFORM*/});
        }
        element.append(erre);
        return null;
    }
    let g = graphers[args.gtype];
    while (g && g.filter) {
        args = g["function"](args);
        g = graphers[args.gtype];
    }
    if (!g) {
        return null;
    }
    args = make_args(element, args);
    args.svg = d3.select(element).append("svg")
        .attr("class", "hotgraph")
        .attr("width", args.width)
        .attr("height", args.height)
      .append("g");
    return g["function"].call(args.svg, element, args);
}


/* graphing wizard */

/** @param {...Element} marks */
function wiz_icon(...marks) {
    return $svg("svg", {class: "graph-wizard-icon", viewBox: "0 0 100 68",
            preserveAspectRatio: "xMidYMid meet", "aria-hidden": "true"},
        ...marks,
        $svg("path", {class: "hg-axis", d: "M4 64H96M4 52h4M4 40h4M4 28h4M4 16h4"}));
}

/** @param {list<[number,number,number]>} pts
 * @param {string} [klass] */
function wiz_dots(pts, klass) {
    return pts.map(function (p) {
        return $svg("circle", {class: klass || "gdot", cx: p[0], cy: p[1], r: p[2]});
    });
}

/** @param {list<[number,number,number,number]>} rects
 * @param {string} [klass] */
function wiz_bars(rects, klass) {
    return rects.map(function (r) {
        return $svg("rect", {class: klass || "gbar", x: r[0], y: r[1], width: r[2], height: r[3]});
    });
}

/** Box-and-whisker glyph; arguments are SVG y coordinates, so smaller is larger.
 * @param {number} cx
 * @param {[number,number,number,number,number]} q -- hi, q3, median, q1, lo */
function wiz_box(cx, q) {
    const l = cx - 7, r = cx + 7;
    return $svg("path", {class: "gbox", d: `M${l} ${q[1]}H${r}V${q[3]}H${l}Z M${l} ${q[2]}H${r} M${cx} ${q[1]}V${q[0]} M${cx} ${q[3]}V${q[4]}`});
}

const WIZ_TYPES = [
    {
        name: "scatter", title: "Scatter plot",
        hint: "One mark per position, sized to the count of matching data.",
        icon: () => wiz_icon(...wiz_dots([[25, 50, 3], [36, 43, 6], [48, 47, 4.5],
            [59, 33, 8], [72, 28, 5], [84, 36, 3.5]]))
    }, {
        name: "dot", title: "Dot plot",
        hint: "One mark per datum, perturbed to avoid overlapping.",
        // equal dots in clumps, the way the collision force packs them, with
        // several clumps at one X the way repeated values arrive
        icon: () => wiz_icon(...wiz_dots([
            [16.6, 54.4, 2.6], [22.2, 53.8, 2.6], [19.4, 43, 2.6],
            [37.6, 38.6, 2.6], [42, 21.4, 2.6],
            [58.4, 52, 2.6], [64, 51.4, 2.6],
            [59.6, 37.4, 2.6], [66.2, 36.8, 2.6], [63.4, 31, 2.6],
            [80, 45.6, 2.6], [85.6, 47, 2.6], [83.2, 26.6, 2.6]]))
    }, {
        name: "numdot", title: "Labeled dot plot",
        hint: "Dot plot labeled with submission or review ID.",
        icon: function () {
            const marks = [];
            for (const p of [[26, 48, "3"], [46, 37, "8"], [66, 43, "5"], [85, 26, "2"]]) {
                marks.push($svg("circle", {class: "gdot", cx: p[0], cy: p[1], r: 8}),
                    $svg("text", {class: "gdot-label", x: p[0], y: p[1] + 3.5}, p[2]));
            }
            return wiz_icon(...marks);
        }
    }, {
        name: "bar", title: "Bar chart",
        hint: "A bar per X value, showing how many points it has—or a total you choose.",
        icon: () => wiz_icon(...wiz_bars([[19, 44, 11, 20], [33, 30, 11, 34],
            [47, 22, 11, 42], [61, 36, 11, 28], [75, 49, 11, 15]]))
    }, {
        name: "fraction", title: "Fraction chart",
        hint: "Equal-height bars showing how each X value divides among the series.",
        y: false,
        icon: () => wiz_icon(
            ...wiz_bars([[20, 12, 13, 24], [37, 12, 13, 18], [54, 12, 13, 30], [71, 12, 13, 14]]),
            ...wiz_bars([[20, 36, 13, 24], [37, 30, 13, 30], [54, 42, 13, 18], [71, 26, 13, 34]], "gbar color1"))
    }, {
        name: "box", title: "Box plot",
        hint: "The spread of Y values at each X value: median, quartiles, and range.",
        icon: () => wiz_icon(wiz_box(26, [28, 34, 40, 46, 52]), wiz_box(45, [20, 28, 33, 40, 47]),
            wiz_box(64, [24, 32, 38, 44, 50]), wiz_box(83, [13, 20, 25, 32, 40]))
    }, {
        name: "cdf", title: "Cumulative fraction",
        hint: "The fraction of points at or below each X value. Good for comparing series.",
        y: false, multix: true,
        icon: () => wiz_icon(
            $svg("path", {class: "gcdf", d: "M8 64H16V58H28V52H40V38H50V28H64V19H73V13H80V11H96"}))
    }, {
        name: "cumfreq", title: "Cumulative count",
        hint: "A running count of points at or below each X value.",
        y: false, multix: true,
        icon: () => wiz_icon(
            $svg("path", {class: "gcdf", d: "M8 64H16V58H28V52H40V38H50V28H64V19H73V13H80V11H96"}),
            $svg("path", {class: "gcdf color1", d: "M8 64H18V60H30V56H42V48H54V42H68V36H82V32H96"}))
    }
];

const WIZ_TYPE_MAP = {};
for (const t of WIZ_TYPES) {
    WIZ_TYPE_MAP[t.name] = t;
}

// Y axis summaries offered next to each axis quantity. The empty summary
// graphs one point per review; the others reduce a submission’s reviews
// to a single number.
const WIZ_SUMMARIES = [
    ["", "Each review"], ["avg", "Average"], ["median", "Median"],
    ["max", "Maximum"], ["min", "Minimum"], ["sum", "Sum"], ["count", "Count"],
    ["stddev", "Standard deviation"], ["var", "Variance"]
];

const WIZ_STYLES = [
    ["default", "By tag"], ["plain", "Plain"], ["tag-red", "Red"],
    ["tag-orange", "Orange"], ["tag-yellow", "Yellow"], ["tag-green", "Green"],
    ["tag-blue", "Blue"], ["tag-purple", "Purple"], ["tag-gray", "Gray"]
];

// These mirror FormulaGraph::graph_type_prefix and ::data_type_prefix.
const WIZ_GTYPE_RE = /^\s*(?:(cdf)|(ogive|cumfreq|cumulativefrequency)|(count|barchart|bars|bar)|(stack|fraction)|(boxplot|box)|(scatterplot|scatter)|(numdotplot|numdots|numdot|ldotplot|ldots|ldot|dotlabelplot|dotlabels|dotlabel)|(dotplot|dots|dot))(?![-\w])\s*/i,
    WIZ_GTYPE_NAMES = [null, "cdf", "cumfreq", "bar", "fraction", "box", "scatter", "numdot", "dot"],
    WIZ_DATA_RE = /^\s*(paper|review)(\s+|(?=\())(?=[-+.\w([])/i;

/** @param {string} s
 * @return {?{gtype: string, rest: string}} */
function wiz_strip_gtype(s) {
    const m = WIZ_GTYPE_RE.exec(s);
    for (let i = 1; m && i !== WIZ_GTYPE_NAMES.length; ++i) {
        if (m[i])
            return {gtype: WIZ_GTYPE_NAMES[i], rest: s.substring(m[0].length)};
    }
    return null;
}

/** @param {string} s
 * @return {?{data: string, rest: string}} */
function wiz_strip_data(s) {
    const m = WIZ_DATA_RE.exec(s);
    return m ? {data: m[1].toLowerCase(), rest: s.substring(m[0].length)} : null;
}

/** @param {string} s */
function wiz_balanced(s) {
    let depth = 0;
    for (const ch of s) {
        if (ch === "(") {
            ++depth;
        } else if (ch === ")" && --depth < 0) {
            return false;
        }
    }
    return depth === 0;
}

/** Split `avg(OveMer)` into `["avg", "OveMer"]`.
 * @param {string} expr
 * @return {[string, string]} */
function wiz_split_summary(expr) {
    const m = /^([A-Za-z_]+)\s*\((.*)\)$/.exec(expr.trim());
    if (m
        && WIZ_SUMMARIES.some(s => s[0] === m[1].toLowerCase())
        && wiz_balanced(m[2])) {
        return [m[1].toLowerCase(), m[2].trim()];
    }
    return ["", expr.trim()];
}

let wiz_catalog = [];

/** @param {string} expr
 * @return {?object} */
function wiz_find_quantity(expr) {
    for (const g of wiz_catalog) {
        for (const q of g.quantities) {
            if (q.expr === expr)
                return q;
        }
    }
    return null;
}

/** One axis: a quantity menu, a summary menu, and the formula that they build.
 * The formula entry is authoritative; the menus resync from whatever it holds.
 * @param {object} wiz
 * @param {string} which -- "x" or "y" */
function wiz_make_axis(wiz, which) {
    const upper = which.toUpperCase(),
        id = "graph-wizard-" + which,
        qsel = $e("select", {class: "graph-wizard-quantity", "aria-label": upper + " axis quantity"}),
        ssel = $e("select", {class: "graph-wizard-summary", "aria-label": upper + " axis summary"}),
        entry = $e("input", {type: "text", name: which, id: id,
            class: "graph-wizard-expr ignore-diff",
            spellcheck: "false", autocomplete: "off"}),
        hint = $e("div", "f-d graph-wizard-axis-hint");

    qsel.append($e("option", {value: ""}, "Custom formula…"));
    for (const g of wiz_catalog) {
        const og = $e("optgroup", {label: g.title});
        for (const q of g.quantities) {
            if (which === "x" || !q.special)
                og.append($e("option", {value: q.expr}, q.title));
        }
        og.firstChild && qsel.append(og);
    }
    for (const s of WIZ_SUMMARIES) {
        ssel.append($e("option", {value: s[0]}, s[1]));
    }

    const element = $e("div", "f-i graph-wizard-axis",
        $e("label", {for: id}, upper + " axis"),
        $e("div", "graph-wizard-axis-menus", qsel, ssel),
        entry, hint);

    const self = {
        element: element,
        /** @return {string} */
        value: function () {
            return entry.value.trim();
        },
        /** @param {string} s */
        set_value: function (s) {
            entry.value = s;
            self.resync();
        },
        /** @return {?object} */
        quantity: function () {
            const q = wiz_find_quantity(wiz_split_summary(entry.value)[1]);
            return q && !q.special ? q : null;
        },
        /** Data level of the current expression, or null if the wizard can’t tell.
         * @return {?string} */
        level: function () {
            const parts = wiz_split_summary(entry.value),
                q = wiz_find_quantity(parts[1]);
            if (!q || q.special) {
                return null;
            }
            return q.indexed && parts[0] === "" ? "review" : "paper";
        },
        /** Set the menus from the formula entry. */
        resync: function () {
            const parts = wiz_split_summary(entry.value),
                q = wiz_find_quantity(parts[1]);
            qsel.value = q ? q.expr : "";
            ssel.value = q ? parts[0] : "";
            ssel.disabled = !q || !q.indexed;
            if (ssel.disabled) {
                ssel.value = "";
            }
        },
        /** @param {boolean} on
         * @param {string} [why] */
        show: function (on, why) {
            element.hidden = !on;
            entry.disabled = !on;
            hint.textContent = why || "";
        }
    };

    function compose() {
        const q = qsel.value, s = ssel.disabled ? "" : ssel.value;
        entry.value = q === "" ? "" : (s === "" ? q : s + "(" + q + ")");
        wiz.changed();
    }
    $(qsel).on("change", function () {
        const q = wiz_find_quantity(qsel.value);
        ssel.disabled = !q || !q.indexed;
        // a bar chart’s Y axis must aggregate, so pick a summary by default
        if (!ssel.disabled && which === "y" && wiz.gtype === "bar" && ssel.value === "") {
            ssel.value = "avg";
        }
        compose();
    });
    $(ssel).on("change", compose);
    $(entry).on("input change", function () {
        self.resync();
        wiz.changed();
    });
    return self;
}

/** @param {object} wiz
 * @return {Element} */
function wiz_make_dataset_panel(wiz) {
    const rows = $e("div", "graph-wizard-datasets"),
        panel = $e("div", null, rows,
            $e("button", {type: "button", class: "graph-wizard-add"}, "Add series"));

    function add_row(ds, i) {
        const qe = $e("input", {type: "text", name: "q" + (i + 1), value: ds.q,
                class: "graph-wizard-search papersearch need-suggest ignore-diff",
                placeholder: i === 0 ? "All submissions" : "Search",
                spellcheck: "false", autocomplete: "off", "aria-label": "Series search"}),
            se = $e("select", {class: "graph-wizard-style", "aria-label": "Series style"}),
            row = $e("div", "graph-wizard-dataset", qe, se,
                $e("button", {type: "button", class: "graph-wizard-remove", "aria-label": "Remove series"}, "✕"));
        for (const s of WIZ_STYLES) {
            se.append($e("option", {value: s[0]}, s[1]));
        }
        se.value = ds.s || "default";
        rows.append(row);
        $(row).awaken();
    }

    function read() {
        wiz.datasets = $(rows).find(".graph-wizard-dataset").map(function () {
            return {q: $(this).find(".graph-wizard-search")[0].value,
                s: $(this).find(".graph-wizard-style")[0].value};
        }).get();
    }

    function rebuild() {
        rows.replaceChildren();
        wiz.datasets.forEach(add_row);
    }

    $(panel).on("click", ".graph-wizard-add", function () {
        read();
        wiz.datasets.push({q: "", s: "default"});
        rebuild();
        $(rows).find(".graph-wizard-search").last().focus();
        wiz.changed();
    });
    $(panel).on("click", ".graph-wizard-remove", function () {
        read();
        const i = $(rows).find(".graph-wizard-dataset").index($(this).closest(".graph-wizard-dataset"));
        wiz.datasets.splice(i, 1);
        if (wiz.datasets.length === 0) {
            wiz.datasets.push({q: "", s: "default"});
        }
        rebuild();
        wiz.changed();
    });
    $(panel).on("input change", ".graph-wizard-search, .graph-wizard-style", function () {
        read();
        wiz.changed();
    });

    rebuild();
    return panel;
}

/** Read the graph page’s form into wizard state. The X, Y, and series fields
 * are form inputs; the graph type and the rest come from data attributes,
 * since the plain form encodes them in the Y expression.
 * @param {?HTMLFormElement} form */
function wiz_state(form) {
    const st = {gtype: "scatter", data: "", x: "", y: "", xorder: "", t: "", datasets: []},
        els = form ? form.elements : {};
    st.x = (els.x && els.x.value) || "";
    st.y = (els.y && els.y.value) || "";
    st.xorder = (form && form.getAttribute("data-graph-xorder")) || "";
    st.t = (form && form.getAttribute("data-graph-t")) || "";

    // the plain form lets you write the type into Y, as in `box OveMer`; the
    // wizard keeps the two apart, so always split the prefix off
    const ysplit = wiz_strip_gtype(st.y);
    if (ysplit) {
        st.y = ysplit.rest;
    }
    const gattr = form && form.getAttribute("data-graph-gtype"),
        g = (gattr && wiz_strip_gtype(gattr)) || ysplit;
    if (g && WIZ_TYPE_MAP[g.gtype]) {
        st.gtype = g.gtype;
    }

    for (const k of ["x", "y"]) {
        const d = wiz_strip_data(st[k]);
        if (d) {
            st.data = d.data;
            st[k] = d.rest;
        }
    }

    for (let i = 1; els["q" + i]; ++i) {
        st.datasets.push({q: els["q" + i].value,
            s: (els["s" + i] && els["s" + i].value) || "default"});
    }
    if (st.datasets.length === 0) {
        st.datasets.push({q: "", s: "default"});
    }
    return st;
}

/** Request parameters describing the graph the wizard currently specifies.
 * @param {object} wiz
 * @return {object} */
function wiz_params(wiz) {
    const t = WIZ_TYPE_MAP[wiz.gtype], p = {gtype: wiz.gtype};
    let x = wiz.xaxis.value();
    // the `paper`/`review` prefix asserts a data level, so only add it where
    // the wizard knows the assertion holds
    if (wiz.data && wiz.data === wiz.xaxis.level()) {
        x = wiz.data + " " + x;
    }
    p.x = x;
    p.y = t.y === false ? "" : wiz.yaxis.value();
    if (wiz.xorder) {
        p.xorder = wiz.xorder;
    }
    if (wiz.t) {
        p.t = wiz.t;
    }
    wiz.datasets.forEach(function (ds, i) {
        p["q" + (i + 1)] = ds.q;
        p["s" + (i + 1)] = ds.s;
    });
    return p;
}

// The preview redraws on every keystroke, so a large conference can make it
// both unreadable and slow: `graph_dot` runs a d3 force simulation over every
// mark, which is superlinear and, once the marks don't fit, pushes them
// outside the plot area. Thin the data instead.
const PREVIEW_DOT_MARK_LIMIT = 300, PREVIEW_LDOT_MARK_LIMIT = 100;

/** @param {object} args
 * @return {?{data: object, n: number, total: number}} */
function preview_sample(args) {
    if ((args.gtype !== "dot" && args.gtype !== "ldot") || !args.data) {
        return null;
    }
    let total = 0;
    for (const k in args.data) {
        total += args.data[k].length;
    }
    const limit = args.gtype === "dot" ? PREVIEW_DOT_MARK_LIMIT : PREVIEW_LDOT_MARK_LIMIT;
    if (total <= limit) {
        return null;
    }
    // Take a fixed stride rather than a random sample: the preview must not
    // reshuffle itself between two refreshes of the same graph. The stride is
    // shared across series, so they keep their relative weights.
    const stride = total / limit, data = {};
    let n = 0;
    for (const k in args.data) {
        const a = args.data[k], b = [];
        for (let i = 0; i < a.length; i += stride) {
            b.push(a[Math.floor(i)]);
        }
        if (b.length !== 0) {
            data[k] = b;
            n += b.length;
        }
    }
    return {data: data, n: n, total: total};
}

function graph_wizard(form) {
    const wiz = wiz_state(form);

    const $pu = $popup({className: "modal-dialog-wide graph-wizard-modal"}),
        tabse = $e("div", {class: "graph-wizard-tabs", role: "tablist"}),
        panelse = $e("div", "graph-wizard-panels"),
        typehint = $e("p", "graph-wizard-type-hint"),
        typese = $e("div", "graph-wizard-types"),
        preview = $e("div", "graph-wizard-preview"),
        previewnote = $e("div", "f-d graph-wizard-preview-note"),
        status = $e("div", "graph-wizard-status"),
        backb = $e("button", {type: "button", name: "back", class: "float-left"}, "← Back"),
        nextb = $e("button", {type: "button", name: "next", class: "float-left"}, "Next →"),
        graphb = $e("button", {type: "button", name: "graph", class: "btn-primary"}, "Graph");
    let panel = 0, seq = 0, timer = null;

    // panel 1: graph type
    for (const t of WIZ_TYPES) {
        typese.append($e("label", {class: "graph-wizard-type", title: t.hint},
            $e("input", {type: "radio", name: "gtype", value: t.name,
                class: "graph-wizard-type-radio ignore-diff"}),
            t.icon(),
            $e("span", "graph-wizard-type-title", t.title)));
    }

    // panel 2: axes
    const datasel = $e("select", {name: "data", id: "graph-wizard-data", class: "ignore-diff"},
        $e("option", {value: ""}, "Automatic"),
        $e("option", {value: "paper"}, "One point per submission"),
        $e("option", {value: "review"}, "One point per review"));
    wiz.xaxis = wiz_make_axis(wiz, "x");
    wiz.yaxis = wiz_make_axis(wiz, "y");
    const xordere = $e("input", {type: "text", name: "xorder", id: "graph-wizard-xorder",
        class: "graph-wizard-expr ignore-diff", spellcheck: "false", autocomplete: "off"});
    const axespanel = $e("div", null,
        $e("div", "f-i graph-wizard-axis",
            $e("label", {for: "graph-wizard-data"}, "Data points"),
            datasel,
            $e("div", "f-d", "What each mark on the graph stands for")),
        wiz.xaxis.element, wiz.yaxis.element);

    // panel 4: advanced
    const advancedpanel = $e("div", null,
        $e("div", "f-i graph-wizard-axis",
            $e("label", {for: "graph-wizard-xorder"}, "X axis order"),
            xordere,
            $e("div", "f-d", "Optional formula that sorts the X axis")));

    const PANELS = [
        {title: "Graph type", element: $e("div", null, typese, typehint)},
        {title: "Axes", element: axespanel},
        {title: "Series", element: wiz_make_dataset_panel(wiz)},
        {title: "Advanced", element: advancedpanel}
    ];
    PANELS.forEach(function (p, i) {
        p.tab = $e("button", {type: "button", role: "tab", class: "graph-wizard-tab",
            id: "graph-wizard-tab-" + i, "aria-controls": "graph-wizard-panel-" + i,
            "aria-selected": "false"}, p.title);
        addClass(p.element, "graph-wizard-panel");
        p.element.setAttribute("role", "tabpanel");
        p.element.setAttribute("id", "graph-wizard-panel-" + i);
        p.element.setAttribute("aria-labelledby", "graph-wizard-tab-" + i);
        p.element.hidden = true;
        tabse.append(p.tab);
        panelse.append(p.element);
    });

    /** @param {number} i
     * @param {boolean} [focus] */
    function select_panel(i, focus) {
        panel = i;
        PANELS.forEach(function (p, j) {
            p.element.hidden = j !== i;
            p.tab.setAttribute("aria-selected", j === i ? "true" : "false");
            (j === i ? addClass : removeClass)(p.tab, "active");
        });
        backb.disabled = i === 0;
        nextb.disabled = i === PANELS.length - 1;
        focus && hotcrp.focus_within(PANELS[i].element);
    }

    /** @param {?list<object>} ml */
    function show_messages(ml) {
        // place field messages next to their inputs, which may be on a
        // hidden panel, and repeat the whole list beside the preview
        $pu.show_errors(ml, {summary: false});
        status.replaceChildren(ml && ml.length ? feedback.render_alert(ml) : "");
    }

    function render_preview(data) {
        preview.replaceChildren();
        show_messages(data.message_list);
        if (data.ok === false) {
            previewnote.replaceChildren();
            return;
        }
        const sample = preview_sample(data);
        previewnote.replaceChildren(sample
            ? "Sampled ".concat(sample.n.toLocaleString(), " of ",
                sample.total.toLocaleString(), " marks")
            : "");
        // the preview lives in a fixed panel, so the box must not grow to fit
        // tick labels; they are truncated instead
        const width = Math.max(preview.clientWidth || 0, 240);
        make_graph(preview, $.extend({}, data, {
            data: sample ? sample.data : data.data,
            height: Math.round(width * 0.72),
            expandable: false
        }));
    }

    function load_preview() {
        const p = wiz_params(wiz), myseq = ++seq;
        if (p.x === "") {
            preview.replaceChildren();
            show_messages([{message: "<0>Choose an X axis to see a preview", status: -4 /*MessageSet::MARKED_NOTE*/}]);
            return;
        }
        $.get(hoturl("api/graphdata", p), function (data) {
            if (myseq !== seq) {
                return;
            }
            if (data) {
                render_preview(data);
            } else {
                show_messages([{message: "<0>Could not load the graph", status: 2}]);
            }
        });
    }

    wiz.changed = function () {
        wiz.data = datasel.value;
        wiz.xorder = xordere.value.trim();
        const t = WIZ_TYPE_MAP[wiz.gtype];
        wiz.xaxis.show(true, t.multix ? "Formula, or several separated by “;”" : "Formula, “search”, or “tag”");
        wiz.yaxis.show(t.y !== false,
            wiz.gtype === "bar" ? "Formula to total; leave empty to count points" : "Formula");
        typehint.textContent = t.hint;
        timer && clearTimeout(timer);
        timer = setTimeout(load_preview, 300);
    };

    $(typese).on("change", ".graph-wizard-type-radio", function () {
        wiz.gtype = this.value;
        $(typese).find(".graph-wizard-type").each(function () {
            (this.querySelector(":checked") ? addClass : removeClass)(this, "active");
        });
        wiz.changed();
    });
    $(tabse).on("click", ".graph-wizard-tab", function () {
        select_panel(PANELS.findIndex(p => p.tab === this), true);
    });
    $(backb).on("click", () => select_panel(panel - 1, true));
    $(nextb).on("click", () => select_panel(panel + 1, true));
    $(datasel).on("change", function () {
        // switching data level only makes sense together with the summaries
        for (const ax of [wiz.xaxis, wiz.yaxis]) {
            const q = ax.quantity();
            if (q && q.indexed) {
                const parts = wiz_split_summary(ax.value());
                if (this.value === "review") {
                    ax.set_value(q.expr);
                } else if (this.value === "paper" && parts[0] === "") {
                    ax.set_value("avg(" + q.expr + ")");
                }
            }
        }
        wiz.changed();
    });
    $(xordere).on("input change", () => wiz.changed());

    $pu.append($e("h2", "graph-wizard-title", "Graphing wizard"),
        $e("div", "graph-wizard",
            $e("div", "graph-wizard-main", tabse, panelse),
            $e("div", "graph-wizard-side",
                $e("h3", "graph-wizard-side-title", "Preview"), status, preview,
                previewnote)))
        .append_actions(graphb, "Cancel", backb, nextb);

    function go() {
        window.location = hoturl("graph", $.extend({group: "formula"}, wiz_params(wiz)));
    }
    $(graphb).on("click", go);
    $pu.on("submit", function (evt) {
        evt.preventDefault();
        go();
    });

    // fill in initial state; the graph type goes last because selecting it
    // refreshes everything else
    datasel.value = wiz.data;
    wiz.xaxis.set_value(wiz.x);
    wiz.yaxis.set_value(wiz.y);
    xordere.value = wiz.xorder;
    $(typese).find(".graph-wizard-type-radio[value=" + wiz.gtype + "]").prop("checked", true).trigger("change");
    select_panel(0);

    $pu.show();
    return $pu;
}

handle_ui.on("js-graph-wizard", function () {
    graph_wizard(this.form || $$("f-graph"));
});

make_graph.wizard = graph_wizard;
/** @param {list<object>} catalog */
make_graph.set_catalog = function (catalog) {
    wiz_catalog = catalog;
};

return make_graph;
})(jQuery, window.d3);
