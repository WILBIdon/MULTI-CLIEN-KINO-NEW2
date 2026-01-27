# Ayuda Memoria de Errores y Soluciones (TROUBLESHOOTING)

Este archivo documenta errores comunes encontrados en el desarrollo de KINO TRACE y sus soluciones.

## 1. Error: "Unexpected end of JSON input" al limpiar base de datos

**Síntoma:**
Al hacer clic en "Limpiar Todo" o "Limpiar base de datos" en el módulo de importación, aparece una alerta o log de error:
`Failed to execute 'json' on 'Response': Unexpected end of JSON input`

**Causa:**
PHP está generando salida (warnings, espacios en blanco, etc.) antes de ejecutar `echo json_encode(...)`. Esto corrompe la respuesta JSON que espera el navegador.

**Solución:**
Asegurarse de limpiar el búfer de salida (`Output Buffer`) antes de enviar el JSON final en el script PHP.

```php
// En process.php, antes de echo json_encode:
ob_clean(); // Limpiar cualquier salida previa
header('Content-Type: application/json'); // Forzar cabecera correcta
echo json_encode($response);
exit;
```

---

## 2. Error: "grep no se reconoce..." en Windows

**Síntoma:**
Al intentar ejecutar comandos de búsqueda en la terminal de Visual Studio Code en Windows, aparece:
`grep : El término 'grep' no se reconoce...`

**Causa:**
Windows (PowerShell/CMD) no tiene el comando `grep` instalado por defecto.

**Solución:**
1.  Usar `findstr` en Windows.
2.  Usar Git Bash si está instalado.
3.  (Recomendado para el Agente) Usar las herramientas internas `grep_search`.

---

## 3. Error: "Los códigos aparecen como 0" tras importación SQL

**Síntoma:**
Después de importar un archivo SQL, los documentos aparecen en la lista, pero la columna "Códigos" muestra `0`, aunque el archivo SQL original contenía datos en la tabla `codigos`.

**Causa:**
El script de importación simple no mantenía la relación entre las tablas. Al insertar documentos, la base de datos genera nuevos `ID`. Si no se actualiza el `documento_id` en las tablas hijas (`codigos`, `vinculos`) con estos nuevos IDs, los registros quedan huérfanos o no se importan.

**Solución:**
Se implementó un sistema de mapeo de IDs (`$idMap`) en `process.php`.
1.  Al insertar un documento, se guarda su `ID original` (del SQL) y su `Nuevo ID` (autoincremental).
2.  Al insertar códigos y vínculos, se busca el `ID original` en el mapa y se reemplaza por el `Nuevo ID`.

**Prevención:**
Cualquier script de migración o importación debe manejar explícitamente las claves foráneas (Foreign Keys) cuando los IDs primarios cambian.

---

## 4. Error: "Documentos Duplicados (Generado Auto)" tras importación

**Síntoma:**
Después de importar SQL y ZIP simultáneamente, aparecen duplicados: uno "Importado" (sin archivo vinculado) y otro "Generado Auto" (con el archivo).

**Causa:**
Al importar los datos SQL, si el script no rellena correctamente el campo `original_path` (usando `ruta_archivo` o `path` como respaldo), el sistema de vinculación de PDF (ZIP) no puede encontrar el documento existente en la base de datos. Al no encontrarlo, crea uno nuevo marcado como "Generado Auto".

**Solución:**
En `process.php`, se mejoró la lógica de importación para que busque el valor de `original_path` en múltiples columnas candidatas (`original_path`, `ruta_archivo`, `path`) de manera insensible a mayúsculas/minúsculas. Si encuentra alguna ruta, la guarda como `original_path` en la base de datos, permitiendo que el ZIP encuentre y vincule el archivo en lugar de crear un duplicado.
