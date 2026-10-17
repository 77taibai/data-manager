<?php
declare(strict_types=1);

namespace Dlm\Controllers\Web;

use Dlm\Support\Config;
use Dlm\Support\Flash;
use Dlm\Support\Request;
use Dlm\Support\Response;
use Dlm\Support\View;
use PDO;
use Throwable;

final class InstallController
{
    public function show(Request $request): void
    {
        if (Config::installed()) {
            Response::redirect('/login');
            return;
        }
        $old = Flash::pullOld();
        View::render('install/index', [
            'title' => '安装向导',
            'old' => $old,
            'error' => null,
        ], 'layout_blank');
    }

    public function run(Request $request): void
    {
        if (Config::installed()) {
            Response::redirect('/login');
            return;
        }

        $db = [
            'host' => trim((string) $request->input('db_host', '127.0.0.1')),
            'port' => (int) $request->input('db_port', 3306),
            'name' => trim((string) $request->input('db_name', '')),
            'user' => trim((string) $request->input('db_user', '')),
            'pass' => (string) $request->input('db_pass', ''),
            'charset' => 'utf8mb4',
        ];
        $username = trim((string) $request->input('admin_user', ''));
        $password = (string) $request->input('admin_pass', '');
        $password2 = (string) $request->input('admin_pass2', '');

        $errors = [];
        if ($db['name'] === '' || $db['user'] === '') {
            $errors[] = '数据库名与数据库用户为必填项';
        }
        if ($username === '') {
            $errors[] = '管理员账号为必填项';
        }
        if (mb_strlen($password) < 6) {
            $errors[] = '管理员密码至少 6 位';
        }
        if ($password !== $password2) {
            $errors[] = '两次输入的管理员密码不一致';
        }
        if (is_file(DLM_ROOT . '/config.php')) {
            $errors[] = 'config.php 已存在，请先删除后再安装';
        }

        if ($errors === []) {
            try {
                $pdo = \Dlm\Support\Db::connect($db);
                $pdo->query('SELECT 1');
                $this->createSchema($pdo);
                $this->createAdmin($pdo, $username, $password);
                $this->writeSettings($pdo);
                $this->writeConfig($db);
                Flash::success('安装完成，请使用管理员账号登录。');
                Response::redirect('/login');
                return;
            } catch (Throwable $e) {
                $errors[] = '安装失败：' . $e->getMessage();
            }
        }

        Flash::keepOld([
            'db_host' => (string) $request->input('db_host', '127.0.0.1'),
            'db_port' => (string) $request->input('db_port', '3306'),
            'db_name' => $db['name'],
            'db_user' => $db['user'],
            'admin_user' => $username,
        ]);
        View::render('install/index', [
            'title' => '安装向导',
            'old' => Flash::pullOld(),
            'error' => implode('；', $errors),
        ], 'layout_blank');
    }

    private function createSchema(PDO $pdo): void
    {
        $sql = (string) file_get_contents(DLM_SRC . '/Install/schema.sql');
        $pdo->exec($sql);
    }

    private function createAdmin(PDO $pdo, string $username, string $password): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO `admin_users` (`username`, `password_hash`, `created_at`) VALUES (:u, :p, :c)'
        );
        $stmt->execute([
            ':u' => $username,
            ':p' => password_hash($password, PASSWORD_DEFAULT),
            ':c' => date('Y-m-d H:i:s'),
        ]);
    }

    private function writeSettings(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO `settings` (`key`, `value`) VALUES (:k, :v) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute([':k' => 'schema_version', ':v' => '1']);
        $stmt->execute([':k' => 'installed', ':v' => '1']);
    }

    private function writeConfig(array $db): void
    {
        $path = DLM_ROOT . '/config.php';
        if (is_file($path)) {
            throw new \RuntimeException('config.php 已存在');
        }
        $secret = bin2hex(random_bytes(24));
        $export = static fn ($value): string => var_export($value, true);
        $content = "<?php\n"
            . "if (!defined('DLM_APP')) { http_response_code(403); exit; }\n"
            . "return [\n"
            . "    'installed' => true,\n"
            . "    'app_secret' => " . $export($secret) . ",\n"
            . "    'timezone' => 'Asia/Shanghai',\n"
            . "    'db' => [\n"
            . "        'host' => " . $export($db['host']) . ",\n"
            . "        'port' => " . (int) $db['port'] . ",\n"
            . "        'name' => " . $export($db['name']) . ",\n"
            . "        'user' => " . $export($db['user']) . ",\n"
            . "        'pass' => " . $export($db['pass']) . ",\n"
            . "        'charset' => 'utf8mb4',\n"
            . "    ],\n"
            . "];\n";
        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('无法写入 config.php，请检查目录权限');
        }
    }
}
