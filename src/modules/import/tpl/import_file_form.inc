<h2>Manage Files</h2>
[SHADOW_DESCRIPTION:[TEXT|tag=upload-csv:Si prega di caricare il file CSV]]
[FORM|name=files:
    [INPUT|single|not-thumbnail|type=file|plugin=csv-file|name=files:files]
    [INPUT|id=import_csv|type=hidden|name=import-csv:metadata-form]
]
[FILES_ADMIN|file_field=files|sort=weight]