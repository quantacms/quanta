$(document).bind('refresh', function() {

    $('.product-edit-link').on('click', function() {
        openShadow({
            module : 'ecommerce',
            context: 'node_edit',
            widget: 'tabs',
            components: ['node_form', 'file_form', 'product_form', 'manager_form'],
            node: $(this).attr('rel')
        });
    });

    $('.product-add-link').each(function() {
        $(this).on('click', function() {
            openShadow({
                module: 'ecommerce',
                context: 'node_add',
                components: ['node_form', 'file_form', 'product_form'],
                widget: 'tabs',

                type: $(this).attr('data-type'),
                node: $(this).attr('rel'),
            });
        });
    });

});