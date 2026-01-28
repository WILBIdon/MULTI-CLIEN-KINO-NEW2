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

## 2026-01-28 - Error 404 al Ver Documento

### 🔴 Problema
Al intentar ver un documento desde los módulos de "Búsqueda" o "Recientes", se generaba un error 404.
La URL resultante era `.../documento/view.php`, lo cual es incorrecto porque el archivo se encuentra en `modules/documento/view.php`.
El problema se debía a enlaces relativos `../documento/view.php` que fallaban dependiendo de la URL base del navegador (posiblemente debido a reescritura de URL o acceso directo a módulos sin la estructura de carpetas esperada).

### 🟢 Solución
Se actualizaron los enlaces en los siguientes archivos para usar una ruta relativa más robusta (`../../modules/documento/view.php`) que fuerza la navegación desde la raíz del sistema de módulos.

### 📂 Archivos Modificados
- `modules/busqueda/index.php`
- `modules/recientes/index.php`
- `modules/trazabilidad/dashboard.php`
