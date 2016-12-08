<h2>Product Data</h2>
<div class="shadow-hint">In this panel you can setup price, quantity, etc. for this product.</div>
<div id="edit-price">
  [INPUT|id=price|title=Price|type=number|required|name=price|value={PRODUCTATTRIBUTE|name=price}:ecommerce]
  [INPUT|id=availability|title=Availability|type=select|range=0-100|required|name=availability|value={PRODUCTATTRIBUTE|name=availability}:ecommerce]
  [FORM|name=ecommerce:ecommerce]
</div>
