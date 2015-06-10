<h2>Create a free account</h2><form>
  <input type="hidden" id="action" name="action" value="[USER:action]" />
  <table>
    <tr><td>Username:</td><td><input id="edit-name" name="edit-name" value="" type="text"></td></tr>
    <tr><td colspan="2" class="description-row">You will not be able to change your username after registering. Choose it carefully!</td></tr>

    <tr><td>E-mail:</td><td><input id="edit-email" name="edit-email" value="" type="text"></td></tr>
    <tr><td>First name:</td><td><input id="edit-first_name" name = "edit-first_name" value="" type="text"></td></tr>
    <tr><td>Last name:</td><td><input id="edit-last_name"  name="edit-last_name" value="" type="text"></td></tr>
    <tr><td>Password:</td><td><input id="edit-password" name="edit-password" value="" type="password"></td></tr>
    <tr><td>Repeat password:</td><td><input id="edit-password_rp" name="edit-password_rp" value="" type="password"></td></tr>
  </table>
  <a onClick="shadowSubmit()" id="edit-save" >
    <span style="color:green">&check;&nbsp;</span>Sign Up!
  </a>
  <div class="messages"></div>
</form>