<?php
namespace App\Core;

/**
 * Исправленный Bootstrap для корректной инициализации
 * Решает проблемы циклических зависимостей и повторной инициализации
 */
class Bootstrap 
{
    private static bool $initialized = false;
    private static array $initLog = [];
    private static array $initErrors = [];
    
    /**
     * Главный метод инициализации с защитой от повторного вызова
     */
    public static function init(): void 
    {
        // Строгая защита от повторной инициализации
        if (self::$initialized) {
            throw new \RuntimeException("Bootstrap already initialized. Multiple initialization attempts detected.");
        }
        
        try {
            // Устанавливаем флаг СРАЗУ для предотвращения рекурсии
            self::$initialized = true;
            
            // Инициализируем компоненты в правильном порядке
            self::initPhase1(); // Базовые компоненты без зависимостей
            self::initPhase2(); // Компоненты с простыми зависимостями  
            self::initPhase3(); // Компоненты с комплексными зависимостями
            
            self::logSuccess();
            
        } catch (\Exception $e) {
            self::$initialized = false; // Откатываем при ошибке
            self::handleInitError($e);
            throw $e;
        }
    }
    
    /**
     * Фаза 1: Инициализация базовых компонентов без зависимостей
     */
    private static function initPhase1(): void
    {
        // 1. Config - должен быть первым, без зависимостей
        self::initComponent('Config', function() {
            Config::load(); // Используем load() вместо get() чтобы избежать рекурсии
        });
        
        // 2. Cache - может работать независимо
        self::initComponent('Cache', function() {
            if (class_exists('\App\Core\Cache')) {
                Cache::init();
            }
        });
    }
    
    /**
     * Фаза 2: Компоненты с простыми зависимостями
     */
    private static function initPhase2(): void
    {
        // 3. Database - зависит только от Config
        self::initComponent('Database', function() {
            // Проверяем соединение без использования Logger
            $pdo = Database::getConnection();
            if (!$pdo) {
                throw new \RuntimeException("Database connection failed");
            }
        });
        
        // 4. Logger - теперь может использовать Database
        self::initComponent('Logger', function() {
            Logger::initialize();
        });
    }
    
    /**
     * Фаза 3: Компоненты с комплексными зависимостями
     */
    private static function initPhase3(): void
    {
        // 5. Security - может использовать все предыдущие компоненты
        self::initComponent('Security', function() {
            if (class_exists('\App\Core\SecurityManager')) {
                SecurityManager::initialize();
            }
        });
        
        // 6. Session - последний, так как может использовать Database
        self::initComponent('Session', function() {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                Session::start();
            }
        });
    }
    
    /**
     * Безопасная инициализация компонента с обработкой ошибок
     */
    private static function initComponent(string $name, callable $initializer): void
    {
        if (isset(self::$initLog[$name])) {
            throw new \RuntimeException("Component {$name} already initialized!");
        }
        
        try {
            $startTime = microtime(true);
            $initializer();
            $duration = microtime(true) - $startTime;
            
            self::$initLog[$name] = [
                'status' => 'success',
                'duration' => $duration,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
        } catch (\Exception $e) {
            self::$initErrors[$name] = [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            error_log("Failed to initialize {$name}: " . $e->getMessage());
            throw new \RuntimeException("Component {$name} initialization failed: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Логирование успешной инициализации
     */
    private static function logSuccess(): void
    {
        $totalDuration = array_sum(array_column(self::$initLog, 'duration'));
        $message = sprintf(
            "Bootstrap completed successfully in %.3fs. Components: %s",
            $totalDuration,
            implode(', ', array_keys(self::$initLog))
        );
        
        error_log($message);
        
        // Теперь Logger доступен, можем им пользоваться
        if (class_exists('\App\Core\Logger')) {
            Logger::info($message, ['init_log' => self::$initLog]);
        }
    }
    
    /**
     * Обработка ошибок инициализации
     */
    private static function handleInitError(\Exception $e): void
    {
        $errorReport = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'successful_components' => array_keys(self::$initLog),
            'failed_components' => self::$initErrors,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        error_log("Bootstrap initialization failed: " . json_encode($errorReport));
    }
    
    /**
     * Проверка статуса инициализации
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }
    
    /**
     * Получить список инициализированных компонентов
     */
    public static function getInitializedComponents(): array
    {
        return array_keys(self::$initLog);
    }
    
    /**
     * Получить полный лог инициализации
     */
    public static function getInitLog(): array
    {
        return self::$initLog;
    }
    
    /**
     * Получить ошибки инициализации
     */
    public static function getInitErrors(): array
    {
        return self::$initErrors;
    }
    
    /**
     * Принудительный сброс (только для тестов!)
     */
    public static function reset(): void
    {
        if (php_sapi_name() !== 'cli') {
            throw new \RuntimeException("Reset is only allowed in CLI mode");
        }
        
        self::$initialized = false;
        self::$initLog = [];
        self::$initErrors = [];
    }
    
    /**
     * Проверка готовности системы
     */
    public static function healthCheck(): array
    {
        if (!self::$initialized) {
            return ['status' => 'not_initialized'];
        }
        
        $health = [
            'status' => 'healthy',
            'components' => [],
            'total_duration' => array_sum(array_column(self::$initLog, 'duration')),
            'initialized_at' => self::$initLog[array_key_first(self::$initLog)]['timestamp'] ?? null
        ];
        
        foreach (self::$initLog as $component => $info) {
            $health['components'][$component] = [
                'status' => $info['status'],
                'duration' => $info['duration']
            ];
        }
        
        return $health;
    }
}