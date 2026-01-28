# PUNTO DE RESTAURACIÓN: 2026-01-28
## ESTADO: PUNTO FUNCIONAL RESALTADO IMPORTANTE

**Fecha:** 2026-01-28 12:30 (Local)
**Versión Git:** `6fe2cd37210b237bfdc122b`
**Etiqueta Git:** `v2026-01-28-funcional`

---

### 🚀 Logros y Mejoras Implementadas

1.  **Visor Unificado (Resaltar/Viewer):** 
    *   Se eliminó el legado de `modules/documento/view.php`.
    *   Todos los enlaces de la aplicación apuntan ahora a `resaltar/viewer.php`.
    *   Funcionalidad completa de resaltado de términos, zoom y modo impresión.

2.  **Gestión de Códigos (Botón Dinámico):**
    *   Implementado botón dinámico **"Ver Códigos" / "Ocultar Códigos"** en las tablas de consulta y búsqueda.
    *   Previene recargas innecesarias y mejora la experiencia de usuario.

3.  **Búsqueda de Códigos Optimizada:**
    *   Cambiado `LIKE` por `=` para búsquedas exactas por código, eliminando falsos positivos.

4.  **Seguridad y Archivos:**
    *   Bloqueo estricto de duplicados basado en hash de archivo.
    *   Actualización de etiquetas: "Número o nombre de documento".
    *   Mejora en la previsualización del PDF actual durante la edición.

5.  **Caché (Asset Versioning):**
    *   Implementado `APP_VERSION` en `config.php` para forzar la actualización de CSS/JS en el navegador.

---

### 🔄 Estrategia de Recuperación

#### A. Recuperación vía GIT (Recomendada)
Si el sistema presenta fallos y deseas volver a este punto exacto:

1.  **Verificar estado:**
    ```bash
    git status
    ```
2.  **Volver al punto:**
    ```bash
    git reset --hard 6fe2cd37210b237bfdc122b
    ```
3.  **Limpiar archivos no rastreados (Opcional):**
    ```bash
    git clean -fd
    ```

#### B. Recuperación Local
1.  Busca el archivo `PUNTO_RESTAURACION_2026-01-28.md` para confirmar los cambios realizados hasta esta fecha.
2.  Los archivos principales modificados son:
    *   `index.php`
    *   `modules/busqueda/index.php`
    *   `modules/resaltar/viewer.php`
    *   `src/Api/DocumentController.php`
    *   `config.php`

---

> [!IMPORTANT]
> **Este punto es considerado ESTABLE Y FUNCIONAL.** 
> Antes de realizar cambios mayores, asegúrate de crear uno nuevo.
