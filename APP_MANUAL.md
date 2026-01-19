# MANUAL MAESTRO DE KINO TRACE
Este documento describe en detalle la funcionalidad, botones y flujos de la aplicación KINO TRACE. Úsalo para entender qué hace cada parte del sistema.

## 1. VISIÓN GENERAL
KINO TRACE es un sistema de trazabilidad documental diseñado para gestionar importaciones, facturas y manifiestos. Su función principal es permitir la búsqueda rápida de códigos de productos dentro de miles de documentos PDF y relacionarlos entre sí.

## 2. EXPLICACIÓN POR MÓDULOS

### 🏠 DASHBOARD (Inicio)
**Ruta:** `/modules/trazabilidad/dashboard.php`
- **Propósito:** Vista general del estado del sistema.
- **Elementos:**
  - **Tarjetas de Estadísticas:** Muestran conteos totales (Documentos, Códigos, Manifiestos, Facturas).
  - **Gráficos:** Visualización de documentos por mes y tipos.
  - **Actividad Reciente:** Lista de las últimas acciones realizadas.

### 🔍 GESTOR DOC (Búsqueda Avanzada)
**Ruta:** `/modules/busqueda/`
- **Propósito:** El buscador principal del sistema. Funciona como un "Google" para tus documentos.
- **Funcionalidad:**
  - Escribes un código, nombre de archivo o número de documento.
  - Muestra resultados agrupados por tipo (Manifiestos, Facturas, etc.).
- **Botones en resultados:**
  - `📄 Ver PDF`: Abre el documento PDF en una nueva pestaña (Ruta inteligente corregida).
  - `👁️ Ver Detalle`: Lleva a la vista detallada del documento.

### 📤 SUBIDA LOTE (Carga Masiva)
**Ruta:** `/modules/lote/`
- **Propósito:** Subir cientos de documentos a la vez usando un archivo ZIP.
- **Flujo de uso:**
  1. Preparas un ZIP con tus PDFs.
  2. Lo arrastras al área de carga.
  3. Clic en `Procesar Lote`.
- **Botones:**
  - `🗑️ Limpiar`: Borra la selección actual.
  - `▶️ Procesar`: Descomprime y registra los archivos en el sistema.

### ⬆️ SUBIR DOCUMENTO (Individual)
**Ruta:** `/modules/subir/`
- **Propósito:** Subir un solo documento y extraer sus códigos automáticamente.
- **Funciones Especiales:**
  - **Extracción por Patrón:** Puedes definir con qué empieza (Prefijo) y termina (Terminador) un código para buscarlos en el PDF.
  - **Extracción IA:** Usa Gemini para leer el PDF y encontrar datos automáticamente.
- **Botones:**
  - `🔍 Extraer Códigos`: Busca códigos según los patrones definidos.
  - `🤖 Extracción con IA`: Usa inteligencia artificial para llenar el formulario.
  - `💾 Guardar`: Registra el documento y los códigos encontrados en la BD.

### 🔗 SINCRONIZAR BD (Enlazador)
**Ruta:** `/modules/sincronizar/`
- **Propósito:** Conectar los documentos subidos con la base de datos histórica (SQL) y limpiar errores.
- **Botones Clave:**
  - `🔍 Analizar Coincidencias`: Busca qué archivos subidos coinciden con registros existentes.
  - `🔄 Sincronizar Ahora`: Realiza el enlace efectivo en la base de datos (INSERT OR UPDATE).
  - `🧹 Limpiar Duplicados`: Herramienta de mantenimiento que elimina códigos repetidos en la base de datos, dejando solo una copia única.

### 🖍️ RESALTAR DOC (Visor Inteligente)
**Ruta:** `/modules/resaltar/`
- **Propósito:** Herramienta visual para "pintar" o resaltar textos específicos dentro de un PDF. Útil para auditoría visual.
- **Funcionalidad:**
  - Seleccionas un PDF existente o subes uno nuevo.
  - Defines texto inicial y final.
  - El sistema marca en colores todas las apariciones.

### 🕒 DOCUMENTOS RECIENTES
**Ruta:** `/modules/recientes/`
- **Propósito:** Lista cronológica de lo último que entró al sistema.
- **Botones:**
  - `📄 Ver PDF`: Acceso directo al archivo (con ruta corregida automática).

### 🤖 CHAT INTELIGENTE (Asistente KINO)
- **Ubicación:** Botón flotante morado en la esquina inferior derecha.
- **Capacidades:**
  - Conoce toda la estructura descrita en este manual.
  - Puede buscar códigos en tiempo real (Ej: "¿Dónde está el código XYZ?").
  - Puede generar enlaces a documentos.
  - Responde dudas sobre cómo usar la app.

## 3. FLUJOS DE TRABAJO COMUNES

### Flujo: Importación Masiva
1. Ir a **Subida Lote**.
2. Subir ZIP con documentos.
3. Ir a **Sincronizar BD**.
4. Ejecutar `Analizar` y luego `Sincronizar`.
5. (Opcional) Ejecutar `Limpiar Duplicados` si se sospecha de redundancia.

### Flujo: Búsqueda de un Producto
1. Abrir **Gestor Doc** o usar el **Chat IA**.
2. Escribir el código del producto.
3. Ver en qué manifiesto llegó y en qué factura se vendió.
4. Abrir los PDFs correspondientes para verificar visualmente.
