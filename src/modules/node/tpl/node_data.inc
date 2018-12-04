[INPUT|id=edit_action|type=hidden|required|name=action|value={SHADOW:context}:node-data-form]
[INPUT|type=hidden|title=path|disabled|id=edit_path|required|name=edit-path|value={ATTRIBUTE|name=name|no_qtags}:node-data-form]
[INPUT|name=redirect|type=hidden|value={SHADOW:redirect}:node-data-form]
[INPUT|id=tmp_files_dir|type=hidden|required|name=tmp_files_dir|value={ATTRIBUTE|name=tmp_files_dir|no_qtags}:node-data-form]
[FORM:node-data-form]
<!-- File plugin -->
<script src="/src/modules/jquery/assets/js/jquery-ui.min.js?refresh=[DOCTOR_TIMESTAMP]"></script>
<script src="/src/modules/jquery/assets/js/jquery.ui.widget.js?refresh=[DOCTOR_TIMESTAMP]"></script>
<script src="/src/modules/jquery/assets/js/jquery.tablesorter.js?refresh=[DOCTOR_TIMESTAMP]"></script>

<script src="/src/modules/file/assets/js/jquery.knob.js?refresh=[DOCTOR_TIMESTAMP]"></script>
<script src="/src/modules/file/assets/js/jquery.iframe-transport.js?refresh=[DOCTOR_TIMESTAMP]"></script>
<script src="/src/modules/file/assets/js/jquery.fileupload.js?refresh=[DOCTOR_TIMESTAMP]"></script>
<script src="/src/modules/file/assets/js/file-upload.js?refresh=[DOCTOR_TIMESTAMP]"></script>
