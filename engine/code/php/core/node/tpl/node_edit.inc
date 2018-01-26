<h2>Edit Content</h2>
<div class="shadow-hint">Here you can edit this node's title and contents.</div>

[INPUT|title=title|id=edit_title|required|name=edit-title|value={ATTRIBUTE|name=title|nowiki}:shadow-form]
[INPUT|id=edit_action|type=hidden|required|name=action|value={SHADOW:context}:shadow-form]
[INPUT|title=teaser|id=edit_teaser|rows=2|name=edit-teaser|type=text|value={ATTRIBUTE|name=teaser|nowiki}:shadow-form]
[INPUT|wysiwyg|title=body|id=edit_content|required|name=edit-content|type=text|value={ATTRIBUTE|name=body|nowiki}:shadow-form]
[INPUT|name=redirect|type=hidden|value={SHADOW:redirect}:shadow-form]
[FORM:shadow-form]
