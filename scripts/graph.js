// graph.js -- HotCRP JavaScript library for graph drawing
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

var hotcrp_graphs = (function ($, d3) {

function pathNodeMayBeNearer(pathNode, point, dist) {
    function oob(l, t, r, b) {
        return l - point[0] >= dist || point[0] - r >= dist
            || t - point[1] >= dist || point[1] - b >= dist;
    }
    // check bounding rectangle of path
    if ("clientX" in point) {
        var bounds = pathNode.getBoundingClientRect();
        var dx = point[0] - point.clientX, dy = point[1] - point.clientY;
        if (oob(bounds.left + dx, bounds.top + dy,
                bounds.right + dx, bounds.bottom + dy))
            return false;
    }
    // check path
    var npsl = pathNode.normalizedPathSegList || pathNode.pathSegList;
    var xo, yo, x0, y0, x, y, l, t, r, b, found;
    for (var i = 0; i < npsl.numberOfItems && !found; ++i) {
        var item = npsl.getItem(i);
        if (item.pathSegType == 1)
            x = xo, y = yo;
        else
            x = item.x, y = item.y;
        if (item.pathSegType == 2) {
            xo = l = r = x;
            yo = t = b = y;
        } else if (item.pathSegType == 6) {
            l = Math.min(x0, x, item.x1, item.x2);
            t = Math.min(y0, y, item.y1, item.y2);
            r = Math.max(x0, x, item.x1, item.x2);
            b = Math.max(y0, y, item.y1, item.y2);
        } else if (item.pathSegType == 1 || item.pathSegType == 4) {
            l = Math.min(x0, x);
            t = Math.min(y0, y);
            r = Math.max(x0, x);
            b = Math.max(y0, y);
        } else
            return true;
        if (!oob(l, t, r, b))
            return true;
        x0 = x, y0 = y;
    }
    return false;
}

function closestPoint(pathNode, point, inbest) {
    // originally from Mike Bostock http://bl.ocks.org/mbostock/8027637
    var pathLength = pathNode.getTotalLength(),
        precision = pathLength / pathNode.pathSegList.numberOfItems * .125,
        best, bestLength, bestDistance2 = Infinity;

    if (inbest && !pathNodeMayBeNearer(pathNode, point, inbest.distance))
        return inbest;

    function check(pLength) {
        var p = pathNode.getPointAtLength(pLength);
        var dx = point[0] - p.x, dy = point[1] - p.y, d2 = dx * dx + dy * dy;
        if (d2 < bestDistance2) {
            best = [p.x, p.y];
            best.pathNode = pathNode;
            bestLength = pLength;
            bestDistance2 = d2;
            return true;
        } else
            return false;
    }

    // linear scan for coarse approximation
    var sl;
    for (sl = 0; sl <= pathLength; sl += precision)
        check(sl);

    // binary search for precise estimate
    precision *= .5;
    while (precision > .5) {
        if (!((sl = bestLength - precision) >= 0 && check(sl))
            && !((sl = bestLength + precision) <= pathLength && check(sl)))
            precision *= .5;
    }

    best.distance = Math.sqrt(bestDistance2);
    best.pathLength = bestLength;
    return inbest && inbest.distance < best.distance + 0.01 ? inbest : best;
}

function tangentAngle(pathNode, length) {
    var length0 = Math.max(0, length - 0.25);
    if (length0 == length)
        length += 0.25;
    var p0 = pathNode.getPointAtLength(length0),
        p1 = pathNode.getPointAtLength(length);
    return Math.atan2(p1.y - p0.y, p1.x - p0.x);
}


/* CDF functions */
function submission_delay_seq(ri) {
    var seq = [], i;
    for (i in ri)
        if (ri[i][1] > 0)
            seq.push((ri[i][1] - ri[i][0]) / 86400);
    seq.ntotal = ri.length;
    return seq;
}
submission_delay_seq.label = function (dl) {
    return "Days after assignment";
};

function procrastination_seq(ri, dl) {
    var seq = [], i;
    for (i in ri)
        if (ri[i][1] > 0)
            seq.push((ri[i][1] - dl[ri[i][2]]) / 86400);
    seq.ntotal = ri.length;
    return seq;
}
procrastination_seq.label = function (dl) {
    return dl.length > 1 ? "Days until round deadline" : "Days until deadline";
};

function max_procrastination_seq(ri, dl) {
    var seq = [], i, dlx = Math.max.apply(null, dl);
    for (i in ri)
        if (ri[i][1] > 0)
            seq.push((ri[i][1] - dlx) / 86400);
    seq.ntotal = ri.length;
    return seq;
}
max_procrastination_seq.label = function (dl) {
    return dl.length > 1 ? "Days until maximum deadline" : "Days until deadline";
};
procrastination_seq.tick_format = max_procrastination_seq.tick_format =
    function (x) { return -x; };

function seq_to_cdf(seq) {
    var cdf = [], i, n = seq.ntotal || seq.length;
    seq.sort(d3.ascending);
    for (i = 0; i <= seq.length; ++i) {
        if (i != 0 && (i == seq.length || seq[i-1] != seq[i]))
            cdf.push([seq[i-1], i/n]);
        if (i != seq.length && (i == 0 || seq[i-1] != seq[i]))
            cdf.push([seq[i], i/n]);
    }
    cdf.cdf = true;
    return cdf;
}


function expand_extent(e, delta) {
    return [e[0] - delta, e[1] + delta];
}

/* actual graphs */
var hotcrp_graphs = {};

// args: {selector: JQUERYSELECTOR,
//        series: [{d: [ARRAY], label: STRING, className: STRING}],
//        xlabel: STRING, ylabel: STRING, xtick_format: STRING}
function hotcrp_graphs_cdf(args) {
    var margin = {top: 20, right: 20, bottom: 30, left: 50},
        width = $(args.selector).width() - margin.left - margin.right,
        height = 500 - margin.top - margin.bottom;

    var x = d3.scale.linear().range([0, width]);
    var y = d3.scale.linear().range([height, 0]);

    var svg = d3.select(args.selector).append("svg")
        .attr("width", width + margin.left + margin.right)
        .attr("height", height + margin.top + margin.bottom)
      .append("g")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

    // massage data
    var series = args.series;
    if (!series.length) {
        series = d3.values(series);
        series.sort(function (a, b) {
            return d3.ascending(a.priority || 0, b.priority || 0);
        });
    }
    series = series.filter(function (d) {
        return (d.d ? d.d : d).length > 0;
    });
    var data = series.map(function (d) {
        d = d.d ? d.d : d;
        return d.cdf ? d : seq_to_cdf(d);
    });

    // axis domains
    var i = data.reduce(function (e, d) {
        e[0] = Math.min(e[0], d[0][0]);
        e[1] = Math.max(e[1], d[d.length - 1][0]);
        return e;
    }, [Infinity, -Infinity]);
    x.domain([i[0] - (i[1] - i[0])/32, i[1] + (i[1] - i[0])/32]);
    var i = d3.max(data, function (d) { return d[d.length - 1][1]; });
    y.domain([0, Math.ceil(i * 10) / 10]);

    // axes
    var xAxis = d3.svg.axis().scale(x).orient("bottom");
    args.xtick_setup && args.xtick_setup(xAxis, x.domain());
    args.xtick_format && xAxis.tickFormat(args.xtick_format);
    var yAxis = d3.svg.axis().scale(y).orient("left");
    var line = d3.svg.line().x(function (d) {return x(d[0]);})
        .y(function (d) {return y(d[1]);});

    // CDF lines
    var xmax = x.domain()[1];
    data.forEach(function (d, i) {
        var klass = "gcdf";
        if (series[i].className)
            klass += " " + series[i].className;
        if (d[d.length - 1][0] != xmax)
            d.push([xmax, d[d.length - 1][1]]);
        svg.append("path").attr("dataindex", i)
            .datum(d)
            .attr("class", klass)
            .attr("d", line);
    });

    svg.append("path").attr("class", "gcdf gcdf_hover0");
    svg.append("path").attr("class", "gcdf gcdf_hover1");
    var hovers = svg.selectAll(".gcdf_hover0, .gcdf_hover1");
    hovers.style("display", "none");

    svg.append("g")
        .attr("class", "x axis")
        .attr("transform", "translate(0," + height + ")")
        .call(xAxis)
      .append("text")
        .attr("x", width).attr("y", 0).attr("dy", "-.5em")
        .style({"text-anchor": "end", "font-size": "smaller"})
        .text(args.xlabel || "");

    svg.append("g")
        .attr("class", "y axis")
        .call(yAxis)
      .append("text")
        .attr("transform", "rotate(-90)")
        .attr("y", 6).attr("dy", ".71em")
        .style({"text-anchor": "end", "font-size": "smaller"})
        .text(args.ylabel || "");

    svg.append("rect").attr("x", -margin.left).attr("width", width + margin.left)
        .attr("height", height + margin.bottom)
        .style({"fill": "none", "pointer-events": "all"})
        .on("mouseover", mousemoved).on("mousemove", mousemoved)
        .on("mouseout", mouseout);

    var hovered_path, hubble;
    function mousemoved() {
        var m = d3.mouse(this), p = {distance: 16};
        m.clientX = d3.event.clientX;
        m.clientY = d3.event.clientY;
        for (i in data)
            if (series[i].label)
                p = closestPoint(svg.select("[dataindex='" + i + "']").node(), m, p);
        if (p.pathNode != hovered_path) {
            if (p.pathNode)
                hovers.datum(data[p.pathNode.getAttribute("dataindex")])
                    .attr("d", line).style("display", null);
            else
                hovers.style("display", "none");
            hovered_path = p.pathNode;
        }
        var u = p.pathNode ? series[p.pathNode.getAttribute("dataindex")] : null;
        if (u && u.label) {
            hubble = hubble || make_bubble("", {color: "tooltip", "pointer-events": "none"});
            var dir = Math.abs(tangentAngle(p.pathNode, p.pathLength));
            hubble.text(u.label)
                .direction(dir >= 0.25*Math.PI && dir <= 0.75*Math.PI ? "h" : "b")
                .show(p[0], p[1], this);
        } else if (hubble)
            hubble = hubble.remove() && null;
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_path = hubble = null;
    }
};
hotcrp_graphs.cdf = hotcrp_graphs_cdf;


hotcrp_graphs.procrastination = function (selector, revdata) {
    var args = {selector: selector, series: {}};

    // collect data
    var alldata = [], d, i, l, cid, u;
    for (cid in revdata.reviews) {
        var d = {d: revdata.reviews[cid]};
        if ((u = revdata.users[cid]) && u.name)
            d.label = u.name;
        if (cid && cid == hotcrp_user.cid) {
            d.className = "revtimel_hilite";
            d.priority = 1;
        } else if (u && u.light)
            d.className = "revtimel_light";
        Array.prototype.push.apply(alldata, d.d);
        if (cid !== "conflicts")
            args.series[cid] = d;
    }
    args.series.all = {d: alldata, className: "revtimel_all", priority: 2};

    var dlf = max_procrastination_seq;

    // infer deadlines when not set
    if (dlf != submission_delay_seq) {
        for (i in revdata.deadlines)
            if (!revdata.deadlines[i]) {
                var subat = alldata.filter(function (d) { return d[2] == i; })
                    .map(function (d) { return d[0]; });
                subat.sort(d3.ascending);
                revdata.deadlines[i] = subat.length ? d3.quantile(subat, 0.8) : 0;
            }
    }
    // make cdfs
    for (i in args.series)
        args.series[i].d = seq_to_cdf(dlf(args.series[i].d, revdata.deadlines));

    if (dlf.tick_format)
        args.xtick_format = dlf.tick_format;
    args.xlabel = dlf.label(revdata.deadlines);
    args.ylabel = "Fraction of assignments completed";

    hotcrp_graphs_cdf(args);
};


/* grouped quadtree */
// mark bounds of each node
function grouped_quadtree_mark_bounds(q, ordinal) {
    var b = [Infinity, -Infinity, -Infinity, Infinity], p, i;
    ordinal = ordinal || (function () { var m = 0; return function () { return ++m; }; })();
    q.ordinal = ordinal();
    for (p = q.point; p; p = p.next)
        if (q.point.maxr == null || p.r > q.point.maxr) {
            b[0] = Math.min(b[0], p[1] - p.r);
            b[1] = Math.max(b[1], p[0] + p.r);
            b[2] = Math.max(b[2], p[1] + p.r);
            b[3] = Math.min(b[3], p[0] - p.r);
            q.point.maxr = p.r;
        }
    for (i = 0; i < 4; ++i)
        if ((p = q.nodes[i])) {
            grouped_quadtree_mark_bounds(p, ordinal);
            b[0] = Math.min(b[0], p.bounds[0]);
            b[1] = Math.max(b[1], p.bounds[1]);
            b[2] = Math.max(b[2], p.bounds[2]);
            b[3] = Math.min(b[3], p.bounds[3]);
        }
    q.bounds = b;
}

function grouped_quadtree_gfind(point, min_distance) {
    var q = this, closest = null;
    if (min_distance == null)
        min_distance = Infinity;
    function visitor(node) {
        var p;
        if (node.bounds[0] > point[1] + min_distance
            || node.bounds[1] < point[0] - min_distance
            || node.bounds[2] < point[1] - min_distance
            || node.bounds[3] > point[0] + min_distance)
            return true;
        if (!(p = node.point))
            return;
        var dx = p[0] - point[0], dy = p[1] - point[1];
        if (Math.abs(dx) - p.maxr < min_distance
            || Math.abs(dy) - p.maxr < min_distance) {
            var dd = Math.sqrt(dx * dx + dy * dy);
            for (; p; p = p.next) {
                var d = Math.max(dd - p.r, 0);
                if (d < min_distance || (d == 0 && p.r < closest.r))
                    closest = p, min_distance = d;
            }
        }
    }
    this.visit(visitor);
    return closest;
}

function grouped_quadtree(data, xs, ys, rf) {
    function make_extent() {
        var xe = xs.range(), ye = ys.range();
        return [[Math.min(xe[0], xe[1]), Math.min(ye[0], ye[1])],
                [Math.max(xe[0], xe[1]), Math.max(ye[0], ye[1])]];
    }
    var q = d3.geom.quadtree().extent(make_extent())([]),
        d, nd = [], vp, vd, dx, dy;
    if (rf == null)
        rf = function (n) { return Math.sqrt(n); };
    else if (typeof rf === "number")
        rf = (function (f) {
            return function (n) { return Math.sqrt(n) * f; };
        })(rf);
    for (var i = 0; (d = data[i]); ++i) {
        if (d[0] == null || d[1] == null)
            continue;
        vd = [xs(d[0]), ys(d[1]), [d[2]], d[3]];
        if ((vp = q.find(vd))) {
            dx = Math.abs(vp[0] - vd[0]);
            dy = Math.abs(vp[1] - vd[1]);
            if (dx > 2 || dy > 2 || dx * dx + dy * dy > 4)
                vp = null;
        }
        while (vp && vp[3] != vd[3] && vp.next)
            vp = vp.next;
        if (vp && vp[3] == vd[3]) {
            vp[2].push(d[2]);
            vp.n += 1;
            vp.r = rf(vp.n);
        } else {
            vp ? vp.next = vd : q.add(vd);
            vd.n = 1;
            nd.push(vd);
            vd.r = rf(vd.n);
        }
    }
    grouped_quadtree_mark_bounds(q);
    delete q.add;
    q.gfind = grouped_quadtree_gfind;
    return {data: nd, quadtree: q};
}

hotcrp_graphs.scatter = function (args) {
    var margin = {top: 20, right: 20, bottom: 30, left: 50},
        width = $(args.selector).width() - margin.left - margin.right,
        height = 500 - margin.top - margin.bottom,
        data = args.data;

    var xe = d3.extent(data, function (d) { return d[0]; }),
        ye = d3.extent(data, function (d) { return d[1]; }),
        x = d3.scale.linear().range(args.xflip ? [width, 0] : [0, width])
                .domain(expand_extent(xe, xe[1] - xe[0] < 10 ? 0.3 : 0)),
        y = d3.scale.linear().range(args.yflip ? [0, height] : [height, 0])
                .domain(expand_extent(ye, ye[1] - ye[0] < 10 ? 0.3 : 0)),
        rf = function (d) { return d.r - 1; };
    data = grouped_quadtree(data, x, y, 4);

    var xAxis = d3.svg.axis().scale(x).orient("bottom");
    args.xtick_setup && args.xtick_setup(xAxis, xe);
    var yAxis = d3.svg.axis().scale(y).orient("left");
    args.ytick_setup && args.ytick_setup(yAxis, ye);

    var svg = d3.select(args.selector).append("svg")
        .attr("width", width + margin.left + margin.right)
        .attr("height", height + margin.top + margin.bottom)
      .append("g")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

    function place(sel) {
        return sel.attr("r", rf)
            .attr("cx", function (d) { return d[0]; })
            .attr("cy", function (d) { return d[1]; });
    }

    place(svg.selectAll(".dot").data(data.data)
          .enter().append("circle")
            .attr("class", function (d) {
                return d[3] ? "gdot " + d[3] : "gdot";
            }));

    svg.append("circle").attr("class", "gdot gdot_hover0");
    svg.append("circle").attr("class", "gdot gdot_hover1");
    var hovers = svg.selectAll(".gdot_hover0, .gdot_hover1").style("display", "none");

    svg.append("g")
        .attr("class", "x axis")
        .attr("transform", "translate(0," + height + ")")
        .call(xAxis)
        .call(args.xaxis_setup || function () {})
      .append("text")
        .attr("x", width).attr("y", 0).attr("dy", "-.5em")
        .style({"text-anchor": "end", "font-size": "smaller"})
        .text(args.xlabel || "");

    svg.append("g")
        .attr("class", "y axis")
        .call(yAxis)
        .call(args.yaxis_setup || function () {})
      .append("text")
        .attr("transform", "rotate(-90)")
        .attr("y", 6).attr("dy", ".71em")
        .style({"text-anchor": "end", "font-size": "smaller"})
        .text(args.ylabel || "");

    svg.append("rect").attr("x", -margin.left).attr("width", width + margin.left)
        .attr("height", height + margin.bottom)
        .style({"fill": "none", "pointer-events": "all"})
        .on("mouseover", mousemoved).on("mousemove", mousemoved)
        .on("mouseout", mouseout).on("click", mouseclick);

    var hovered_data, hubble;
    function mousemoved() {
        var m = d3.mouse(this), p = data.quadtree.gfind(m, 4);
        if (p != hovered_data) {
            if (p)
                place(hovers.datum(p)).style("display", null);
            else
                hovers.style("display", "none");
            svg.style("cursor", p ? "pointer" : null);
            hovered_data = p;
        }
        if (p) {
            hubble = hubble || make_bubble("", {color: "tooltip", "pointer-events": "none"});
            if (!p.sorted) {
                p[2].sort(function (a, b) {
                    var an = parseInt(a, 10), bn = parseInt(b, 10);
                    if (an == bn)
                        an = a, bn = b;
                    return an < bn ? -1 : an == bn ? 0 : 1;
                });
                p.sorted = true;
            }
            hubble.html("<p>#" + p[2].join(", #") + "</p>")
                .direction("b").near(hovers.node());
        } else if (hubble)
            hubble = hubble.remove() && null;
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_data = hubble = null;
    }

    function mouseclick() {
        var pids = hovered_data ? hovered_data[2] : null, m, x, i, url;
        if (!pids)
            return;
        for (i = 0, x = []; i < pids.length; ++i) {
            m = parseInt(pids[i], 10);
            if (!x.length || x[x.length - 1] != m)
                x.push(m);
        }
        if (x.length == 1 && pids.length == 1 && /[A-Z]$/.test(pids[0]))
            url = hoturl("paper", {p: x[0], anchor: "review" + pids[0]});
        else if (x.length == 1)
            url = hoturl("paper", {p: x[0]});
        else
            url = hoturl("search", {q: x.join(" ")});
        if (d3.event.metaKey)
            window.open(url, "_blank");
        else
            window.location = url;
    }
};

hotcrp_graphs.formulas_add_qrow = function () {
    var i, h, j;
    for (i = 0; $("#q" + i).length; ++i)
        /* do nothing */;
    j = $(hotcrp_graphs.formulas_qrow.replace(/\$/g, i)).appendTo("#qcontainer");
    hiliter_children(j);
    j.find("input[hottemptext]").each(mktemptext);
};

hotcrp_graphs.option_letter_ticks = function (n, c) {
    return function (axis, extent) {
        var split = 2,
            count = Math.floor(extent[1] * 2) - Math.ceil(extent[0] * 2) + 1,
            info = make_score_info(n, c);
        if (count > 11)
            split = 1, count = Math.floor(extent[1]) - Math.ceil(extent[0]) + 1;
        axis.ticks(count).tickFormat(function (value) {
            return info.unparse(value, split);
        });
    };
};

hotcrp_graphs.named_integer_ticks = function (map) {
    return function (axis, extent) {
        var count = Math.floor(extent[1]) - Math.ceil(extent[0]) + 1;
        axis.ticks(count).tickFormat(function (value) {
            return map[value];
        });
    };
};

hotcrp_graphs.rotate_ticks = function (angle) {
    return function (axis) {
        axis.selectAll("text")
            .attr("x", 0).attr("y", 0).attr("dy", "-.71em")
            .attr("transform", "rotate(" + angle + ")")
            .style("text-anchor", "middle");
    };
};

return hotcrp_graphs;
})(jQuery, d3);
