<div class="shadow-inner" id="shadow-edit-page">
  [MESSAGES]
  <div id="shadow-titles">
    <ul class="shadow-titles-list">[SHADOW:tab-titles]</ul>
  </div>
  <div class="shadow-contents">
    [SHADOW:tab-contents]
  </div>

  <a onClick="shadowSubmit()" id="edit-save" ><span style="color:green">&check;&nbsp;</span>Save</a>

</div>

<script>
    $('.shadow-title').find('a').on('click', function () {
      if (!($(this).parent().hasClass('enabled'))) {
        $('.enabled').removeClass('enabled');
        $(this).parent().addClass('enabled');
        $('#shadow-content-' + $(this).attr('rel')).addClass('enabled');
      }
      return false;
    });

    $.getScript('/engine/code/php/core/file/js/jquery.fileupload.js');
    $.getScript('/engine/code/php/core/file/js/file-upload.js');
</script>