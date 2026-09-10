<style>
/* Estilos críticos de tarjetas: van en la página para no depender de un app.css cacheado. */
.product-grid .product-card > .thumb {
  /* Más apaisado: mejor para logos rectangulares sin recortar */
  aspect-ratio: 16 / 10 !important;
  background: linear-gradient(160deg, #f7f9fc, #e8eef7) !important;
  position: relative !important;
  overflow: hidden !important;
  padding: 0 !important;
  display: block !important;
  min-height: 0 !important;
}
.product-grid .product-card > .thumb > img {
  position: absolute !important;
  top: 0 !important;
  right: 0 !important;
  bottom: 0 !important;
  left: 0 !important;
  width: 100% !important;
  height: 100% !important;
  max-width: none !important;
  max-height: none !important;
  margin: 0 !important;
  padding: .55rem !important;
  box-sizing: border-box !important;
  object-fit: contain !important;
  object-position: center !important;
  transform: none !important;
}
</style>
