function initJudgehostTimeline(dataUrl) {
    const margin = {top: 20, right: 30, bottom: 40, left: 150};
    const $container = $('#judgehost-timeline');
    const containerEl = $container[0];
    const $status = $container.find('.timeline-status');

    let canvas = containerEl.querySelector('canvas.timeline-canvas');
    if (!canvas) {
        canvas = document.createElement('canvas');
        canvas.className = 'timeline-canvas';
        containerEl.appendChild(canvas);
    }
    const ctx = canvas.getContext('2d');

    let tooltip = d3.select('.timeline-tooltip');
    if (tooltip.empty()) {
        tooltip = d3.select('body').append('div')
            .attr('class', 'timeline-tooltip')
            .style('opacity', 0);
    }

    const colors = d3.scale.category20();

    function buildRowIndex(runs) {
        const index = new Map();
        for (let i = 0; i < runs.length; i++) {
            const run = runs[i];
            let list = index.get(run.hostname);
            if (!list) {
                list = [];
                index.set(run.hostname, list);
            }
            list.push(run);
        }
        index.forEach(function(list) {
            list.sort(function(a, b) {
                return (a.startTime - b.startTime) || (a.endtime - b.endtime);
            });
        });
        return index;
    }

    let currentJson = null;
    let width = 0;
    let height = 0;
    let x = null;
    let y = null;
    let xAxis = null;
    let xAxisGroup = null;
    let activeHostnames = [];
    let rowIndex = new Map();
    let rafId = null;

    function hideTooltip() {
        tooltip.style('opacity', 0);
    }

    function showTooltip(run, pageX, pageY) {
        const duration = (Math.max(0, run.endtime - run.startTime) / 1000).toFixed(3);
        // Build the tooltip from DOM nodes so names are never parsed as HTML.
        tooltip.html('');
        [
            ['Contest', run.contest_shortname],
            ['Problem', run.problem_name],
            ['Submission', 's' + run.submitid + ' (j' + run.jobid + ')'],
            ['Result', run.runresult || 'judging'],
            ['Duration', duration + 's'],
        ].forEach(function(row) {
            const line = tooltip.append('div');
            line.append('b').text(row[0] + ': ');
            line.append('span').text(row[1]);
        });
        tooltip
            .style('left', (pageX + 10) + 'px')
            .style('top', (pageY - 28) + 'px')
            .style('opacity', 0.95);
    }

    function findRunAt(mx, my) {
        if (!x || !y || width <= 0 || activeHostnames.length === 0) {
            return null;
        }
        const bandHeight = y.rangeBand();
        let matchedHost = null;
        for (let i = 0; i < activeHostnames.length; i++) {
            const name = activeHostnames[i];
            const rowY = y(name);
            if (my >= rowY && my <= rowY + bandHeight) {
                matchedHost = name;
                break;
            }
        }
        if (matchedHost === null) {
            return null;
        }

        const runs = rowIndex.get(matchedHost);
        if (!runs || runs.length === 0) {
            return null;
        }

        const domain = x.domain();
        const tAtMouse = x.invert(mx).getTime();
        const pxToMs = (domain[1].getTime() - domain[0].getTime()) / width;
        const minEnd = tAtMouse - Math.max(pxToMs * 2, 0);

        for (let i = runs.length - 1; i >= 0; i--) {
            const run = runs[i];
            if (run.startTime > tAtMouse || run.endtime < minEnd) {
                continue;
            }
            const rx = x(run.startTime);
            const rw = Math.max(1, x(run.endtime) - rx);
            if (mx >= rx && mx <= rx + rw) {
                return run;
            }
        }
        return null;
    }

    function cancelScheduledDraw() {
        if (rafId !== null) {
            window.cancelAnimationFrame(rafId);
            rafId = null;
        }
    }

    function drawCanvas() {
        rafId = null;
        if (!ctx || !x || !y || width <= 0 || height <= 0) {
            return;
        }

        const dpr = window.devicePixelRatio || 1;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);

        const domain = x.domain();
        const visStart = domain[0].getTime();
        const visEnd = domain[1].getTime();
        const pxToMs = Math.max(0, (visEnd - visStart) / width);
        const minEnd = visStart - pxToMs * 2;
        const bandHeight = y.rangeBand();

        for (let r = 0; r < activeHostnames.length; r++) {
            const hostName = activeHostnames[r];
            const runs = rowIndex.get(hostName);
            if (!runs || runs.length === 0) {
                continue;
            }
            const rowY = y(hostName);
            let lastColor = null;
            for (let i = 0; i < runs.length; i++) {
                const run = runs[i];
                if (run.startTime > visEnd) {
                    break;
                }
                if (run.endtime < minEnd) {
                    continue;
                }
                const rx = x(run.startTime);
                const rw = Math.max(1, x(run.endtime) - rx);
                if (rx + rw < 0 || rx > width) {
                    continue;
                }
                if (run._color !== lastColor) {
                    ctx.fillStyle = run._color;
                    lastColor = run._color;
                }
                ctx.fillRect(rx, rowY, rw, bandHeight);
            }
        }
    }

    function scheduleDraw() {
        if (rafId === null) {
            rafId = window.requestAnimationFrame(drawCanvas);
        }
    }

    function render() {
        if (!currentJson) {
            return;
        }
        const fullWidth = $container.width();
        const fullHeight = $container.height();
        width = fullWidth - margin.left - margin.right;
        height = fullHeight - margin.top - margin.bottom;

        const dpr = window.devicePixelRatio || 1;
        canvas.width = Math.max(1, Math.round(width * dpr));
        canvas.height = Math.max(1, Math.round(height * dpr));
        canvas.style.left = margin.left + 'px';
        canvas.style.top = margin.top + 'px';
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';

        const svg = d3.select('#judgehost-timeline svg')
            .attr('width', fullWidth)
            .attr('height', fullHeight);
        svg.selectAll('*').remove();

        const svgGroup = svg.append('g')
            .attr('transform', 'translate(' + margin.left + ',' + margin.top + ')');

        const showDisabled = $('#toggle-disabled-jh').is(':checked');
        activeHostnames = [];
        for (const id in currentJson.judgehosts) {
            const jh = currentJson.judgehosts[id];
            if (showDisabled || jh.enabled) {
                activeHostnames.push(jh.hostname);
            }
        }
        activeHostnames.sort(d3.ascending);

        const hostNames = new Set(activeHostnames);
        const visibleData = currentJson.allData.filter(function(d) { return hostNames.has(d.hostname); });
        rowIndex = buildRowIndex(visibleData);
        hideTooltip();

        const now = currentJson.now;
        const start = currentJson.start;
        const minStart = d3.min(visibleData, function(d) { return d.startTime; });
        let displayStart = start;
        if (minStart !== undefined && minStart < now) {
            displayStart = Math.max(start, minStart - 1 * 60 * 1000);
        }

        x = d3.time.scale()
            .domain([displayStart, now])
            .range([0, width]);

        y = d3.scale.ordinal()
            .domain(activeHostnames)
            .rangeRoundBands([0, height], 0.1);

        xAxis = d3.svg.axis()
            .scale(x)
            .orient('bottom')
            .tickFormat(d3.time.format('%H:%M'));

        const yAxis = d3.svg.axis()
            .scale(y)
            .orient('left');

        svgGroup.append('g')
            .attr('class', 'timeline-axis timeline-y-axis')
            .call(yAxis);

        xAxisGroup = svgGroup.append('g')
            .attr('class', 'timeline-axis timeline-x-axis')
            .attr('transform', 'translate(0,' + height + ')')
            .call(xAxis);

        const zoom = d3.behavior.zoom()
            .x(x)
            .scaleExtent([1, 1000])
            .on('zoom', function() {
                xAxisGroup.call(xAxis);
                scheduleDraw();
            });

        d3.select(canvas).call(zoom);

        if (activeHostnames.length === 0) {
            $status.text('No judgehosts to display.');
        } else if (visibleData.length === 0) {
            $status.text('No activity in this period.');
        } else {
            $status.text('');
        }

        cancelScheduledDraw();
        drawCanvas();
    }

    // Bind canvas mouse events once (replace previous handlers if re-initialized).
    const $canvas = $(canvas);
    $canvas.off('.timeline');

    $canvas.on('mousemove.timeline', function(e) {
        const rect = canvas.getBoundingClientRect();
        const hit = findRunAt(e.clientX - rect.left, e.clientY - rect.top);
        if (hit) {
            showTooltip(hit, e.pageX, e.pageY);
        } else {
            hideTooltip();
        }
    });

    $canvas.on('mouseleave.timeline', hideTooltip);

    $canvas.on('click.timeline', function(e) {
        const rect = canvas.getBoundingClientRect();
        const hit = findRunAt(e.clientX - rect.left, e.clientY - rect.top);
        if (hit) {
            window.location.href = domjudge_base_url + '/jury/submissions/' + encodeURIComponent(hit.submitid);
        }
    });

    $status.text('Loading timeline...');

    d3.json(dataUrl, function(error, json) {
        if (error) {
            console.error('Error loading timeline data:', error);
            $status.text('Failed to load timeline data.');
            return;
        }

        const allData = json.data;
        for (let i = 0; i < allData.length; i++) {
            const d = allData[i];
            d.startTime = +d.startTime * 1000;
            d.endtime = +d.endtime * 1000;
            d._color = colors(d.jobid % 20);
        }

        currentJson = {
            now: json.now * 1000,
            start: json.twenty_four_hours_ago * 1000,
            judgehosts: json.judgehosts,
            allData: allData,
        };

        render();
        $('#toggle-disabled-jh').off('change.timeline').on('change.timeline', render);
    });
}
