<?php
declare(strict_types=1);

namespace Dlm\Controllers;

use Dlm\Support\Config;
use Dlm\Support\Db;
use Dlm\Support\Request;
use Dlm\Support\Response;
use PDO;
use Throwable;

/**
 * 健康检查（供负载均衡 / 容器探针 / 监控使用）。
 *
 * 返回纯 JSON，不套用 /v1 的统一响应信封：
 * - 200 + {"status":"ok"}   全部检查项通过
 * - 503 + {"status":"fail"} 任一检查项失败
 *
 * 该路由不要求登录、不挂 csrf，且在系统未安装时也照常响应（安装向导未完成本身就应视为不健康）。
 */
final class HealthController
{
    /** 数据库探测的连接超时（秒），避免 MySQL 不可达时探针长时间挂起 */
    private const DB_TIMEOUT = 3;

    public function show(Request $request): void
    {
        $healthy = true;
        $checks = [];

        // WEB 服务：能执行到控制器即说明 PHP 与路由链路正常，无需额外探测
        $checks['web'] = [
            'status' => 'ok',
            'php_version' => PHP_VERSION,
        ];

        if (!Config::installed()) {
            $healthy = false;
            $checks['database'] = [
                'status' => 'fail',
                'message' => '系统尚未安装完成',
            ];
        } else {
            $startedAt = microtime(true);
            try {
                $pdo = Db::connect((array) Config::get('db', []), self::DB_TIMEOUT);
                $pdo->query('SELECT 1');
                $checks['database'] = [
                    'status' => 'ok',
                    'server_version' => (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                    'duration_ms' => $this->elapsedMs($startedAt),
                ];
            } catch (Throwable $e) {
                // 详细原因（可能含账号等敏感信息）只写日志，响应里给通用提示
                error_log('[DLM] healthz 数据库检查失败: ' . $e->getMessage());
                $healthy = false;
                $checks['database'] = [
                    'status' => 'fail',
                    'message' => '数据库连接失败',
                    'duration_ms' => $this->elapsedMs($startedAt),
                ];
            }
        }

        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }

        Response::json([
            'status' => $healthy ? 'ok' : 'fail',
            'checks' => $checks,
            'timestamp' => date('c'),
        ], $healthy ? 200 : 503);
    }

    private function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }
}
