<style>
/* Estilos críticos de tarjetas: van en la página para no depender de un app.css cacheado. */
.product-card-shell { position: relative; height: 100%; }
.catalog-admin-edit--card {
  position: absolute; top: .55rem; right: .55rem; z-index: 3;
  display: inline-flex; align-items: center; justify-content: center;
  width: 2rem; height: 2rem; border-radius: 999px;
  border: 1px solid #cfd8e6; background: #fff; color: var(--doceo-blue);
  text-decoration: none; box-shadow: 0 2px 10px rgba(15,23,42,.12);
}
.catalog-admin-edit--card:hover { background: #eef4ff; text-decoration: none; }
.catalog-admin-edit--card svg { width: 14px; height: 14px; display: block; }
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
