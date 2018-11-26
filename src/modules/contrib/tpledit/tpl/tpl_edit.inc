<h2>Edit Template</h2>
<div class="shadow-hint">Here you can edit this node's tpl file, that has been set at [ATTRIBUTE|name=tpl_file].</div>
[INPUT|title=Template file|id=edit_tpl_file|required|name=edit-tpl-file|type=hidden|value={ATTRIBUTE|name=tpl_file}:tpl-form]
[INPUT|title=Template code|rows=20|id=edit_tpl|required|name=edit-tpl|type=text|value={ATTRIBUTE|no_qtags|name=tpl}:tpl-form]
[FORM:tpl-form]
