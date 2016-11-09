$(document).bind('refresh', function() {

    $('.delete-link').on('click', function() {
        openShadow({ module : 'node', context: 'node_delete', widget: 'single', node: $(this).attr('rel')});
    });

    $('.edit-link').on('click', function() {
        openShadow({ module : 'node', context: 'node_edit', widget: 'tabs', node: $(this).attr('rel')});
    });

    $('.add-link').each(function() {
        $(this).on('click', function() {
            openShadow({
                module: 'node',
                context: 'node_add',
                type: $(this).attr('data-type'),
                node: $(this).attr('rel'),
                widget: $(this).attr('data-widget')
            });
        });
    });

    $('.node-item-actions').parent()
        // TO BE COMPLETED
        /*
        .on('mouseenter', function() {
            $(this).parent().css('opacity', '0.8');
            $(this).children('.node-item-actions').show();
        })
        .on('mouseleave', function() {
            $(this).parent().css('opacity', '1');
            $(this).children('.node-item-actions').hide();
        });
        */
});