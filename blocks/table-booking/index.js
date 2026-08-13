(function (blocks, element, serverSideRender) {
    var el  = element.createElement;
    var SSR = serverSideRender;

    blocks.registerBlockType('table-booking/form', {
        title:       'getBooked – Reservation Form',
        description: 'Display your restaurant table reservation form.',
        icon:        'calendar-alt',
        category:    'widgets',
        keywords:    ['booking', 'reservation', 'restaurant', 'table', 'getbooked'],
        supports: { html: false },

        edit: function () {
            return el(SSR, { block: 'table-booking/form' });
        },

        save: function () {
            return null; // rendered server-side
        },
    });
}(window.wp.blocks, window.wp.element, window.wp.serverSideRender));
