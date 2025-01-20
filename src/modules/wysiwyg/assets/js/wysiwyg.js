ClassicEditor.builtinPlugins.map( plugin => plugin.pluginName );

$('.wysiwyg').each(function (elm) {

  ClassicEditor
    .create( this, {
      alignment: {
        options: [ 'left', 'center', 'right', 'justify' ]
      },
      toolbar: [ 'heading', '|', 'bold', 'italic', 'link', '|', 'bulletedList', 'numberedList', 'alignment','blockQuote', '|', 'undo', 'redo', '|', 'alignment', 'insertTable' ],
      heading: {
        options: [
          { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
          { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
          { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
          { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' },

        ]
      }
    } )
    .then( editor => {
    console.log( editor );
  editor.model.document.on( 'change:data', ( evt, data ) => {
    editor.updateSourceElement();
    // Trigger the change event on the original textarea
    $(editor.sourceElement).trigger('change');
});
})
.catch(error => {console.log( error );});
});


