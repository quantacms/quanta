$('.wysiwyg').each(function (elm) {
  var editor = new Jodit(this, {
    cleanHTML: {
      cleanOnPaste: true
    }
  });
});