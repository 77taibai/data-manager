<?php
declare(strict_types=1);

define('DLM_APP', true);
define('DLM_ROOT', dirname(__DIR__));
define('DLM_SRC', __DIR__);
define('DLM_VIEW', DLM_ROOT . '/views');
define('DLM_PUBLIC', DLM_ROOT . '/public');
define('DLM_START', microtime(true));

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$dlmLogDir = DLM_ROOT . '/logs';
if (!is_dir($dlmLogDir)) {
    @mkdir($dlmLogDir, 0775, true);
}
ini_set('error_log', $dlmLogDir . '/php-error.log');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Dlm\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = DLM_SRC . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Dlm\Support\Config::load(DLM_ROOT . '/config.php');

date_default_timezone_set((string) (Dlm\Support\Config::get('timezone') ?: 'Asia/Shanghai'));

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE
    && !Dlm\Support\Request::isHealthRequest()
) {
    // 健康检查不建会话：探针请求高频且无需登录态，避免产生大量无用 session 文件
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_name('dlmsid');
    session_start();
}

set_exception_handler(static function (Throwable $e): void {
    error_log('[DLM] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
        . "\n" . $e->getTraceAsString());
    if ($e instanceof Dlm\Support\ApiException) {
        Dlm\Support\Response::apiError($e->getMessage(), $e->apiCode, $e->httpStatus);
        return;
    }
    if (Dlm\Support\Request::isHealthRequest()) {
        // 健康检查只返回状态，不暴露异常细节（细节已写入日志）
        Dlm\Support\Response::json([
            'status' => 'fail',
            'checks' => [
                'web' => ['status' => 'fail', 'message' => '服务内部错误'],
            ],
            'timestamp' => date('c'),
        ], 503);
        return;
    }
    if (Dlm\Support\Request::isApiRequest()) {
        Dlm\Support\Response::apiError('服务器内部错误', 5000, 500);
        return;
    }
    http_response_code(500);
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8">'
        . '<title>500</title><body style="font-family:sans-serif;padding:40px">'
        . '<h1>500 服务器内部错误</h1><p>请稍后重试或查看服务器日志。</p></body></html>';
});
