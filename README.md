# KINO-TRACE 🚀

Sistema de Gestión Documental Multi-cliente con Trazabilidad.

## Características

- 📤 **Subida de documentos PDF** con extracción automática de códigos
- 🔍 **Búsqueda inteligente voraz** de códigos en documentos
- 📥 **Importación de datos** desde CSV/SQL
- 🤖 **Integración con IA** (Google Gemini) para extracción inteligente
- 👥 **Multi-cliente** con bases de datos SQLite aisladas
- 🔗 **Vinculación de documentos** con detección de discrepancias

## 🚀 Despliegue en Railway

Esta aplicación está optimizada para desplegarse en Railway.

### Requisitos Previos
1.  Tener una cuenta en [Railway.app](https://railway.app/).
2.  Tener este proyecto en un repositorio de GitHub.

### Pasos
1.  **Nuevo Proyecto**: En Railway, crea un "New Project" -> "Deploy from GitHub repo" y selecciona este repositorio.
2.  **Configuración de Volumen (IMPORTANTE)**:
    *   Este paso es CRÍTICO para no perder datos, ya que Railway borra los archivos en cada despliegue.
    *   Ve a la configuración del servicio ("Settings").
    *   Baja a la sección de **Volumes**.
    *   Haz clic en "New Volume".
    *   **Mount Path**: `/var/www/html/clients`
    *   Esto asegurará que **todos** los datos (base de datos central, bases de datos de clientes y archivos subidos) se persistan.
3.  **Variables de Entorno**:
    *   `GEMINI_API_KEY`: Tu clave de API de Google Gemini (opcional, para IA).
    *   `PORT`: Opcional, por defecto es asigando automáticamante por Railway.

### Notas sobre Base de Datos
*   La aplicación usa **SQLite**.
*   `database_structure.sql` se incluye solo como referencia de la estructura. No se usa para la conexión en vivo.
*   Todo se guarda en `/clients/`, por eso el volumen debe montarse ahí.

## Configuración Local

```bash
# Clonar
git clone https://github.com/tu-usuario/kino-trace.git
cd kino-trace

# Iniciar servidor PHP
php -S localhost:8000

# Visitar http://localhost:8000
```

## Usuario Admin por Defecto

Ejecuta `migrate.php` para crear el usuario administrador:
- **Código**: admin
- **Contraseña**: admin123

## Estructura

```
kino-trace/
├── api.php              # API unificada
├── helpers/
│   ├── pdf_extractor.php   # Extracción de códigos
│   ├── search_engine.php   # Búsqueda voraz
│   ├── gemini_ai.php       # Integración IA
│   └── import_engine.php   # Importación CSV/SQL
├── modules/
│   ├── busqueda/        # Búsqueda inteligente
│   ├── subir/           # Subida de documentos
│   ├── importar/        # Importación de datos
│   └── trazabilidad/    # Dashboard y validación
└── clients/             # Datos por cliente (SQLite)
```

## Licencia

MIT License - Elaborado por KINO GENIUS
