document.addEventListener("DOMContentLoaded", function(event) {
    var last_known_scroll_position = 0;
    const menu = document.getElementById("menu");
    
    var ticking = false;
    
    function doSomething(scroll_pos) {
      if ( scroll_pos > 140 && menu) {
        menu.classList.add("fixed-menu");
      } else {
        menu.classList.remove("fixed-menu");
      }
    }
    
    window.addEventListener('scroll', function(e) {
    
      last_known_scroll_position = window.scrollY;
    
      if (!ticking) {
    
        window.requestAnimationFrame(function() {
          doSomething(last_known_scroll_position);
          ticking = false;
        });
         
        ticking = true;
    
      }
    });
});