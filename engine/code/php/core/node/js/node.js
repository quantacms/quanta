$(document).bind('shadow_node_edit_submit', function(ev) {
   var editor = CKEDITOR.instances.edit_content;
   var edata = editor.getData();
   $('#edit_content').html(edata);
});

$(document).ready(function() {
    $('.delete-link').on('click', function() {
        openShadow({ module : 'node', context: 'node_delete', widget: 'single'});
    });

    $('.edit-link').bind('click', function() {
        openShadow({ module : 'node', context: 'node_edit', widget: 'tabs'});
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



});