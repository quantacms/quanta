<h2>Manage Files</h2>
[SHADOW_DESCRIPTION:[TEXT|tag=upload-logo:Upload yor logo]]
[FORM|name=files:
    [INPUT|single|not-thumbnail|type=file|plugin=csv-file|name=files:files]
    [INPUT|title=thumbnail|id=edit_thumbnail|type=hidden|name=edit-thumbnail|value={ATTRIBUTE|name=thumbnail|no_qtags}:metadata-form]
]
[FILES_ADMIN|file_field=files|sort=weight]