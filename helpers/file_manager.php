<?php
/**
 * Helper para gestión de archivos y resolución de rutas.
 */

/**
 * Busca un archivo PDF de manera robusta explorando directorios locales.
 * 
 * @param string $clientCode Código del cliente.
 * @param array $doc Datos del documento (debe incluir 'ruta_archivo' y 'tipo').
 * @return string|null Ruta absoluta del archivo si existe, o null.
 */
function resolve_pdf_path(string $clientCode, array $doc): ?string
{
    if (empty($doc['ruta_archivo'])) {
        return null;
    }

    $uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";

    // Si no existe el directorio base, no tiene sentido buscar
    if (!is_dir($uploadsDir)) {
        return null;
    }

    $filename = basename($doc['ruta_archivo']);
    $type = strtolower($doc['tipo']);

    // Lista de rutas candidatas para probar en orden
    $candidates = [];

    // 1. Ruta exacta almacenada en BD (si es relativa)
    $candidates[] = $uploadsDir . $doc['ruta_archivo'];

    // 2. En la raíz de uploads
    $candidates[] = $uploadsDir . $filename;

    // 3. Escaneo dinámico de carpetas (para manejar mayúsculas/minúsculas y singulares/plurales)
    // Buscamos subcarpetas y vemos si coinciden con el 'tipo'
    $subdirs = glob($uploadsDir . '*', GLOB_ONLYDIR);
    if ($subdirs) {
        foreach ($subdirs as $dir) {
            $dirname = basename($dir);
            $compare = strtolower($dirname);

            // Coincidencia laxa: tipo exacto, plural 's', plural 'es', o si el nombre del directorio contiene el tipo
            if (
                $compare === $type ||
                $compare === $type . 's' ||
                $compare === $type . 'es' ||
                strpos($compare, $type) !== false
            ) {

                // Probar archivo en esta carpeta
                $candidates[] = $dir . '/' . $filename;

                // Probar ruta original completa dentro de esta carpeta (por si acaso)
                if ($doc['ruta_archivo'] !== $filename) {
                    $candidates[] = $dir . '/' . basename($doc['ruta_archivo']);
                }
            }
        }
    }

    // Verificar existencia
    foreach ($candidates as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Retorna lista de carpetas disponibles para debug.
 */
function get_available_folders(string $clientCode): array
{
    $uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
    $folders = [];
    if (is_dir($uploadsDir)) {
        $subdirs = glob($uploadsDir . '*', GLOB_ONLYDIR);
        if ($subdirs) {
            foreach ($subdirs as $dir) {
                $folders[] = basename($dir);
            }
        }
    }
    return $folders;
}
