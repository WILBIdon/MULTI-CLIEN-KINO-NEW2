<?php
/**
 * Funciones de utilidad relacionadas con la gestión de clientes (tenants).
 *
 * Estas funciones ayudan a sanitizar códigos, crear la estructura física
 * para cada cliente, abrir conexiones a bases de datos SQLite individuales
 * y copiar directorios. Al centralizar esta lógica aquí, evitamos repetir
 * código en distintas partes de la aplicación y facilitamos futuras mejoras.
 */

require_once __DIR__ . '/../config.php';

/**
 * Sanitiza un código de cliente para asegurar que solo contenga letras
 * minúsculas, números y guiones bajos. Esto previene inyecciones de rutas
 * indeseadas.
 *
 * @param string $code Código de cliente ingresado por el usuario.
 * @return string Código sanitizado.
 */
function sanitize_code(string $code): string
{
    return preg_replace('/[^a-z0-9_]+/i', '', strtolower($code));
}

/**
 * Devuelve la ruta al archivo de base de datos de un cliente.
 *
 * @param string $code Código de cliente.
 * @return string Ruta al archivo .db del cliente.
 */
function client_db_path(string $code): string
{
    return CLIENTS_DIR . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . $code . '.db';
}

/**
 * Abre (y crea si no existe) una conexión PDO a la base de datos SQLite de un
 * cliente. También se encarga de activar el modo estricto de errores.
 *
 * @param string $code Código de cliente.
 * @return PDO Conexión a la base de datos del cliente.
 */
function open_client_db(string $code): PDO
{
    $path = client_db_path($code);
    $dsn = 'sqlite:' . $path;
    $db = new PDO($dsn);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

/**
 * Crea toda la estructura necesaria para un nuevo cliente.
 *
 * - Crea directorios para el cliente y sus subcarpetas de uploads.
 * - Genera un archivo de base de datos SQLite con las tablas necesarias.
 * - Inserta el registro en la base central de clientes.
 *
 * @param string $code Código único del cliente (sanitizado).
 * @param string $name Nombre del cliente o empresa.
 * @param string $password_hash Hash de la contraseña del cliente.
 * @param string $titulo Título de la aplicación que verá el cliente.
 * @param string $colorP Color primario de la interfaz del cliente.
 * @param string $colorS Color secundario de la interfaz del cliente.
 * @return void
 */
function create_client_structure(
    string $code,
    string $name,
    string $password_hash,
    string $titulo = '',
    string $colorP = '#2563eb',
    string $colorS = '#F87171'
): void {
    global $centralDb;
    $code = sanitize_code($code);

    // 1. Crear directorios
    $clientDir = CLIENTS_DIR . DIRECTORY_SEPARATOR . $code;
    if (!file_exists($clientDir)) {
        mkdir($clientDir, 0777, true);
        mkdir($clientDir . '/uploads', 0777, true);
        mkdir($clientDir . '/uploads/manifiestos', 0777, true);
        mkdir($clientDir . '/uploads/declaraciones', 0777, true);
        mkdir($clientDir . '/uploads/facturas', 0777, true);
    }

    // 2. Crear base de datos SQLite del cliente y tablas
    $db = open_client_db($code);
    $db->exec(
        "CREATE TABLE IF NOT EXISTS documentos (\n"
        . "    id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "    tipo TEXT NOT NULL,\n"
        . "    numero TEXT NOT NULL,\n"
        . "    fecha DATE NOT NULL,\n"
        . "    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,\n"
        . "    proveedor TEXT,\n"
        . "    naviera TEXT,\n"
        . "    peso_kg REAL,\n"
        . "    valor_usd REAL,\n"
        . "    ruta_archivo TEXT NOT NULL,\n"
        . "    hash_archivo TEXT,\n"
        . "    datos_extraidos TEXT,\n"
        . "    ai_confianza REAL,\n"
        . "    requiere_revision INTEGER DEFAULT 0,\n"
        . "    estado TEXT DEFAULT 'pendiente',\n"
        . "    notas TEXT\n"
        . ");\n"
        . "CREATE TABLE IF NOT EXISTS codigos (\n"
        . "    id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "    documento_id INTEGER NOT NULL,\n"
        . "    codigo TEXT NOT NULL,\n"
        . "    descripcion TEXT,\n"
        . "    cantidad INTEGER,\n"
        . "    valor_unitario REAL,\n"
        . "    validado INTEGER DEFAULT 0,\n"
        . "    alerta TEXT,\n"
        . "    FOREIGN KEY(documento_id) REFERENCES documentos(id) ON DELETE CASCADE\n"
        . ");\n"
        . "CREATE TABLE IF NOT EXISTS vinculos (\n"
        . "    id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "    documento_origen_id INTEGER NOT NULL,\n"
        . "    documento_destino_id INTEGER NOT NULL,\n"
        . "    tipo_vinculo TEXT NOT NULL,\n"
        . "    codigos_coinciden INTEGER DEFAULT 0,\n"
        . "    codigos_faltan INTEGER DEFAULT 0,\n"
        . "    codigos_extra INTEGER DEFAULT 0,\n"
        . "    discrepancias TEXT,\n"
        . "    FOREIGN KEY(documento_origen_id) REFERENCES documentos(id) ON DELETE CASCADE,\n"
        . "    FOREIGN KEY(documento_destino_id) REFERENCES documentos(id) ON DELETE CASCADE\n"
        . ");"
    );

    // 3. Registrar cliente en la base de control
    // Insert the new client into the control table. Note: we do not include
    // 'activo' here because the schema defines a default of 1 (active).
    $stmt = $centralDb->prepare(
        'INSERT INTO control_clientes (codigo, nombre, password_hash, titulo, color_primario, color_secundario) ' .
        'VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$code, $name, $password_hash, $titulo, $colorP, $colorS]);
}

/**
 * Copia todos los archivos (no carpetas vacías) de un directorio origen a uno
 * destino. Esta función se utiliza para clonar clientes o respaldos.
 *
 * @param string $src Directorio de origen.
 * @param string $dst Directorio de destino.
 * @return void
 */
function copy_dir_files_only(string $src, string $dst): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $targetPath = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($targetPath)) {
                mkdir($targetPath, 0777, true);
            }
        } else {
            if (!file_exists(dirname($targetPath))) {
                mkdir(dirname($targetPath), 0777, true);
            }
            copy($item, $targetPath);
        }
    }
}

/**
 * Clona un cliente existente. Crea la estructura para el nuevo cliente y
 * copia todos los archivos y la base de datos del cliente origen al nuevo.
 *
 * @param string $sourceCode Código del cliente existente que se desea clonar.
 * @param string $newCode Código del nuevo cliente.
 * @param string $newName Nombre del nuevo cliente.
 * @param string $password_hash Hash de la contraseña para el nuevo cliente.
 * @return void
 */
function clone_client(
    string $sourceCode,
    string $newCode,
    string $newName,
    string $password_hash
): void {
    global $centralDb;
    // Sanitizar códigos
    $sourceCode = sanitize_code($sourceCode);
    $newCode = sanitize_code($newCode);
    // Verificar que el cliente origen exista
    $stmt = $centralDb->prepare('SELECT COUNT(*) FROM control_clientes WHERE codigo = ?');
    $stmt->execute([$sourceCode]);
    $exists = (int) $stmt->fetchColumn() > 0;
    if (!$exists) {
        throw new Exception("El cliente origen no existe: $sourceCode");
    }
    // Crear estructura del nuevo cliente (directorios y tablas vacías)
    create_client_structure($newCode, $newName, $password_hash);
    // Copiar archivos del cliente origen al nuevo (incluyendo base de datos)
    $srcDir = CLIENTS_DIR . DIRECTORY_SEPARATOR . $sourceCode;
    $dstDir = CLIENTS_DIR . DIRECTORY_SEPARATOR . $newCode;
    copy_dir_files_only($srcDir, $dstDir);
    // Reemplazar el registro en central para asegurarnos de que el nombre y colores se conserven del origen
    // Obtenemos info del origen
    $infoStmt = $centralDb->prepare('SELECT titulo, color_primario, color_secundario FROM control_clientes WHERE codigo = ?');
    $infoStmt->execute([$sourceCode]);
    $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
    $titulo = $info['titulo'] ?? '';
    $colorP = $info['color_primario'] ?? '#2563eb';
    $colorS = $info['color_secundario'] ?? '#F87171';
    // Actualizamos los colores y título del nuevo cliente en la central
    $updateStmt = $centralDb->prepare('UPDATE control_clientes SET titulo = ?, color_primario = ?, color_secundario = ? WHERE codigo = ?');
    $updateStmt->execute([$titulo, $colorP, $colorS, $newCode]);
}
