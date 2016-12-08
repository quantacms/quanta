<h2>Manage Permissions</h2>
<div class="shadow-hint">Here you can change the permissions for this node. You should use the role name.</div>
[INPUT|type=string|name=node_view|value={JSONPERMISSION|name=node_view}:edit-permissions]
[INPUT|type=string|name=node_edit|value={JSONPERMISSION|name=node_edit}:edit-permissions]
[INPUT|type=string|name=node_add|value={JSONPERMISSION|name=node_add}:edit-permissions]
[INPUT|type=string|name=node_delete|value={JSONPERMISSION|name=node_delete}:edit-permissions]
[FORM|name=permissions:edit-permissions]