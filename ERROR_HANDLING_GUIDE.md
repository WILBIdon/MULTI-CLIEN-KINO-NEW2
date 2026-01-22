# Guía de Uso - Sistema de Manejo de Errores

## 📚 Documentación para Desarrolladores

### Códigos de Error Estandarizados

El sistema ahora utiliza códigos de error únicos para cada tipo de falla. Esto facilita el debugging y proporciona mensajes claros al usuario.

#### Categorías de Errores

**Autenticación (AUTH_xxx)**
- `AUTH_001`: Credenciales inválidas
- `AUTH_002`: Sesión expirada
- `AUTH_003`: Cliente no existe
- `AUTH_004`: Sin permisos

**Base de Datos (DB_xxx)**
- `DB_001`: Error de conexión
- `DB_002`: Error en consulta SQL
- `DB_003`: Registro no encontrado
- `DB_004`: Error de integridad (duplicados)

**Archivos (FILE_xxx)**
- `FILE_001`: Archivo no encontrado
- `FILE_002`: Tipo de archivo inválido
- `FILE_003`: Archivo muy grande (>10MB)
- `FILE_004`: Error al subir
- `FILE_005`: No se recibió archivo
- `FILE_006`: Error al eliminar

**PDF (PDF_xxx)**
- `PDF_001`: pdftotext no disponible
- `PDF_002`: PDF corrupto o protegido
- `PDF_003`: Sin texto extraíble
- `PDF_004`: Timeout en extracción
- `PDF_005`: No se encontraron códigos

**Validación (VALIDATION_xxx)**
- `VALIDATION_001`: Campos requeridos faltantes
- `VALIDATION_002`: Formato de fecha inválido
- `VALIDATION_003`: Código de cliente inválido

---

## 💻 Uso del Sistema de Logging

### 1. Logging Básico

```php
require_once __DIR__ . '/helpers/logger.php';

// Diferentes niveles de severidad
Logger::debug('Mensaje de depuración', ['var' => $value]);
Logger::info('Operación completada');
Logger::warning('Condición inusual detectada');
Logger::error('Error recuperable');
Logger::critical('Error crítico del sistema');
```

### 2. Logging de Excepciones

```php
try {
    // Código que puede fallar
    $db->query($sql);
} catch (Exception $e) {
    Logger::exception($e, [
        'query' => $sql,
        'params' => $params
    ]);
}
```

### 3. Logs Automáticos

El logger captura automáticamente:
- IP del cliente
- User Agent
- Método HTTP
- URI de la petición
- Cliente actual (si está en sesión)

---

## 🔧 Uso de Códigos de Error en API

### 1. Retornar Error Estandarizado

```php
require_once __DIR__ . '/helpers/error_codes.php';

// Simple
json_exit(api_error('AUTH_001'));

// Con mensaje personalizado
json_exit(api_error('FILE_003', 'El PDF es de 25MB, máximo permitido: 10MB'));

// Con contexto adicional
json_exit(api_error('DB_002', null, [
    'query' => $sql,
    'params' => $params
]));
```

### 2. Validar Campos Requeridos

```php
$validationError = validate_required_fields($_POST, ['tipo', 'numero', 'fecha']);
if ($validationError) {
    json_exit($validationError);
}
```

### 3. Validar Archivos

```php
// Tipo de archivo
$fileValidation = validate_file_type($_FILES['file']);
if ($fileValidation) {
    json_exit($fileValidation);
}

// Tamaño
$sizeValidation = validate_file_size($_FILES['file'], 10485760); // 10MB
if ($sizeValidation) {
    json_exit($sizeValidation);
}
```

### 4. Validar PDF

```php
require_once __DIR__ . '/helpers/pdf_extractor.php';

$validation = validate_pdf($pdfPath);
if (!$validation['valid']) {
    json_exit(api_error($validation['error_code'], null, $validation));
}
```

---

## 📊 Estructura de Respuestas

### Respuesta de Error

```json
{
  "error": true,
  "code": "FILE_003",
  "message": "Archivo muy grande. El tamaño máximo permitido es 10MB.",
  "http_code": 413,
  "timestamp": "2026-01-22 09:12:00"
}
```

### Formato de Log

```json
{
  "timestamp": "2026-01-22 09:12:00",
  "level": "ERROR",
  "message": "API Error: FILE_003 - Archivo muy grande.",
  "context": {
    "ip": "192.168.1.100",
    "user_agent": "Mozilla/5.0...",
    "method": "POST",
    "uri": "/api.php",
    "client": "kino",
    "error_code": "FILE_003",
    "http_code": 413,
    "file_size": 26214400,
    "max_size": 10485760
  }
}
```

---

## 📁 Ubicación de Logs

```
clients/
└── logs/
    ├── app.log          # Todos los logs
    ├── error.log        # Solo errores y críticos
    └── {cliente}/
        └── {cliente}.log  # Logs específicos del cliente
```

---

## 🎯 Ejemplos Prácticos

### Ejemplo 1: Endpoint con Validación Completa

```php
case 'create_document':
    // 1. Validar campos requeridos
    $validationError = validate_required_fields($_POST, ['tipo', 'numero', 'fecha']);
    if ($validationError) {
        json_exit($validationError);
    }

    // 2. Validar archivo
    if (empty($_FILES['file']['tmp_name'])) {
        json_exit(api_error('FILE_005'));
    }

    $fileValidation = validate_file_type($_FILES['file']);
    if ($fileValidation) {
        json_exit($fileValidation);
    }

    // 3. Validar PDF
    $pdfValidation = validate_pdf($_FILES['file']['tmp_name']);
    if (!$pdfValidation['valid']) {
        json_exit(api_error($pdfValidation['error_code']));
    }

    // 4. Procesar con logging
    try {
        $result = process_document($_FILES['file']);
        Logger::info('Document created successfully', ['doc_id' => $result['id']]);
        json_exit(['success' => true, 'data' => $result]);
    } catch (Exception $e) {
        Logger::exception($e);
        json_exit(api_error('DOC_003'));
    }
```

### Ejemplo 2: Timeout en Extracción PDF

```php
try {
    $text = extract_with_pdftotext($pdfPath, 30); // 30 segundos timeout
    
    if (empty($text)) {
        json_exit(api_error('PDF_003', 'No se pudo extraer texto del PDF'));
    }
    
    Logger::info('PDF text extracted successfully', [
        'path' => $pdfPath,
        'text_length' => strlen($text)
    ]);
    
} catch (Exception $e) {
    Logger::exception($e, ['path' => $pdfPath]);
    json_exit(api_error('PDF_004'));
}
```

---

## ⚙️ Configuración

### Habilitar Modo Debug

En desarrollo, puedes incluir contexto en las respuestas:

```php
// En .env o config.php
putenv('APP_ENV=development');
putenv('DEBUG=true');

// Ahora las respuestas incluirán el contexto completo
$error = api_error('DB_002', null, ['query' => $sql], true);
```

### Habilitar Console Logging

Para debugging en CLI:

```php
Logger::enableConsole();
// Ahora también se imprime en consola
Logger::error('Test error');
```

---

## 📈 Métricas y Monitoreo

### Ver Logs Recientes

```php
// Leer últimas 100 líneas de error.log
$logPath = CLIENTS_DIR . '/logs/error.log';
$lines = array_slice(file($logPath), -100);

foreach ($lines as $line) {
    $entry = json_decode($line, true);
    echo "{$entry['timestamp']} [{$entry['level']}] {$entry['message']}\n";
}
```

### Analizar Errores por Código

```php
$logPath = CLIENTS_DIR . '/logs/error.log';
$errorCounts = [];

foreach (file($logPath) as $line) {
    $entry = json_decode($line, true);
    if (isset($entry['context']['error_code'])) {
        $code = $entry['context']['error_code'];
        $errorCounts[$code] = ($errorCounts[$code] ?? 0) + 1;
    }
}

arsort($errorCounts);
print_r($errorCounts);
```

---

## ✅ Checklist de Implementación

- [x] Crear `helpers/logger.php`
- [x] Crear `helpers/error_codes.php`
- [x] Integrar en `api.php`
- [x] Mejorar `pdf_extractor.php` con timeouts
- [x] Agregar validaciones en endpoints críticos
- [ ] Implementar en todos los módulos
- [ ] Crear health check dashboard
- [ ] Configurar alertas de email (opcional)
- [ ] Integrar con Sentry (opcional)

---

## 🚀 Próximos Pasos

1. **Refactorizar módulos restantes**: Aplicar el mismo patrón en `modules/*/index.php`
2. **Health Check Dashboard**: Crear `/modules/health/index.php`
3. **Tests**: Escribir pruebas para cada código de error
4. **Documentación de Usuario**: Crear mensajes de error user-friendly en el frontend

---

## 📞 Soporte

Para agregar nuevos códigos de error:
1. Editar `helpers/error_codes.php`
2. Agregar entrada en función `get_error_map()`
3. Documentar en esta guía
