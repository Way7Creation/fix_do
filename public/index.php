<?php
declare(strict_types=1);

/**
 * Исправленная точка входа приложения
 * Убирает смешанную логику и упрощает роутинг
 */

// Критическая обработка ошибок
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Загружаем autoloader
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    die('Composer autoloader not found. Please run: composer install');
}
require_once __DIR__ . '/../vendor/autoload.php';

// ЕДИНСТВЕННАЯ инициализация через исправленный Bootstrap
try {
    \App\Core\Bootstrap::init();
} catch (\Exception $e) {
    error_log("Critical init error: " . $e->getMessage());
    http_response_code(500);
    
    // В development режиме показываем детали
    if (\App\Core\Config::get('app.debug', false)) {
        die('<h1>Initialization Error</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>');
    } else {
        die('System temporarily unavailable');
    }
}

// Импорты контроллеров
use App\Core\Router;
use App\Controllers\{
    LoginController,
    AdminController,
    CartController,
    SpecificationController,
    ProductController,
    ApiController
};

// Создаем роутер
$router = new Router();

// ========================================
// API РОУТЫ (проверяем в первую очередь)
// ========================================

$apiController = new ApiController();
$router->get('/api/test', [$apiController, 'testAction']);
$router->get('/api/availability', [$apiController, 'availabilityAction']);
$router->get('/api/search', [$apiController, 'searchAction']);
$router->get('/api/autocomplete', [$apiController, 'autocompleteAction']);

// ========================================
// ТОВАРЫ
// ========================================

$productController = new ProductController();
$router->get('/shop/product', [$productController, 'viewAction']);
$router->get('/shop/product/{id}', [$productController, 'viewAction']);
$router->get('/api/product/{id}/info', [$productController, 'ajaxProductInfoAction']);
$router->get('/shop', [$productController, 'catalogAction']);

// ========================================
// АВТОРИЗАЦИЯ
// ========================================

$loginController = new LoginController();
$router->match(['GET', 'POST'], '/login', [$loginController, 'loginAction']);

$router->get('/logout', function() {
    \App\Services\AuthService::destroySession();
    header('Location: /login');
    exit;
});

// ========================================
// АДМИН ПАНЕЛЬ
// ========================================

$adminController = new AdminController();

// Группируем админские роуты с единой авторизацией
$adminRoutes = [
    '/admin' => 'indexAction',
    '/admin/diagnostics' => 'diagnosticsAction',
    '/admin/documentation' => 'documentationAction'
];

foreach ($adminRoutes as $route => $action) {
    $router->get($route, function() use ($adminController, $action) {
        // Единая проверка прав админа
        if (!\App\Services\AuthService::checkRole('admin')) {
            header('Location: /login');
            exit;
        }
        
        return $adminController->{$action}();
    });
}

// ========================================
// КОРЗИНА
// ========================================

$cartController = new CartController();
$router->match(['GET', 'POST'], '/cart/add', [$cartController, 'addAction']);
$router->get('/cart', [$cartController, 'viewAction']);
$router->post('/cart/clear', [$cartController, 'clearAction']);
$router->post('/cart/remove', [$cartController, 'removeAction']);
$router->get('/cart/json', [$cartController, 'getJsonAction']);

// ========================================
// СПЕЦИФИКАЦИИ
// ========================================

$specController = new SpecificationController();

// Группируем спецификации с проверкой авторизации
$specRoutes = [
    '/specification/create' => ['POST', 'createAction'],
    '/specifications' => ['GET', 'listAction']
];

foreach ($specRoutes as $route => [$method, $action]) {
    $router->match([$method], $route, function() use ($specController, $action) {
        // Проверяем авторизацию для спецификаций
        if (!\App\Services\AuthService::check()) {
            if ($action === 'createAction') {
                // Для AJAX запросов возвращаем JSON
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Требуется авторизация']);
                exit;
            } else {
                header('Location: /login');
                exit;
            }
        }
        
        return $specController->{$action}();
    });
}

// Просмотр спецификации (доступен всем)
$router->get('/specification/{id}', [$specController, 'viewAction']);

// ========================================
// ГЛАВНАЯ СТРАНИЦА
// ========================================

$router->get('/', function() {
    try {
        \App\Core\Layout::render('home/index', [
            'stats' => [
                'products_count' => \App\Core\Database::query("SELECT COUNT(*) FROM products")->fetchColumn(),
                'brands_count' => \App\Core\Database::query("SELECT COUNT(*) FROM brands")->fetchColumn(),
                'cities_count' => \App\Core\Database::query("SELECT COUNT(*) FROM cities")->fetchColumn()
            ]
        ]);
    } catch (\Exception $e) {
        \App\Core\Logger::error('Home page error', ['error' => $e->getMessage()]);
        \App\Core\Layout::render('errors/500', []);
    }
});

// ========================================
// 404 ОБРАБОТЧИК
// ========================================

$router->set404(function() {
    http_response_code(404);
    
    // Для API запросов возвращаем JSON
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($requestUri, '/api/') !== false) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Endpoint not found',
            'code' => 404
        ]);
    } else {
        \App\Core\Layout::render('errors/404', []);
    }
});

// ========================================
// ОБРАБОТКА ЗАПРОСА
// ========================================

try {
    // Добавляем middleware для логирования
    $startTime = microtime(true);
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    // Диспетчер роутов
    $router->dispatch();
    
    // Логируем время выполнения
    $duration = microtime(true) - $startTime;
    if ($duration > 1.0) { // Логируем медленные запросы
        \App\Core\Logger::warning('Slow request detected', [
            'uri' => $requestUri,
            'method' => $method,
            'duration' => $duration
        ]);
    }
    
} catch (\App\Exceptions\ValidationException $e) {
    // Обработка ошибок валидации
    http_response_code(422);
    
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'errors' => $e->getErrors()
        ]);
    } else {
        \App\Core\Layout::render('errors/validation', [
            'message' => $e->getMessage(),
            'errors' => $e->getErrors()
        ]);
    }
    
} catch (\App\Exceptions\AuthenticationException $e) {
    // Обработка ошибок авторизации
    http_response_code(401);
    
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Authentication required'
        ]);
    } else {
        header('Location: /login');
        exit;
    }
    
} catch (\Exception $e) {
    // Общая обработка ошибок
    \App\Core\Logger::error("Application error", [
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    http_response_code(500);
    
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error'
        ]);
    } else {
        if (\App\Core\Config::get('app.debug', false)) {
            // В режиме отладки показываем детали
            echo '<h1>Application Error</h1>';
            echo '<p><strong>Message:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        } else {
            \App\Core\Layout::render('errors/500', []);
        }
    }
}