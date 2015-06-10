<form id="shadow-form" method="POST" action="/[NODE:name]">
  <div id="show-thumbnail">[NODE:thumbnail-img]</div>
  <input type="hidden" name="edit-thumbnail" value="[NODE:thumbnail]"/>
  <input
    type="hidden" id="action" name="action" value="[NODE:action]">
  <input
    type="hidden" name="edit-father" value="[NODE:name]">
  <label for="edit-title">Title</label>
  <input type="text" name="edit-title"
         id="edit-title"
         value="[NODE:title]"/>
  <label for="edit-content">Content</label>
  <textarea id="edit-content"
            name="edit-content">[NODE:body]
  </textarea>
  <input type="hidden" id="edit-action" name="edit-action" value="[NODE:action]"/>
  <label for="edit-path">Path</label><input type="text" name="edit-path"
                                            id="edit-path" value="[NODE:name]"/>

  <div class="edit-date-wrapper">
    <label for="edit-date">Date</label>
    <input
      size="10" type="text" name="edit-date" id="edit-date"
      class="hasDatepicker" value="[NODE:date]"/>
    <input size="5"
           name="edit-time"
           value="[NODE:time]"/>
  </div>
</form>