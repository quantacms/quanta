<div class="shadow-hint">Hint: after uploading files, use the FILES tag to include this list of files in content.</div>
<div id="edit-files">
  [INPUT|id=tmp_files_dir|type=hidden|required|name=tmp_files_dir|value={ATTRIBUTE|name=tmp_files_dir|nowiki}|nowiki:upload]
  [INPUT|type=file|plugin=drop|nowiki|title=Manage here all your uploaded files:upload]
  [FORM|name=upload:upload]
</div>
<div id="filelist">[FILES]</div>

<script src="/engine/code/php/core/file/js/jquery.fileupload.js"></script>
<script src="/engine/code/php/core/file/js/file-upload.js"></script>