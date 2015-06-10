<form id="shadow-login" method="POST">
  <div>Please insert your personal data to enter the system.</div>
  <div><label for="username">Username</label>
    <input type="text" id="username" name="username" />
  </div>
  <div>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" />
  </div>
  <input type="hidden" id="login-action" name="action" value="login" />
  <a onClick="shadowSubmit()" id="edit-save" >
    <span style="color:green">&check;&nbsp;</span>Login
  </a>
</form>