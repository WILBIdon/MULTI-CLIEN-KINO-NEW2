# KINO TRACE - Resumen para Análisis de Agente IA

## 🎯 ¿Qué es KINO TRACE?

Sistema de gestión documental multi-cliente para rastreo aduanero con búsqueda inteligente de códigos en PDFs.

## 📦 Stack Tecnológico

- **Backend**: PHP 7.4+ con SQLite
- **Frontend**: HTML, CSS, JavaScript vanilla
- **PDF Processing**: Smalot/PdfParser + PDF.js
- **Deployment**: Railway (Docker)
- **AI**: Google Gemini API (opcional)

## 🏗️ Arquitectura Simplificada

```
Usuario → Login → Dashboard → Módulos → API → SQLite DB → File Storage
                                ├─ Búsqueda Voraz
                                ├─ Subir Documentos
                                ├─ Resaltar PDFs
                                └─ Trazabilidad
```

## 📁 Estructura Clave (Solo lo importante)

```
kino-trace/
├── api.php                  ⭐ API REST principal (857 líneas)
├── index.php                ⭐ Dashboard (1,089 líneas)
├── config.php               🔧 Configuración multi-tenant
├── autoload.php             ✨ Nuevo: Sistema autoload
│
├── helpers/                 🛠️ Utilidades core
│   ├── search_engine.php    ⭐ Algoritmo búsqueda voraz
│   ├── pdf_extractor.php    📄 Extracción de PDFs
│   ├── tenant.php           🏢 Multi-tenancy
│   └── logger.php           📝 Sistema de logs
│
├── modules/                 📦 Funcionalidades
│   ├── resaltar/            ⭐ Resaltado de PDFs
│   ├── trazabilidad/        🔍 Validación cruzada
│   └── [20 módulos más]
│
├── includes/
│   └── components.php       ✨ Nuevo: Componentes UI
│
└── clients/                 💾 Datos por cliente
    ├── central.db           Control de clientes
    └── {client}/
        ├── {client}.db      BD del cliente
        └── uploads/         PDFs del cliente
```

## 🎯 Funcionalidades Core

### 1. Búsqueda Voraz (Algoritmo Principal)
```php
// Dado una lista de códigos, encuentra el MÍNIMO conjunto
// de documentos que los contenga usando algoritmo greedy

Input:  ['COD001', 'COD002', 'COD003', ... 'COD100']
Output: [Documento A, Documento B, Documento C]
        // Que juntos contienen todos los códigos
```

### 2. Extracción Automática de Códigos
```php
PDF → Parse Text → Regex Match → Extract Codes → Store in DB
```

### 3. Multi-tenancy
```php
// Cada cliente tiene su propia BD SQLite aislada
clients/
  ├── KINO/kino.db          # Cliente 1
  └── EMPRESA/empresa.db    # Cliente 2
```

## 📊 Base de Datos (Esquema Simplificado)

```sql
-- Por cada cliente
documentos (id, tipo, numero, fecha, ruta_archivo, datos_extraidos)
codigos (id, documento_id, codigo, validado)
vinculos (id, doc_origen, doc_destino, tipo_vinculo, discrepancias)
```

## 🔥 Puntos Críticos para Analizar

### 1. API Monolítica (`api.php` - 857 líneas)
```php
switch ($action) {
    case 'upload': ...     // 100+ líneas
    case 'search': ...     // 50+ líneas
    case 'update': ...     // 80+ líneas
    // ... 15 casos más
}
```
**Problema**: Difícil de mantener, testear y escalar  
**Solución sugerida**: Separar en clases (ApiDocuments, ApiSearch, ApiCodes)

### 2. Búsqueda Voraz (`helpers/search_engine.php`)
```php
function greedy_search(PDO $db, array $codes): array
{
    // Algoritmo greedy O(n*m)
    // ¿Se puede optimizar más?
    // ¿Usar caché para búsquedas repetidas?
}
```

### 3. Extracción de PDFs (`helpers/pdf_extractor.php`)
```php
// Usa Smalot\PdfParser
// ¿Manejar PDFs escaneados (OCR)?
// ¿Cómo optimizar PDFs grandes (>10MB)?
```

### 4. Seguridad
- ✅ PDO prepared statements
- ✅ Password hashing
- ⚠️ Falta validación MIME de uploads
- ⚠️ No hay rate limiting en API
- ⚠️ Falta CSRF protection

## 📈 Optimizaciones Recientes (23-Ene-2026)

1. ✅ **Autoloader** - Elimina 98+ require_once
2. ✅ **Componentes** - Reduce duplicación HTML
3. ✅ **CSS Consolidado** - -282 líneas
4. ✅ **DB Optimizer** - Índices para mejor rendimiento

## 🎓 Para Empezar el Análisis

### Paso 1: Entender el Flujo
1. Lee `DOCUMENTACION_TECNICA.md` (este archivo)
2. Revisa `config.php` - Multi-tenancy
3. Explora `api.php` - Endpoints principales

### Paso 2: Revisar Código Crítico
1. `helpers/search_engine.php` - Lógica de búsqueda
2. `helpers/pdf_extractor.php` - Extracción PDFs
3. `index.php` - UI principal

### Paso 3: Identificar Mejoras
- ¿Cómo refactorizar `api.php`?
- ¿Qué tests faltan?
- ¿Qué vulnerabilidades hay?
- ¿Cómo mejorar rendimiento?

## 🔍 Preguntas Específicas para Análisis

1. **Arquitectura**: ¿Conviene migrar a un framework (Laravel, Slim)?
2. **DB**: ¿SQLite escala bien? ¿Cuándo migrar a PostgreSQL?
3. **API**: ¿Implementar GraphQL para queries complejas?
4. **Testing**: ¿Qué % de código debería tener tests?
5. **Cache**: ¿Redis para búsquedas frecuentes?
6. **Queue**: ¿RabbitMQ para procesamiento de PDFs pesados?

## 📊 Métricas Actuales

```
Archivos PHP: 51
Líneas de código: ~15,000
Tamaño repo: 0.63 MB
Clientes activos: Variable (multi-tenant)
Documentos por cliente: Variable (cientos a miles)
```

## 🚀 Roadmap Sugerido

### Corto plazo (1-2 semanas)
- [ ] Separar API en clases
- [ ] Agregar tests unitarios básicos
- [ ] Implementar rate limiting
- [ ] Validación MIME de uploads

### Mediano plazo (1-2 meses)
- [ ] Implementar autoloader en todos módulos
- [ ] Migrar a PSR-4
- [ ] Agregar CI/CD
- [ ] Documentar APIs con OpenAPI

### Largo plazo (3-6 meses)
- [ ] Evaluar framework PHP
- [ ] Considerar microservicios
- [ ] Implementar cache distribuido
- [ ] Agregar colas de procesamiento

## 📞 Links Útiles

- **Repositorio**: https://github.com/WILBIdon/MULTI-CLIEN-KINO-NEW2
- **Documentación completa**: Ver `DOCUMENTACION_TECNICA.md`
- **Deployment**: Railway
- **Stack técnico detallado**: Ver `README.md`

## 💡 Consejos para el Agente Analizador

1. **No te abrumes**: Empieza por los archivos ⭐ marcados
2. **Usa grep**: `grep -r "function.*search" helpers/`
3. **Sigue el flujo**: Usuario → UI → API → Helpers → DB
4. **Pregunta específico**: No "¿está bien el código?", sino "¿cómo optimizar la búsqueda voraz?"
5. **Propón soluciones**: No solo problemas, sino mejoras concretas

---

**Resumen en 1 línea**: Sistema multi-tenant de gestión documental con búsqueda inteligente de códigos en PDFs usando algoritmo voraz, PHP+SQLite, desplegado en Railway.

**¿Necesitas más detalles sobre algún aspecto?** Consulta `DOCUMENTACION_TECNICA.md` para análisis profundo.
