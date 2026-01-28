# 📋 Bitácora de Errores y Soluciones - KINO TRACE

## 2026-01-28 - Resultados Duplicados en Búsqueda por Código

### 🔴 Problema
La funcionalidad de "Búsqueda por Código" mostraba el mismo documento múltiples veces en los resultados.
Esto ocurría cuando un documento tenía múltiples códigos que coincidían con el término de búsqueda (por ejemplo, coincidencias parciales o variantes). La consulta SQL original devolvía una fila por cada código coincidente en lugar de una por documento.

### 🟢 Solución
Se modificó la consulta SQL en `helpers/search_engine.php` dentro de la función `search_by_code`.
- Se reemplazó `SELECT DISTINCT ...` (que no agrupaba correctamente por ID si las columnas diferían en el código) por una estructura con `GROUP BY d.id`.
- Se usa `MAX(c.codigo)` para obtener uno de los códigos representativos para mostrar.

### 📂 Archivos Modificados
- `helpers/search_engine.php`
