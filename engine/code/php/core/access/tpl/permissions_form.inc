<h2>Manage Permissions</h2>
<div class="shadow-hint">Here you can change the permissions for this node. You should use the role name.</div>
[INPUT|multiple|type=string|name=node_view|value={PERMISSION|name=node_view}:edit-permissions]
[INPUT|multiple|type=string|name=node_edit|value={PERMISSION|name=node_edit}:edit-permissions]
[INPUT|multiple|type=string|name=node_add|value={PERMISSION|name=node_add}:edit-permissions]
[INPUT|multiple|type=string|name=node_delete|value={PERMISSION|name=node_delete}:edit-permissions]
[FORM|name=permissions:edit-permissions]
