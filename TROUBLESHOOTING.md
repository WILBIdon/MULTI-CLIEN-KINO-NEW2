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

