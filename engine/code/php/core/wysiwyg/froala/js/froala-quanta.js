$(document).bind('shadow_node_add',function(ev) { froala(); });
$(document).bind('shadow_node_edit',function(ev) { froala(); });

function froala() {
    $('#edit_content').froalaEditor();
}
