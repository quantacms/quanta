[FORM|name=user-form:
[INPUT|type=hidden|required|name=action|value={SHADOW:context}:user-form]
test
[GRID:
[INPUT|multiple|grid=grid-span-12|type=select|title={TEXT|tag=roles:Roles}|id=edit_roles|required|name=roles|value={USER_ATTRIBUTE|name=roles}:user-form]

]
]