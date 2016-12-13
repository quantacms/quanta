$(document).bind('refresh', function() {
    $('.delete-link').off('click').on('click', function(e) {
        openShadow({
            module: 'node',
            context: 'node_delete',
            widget: 'single',
            components: ['node_delete_form'],
            node: $(this).attr('rel')
        });
        e.preventDefault();

    });

    // TODO: redundant links.
    // TODO: add 'access_form'.
    $('.edit-link').off('click').off('click').on('click', function(e) {
        openShadow({
            module : 'node',
            context: 'node_edit',
            widget: 'tabs',
            components: ['node_form', 'file_form', 'manager_form'],
            node: $(this).attr('rel')
        });
        e.preventDefault();
    });

    $('.add-link').each(function() {
        $(this).off('click').off('click').on('click', function(e) {
            openShadow({
                module: 'node',
                context: 'node_add',
                widget: $(this).attr('data-widget'),
                components: ['node_form', 'file_form'],
                node: $(this).attr('rel')
            });
            e.preventDefault();
        });
    });

    $('.node-item-actions').parent()
        // TO BE COMPLETED
        .off('mouseenter').on('mouseenter', function() {
            $(this).parent().css('opacity', '0.8');
            $(this).children('.node-item-actions').show();
        })
        .off('mouseleave').on('mouseleave', function() {
            $(this).parent().css('opacity', '1');
            $(this).children('.node-item-actions').hide();
        });
});