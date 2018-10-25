document.addEventListener("DOMContentLoaded", function(event) {
    var last_known_scroll_position = 0;
    const nav = document.getElementById("nav");
    
    var ticking = false;
    
    function doSomething(scroll_pos) {
      if ( scroll_pos > 140 && nav) {
        nav.classList.add("mobile-drop");
      } else {
        nav.classList.remove("mobile-drop");
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