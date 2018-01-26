<h2>Create a free account</h2>
 <!-- <input type="hidden" id="action" name="action" value="[USER:action]" />
  <table>
    <tr><td>Username:</td><td><input id="edit-name" name="edit-name" value="" type="text"></td></tr>
    <tr><td colspan="2"><div class="description">An username is forever. Please choose it carefully!</div> </td></tr>

    <tr><td>E-mail:</td><td><input id="edit-email" name="edit-email" value="" type="text"></td></tr>
    <tr><td>First name:</td><td><input id="edit-first_name" name = "edit-first_name" value="" type="text"></td></tr>
    <tr><td>Last name:</td><td><input id="edit-last_name"  name="edit-last_name" value="" type="text"></td></tr>
    <tr><td>Password:</td><td><input id="edit-password" name="edit-password" value="" type="password"></td></tr>
    <tr><td>Repeat password:</td><td><input id="edit-password_rp" name="edit-password_rp" value="" type="password"></td></tr>
  </table>-->[INPUT|title=Username|id=edit_name|required|name=edit-name:user-register-form]
<div class="description">An username is forever. Please choose it carefully!</div>
[INPUT|title=Email|id=edit_email|required|name=edit-email:user-register-form]
[INPUT|title=First Name|id=edit_firstname|required|name=edit-first_name:user-register-form]
[INPUT|title=Last Name|id=edit_lastname|required|name=edit-last_name:user-register-form]
[INPUT|title=Password|type=password|id=edit_password|required|name=edit-password:user-register-form]
[INPUT|title=Password Repeat|type=password|id=edit_password_rp|required|name=edit-password_rp:user-register-form]
[INPUT|id=edit_action|type=hidden|required|name=action|value={SHADOW:context}:user-register-form]

[FORM:user-register-form]
