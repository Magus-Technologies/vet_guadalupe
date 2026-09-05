-- ============================================================
-- Repara permisos incoherentes y deja al veterinario facturando.
--
-- Contexto: `ver` es la puerta de entrada a un módulo — el menú
-- (includes/header.php) y el router (index.php) lo consultan antes que
-- nada. Una fila con puede_ver=0 y puede_crear=1 describe un permiso
-- imposible: el rol nunca llega a la pantalla donde crear. Ese era el
-- estado del veterinario en Facturación (ver ❌, crear ✅, editar ✅),
-- y por eso el módulo no figuraba en su menú.
-- ============================================================

-- 1. Normalizar los `ver` huérfanos de cualquier rol/módulo.
UPDATE permisos
   SET puede_ver = 1
 WHERE puede_ver = 0
   AND (puede_crear = 1 OR puede_editar = 1 OR puede_eliminar = 1 OR puede_exportar = 1);

-- 2. Veterinario: emite comprobantes, pero no anula ni exporta.
INSERT INTO permisos (rol, modulo, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar)
VALUES ('veterinario', 'facturacion', 1, 1, 1, 0, 0)
ON DUPLICATE KEY UPDATE
    puede_ver      = 1,
    puede_crear    = 1,
    puede_editar   = 1,
    puede_eliminar = 0,
    puede_exportar = 0;

-- 3. Caja cerrada para el veterinario. Es la llave que canViewMoney()
--    consulta: sin ella no ve ingresos ni egresos en el Dashboard, no ve
--    la recaudación del período en Facturación y el listado de
--    comprobantes queda acotado a los que emitió él mismo.
INSERT INTO permisos (rol, modulo, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar)
VALUES ('veterinario', 'caja', 0, 0, 0, 0, 0)
ON DUPLICATE KEY UPDATE
    puede_ver      = 0,
    puede_crear    = 0,
    puede_editar   = 0,
    puede_eliminar = 0,
    puede_exportar = 0;
