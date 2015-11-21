<div class="shadow-hint">Hint: after uploading files, use the FILES tag to include this list of files in content.</div>
<div id="edit-files">
  <form id="upload" method="POST" enctype="multipart/form-data">
  <input type="hidden" name="tmp_files_dir" value="[NODE:tmp_files_dir]" />
  <input type="hidden" id="edit-thumbnail" name="edit-thumbnail" value="[NODE:thumbnail]"/>
  <input type="hidden" name="thumbnail" value="" />
  <div id="drop">Drop here files<a>or press here</a><input type="file" name="upl" id="file-upload" multiple /></div>
    [NODE:filelist]</form>
  </div>
<script src="/engine/code/php/core/file/js/jquery.fileupload.js"></script>
<script src="/engine/code/php/core/file/js/file-upload.js"></script>