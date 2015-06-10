<div id="software">
  <div id="userbox">
    <h2>
      <div class="logo" id="logosmall">
        <span class="logo-color1">Name</span><span class="logo-color2">Archer</span>
      </div>
    </h2>
    <div id="domain-messages"></div>

    <div id="key-info">
      <span id="key-used"></span> /
      <span id="key-limit">[USER:credits]</span> shots (<span id="key-percent"></span> %) left.
    </div>

    <div id="searchbox">
      <input id="domain" size="20">
      <select id="extension">
        <option value="com">.com</option>
        <option value="net">.net</option>
        <option value="org">.org</option>
        <option value="info">.info</option>
        <option value="io">.io</option>
        <option value="it">.it</option>
        <option value="domains">.domains</option>
      </select>
      <input type="button" id="search" value="Search"/>
    </div>

    <div id="searches">
    </div>
  </div>

  <div id="results">
    <!-- Wrappers per OK tables -->
    <div id="OK-wrapper">
      <h2>Available domains</h2>
      <table id="OK" cellspacing="1" class="tablesorter">
        <thead>
        <th>#</th>
        <th>Name</th>
        <th>Startpage</th>
        <th>.NET taken?</th>
        <th></th>
        </thead>

        <tbody id="OK-body">
        <tr style="display:none">
          <td>test</td>
          <td>3</td>
          <td>3</td>
          <td>3</td>
        </tr>
        </tbody>
      </table>
    </div>


    <!-- Wrappers per KO tables -->
    <div id="KO-wrapper">
      <h2>Unavailable domains</h2>
      <table id="KO" cellspacing="1" class="tablesorter">
        <thead>
        <th>#</th>
        <th>Name</th>
        <th>Expiry Date</th>
        <th>Contact</th>
        <th></th>
        </thead>

        <tbody id="KO-body">
        <tr style="display:none">
          <td>test</td>
          <td>3</td>
          <td>3</td>
        </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>