$(document).bind('refresh', function() {
    $('.delete-link').off('click').on('click', function(e) {
        openShadow({
            module: 'node',
            context: 'node_delete',
            widget: 'single',
            components: ['node_delete_form'],
            node: $(this).attr('data-rel')
        });
        e.preventDefault();

    });


    $('.edit-link').off('click').on('click', function(e) {
        var components = (($(this).attr('data-components') != undefined) ? ($(this).attr('data-components').split(',')) : ['node_form', 'file_form', 'manager_form', 'access_form', 'status_form']);
        openShadow({
            module : 'node',
            context: 'node_edit',
            widget: $(this).attr('data-widget'),
            components: components,
            node: $(this).attr('data-rel')
        });
        e.preventDefault();
    });

    $('.add-link').off('click').on('click', function(e) {
            var components = (($(this).attr('data-components') != undefined) ? ($(this).attr('data-components').split(',')) : ['node_form', 'file_form']);
            openShadow({
                module: 'node',
                context: 'node_add',
                widget: $(this).attr('data-widget'),
                components: components,
                node: $(this).attr('data-rel')
            });
            e.preventDefault();
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
