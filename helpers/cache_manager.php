<?php
/**
 * CacheManager Optimizado
 * - Recolección de basura (Garbage Collection) automática.
 * - Bloqueo de archivos para evitar corrupción.
 * - Estructura segura.
 */

class CacheManager
{
    private static $cacheDir = null;
    // Probabilidad de 1 en 50 de ejecutar limpieza en cada escritura (2%)
    private static $gcProbability = 2;

    private static function init($clientCode)
    {
        if (self::$cacheDir === null) {
            // Usar __DIR__ para asegurar ruta absoluta correcta
            self::$cacheDir = dirname(__DIR__) . '/clients/' . $clientCode . '/cache';

            if (!is_dir(self::$cacheDir)) {
                if (!mkdir(self::$cacheDir, 0755, true)) {
                    return false;
                }
                // Proteger carpeta de acceso web
                file_put_contents(self::$cacheDir . '/.htaccess', "Order Deny,Allow\nDeny from all");
            }
        }
        return true;
    }

    public static function set($clientCode, $key, $data, $ttl = 300)
    {
        if (!self::init($clientCode))
            return false;

        // Garbage Collector Probabilístico
        if (rand(1, 100) <= self::$gcProbability) {
            self::gc($clientCode);
        }

        $filename = self::getFilename($key);
        $payload = [
            'expiry' => time() + $ttl,
            'data' => $data
        ];

        // LOCK_EX evita que dos procesos escriban a la vez
        return file_put_contents($filename, json_encode($payload), LOCK_EX) !== false;
    }

    public static function get($clientCode, $key)
    {
        if (!self::init($clientCode))
            return null;

        $filename = self::getFilename($key);

        if (!file_exists($filename)) {
            return null;
        }

        // Suprimir errores de lectura si el archivo está siendo escrito
        $content = @file_get_contents($filename);
        if (!$content)
            return null;

        $payload = json_decode($content, true);

        // Si expiró, retornamos null (el GC lo borrará después)
        if (!$payload || !isset($payload['expiry']) || time() > $payload['expiry']) {
            return null;
        }

        return $payload['data'];
    }

    public static function delete($clientCode, $key)
    {
        if (!self::init($clientCode))
            return;
        $filename = self::getFilename($key);
        if (file_exists($filename))
            @unlink($filename);
    }

    public static function clear($clientCode)
    {
        if (!self::init($clientCode))
            return;
        $files = glob(self::$cacheDir . '/*.cache');
        foreach ($files as $file) {
            if (is_file($file))
                @unlink($file);
        }
    }

    /**
     * Garbage Collector: Borra archivos expirados
     */
    public static function gc($clientCode)
    {
        if (!self::init($clientCode))
            return;

        $files = glob(self::$cacheDir . '/*.cache');
        $now = time();

        foreach ($files as $file) {
            if (is_file($file)) {
                // Solo leemos las primeras líneas si el archivo es gigante, 
                // pero como es JSON, leemos todo por simplicidad.
                $content = @file_get_contents($file);
                if ($content) {
                    $payload = json_decode($content, true);
                    if (isset($payload['expiry']) && $now > $payload['expiry']) {
                        @unlink($file);
                    }
                }
            }
        }
    }

    private static function getFilename($key)
    {
        return self::$cacheDir . DIRECTORY_SEPARATOR . md5($key) . '.cache';
    }
}
