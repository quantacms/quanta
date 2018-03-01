<h2>Create a free account</h2>
[INPUT|title=Username|id=edit_name|required|name=edit-name:user-register-form]
<div class="description">An username is forever. Please choose it carefully!</div>
[INPUT|title=Email|id=edit_email|required|name=edit-email:user-register-form]
[INPUT|title=First Name|id=edit_firstname|required|name=edit-first_name:user-register-form]
[INPUT|title=Last Name|id=edit_lastname|required|name=edit-last_name:user-register-form]
[INPUT|title=Password|type=password|id=edit_password|required|name=edit-password:user-register-form]
[INPUT|title=Password Repeat|type=password|id=edit_password_rp|required|name=edit-password_rp:user-register-form]
[INPUT|id=edit_action|type=hidden|required|name=action|value={SHADOW:context}:user-register-form]

[FORM:user-register-form]
