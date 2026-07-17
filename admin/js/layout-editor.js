/* global tbLayout, jQuery */
(function ($) {
    'use strict';

    const canvas = document.getElementById('tb-canvas');
    const ctx    = canvas.getContext('2d');
    const GRID   = 20;

    // =========================================================================
    // State
    // =========================================================================
    const state = {
        tables:    (tbLayout.tables || []).map(normTable),
        selected:  null,    // index into state.tables
        mode:      'select',
        drag:      null,    // { idx, startX, startY, origX, origY }
        draw:      null,    // { x1, y1, x2, y2 } while drawing new table
        bookedIds: [],
        dirty:     false,
    };

    const areaColors = {};
    (tbLayout.areas || []).forEach(function (a) { areaColors[a.id] = a.color || '#6b7280'; });

    // =========================================================================
    // Helpers
    // =========================================================================
    function normTable(t) {
        return {
            id:           parseInt(t.id)           || 0,
            table_name:   t.table_name             || 'Table',
            capacity:     parseInt(t.capacity)     || 4,
            min_capacity: parseInt(t.min_capacity) || 1,
            area:         t.area                   || 'dining',
            pos_x:        parseInt(t.pos_x)        || 50,
            pos_y:        parseInt(t.pos_y)        || 50,
            width:        parseInt(t.width)        || 80,
            height:       parseInt(t.height)       || 80,
            shape:        t.shape                  || 'square',
        };
    }

    function snap(v) { return Math.round(v / GRID) * GRID; }

    function canvasXY(e) {
        const rect   = canvas.getBoundingClientRect();
        const scaleX = canvas.width  / rect.width;
        const scaleY = canvas.height / rect.height;
        return {
            x: (e.clientX - rect.left) * scaleX,
            y: (e.clientY - rect.top)  * scaleY,
        };
    }

    function tableAt(x, y) {
        for (let i = state.tables.length - 1; i >= 0; i--) {
            const t = state.tables[i];
            if (t.shape === 'circle') {
                const cx = t.pos_x + t.width / 2;
                const cy = t.pos_y + t.height / 2;
                const dx = (x - cx) / (t.width  / 2);
                const dy = (y - cy) / (t.height / 2);
                if (dx * dx + dy * dy <= 1) return i;
            } else {
                if (x >= t.pos_x && x <= t.pos_x + t.width &&
                    y >= t.pos_y && y <= t.pos_y + t.height) return i;
            }
        }
        return -1;
    }

    // =========================================================================
    // Render
    // =========================================================================
    function render() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        drawGrid();
        state.tables.forEach(function (t, i) { drawTable(t, i === state.selected); });
        if (state.draw) drawPreview();
    }

    function drawGrid() {
        ctx.save();
        ctx.strokeStyle = '#e5e7eb';
        ctx.lineWidth   = 1;
        for (let x = 0; x <= canvas.width;  x += GRID) { ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, canvas.height); ctx.stroke(); }
        for (let y = 0; y <= canvas.height; y += GRID) { ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(canvas.width, y);  ctx.stroke(); }
        ctx.restore();
    }

    function drawTable(t, selected) {
        const booked    = state.bookedIds.indexOf(t.id) !== -1;
        const baseColor = booked ? '#ef4444' : (areaColors[t.area] || '#6b7280');

        ctx.save();
        ctx.fillStyle   = baseColor + '33';
        ctx.strokeStyle = selected ? '#1d4ed8' : baseColor;
        ctx.lineWidth   = selected ? 3 : 2;

        if (t.shape === 'circle') {
            const cx = t.pos_x + t.width  / 2;
            const cy = t.pos_y + t.height / 2;
            ctx.beginPath();
            ctx.ellipse(cx, cy, t.width / 2, t.height / 2, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
        } else {
            roundRect(ctx, t.pos_x, t.pos_y, t.width, t.height, 6);
            ctx.fill();
            ctx.stroke();
        }

        const cx = t.pos_x + t.width  / 2;
        const cy = t.pos_y + t.height / 2;

        ctx.fillStyle    = selected ? '#1d4ed8' : (booked ? '#991b1b' : '#1f2937');
        ctx.textAlign    = 'center';
        ctx.textBaseline = 'middle';
        ctx.font         = 'bold 11px -apple-system, sans-serif';
        ctx.fillText(t.table_name, cx, cy - 6);
        ctx.font = '10px -apple-system, sans-serif';
        ctx.fillText(t.capacity + ' cap', cx, cy + 7);

        ctx.restore();
    }

    function roundRect(c, x, y, w, h, r) {
        c.beginPath();
        c.moveTo(x + r, y);
        c.lineTo(x + w - r, y);     c.arcTo(x + w, y,     x + w, y + r,     r);
        c.lineTo(x + w, y + h - r); c.arcTo(x + w, y + h, x + w - r, y + h, r);
        c.lineTo(x + r, y + h);     c.arcTo(x,     y + h, x,     y + h - r, r);
        c.lineTo(x, y + r);         c.arcTo(x,     y,     x + r, y,         r);
        c.closePath();
    }

    function drawPreview() {
        const d = state.draw;
        const x = Math.min(d.x1, d.x2);
        const y = Math.min(d.y1, d.y2);
        const w = Math.abs(d.x2 - d.x1);
        const h = Math.abs(d.y2 - d.y1);
        ctx.save();
        ctx.strokeStyle = '#2271b1';
        ctx.lineWidth   = 2;
        ctx.setLineDash([4, 4]);
        ctx.strokeRect(x, y, w, h);
        ctx.restore();
    }

    // =========================================================================
    // Mouse events
    // =========================================================================
    canvas.addEventListener('mousedown', function (e) {
        const pos = canvasXY(e);

        if (state.mode === 'add') {
            state.draw = { x1: snap(pos.x), y1: snap(pos.y), x2: snap(pos.x), y2: snap(pos.y) };
            return;
        }

        const idx = tableAt(pos.x, pos.y);
        if (idx >= 0) {
            state.selected = idx;
            const t = state.tables[idx];
            state.drag = { idx: idx, startX: pos.x, startY: pos.y, origX: t.pos_x, origY: t.pos_y };
            showProps(t);
        } else {
            state.selected = null;
            hideProps();
        }
        render();
    });

    canvas.addEventListener('mousemove', function (e) {
        const pos = canvasXY(e);

        if (state.mode === 'add' && state.draw) {
            state.draw.x2 = snap(pos.x);
            state.draw.y2 = snap(pos.y);
            render();
            return;
        }

        if (state.drag) {
            const t    = state.tables[state.drag.idx];
            t.pos_x    = snap(Math.max(0, state.drag.origX + pos.x - state.drag.startX));
            t.pos_y    = snap(Math.max(0, state.drag.origY + pos.y - state.drag.startY));
            state.dirty = true;
            render();
        }
    });

    canvas.addEventListener('mouseup', function () {
        if (state.mode === 'add' && state.draw) {
            const d = state.draw;
            const x = snap(Math.min(d.x1, d.x2));
            const y = snap(Math.min(d.y1, d.y2));
            const w = snap(Math.max(40, Math.abs(d.x2 - d.x1)));
            const h = snap(Math.max(40, Math.abs(d.y2 - d.y1)));

            if (w >= 40 && h >= 40) {
                const area  = $('#tb-new-area').val()                  || 'dining';
                const shape = $('#tb-new-shape').val()                 || 'square';
                const cap   = parseInt($('#tb-new-capacity').val(), 10) || 4;
                const count = state.tables.length + 1;

                state.tables.push({
                    id:           0,
                    table_name:   'T' + count,
                    capacity:     cap,
                    min_capacity: 1,
                    area:         area,
                    pos_x:        x,
                    pos_y:        y,
                    width:        w,
                    height:       h,
                    shape:        shape,
                });
                state.selected = state.tables.length - 1;
                showProps(state.tables[state.selected]);
                state.dirty = true;
            }

            state.draw = null;
            render();
            return;
        }

        state.drag = null;
    });

    // =========================================================================
    // Properties panel
    // =========================================================================
    function showProps(t) {
        $('#tb-props-hint').hide();
        $('#tb-props-form').show();
        $('#tb-prop-name').val(t.table_name);
        $('#tb-prop-area').val(t.area);
        $('#tb-prop-shape').val(t.shape);
        $('#tb-prop-capacity').val(t.capacity);
        $('#tb-prop-min-cap').val(t.min_capacity);
        $('#tb-prop-x').val(t.pos_x);
        $('#tb-prop-y').val(t.pos_y);
        $('#tb-prop-w').val(t.width);
        $('#tb-prop-h').val(t.height);
    }

    function hideProps() {
        $('#tb-props-form').hide();
        $('#tb-props-hint').show();
    }

    $('#tb-apply-props').on('click', function () {
        if (state.selected === null) return;
        const t        = state.tables[state.selected];
        t.table_name   = $('#tb-prop-name').val()                    || t.table_name;
        t.area         = $('#tb-prop-area').val();
        t.shape        = $('#tb-prop-shape').val();
        t.capacity     = Math.max(1,  parseInt($('#tb-prop-capacity').val(), 10) || 4);
        t.min_capacity = Math.max(1,  parseInt($('#tb-prop-min-cap').val(),  10) || 1);
        t.pos_x        = Math.max(0,  parseInt($('#tb-prop-x').val(),        10) || 0);
        t.pos_y        = Math.max(0,  parseInt($('#tb-prop-y').val(),        10) || 0);
        t.width        = Math.max(40, parseInt($('#tb-prop-w').val(),        10) || 80);
        t.height       = Math.max(40, parseInt($('#tb-prop-h').val(),        10) || 80);
        state.dirty = true;
        render();
    });

    // =========================================================================
    // Toolbar
    // =========================================================================
    $('#tb-tool-select').on('click', function () {
        state.mode = 'select';
        state.draw = null;
        $('#tb-tool-select').addClass('active');
        $('#tb-tool-add').removeClass('active');
        $('#tb-add-options').hide();
        canvas.style.cursor = 'default';
    });

    $('#tb-tool-add').on('click', function () {
        state.mode     = 'add';
        state.selected = null;
        state.drag     = null;
        hideProps();
        $('#tb-tool-add').addClass('active');
        $('#tb-tool-select').removeClass('active');
        $('#tb-add-options').show();
        canvas.style.cursor = 'crosshair';
        render();
    });

    $('#tb-tool-delete').on('click', function () {
        if (state.selected === null) { alert('Select a table first.'); return; }
        if (!confirm('Delete this table? This cannot be undone.')) return;
        state.tables.splice(state.selected, 1);
        state.selected = null;
        state.dirty    = true;
        hideProps();
        render();
    });

    // =========================================================================
    // Save layout
    // =========================================================================
    $('#tb-save-layout').on('click', function () {
        const $btn = $(this).prop('disabled', true).text('Saving…');
        const $msg = $('#tb-layout-msg');

        $.post(tbLayout.ajaxUrl, {
            action: 'tb_save_layout',
            nonce:  tbLayout.nonce,
            tables: JSON.stringify(state.tables),
        }, function (res) {
            $btn.prop('disabled', false).text('Save Layout');
            if (res.success) {
                $msg.attr('class', 'tb-msg-ok').text('Layout saved.');
                state.dirty = false;
            } else {
                $msg.attr('class', 'tb-msg-err').text('Error: ' + (res.data || 'Save failed.'));
            }
            setTimeout(function () { $msg.text(''); }, 3000);
        }).fail(function () {
            $btn.prop('disabled', false).text('Save Layout');
            $msg.attr('class', 'tb-msg-err').text('Network error. Please try again.');
        });
    });

    // =========================================================================
    // Reservation overlay
    // =========================================================================
    $('#tb-overlay-date').on('change', function () {
        const date = $(this).val();
        if (!date) return;

        $.post(tbLayout.ajaxUrl, {
            action: 'tb_get_overlay_times',
            nonce:  tbLayout.nonce,
            date:   date,
        }, function (res) {
            const $sel = $('#tb-overlay-time').empty().append('<option value="">Select time…</option>');
            if (res.success) {
                res.data.forEach(function (slot) {
                    $sel.append('<option value="' + slot.value + '">' + slot.label + '</option>');
                });
            }
        });
    });

    $('#tb-overlay-time').on('change', function () {
        const date = $('#tb-overlay-date').val();
        const time = $(this).val();

        if (!date || !time) { state.bookedIds = []; render(); return; }

        $.post(tbLayout.ajaxUrl, {
            action: 'tb_get_overlay',
            nonce:  tbLayout.nonce,
            date:   date,
            time:   time,
        }, function (res) {
            state.bookedIds = res.success ? res.data.booked_ids : [];
            render();
        });
    });

    // =========================================================================
    // Unsaved changes warning
    // =========================================================================
    window.addEventListener('beforeunload', function (e) {
        if (state.dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    render();

}(jQuery));
