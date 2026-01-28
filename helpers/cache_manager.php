<?php
/**
 * Sistema de Caché de Archivos Simple
 * 
 * Almacena datos cacheados en archivos JSON dentro del directorio del cliente.
 * Soporta TTL (Time To Live).
 */

class CacheManager
{
    private static $cacheDir = null;

    /**
     * Inicializa el directorio de caché para el cliente actual
     */
    private static function init($clientCode)
    {
        if (self::$cacheDir === null) {
            self::$cacheDir = CLIENTS_DIR . DIRECTORY_SEPARATOR . $clientCode . DIRECTORY_SEPARATOR . 'cache';

            if (!is_dir(self::$cacheDir)) {
                mkdir(self::$cacheDir, 0777, true);
            }
        }
    }

    /**
     * Define una clave de caché
     * 
     * @param string $clientCode Código del cliente
     * @param string $key Clave única del caché
     * @param mixed $data Datos a almacenar
     * @param int $ttl Tiempo de vida en segundos (default 300 = 5 min)
     */
    public static function set($clientCode, $key, $data, $ttl = 300)
    {
        self::init($clientCode);

        $filename = self::getFilename($key);
        $payload = [
            'expiry' => time() + $ttl,
            'data' => $data
        ];

        file_put_contents($filename, json_encode($payload));
    }

    /**
     * Obtiene datos del caché
     * 
     * @param string $clientCode Código del cliente
     * @param string $key Clave única
     * @return mixed|null Retorna los datos o null si expiró/no existe
     */
    public static function get($clientCode, $key)
    {
        self::init($clientCode);

        $filename = self::getFilename($key);

        if (!file_exists($filename)) {
            return null;
        }

        $content = file_get_contents($filename);
        $payload = json_decode($content, true);

        if (!$payload || !isset($payload['expiry'])) {
            return null; // Archivo corrupto
        }

        if (time() > $payload['expiry']) {
            unlink($filename); // Eliminar expirado
            return null;
        }

        return $payload['data'];
    }

    /**
     * Elimina una clave específica
     */
    public static function delete($clientCode, $key)
    {
        self::init($clientCode);
        $filename = self::getFilename($key);

        if (file_exists($filename)) {
            unlink($filename);
        }
    }

    /**
     * Limpia todo el caché de un cliente
     */
    public static function clear($clientCode)
    {
        self::init($clientCode);

        $files = glob(self::$cacheDir . '/*.cache');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private static function getFilename($key)
    {
        return self::$cacheDir . DIRECTORY_SEPARATOR . md5($key) . '.cache';
    }
}
