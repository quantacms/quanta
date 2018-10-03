$(document).ready(function(){
  // Workaround for flexbox to emulate grid-col-start:
  // create a div with span outside "cells" but inside the wrapper.
/*
  var flex_span_class, match_array, span, order_class = '';
  var class_keyword = 'grid-start-';
  var class_prefix = 'grid-span-';
  //var re = /grid-start-([1-9]*) |grid-start-([a-z]*)-([1-9]*)/;
  var re = new RegExp(class_keyword + '([1-9]*) |' + class_keyword + '([a-z]*)-([1-9]*)');
  var re_order = /order-(\S*)/g;
  
  $('[class*="' + class_keyword + '"]:not(.dir-list-item)').each(function(){
    match_array = $(this).attr('class').match(re);

    // span class
    if (match_array[1] != undefined) {
      // only span
      // span is 1 less than col-start
      span = match_array[1] - 1;
      flex_span_class = class_prefix + span;
    } else {
      // media size and span
      // span is 1 less than col-start
      span = match_array[3] - 1;
      flex_span_class = class_prefix + match_array[2] + '-' + span;
    }

    // order class (if exists)
    if ($(this).is('[class*="order-"]')) {
      order_class = $(this).attr('class').match(re_order)[0];
    }

    $(this).parent().prepend('<div class="' + flex_span_class + ' ' + order_class + '"><div>');
  });
*/
});