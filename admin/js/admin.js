/* global tbAdmin, jQuery */
(function ($) {
    'use strict';

    // =========================================================================
    // Quick status modal (reservations list)
    // =========================================================================
    $(document).on('click', '.tb-status-btn', function () {
        const id     = $(this).data('id');
        const status = $(this).data('status');
        $('#tb-modal-id').val(id);
        $('#tb-modal-status').val(status);
        $('#tb-status-modal').show();
    });

    $('#tb-modal-cancel').on('click', function () {
        $('#tb-status-modal').hide();
    });

    $('#tb-status-modal').on('click', function (e) {
        if ($(e.target).is('#tb-status-modal')) $(this).hide();
    });

    $('#tb-modal-save').on('click', function () {
        const id     = $('#tb-modal-id').val();
        const status = $('#tb-modal-status').val();

        $.post(tbAdmin.ajaxUrl, {
            action: 'tb_update_status',
            nonce:  tbAdmin.nonce,
            id:     id,
            status: status,
        }, function (res) {
            if (res.success) {
                location.reload();
            } else {
                alert('Error: ' + (res.data || 'Could not update status'));
            }
        });

        $('#tb-status-modal').hide();
    });

    // =========================================================================
    // Settings page – areas management
    // =========================================================================
    let areaIndex = $('.tb-area-row').length;

    $('#tb-add-area').on('click', function () {
        const html = '<div class="tb-area-row" data-index="' + areaIndex + '">' +
            '<input type="text"  name="areas[' + areaIndex + '][id]"    value=""   placeholder="id (no spaces)"   class="tb-admin-input" style="width:120px;">' +
            '<input type="text"  name="areas[' + areaIndex + '][label]" value=""   placeholder="Display name"     class="tb-admin-input" style="width:160px;">' +
            '<input type="color" name="areas[' + areaIndex + '][color]" value="#888888" class="tb-color-input">' +
            '<button type="button" class="button tb-remove-area">Remove</button>' +
            '</div>';
        $('#tb-areas-list').append(html);
        areaIndex++;
    });

    $(document).on('click', '.tb-remove-area', function () {
        $(this).closest('.tb-area-row').remove();
    });

    $(document).on('input', 'input[name$="[id]"]', function () {
        this.value = this.value.toLowerCase().replace(/[^a-z0-9_]/g, '_');
    });

    // =========================================================================
    // Booking mode – show/hide Simple options and update mode card selection
    // =========================================================================
    $('input[name="booking_mode"]').on('change', function () {
        const isSimple = $(this).val() === 'simple';
        $('#tb-simple-opts').toggle(isSimple);
        $('.tb-mode-card').removeClass('tb-mode-selected');
        $(this).closest('.tb-mode-card').addClass('tb-mode-selected');
    });

    // =========================================================================
    // Reminder preview – update label when hours change
    // =========================================================================
    $(document).on('input change', '.tb-reminder-hours', function () {
        const idx   = $(this).data('idx');
        const hours = parseInt($(this).val(), 10) || 0;
        let lbl;
        if (hours <= 0) {
            lbl = '—';
        } else if (hours >= 24 && hours % 24 === 0) {
            const days = hours / 24;
            lbl = days + ' day' + (days !== 1 ? 's' : '');
        } else {
            lbl = hours + ' hour' + (hours !== 1 ? 's' : '');
        }
        $('#tb-rp-' + idx).html(
            '"Reminder: Your table at [Restaurant] is <strong>' + lbl + '</strong> away"'
        );
    });

    // =========================================================================
    // Log viewer – client-side level + context filter
    // =========================================================================
    $('#tb-log-level, #tb-log-ctx').on('change', function () {
        const level = $('#tb-log-level').val();
        const ctx   = $('#tb-log-ctx').val();
        $('#tb-log-body tr').each(function () {
            const ok = (!level || $(this).data('level') === level) &&
                       (!ctx   || $(this).data('ctx')   === ctx);
            $(this).toggleClass('tb-log-hidden', !ok);
        });
    });

    // =========================================================================
    // Emails page – media library logo picker
    // =========================================================================
    var logoFrame;

    $('#tb-upload-logo').on('click', function (e) {
        e.preventDefault();
        if (logoFrame) { logoFrame.open(); return; }
        logoFrame = wp.media({
            title:    'Select Logo',
            button:   { text: 'Use this logo' },
            multiple: false,
            library:  { type: 'image' },
        });
        logoFrame.on('select', function () {
            var att = logoFrame.state().get('selection').first().toJSON();
            $('#tb-logo-id').val(att.id);
            $('#tb-logo-url').val(att.url);
            $('#tb-logo-preview-wrap')
                .removeClass('tb-logo-empty')
                .html('<img id="tb-logo-preview" src="' + att.url + '" alt="Logo preview">');
            $('#tb-upload-logo').text('Change Logo');
            if (!$('#tb-remove-logo').length) {
                $('#tb-upload-logo').after(
                    ' <button type="button" class="button tb-btn-danger" id="tb-remove-logo">Remove</button>'
                );
            }
        });
        logoFrame.open();
    });

    $(document).on('click', '#tb-remove-logo', function () {
        $('#tb-logo-id').val('');
        $('#tb-logo-url').val('');
        $('#tb-logo-preview-wrap')
            .addClass('tb-logo-empty')
            .html('<span>No logo set</span>');
        $('#tb-upload-logo').text('Upload / Select Logo');
        $(this).remove();
    });

    // =========================================================================
    // Auto-dismiss notices
    // =========================================================================
    setTimeout(function () {
        $('.notice.is-dismissible').fadeOut(400);
    }, 4000);

}(jQuery));
