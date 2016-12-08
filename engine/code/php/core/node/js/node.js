$(document).bind('refresh', function() {

    $('.delete-link').on('click', function() {
        openShadow({
            module: 'node',
            context: 'node_delete',
            widget: 'single',
            components: ['node_delete_form'],
            node: $(this).attr('rel')
        });
    });

    // TODO: redundant links.
    // TODO: add 'access_form'.
    $('.edit-link').on('click', function() {
        openShadow({
            module : 'node',
            context: 'node_edit',
            widget: 'tabs',
            components: ['node_form', 'file_form', 'manager_form'],
            node: $(this).attr('rel')
        });
    });

    $('.add-link').each(function() {
        $(this).on('click', function() {
            openShadow({
                module: 'node',
                context: 'node_add',
                widget: $(this).attr('data-widget'),
                components: ['node_form', 'file_form'],
                node: $(this).attr('rel')
            });
        });
    });

    $('.node-item-actions').parent()
        // TO BE COMPLETED
        .on('mouseenter', function() {
            $(this).parent().css('opacity', '0.8');
            $(this).children('.node-item-actions').show();
        })
        .on('mouseleave', function() {
            $(this).parent().css('opacity', '1');
            $(this).children('.node-item-actions').hide();
        });

});