[FORM|name=user-form:
[SHADOW_DESCRIPTION:Here you can edit your profile data.]
[INPUT|type=hidden|required|name=action|value={SHADOW:context}:user-form]

[INPUT|type=string|title={TEXT:Username}|description={TEXT:An username is forever. Please choose it carefully!}|id=edit_name|required|name=username|value={USER_ATTRIBUTE|name=username}:user-form]
[INPUT|title={TEXT:Email}|id=edit_email|required|name=email|value={USER_ATTRIBUTE|name=email}:user-form]
[INPUT|title={TEXT:First Name}|id=edit_firstname|required|name=first_name|value={USER_ATTRIBUTE|name=first_name}:user-form]
[INPUT|title={TEXT:Last Name}|id=edit_lastname|required|name=last_name|value={USER_ATTRIBUTE|name=last_name}:user-form]
[INPUT|filter=context@user_edit_own|title={TEXT:Old Password}|type=password|id=old_password|required|name=old_password:user-form]
[INPUT|title={TEXT|filter=context@user_edit_own:New }{TEXT:Password}|type=password|id=edit_password|required|name=password:user-form]
[INPUT|title={TEXT|filter=context@user_edit_own:New }{TEXT:Password Repeat}|type=password|id=edit_password_rp|required|name=password_rp:user-form]
]