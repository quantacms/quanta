<h2>Edit Metadata</h2>
<div class="shadow-hint">Here you can edit this node's metadata. Only change those items if you <b>really</b> know what you are doing!</div>
[INPUT|title=author|id=edit_author|required|name=edit-author|default={USERATTRIBUTE|name=name}|value={ATTRIBUTE|name=author|nowiki}:metadata-form]
[INPUT|title=path|disabled|id=edit_path|required|name=edit-path|value={ATTRIBUTE|name=name|nowiki}:metadata-form]
[INPUT|title=time|id=edit_time|required|name=edit-time|value={ATTRIBUTE|name=time|nowiki}|type=time:metadata-form]
[INPUT|title=date|id=edit_date|required|name=edit-date|value={ATTRIBUTE|name=date|nowiki}|type=date:metadata-form]
[INPUT|title=thumbnail|id=edit_thumbnail|type=hidden|name=edit-thumbnail|value={ATTRIBUTE|name=thumbnail|nowiki}:metadata-form]
[INPUT|disabled|title=father|id=edit_father|required|name=edit-father|value={ATTRIBUTE|name=father}:metadata-form]
[FORM:metadata-form]
