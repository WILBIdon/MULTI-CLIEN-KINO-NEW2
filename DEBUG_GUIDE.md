# 🔧 Guía Rápida de Depuración - KINO TRACE

## ✅ Cómo Depurar de Forma Segura

### 1. Usar el Logger (Recomendado)

```php
// En cualquier archivo PHP
require_once __DIR__ . '/helpers/logger.php';

// Ver valores de variables
Logger::debug('Revisando valores', [
    'usuario' => $usuario,
    'datos' => $datos
]);

// Marcar puntos de control
Logger::info('Llegó al punto X del código');

// Ver queries SQL
Logger::debug('Query ejecutado', [
    'sql' => $sql,
    'params' => $params
]);
```

Los logs se guardan en: `clients/logs/app.log`

### 2. Modo Debug Automático

El archivo `debug_config.php` activa funciones de debug solo para tu IP:

```php
// Al inicio de tu archivo
require_once __DIR__ . '/debug_config.php';

// Ahora puedes usar:
debug_log('Probando algo', ['valor' => $x]);

// O para detener y ver valores (SOLO en desarrollo):
dd($variable); // Muestra y detiene ejecución
```

### 3. Ver Logs en Tiempo Real

```bash
# En terminal (Windows)
Get-Content clients\logs\app.log -Wait -Tail 20

# Ver solo errores
Get-Content clients\logs\error.log -Wait -Tail 20
```

### 4. Archivo de Prueba Temporal

Crea `test_algo.php` (NO se sube al repo):

```php
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/logger.php';
require_once __DIR__ . '/debug_config.php';

// Prueba lo que necesites sin miedo
$_SESSION['client_code'] = 'kino';
$db = open_client_db('kino');

// Ejemplo: Ver todos los documentos
$stmt = $db->query("SELECT COUNT(*) FROM documentos");
$count = $stmt->fetchColumn();

Logger::debug('Total docs', ['count' => $count]);
echo "Total documentos: $count\n";
echo "Ver detalles en clients/logs/app.log\n";
```

## 🚫 Lo que NO Debes Hacer

```php
// ❌ NUNCA en archivos de producción:
echo "Debug";           // Rompe JSON en API
var_dump($x);          // Rompe JSON en API
print_r($array);       // Rompe JSON en API

// ✅ HAZ ESTO:
Logger::debug('Debug', ['x' => $x]);
Logger::debug('Array', ['array' => $array]);
```

## 📊 Verificar Si Debug Está Activo

Accede a: `http://localhost/debug_config.php?debug_info=1`

Verás:
```json
{
  "debug_mode": true,
  "ip": "127.0.0.1",
  "logger_available": true
}
```

## 🔍 Ejemplos Prácticos

### Depurar Upload de Archivos

```php
// En api.php, caso 'upload'
case 'upload':
    debug_log('Inicio upload', [
        'files' => $_FILES,
        'post' => $_POST
    ]);
    
    // ... código normal ...
    
    debug_log('Archivo procesado', [
        'path' => $targetPath,
        'hash' => $hash
    ]);
```

### Depurar Extracción de PDF

```php
// En el código de extracción
$result = extract_codes_from_pdf($pdfPath);
debug_log('Extracción completa', [
    'success' => $result['success'],
    'codes_found' => count($result['codes']),
    'text_length' => strlen($result['text'])
]);
```

## 🛡️ Seguridad

- ✅ Los archivos `test_*.php` NO se suben al repo (están en .gitignore)
- ✅ Los logs NO se suben al repo
- ✅ El modo debug solo funciona con tu IP
- ✅ En producción, las funciones de debug no hacen nada

## 📝 Workflow Recomendado

1. **Agregar logs** donde necesites información
2. **Ejecutar** la acción que quieres depurar
3. **Revisar** `clients/logs/app.log`
4. **Ajustar** el código según lo que encuentres
5. **Quitar** los logs de debug antes de commit final

---

**¿Problemas?** Revisa siempre `clients/logs/error.log` primero.
