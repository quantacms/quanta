<h4>[TEXT|tag=file-operations:Operazioni sulle immagini]</h4>
[FORM|name=file_operations_form|id=file_operations_form:
    [TEXT|tag=file-operations-desc:Scegli l'azione che desideri applicare all'immagine]
    [INPUT|type=hidden|name=form|value=file_operations:file_operations_form]
    [INPUT|type=hidden|id=current_url|required|name=current_url:file_operations_form]
    [INPUT|type=hidden|name=img_node|value={SHADOW:img_node}:file_operations_form]
    [INPUT|type=hidden|name=img_key|value={SHADOW:img_key}:file_operations_form]
    [INPUT|type=hidden|name=img|value={SHADOW:img}:file_operations_form]
    [INPUT|id=edit_action|type=hidden|name=action|value=file_operations:file_operations_form]
    [INPUT|type=hidden|class=d-none|id=show_delete_btn|name=show_delete_btn|value={SHADOW:show_delete_btn}:file_operations_form]
    [INPUT|type=hidden|class=d-none|id=show_set_as_thumbnail_btn|name=show_set_as_thumbnail_btn|value={SHADOW:show_set_as_thumbnail_btn}:file_operations_form]
]
