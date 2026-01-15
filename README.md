# KINO-TRACE 🚀

Sistema de Gestión Documental Multi-cliente con Trazabilidad.

## Características

- 📤 **Subida de documentos PDF** con extracción automática de códigos
- 🔍 **Búsqueda inteligente voraz** de códigos en documentos
- 📥 **Importación de datos** desde CSV/SQL
- 🤖 **Integración con IA** (Google Gemini) para extracción inteligente
- 👥 **Multi-cliente** con bases de datos SQLite aisladas
- 🔗 **Vinculación de documentos** con detección de discrepancias

## Despliegue en Railway

1. Haz fork o clona este repositorio
2. Ve a [railway.app](https://railway.app)
3. Crea un nuevo proyecto desde GitHub
4. Railway detectará el Dockerfile automáticamente
5. (Opcional) Agrega variable `GEMINI_API_KEY` para habilitar IA

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
