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
function finish_seq(seq, ri) {
    seq.sort(d3.ascending);
    seq.ntotal = ri.length;
    return seq;
}

function submission_delay_seq(ri) {
    var seq = [], i;
    for (i in ri)
        if (ri[i][1] > 0)
            seq.push((ri[i][1] - ri[i][0]) / 86400);
    return finish_seq(seq, ri);
}
submission_delay_seq.label = function (dl) {
    return "Days after assignment";
};

function procrastination_seq(ri, dl) {
    var seq = [], i;
    for (i in ri)
        if (ri[i][1] > 0)
            seq.push((ri[i][1] - dl[ri[i][2]]) / 86400);
    return finish_seq(seq, ri);
}
procrastination_seq.label = function (dl) {
    return dl.length > 1 ? "Days until round deadline" : "Days until deadline";
};

function max_procrastination_seq(ri, dl) {
    var seq = [], i, dlx = Math.max.apply(null, dl);
    for (i in ri)
        if (ri[i][1] > 0)
            seq.push((ri[i][1] - dlx) / 86400);
    return finish_seq(seq, ri);
}
max_procrastination_seq.label = function (dl) {
    return dl.length > 1 ? "Days until maximum deadline" : "Days until deadline";
};
procrastination_seq.tick_format = max_procrastination_seq.tick_format =
    function (x) { return -x; };

function seq_to_cdf(seq) {
    var cdf = [], i, n = seq.ntotal || seq.length;
    for (i = 0; i <= seq.length; ++i) {
        if (i != 0 && (i == seq.length || seq[i-1] != seq[i]))
            cdf.push([seq[i-1], i/n]);
        if (i != seq.length && (i == 0 || seq[i-1] != seq[i]))
            cdf.push([seq[i], i/n]);
    }
    return cdf;
}


/* perturbations */
function quantize(data, xs, ys) {
    var q = d3.geom.quadtree().extent([[xs.range()[0], ys.range()[1]],
                                       [xs.range()[1], ys.range()[0]]])([]),
        d, nd = [], vp, vd, dx, dy;
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
            vp.r = Math.sqrt(vp.n);
        } else {
            vp ? vp.next = vd : q.add(vd);
            vd.r = vd.n = 1;
            nd.push(vd);
        }
    }
    return {data: nd, quadtree: q};
}


/* actual graphs */
var hotcrp_graphs = {};

hotcrp_graphs.procrastination = function (selector, revdata) {
    var margin = {top: 20, right: 20, bottom: 30, left: 50},
        width = $(selector).width() - margin.left - margin.right,
        height = 500 - margin.top - margin.bottom;

    var x = d3.scale.linear().range([0, width]);
    var y = d3.scale.linear().range([height, 0]);

    var xAxis = d3.svg.axis().scale(x).orient("bottom");
    var yAxis = d3.svg.axis().scale(y).orient("left");
    var line = d3.svg.line().x(function (d) {return x(d[0]);})
        .y(function (d) {return y(d[1]);});

    var svg = d3.select(selector).append("svg")
        .attr("width", width + margin.left + margin.right)
        .attr("height", height + margin.top + margin.bottom)
      .append("g")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

    // collect data
    var data = {all: []}, i, cid, dlf = max_procrastination_seq;
    for (cid in revdata.reviews) {
        data[cid] = revdata.reviews[cid];
        Array.prototype.push.apply(data.all, data[cid]);
    }
    delete data.conflicts;
    // infer deadlines when not set
    if (dlf != submission_delay_seq) {
        for (i in revdata.deadlines)
            if (!revdata.deadlines[i]) {
                var subat = data.all.filter(function (d) { return d[2] == i; })
                    .map(function (d) { return d[0]; });
                subat.sort(d3.ascending);
                revdata.deadlines[i] = subat.length ? d3.quantile(subat, 0.8) : 0;
            }
    }
    // make cdfs
    data.all.no_ntotal = true;
    for (cid in data)
        data[cid] = seq_to_cdf(dlf(data[cid], revdata.deadlines));
    // append last point
    var lastx = data.all.length ? data.all[data.all.length - 1][0] : 0;
    for (cid in data)
        if (cid !== "all" && data[cid].length) {
            i = data[cid][data[cid].length - 1];
            if (i[0] != lastx)
                data[cid].push([lastx, i[1]]);
        }

    x.domain(d3.extent(data.all, function (d) { return d[0]; }));
    i = d3.max(d3.values(data), function (d) { return d.length ? d[d.length - 1][1] : 0; });
    y.domain([0, Math.ceil(i * 10) / 10]);
    if (dlf.tick_format)
        xAxis.tickFormat(dlf.tick_format);

    for (cid in data) {
        var u = revdata.users[cid], klass = "revtimel";
        if (cid == "all")
            klass += " revtimel_all";
        else if (cid == hotcrp_user.cid)
            klass += " revtimel_hilite";
        else if (u && u.light)
            klass += " revtimel_light";
        svg.append("path").attr("cid", cid)
            .datum(data[cid])
            .attr("class", klass)
            .attr("d", line);
    }

    svg.append("path").attr("class", "revtimel revtimel_hover0");
    svg.append("path").attr("class", "revtimel revtimel_hover1");
    var hovers = svg.selectAll(".revtimel_hover0, .revtimel_hover1");
    hovers.style("display", "none");

    svg.append("g")
        .attr("class", "x axis")
        .attr("transform", "translate(0," + height + ")")
        .call(xAxis)
      .append("text")
        .attr("x", width).attr("y", 0).attr("dy", "-.5em")
        .style({"text-anchor": "end", "font-size": "smaller"})
        .text(dlf.label(revdata.deadlines));

    svg.append("g")
        .attr("class", "y axis")
        .call(yAxis)
      .append("text")
        .attr("transform", "rotate(-90)")
        .attr("y", 6).attr("dy", ".71em")
        .style({"text-anchor": "end", "font-size": "smaller"})
        .text("Fraction of assignments completed");

    svg.append("rect").attr("width", width).attr("height", height)
        .style({"fill": "none", "pointer-events": "all"})
        .on("mouseover", mousemoved).on("mousemove", mousemoved)
        .on("mouseout", mouseout);

    var hovered_path, hubble;
    function mousemoved() {
        var m = d3.mouse(this), p = {distance: 16};
        m.clientX = d3.event.clientX;
        m.clientY = d3.event.clientY;
        for (cid in data)
            if (cid != "all")
                p = closestPoint(svg.select("[cid='" + cid + "']").node(), m, p);
        if (p.pathNode != hovered_path) {
            if (p.pathNode)
                hovers.datum(data[p.pathNode.getAttribute("cid")])
                    .attr("d", line).style("display", null);
            else
                hovers.style("display", "none");
            hovered_path = p.pathNode;
        }
        var u = p.pathNode ? revdata.users[p.pathNode.getAttribute("cid")] : null;
        if (u && u.name) {
            hubble = hubble || make_bubble("", {color: "tooltip", "pointer-events": "none"});
            var dir = Math.abs(tangentAngle(p.pathNode, p.pathLength));
            hubble.text(u.name)
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

hotcrp_graphs.scatter = function (selector, data, info) {
    var margin = {top: 20, right: 20, bottom: 30, left: 50},
        width = $(selector).width() - margin.left - margin.right,
        height = 500 - margin.top - margin.bottom;

    var xe = d3.extent(data, function (d) { return d[0]; }),
        ye = d3.extent(data, function (d) { return d[1]; }),
        x = d3.scale.linear().range([0, width]).domain([xe[0] - 0.3, xe[1] + 0.3]),
        y = d3.scale.linear().range([height, 0]).domain([ye[0] - 0.3, ye[1] + 0.3]),
        rf = function (d) { return 4 * d.r; };
    data = quantize(data, x, y);

    var xAxis = d3.svg.axis().scale(x).orient("bottom");
    var yAxis = d3.svg.axis().scale(y).orient("left");

    var svg = d3.select(selector).append("svg")
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
      .append("text")
        .attr("x", width).attr("y", 0).attr("dy", "-.5em")
        .style({"text-anchor": "end", "font-size": "smaller"})
        .text(info.xlabel || "");

    svg.append("g")
        .attr("class", "y axis")
        .call(yAxis)
      .append("text")
        .attr("transform", "rotate(-90)")
        .attr("y", 6).attr("dy", ".71em")
        .style({"text-anchor": "end", "font-size": "smaller"})
        .text(info.ylabel || "");

    svg.append("rect").attr("width", width).attr("height", height)
        .style({"fill": "none", "pointer-events": "all"})
        .on("mouseover", mousemoved).on("mousemove", mousemoved)
        .on("mouseout", mouseout).on("click", mouseclick);

    var hovered_data, hubble;
    function mousemoved() {
        var m = d3.mouse(this), p = data.quadtree.find(m);
        if (p) {
            var dx = p[0] - m[0], dy = p[1] - m[1],
                d = Math.sqrt(dx * dx + dy * dy), rfp = rf(p);
            for (var pp = p.next; pp; pp = pp.next) {
                var rfpp = rf(pp);
                if (rfpp < rfp ? d <= rfpp : d > rfp)
                    p = pp, rfp = rfpp;
            }
            if (d > rfp + 4)
                p = null;
        }
        if (p != hovered_data) {
            if (p)
                place(hovers.datum(p)).style("display", null);
            else
                hovers.style("display", "none");
            hovered_data = p;
        }
        if (p) {
            hubble = hubble || make_bubble("", {color: "tooltip", "pointer-events": "none"});
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
        if (hovered_data && hovered_data[2].length > 1)
            window.location = hoturl("search", {q: hovered_data[2].join(" ")});
        else if (hovered_data)
            window.location = hoturl("paper", {p: hovered_data[2][0]});
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

return hotcrp_graphs;
})(jQuery, d3);
