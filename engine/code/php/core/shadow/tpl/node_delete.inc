<div class="shadow-inner" id="shadow-edit-page">
  <div class="shadow-content enabled">
      <form id="shadow-form" method="POST"><br/><br/>
        You are about to delete this post:<br/><br/>
        <b>[NODE:title]</b>
        <input type="hidden" id="action" name="action" value="node_delete" />
        <input type="hidden" name="edit-path" id="edit-path" value="[NODE:path]"/><br/><br/>
        <a onClick="shadowSubmit()" id="edit-save" ><span style="color:green">&check;&nbsp;</span>Delete it</a>
      </form>
  </div>
</div>