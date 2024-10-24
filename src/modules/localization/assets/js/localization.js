$(document).bind('refresh', function() {
  $('.translate-link').off('click').on('click', function (e) {
    var components = (($(this).attr('data-components') != undefined) ? ($(this).attr('data-components').split(',')) : ['node_form', 'file_form', 'access_form', 'status_form']);
    openShadow({
      module : 'node',
      context: 'node_edit',
      widget: $(this).attr('data-widget'),
      language: $(this).attr('data-language'),
      components: components,
      node: $(this).attr('data-rel'),
      redirect: $(this).attr('data-redirect')
    });
    e.preventDefault();

  });
});
