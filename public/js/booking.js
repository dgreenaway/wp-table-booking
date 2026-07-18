/* global tbData, jQuery */
(function ($) {
    'use strict';

    // =========================================================================
    // State
    // =========================================================================
    const sel = {
        date:     '',
        time:     '',
        endLabel: '',   // human-readable end time from server (e.g. "2:30 PM")
        area:     '',
        party:    0,
    };

    let currentStep = 1;

    // =========================================================================
    // Init
    // =========================================================================
    $(function () {
        initDatePicker();
        initAreaCards();
        bindNav();
    });

    // =========================================================================
    // Step 1 – Date & Area
    // =========================================================================
    function initDatePicker() {
        const $date = $('#tb-date');

        const today   = new Date();
        const maxDate = new Date();
        maxDate.setDate(today.getDate() + (tbData.maxDays || 60));

        $date.attr('min', formatDate(today));
        $date.attr('max', formatDate(maxDate));

        $date.on('change', function () {
            const raw = $(this).val();
            $('#tb-date-error').hide().text('');
            sel.date = '';
            sel.time = '';

            if (raw) {
                const d        = new Date(raw + 'T12:00:00');
                const dow      = d.getDay();
                const openDays = tbData.openDays    || [0,1,2,3,4,5,6];
                const closed   = tbData.closedDates || [];

                if (openDays.indexOf(dow) === -1) {
                    const dayName = d.toLocaleDateString('en-GB', { weekday: 'long' });
                    $('#tb-date-error').text('We\'re closed on ' + dayName + 's — please choose another date.').show();
                } else if (closed.indexOf(raw) !== -1) {
                    $('#tb-date-error').text('We\'re closed on this date — please choose another day.').show();
                } else {
                    sel.date = raw;
                }
            }

            checkStep1();
        });
    }

    function initAreaCards() {
        const areas = tbData.areas || [];
        const $grid = $('#tb-area-grid').empty();
        const icons = { dining: '🍽️', bar: '🍹', garden: '🌿' };

        areas.forEach(function (a) {
            const icon  = icons[a.id] || '🪑';
            const $card = $('<div class="tb-area-card" tabindex="0">')
                .attr('data-area', a.id)
                .css('color', a.color)
                .html(
                    '<div class="tb-area-card-icon">' + icon + '</div>' +
                    '<div class="tb-area-card-name">' + escHtml(a.label) + '</div>'
                );

            $card.on('click keypress', function (e) {
                if (e.type === 'keypress' && e.which !== 13) return;
                $('.tb-area-card').removeClass('selected');
                $card.addClass('selected');
                sel.area = a.id;
                sel.time = '';
                checkStep1();
            });

            $grid.append($card);
        });
    }

    function checkStep1() {
        $('#tb-step1-next').prop('disabled', !(sel.date && sel.area));
    }

    // =========================================================================
    // Step 2 – Time & Party
    // =========================================================================
    function loadTimes() {
        $('#tb-time-wrap').hide();
        $('#tb-time-loading').show();
        sel.time     = '';
        sel.endLabel = '';
        sel.party    = 0;
        checkStep2();

        $.post(tbData.ajaxUrl, {
            action: 'tb_get_times',
            nonce:  tbData.nonce,
            date:   sel.date,
            area:   sel.area,
        }, function (res) {
            $('#tb-time-loading').hide();

            if (!res.success || !res.data || !res.data.length) {
                $('#tb-time-slots').html(
                    '<p style="color:#dc2626;font-size:13px;margin:0;">No available times for this selection.<br>Try a different date or area.</p>'
                );
                $('#tb-time-wrap').show();
                return;
            }

            const $grid = $('#tb-time-slots').empty();
            res.data.forEach(function (slot) {
                const label = slot.end_label ? slot.label + ' – ' + slot.end_label : slot.label;
                const $btn  = $('<button type="button" class="tb-time-slot">')
                    .text(label)
                    .attr('data-time', slot.time);

                if (!slot.available) {
                    $btn.addClass('unavailable').prop('disabled', true);
                } else {
                    $btn.on('click', function () {
                        $('.tb-time-slot').removeClass('selected');
                        $(this).addClass('selected');
                        sel.time     = slot.time;
                        sel.endLabel = slot.end_label || '';
                        renderPartySelector();
                        $('#tb-party-wrap').show();
                        checkStep2();
                    });
                }

                $grid.append($btn);
            });

            $('#tb-time-wrap').show();
        }).fail(function () {
            $('#tb-time-loading').hide();
            $('#tb-time-slots').html('<p style="color:#dc2626;font-size:13px;margin:0;">Could not load times. Please try again.</p>');
            $('#tb-time-wrap').show();
        });
    }

    function renderPartySelector() {
        const max   = tbData.maxParty || 12;
        const $wrap = $('#tb-party-selector').empty();

        for (let i = 1; i <= max; i++) {
            const $btn = $('<button type="button" class="tb-party-btn">').text(i);
            if (i === sel.party) $btn.addClass('selected');

            $btn.on('click', function () {
                $('.tb-party-btn').removeClass('selected');
                $btn.addClass('selected');
                sel.party = i;
                checkStep2();
            });

            $wrap.append($btn);
        }
    }

    function checkStep2() {
        $('#tb-step2-next').prop('disabled', !(sel.time && sel.party));
    }

    // =========================================================================
    // Navigation
    // =========================================================================
    function bindNav() {
        $('#tb-step1-next').on('click', function () {
            goTo(2);
            loadTimes();
        });

        $('#tb-step2-back').on('click', function () { goTo(1); });

        $('#tb-step2-next').on('click', function () {
            if (!(sel.time && sel.party)) return;
            goTo(3);
        });

        $('#tb-step3-back').on('click', function () { goTo(2); });

        $('#tb-step3-next').on('click', function () {
            if (!validateStep3()) return;
            buildSummary();
            goTo(4);
        });

        $('#tb-step4-back').on('click', function () { goTo(3); });
        $('#tb-submit').on('click', submitBooking);
    }

    function goTo(step) {
        $('.tb-panel').hide();
        $('#tb-panel-' + step).show();
        currentStep = step;
        updateStepIndicator();
        $('html, body').animate({ scrollTop: $('#tb-booking-wrap').offset().top - 40 }, 200);
    }

    function updateStepIndicator() {
        $('.tb-step').each(function () {
            const s = parseInt($(this).data('step'), 10);
            $(this).removeClass('active done');
            if (s === currentStep) $(this).addClass('active');
            if (s < currentStep)  $(this).addClass('done');
        });
    }

    // =========================================================================
    // Step 3 validation
    // =========================================================================
    function validateStep3() {
        const name  = $('#tb-name').val().trim();
        const email = $('#tb-email').val().trim();
        hideError();

        if (!name)                     { showError('Please enter your full name.');        return false; }
        if (!email || !isEmail(email)) { showError('Please enter a valid email address.'); return false; }
        return true;
    }

    // =========================================================================
    // Summary
    // =========================================================================
    function buildSummary() {
        const areas = tbData.areas || [];
        let areaLabel = sel.area;
        areas.forEach(function (a) { if (a.id === sel.area) areaLabel = a.label; });

        const timeDisplay = sel.endLabel
            ? formatTime(sel.time) + ' – ' + sel.endLabel
            : formatTime(sel.time);

        const rows = [
            ['Date',       formatDateDisplay(sel.date)],
            ['Time',       timeDisplay],
            ['Area',       areaLabel],
            ['Party size', sel.party + (sel.party === 1 ? ' guest' : ' guests')],
            ['Name',       $('#tb-name').val().trim()],
            ['Email',      $('#tb-email').val().trim()],
        ];

        const phone = $('#tb-phone').val().trim();
        if (phone) rows.push(['Phone', phone]);

        const notes = $('#tb-notes').val().trim();
        if (notes) rows.push(['Special requests', notes]);

        const $summary = $('#tb-summary').empty();
        rows.forEach(function ([lbl, val]) {
            $summary.append(
                '<div class="tb-summary-row">' +
                '<span class="lbl">' + escHtml(lbl) + '</span>' +
                '<span class="val">' + escHtml(val) + '</span>' +
                '</div>'
            );
        });
    }

    // =========================================================================
    // Submit
    // =========================================================================
    function submitBooking() {
        hideError();
        const $btn = $('#tb-submit').prop('disabled', true).text('Submitting…');

        $.post(tbData.ajaxUrl, {
            action:           'tb_submit_booking',
            nonce:            tbData.nonce,
            date:             sel.date,
            time:             sel.time,
            area:             sel.area,
            party_size:       sel.party,
            customer_name:    $('#tb-name').val().trim(),
            customer_email:   $('#tb-email').val().trim(),
            customer_phone:   $('#tb-phone').val().trim(),
            special_requests: $('#tb-notes').val().trim(),
        }, function (res) {
            if (res.success) {
                const d = res.data;
                $('#tb-success-msg').text(
                    'Your table for ' + d.party_size + ' has been reserved on ' +
                    d.date + ' at ' + d.time + '. A confirmation has been sent to your email.'
                );
                $('#tb-success-ref').text(d.reservation_number);
                $('.tb-step').removeClass('active').addClass('done');
                $('.tb-panel').hide();
                $('#tb-panel-success').show();
            } else {
                $btn.prop('disabled', false).text('Confirm Booking');
                showError(res.data || 'Something went wrong. Please try again.');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Confirm Booking');
            showError('Network error. Please check your connection and try again.');
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================
    function showError(msg) { $('#tb-error').text(msg).show(); }
    function hideError()    { $('#tb-error').hide().text(''); }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function isEmail(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }

    function formatDate(d) {
        return d.getFullYear() + '-' +
               String(d.getMonth() + 1).padStart(2, '0') + '-' +
               String(d.getDate()).padStart(2, '0');
    }

    function formatDateDisplay(str) {
        if (!str) return '';
        return new Date(str + 'T12:00:00').toLocaleDateString('en-GB', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
    }

    function formatTime(str) {
        if (!str) return '';
        const [h, m] = str.split(':');
        const d = new Date();
        d.setHours(parseInt(h, 10), parseInt(m, 10));
        return d.toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true });
    }

}(jQuery));
