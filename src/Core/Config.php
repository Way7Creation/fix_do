<?php
namespace App\Core;

/**
 * Безопасный менеджер конфигурации
 * ИСПРАВЛЕННАЯ ВЕРСИЯ - без хардкода паролей
 */
class Config
{
    private static array $config = [];
    private static bool $loaded = false;
    private static ?string $configPath = null;

    /**
     * Получить значение конфигурации
     */
    public static function get(string $key, $default = null)
    {
        if (!self::$loaded) {
            self::load();
        }

        $keys = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Проверить существование ключа
     */
    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /**
     * Получить всю конфигурацию
     */
    public static function all(): array
    {
        if (!self::$loaded) {
            self::load();
        }
        return self::$config;
    }

    /**
     * Загрузка конфигурации ТОЛЬКО из внешних файлов
     */
    public static function load(): void
    {
        try {
            // Определяем путь к конфигурации
            self::$configPath = self::findConfigPath();
            
            if (!self::$configPath) {
                throw new \RuntimeException("Configuration directory not found. Please check paths.");
            }
            
            // Загружаем .env файл первым
            self::loadEnvironmentFile();
            
            // Загружаем все ini файлы
            self::loadIniFiles();
            
            // Заменяем переменные окружения в конфигах
            self::$config = self::replaceEnvironmentVariables(self::$config);
            
            // Валидируем обязательные параметры
            self::validateRequiredConfig();
            
            self::$loaded = true;
            
        } catch (\Exception $e) {
            error_log("Configuration loading failed: " . $e->getMessage());
            throw new \RuntimeException("Cannot start application without configuration: " . $e->getMessage());
        }
    }

    /**
     * Поиск директории конфигурации
     */
    private static function findConfigPath(): ?string
    {
        $configPaths = [
            '/etc/vdestor/config',         // Основной путь для продакшена
            '/var/www/config/vdestor',     // Альтернативный путь
            $_ENV['CONFIG_PATH'] ?? null,  // Переменная окружения
            dirname(__DIR__, 2) . '/config',  // Локальная разработка
        ];
        
        foreach (array_filter($configPaths) as $path) {
            if (is_dir($path) && is_readable($path)) {
                return $path;
            }
        }
        
        return null;
    }

    /**
     * Загрузка переменных окружения из .env файла
     */
    private static function loadEnvironmentFile(): void
    {
        $envFile = self::$configPath . '/.env';
        
        if (!file_exists($envFile)) {
            return; // .env файл опционален
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Пропускаем комментарии и пустые строки
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            
            // Разбираем строку KEY=VALUE
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value, '"\'');
            }
        }
    }

    /**
     * Загрузка всех INI файлов из директории
     */
    private static function loadIniFiles(): void
    {
        $configFiles = [
            'database' => 'database.ini',
            'app' => 'app.ini', 
            'integrations' => 'integrations.ini',
            'security' => 'security.ini'
        ];

        foreach ($configFiles as $section => $filename) {
            $filePath = self::$configPath . '/' . $filename;
            
            if (file_exists($filePath)) {
                $config = parse_ini_file($filePath, true);
                if ($config !== false) {
                    self::$config[$section] = $config;
                }
            }
        }
    }

    /**
     * Замена переменных окружения в значениях конфигурации
     */
    private static function replaceEnvironmentVariables(array $config): array
    {
        array_walk_recursive($config, function (&$value) {
            if (is_string($value) && preg_match('/\$\{([^}]+)\}/', $value, $matches)) {
                $envKey = $matches[1];
                $envValue = $_ENV[$envKey] ?? '';
                $value = str_replace($matches[0], $envValue, $value);
            }
        });
        
        return $config;
    }

    /**
     * Валидация обязательных параметров конфигурации
     */
    private static function validateRequiredConfig(): void
    {
        $required = [
            'database.mysql.host',
            'database.mysql.user', 
            'database.mysql.password',
            'database.mysql.database'
        ];

        $missing = [];
        foreach ($required as $key) {
            if (!self::has($key)) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException("Missing required configuration: " . implode(', ', $missing));
        }
    }

    /**
     * Получить путь к директории конфигурации
     */
    public static function getConfigPath(): ?string
    {
        if (!self::$loaded) {
            self::load();
        }
        return self::$configPath;
    }

    /**
     * Проверить безопасность конфигурации
     */
    public static function validateSecurity(): array
    {
        $issues = [];
        
        // Проверяем права доступа к директории
        $configPath = self::getConfigPath();
        if ($configPath && is_readable($configPath)) {
            $perms = fileperms($configPath) & 0777;
            if ($perms > 0750) {
                $issues[] = "Configuration directory has too permissive rights: " . decoct($perms);
            }
        }

        // Проверяем наличие обязательных настроек
        $required = [
            'database.mysql.host',
            'database.mysql.user', 
            'database.mysql.password',
            'database.mysql.database'
        ];

        foreach ($required as $key) {
            if (!self::has($key)) {
                $issues[] = "Required configuration missing: {$key}";
            }
        }

        return $issues;
    }

    /**
     * Получить конфигурацию для подключения к БД
     */
    public static function getDatabaseConfig(): array
    {
        return [
            'host' => self::get('database.mysql.host'),
            'port' => self::get('database.mysql.port', 3306),
            'user' => self::get('database.mysql.user'),
            'password' => self::get('database.mysql.password'),
            'database' => self::get('database.mysql.database'),
            'charset' => self::get('database.mysql.charset', 'utf8mb4')
        ];
    }
}