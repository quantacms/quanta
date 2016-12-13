<h2>Product Data</h2>
<div class="shadow-hint">In this panel you can setup price, quantity, etc. for this product.</div>
[INPUT|id=price|title=Price|type=number|required|name=price|value={ATTRIBUTE|name=price}:ecommerce]
[INPUT|id=stock|title=Stock|type=select|range=0-100|required|name=stock|value={ATTRIBUTE|name=stock}:ecommerce]
[INPUT|type=select|id=availability|title=Availability|type=select|values=available, unavailable, preorder|required|name=availability|value={ATTRIBUTE|name=availability}:ecommerce]
[FORM|name=ecommerce:ecommerce]
