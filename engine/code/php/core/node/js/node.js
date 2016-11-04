$(document).bind('shadow_node_edit_submit', function(ev) {
   var editor = CKEDITOR.instances.edit_content;
   var edata = editor.getData();
   $('#edit_content').html(edata);
});

$(document).bind('refresh', function() {

    $('.delete-link').on('click', function() {
        openShadow({ module : 'node', context: 'node_delete', widget: 'single', node: $(this).attr('rel')});
    });

    $('.edit-link').bind('click', function() {
        openShadow({ module : 'node', context: 'node_edit', widget: 'tabs', node: $(this).attr('rel')});
    });

    $('.add-link').each(function() {
        $(this).unbind().bind('click', function() {
            openShadow({
                module: 'node',
                context: 'node_add',
                type: $(this).attr('data-type'),
                widget: $(this).attr('data-widget')
            });
        });
    });

    $('.node-item').on('mouseenter', function() {
        $(this).parent().css('opacity', '0.5');
        $(this).children('.node-item-actions').show();
    });

    $('.node-item').on('mouseleave', function() {
        $(this).parent().css('opacity', '1');
        $(this).children('.node-item-actions').hide();
    });

});