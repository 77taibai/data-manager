<?php
declare(strict_types=1);

namespace Dlm\Controllers\Web;

use Dlm\Support\Config;
use Dlm\Support\Flash;
use Dlm\Support\Helpers;
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
        $password = (string) $request->input('admin_pass', '');
        $password2 = (string) $request->input('admin_pass2', '');
        $oidc = $this->readOidcInput($request);

        $errors = [];
        if ($db['name'] === '' || $db['user'] === '') {
            $errors[] = '数据库名与数据库用户为必填项';
        }

        $oidcEnabled = (bool) $request->input('oidc_enabled', false);
        if (!$oidcEnabled) {
            if (mb_strlen($password) < 6) {
                $errors[] = '管理密码至少 6 位';
            }
        } elseif ($password !== '' && mb_strlen($password) < 6) {
            $errors[] = '管理密码至少 6 位（留空则仅允许 OIDC 登录）';
        }
        if ($password !== $password2) {
            $errors[] = '两次输入的管理密码不一致';
        }
        if (is_file(DLM_ROOT . '/config.php')) {
            $errors[] = 'config.php 已存在，请先删除后再安装';
        }

        if ($oidcEnabled) {
            $errors = array_merge($errors, $this->validateOidc($oidc));
        }

        if ($errors === []) {
            try {
                $pdo = \Dlm\Support\Db::connect($db);
                $pdo->query('SELECT 1');
                $this->createSchema($pdo);
                $this->writeSettings($pdo);
                $this->writeConfig($db, $password, $oidcEnabled ? $oidc : null);
                Flash::success('安装完成，请登录。');
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
            'oidc_enabled' => $oidcEnabled ? '1' : '',
            'oidc_issuer' => $oidc['issuer'],
            'oidc_client_id' => $oidc['client_id'],
            'oidc_redirect_uri' => $oidc['redirect_uri'],
            'oidc_allowed_subs' => implode("\n", $oidc['allowed_subs']),
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

    private function writeSettings(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO `settings` (`key`, `value`) VALUES (:k, :v) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute([':k' => 'schema_version', ':v' => '1']);
        $stmt->execute([':k' => 'installed', ':v' => '1']);
    }

    private function writeConfig(array $db, string $password, ?array $oidc): void
    {
        $path = DLM_ROOT . '/config.php';
        if (is_file($path)) {
            throw new \RuntimeException('config.php 已存在');
        }
        $secret = bin2hex(random_bytes(24));
        $export = static fn ($value): string => var_export($value, true);

        $admin = "    'admin' => [\n";
        if ($password !== '') {
            $admin .= "        'password_hash' => " . $export(password_hash($password, PASSWORD_DEFAULT)) . ",\n";
        }
        $admin .= "    ],\n";

        $oidcBlock = '';
        if ($oidc !== null) {
            $subs = '';
            foreach ($oidc['allowed_subs'] as $sub) {
                $subs .= "            " . $export($sub) . ",\n";
            }
            $oidcBlock = "    'oidc' => [\n"
                . "        'enabled' => true,\n"
                . "        'issuer' => " . $export($oidc['issuer']) . ",\n"
                . "        'client_id' => " . $export($oidc['client_id']) . ",\n"
                . "        'client_secret' => " . $export($oidc['client_secret']) . ",\n"
                . "        'redirect_uri' => " . $export($oidc['redirect_uri']) . ",\n"
                . "        'scope' => " . $export($oidc['scope']) . ",\n"
                . "        'allowed_subs' => [\n"
                . $subs
                . "        ],\n"
                . "    ],\n";
        }

        $content = "<?php\n"
            . "if (!defined('DLM_APP')) { http_response_code(403); exit; }\n"
            . "return [\n"
            . "    'installed' => true,\n"
            . "    'app_secret' => " . $export($secret) . ",\n"
            . "    'timezone' => 'Asia/Shanghai',\n"
            . $admin
            . $oidcBlock
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

    /**
     * @return array{issuer:string, client_id:string, client_secret:string, redirect_uri:string, scope:string, allowed_subs:string[]}
     */
    private function readOidcInput(Request $request): array
    {
        $subsRaw = (string) $request->input('oidc_allowed_subs', '');
        $subs = [];
        foreach (preg_split('/[\s,]+/', $subsRaw) ?: [] as $item) {
            $sub = trim($item);
            if ($sub !== '') {
                $subs[] = $sub;
            }
        }
        $scope = trim((string) $request->input('oidc_scope', ''));
        return [
            'issuer' => rtrim(trim((string) $request->input('oidc_issuer', '')), '/'),
            'client_id' => trim((string) $request->input('oidc_client_id', '')),
            'client_secret' => trim((string) $request->input('oidc_client_secret', '')),
            'redirect_uri' => trim((string) $request->input('oidc_redirect_uri', '')),
            'scope' => $scope !== '' ? $scope : 'openid email profile',
            'allowed_subs' => array_values(array_unique($subs)),
        ];
    }

    /**
     * @return string[]
     */
    private function validateOidc(array $oidc): array
    {
        $errors = [];
        if ($oidc['issuer'] === '' || !Helpers::isValidUrl($oidc['issuer'])) {
            $errors[] = 'OIDC Issuer 必须是合法的 http/https 地址';
        }
        if ($oidc['client_id'] === '') {
            $errors[] = 'OIDC Client ID 为必填项';
        }
        if ($oidc['client_secret'] === '') {
            $errors[] = 'OIDC Client Secret 为必填项';
        }
        if ($oidc['redirect_uri'] !== '' && !Helpers::isValidUrl($oidc['redirect_uri'])) {
            $errors[] = 'OIDC 回调地址必须是合法的 http/https 地址';
        }
        if ($oidc['allowed_subs'] === []) {
            $errors[] = 'OIDC 至少需要填写一个允许登录的 sub';
        }
        $scope = preg_split('/\s+/', $oidc['scope']) ?: [];
        if (!in_array('openid', $scope, true)) {
            $errors[] = 'OIDC Scope 必须包含 openid';
        }
        return $errors;
    }
}
