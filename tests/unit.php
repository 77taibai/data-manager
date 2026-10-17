<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Dlm\Models\Attribute;
use Dlm\Models\ValueCodec;
use Dlm\Support\Helpers;

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS  {$name}\n";
    } else {
        $failed++;
        echo "FAIL  {$name}" . ($detail !== '' ? "  -> {$detail}" : '') . "\n";
    }
}

check('parseSizeToBytes 纯数字', Helpers::parseSizeToBytes('1024') === 1024);
check('parseSizeToBytes 1.5KB', Helpers::parseSizeToBytes('1.5KB') === 1536);
check('parseSizeToBytes 2MB', Helpers::parseSizeToBytes('2MB') === 2097152);
check('parseSizeToBytes 1GB', Helpers::parseSizeToBytes('1GB') === 1073741824);
check('parseSizeToBytes 非法', Helpers::parseSizeToBytes('abc') === null);
check('parseSizeToBytes 负数', Helpers::parseSizeToBytes(-5) === null);

check('formatBytes 0', Helpers::formatBytes(0) === '0 B');
check('formatBytes 1536', Helpers::formatBytes(1536) === '1.5 KB');
check('formatBytes 1MB', Helpers::formatBytes(1048576) === '1 MB');

check('isValidUrl http', Helpers::isValidUrl('http://example.com/a'));
check('isValidUrl https', Helpers::isValidUrl('https://example.com/a?b=1'));
check('isValidUrl javascript 拒绝', Helpers::isValidUrl('javascript:alert(1)') === false);
check('isValidUrl ftp 拒绝', Helpers::isValidUrl('ftp://example.com/a') === false);
check('isValidUrl 文本拒绝', Helpers::isValidUrl('not a url') === false);

check('normalizeUrl 小写主机', Helpers::normalizeUrl('HTTP://Example.COM/Path') === 'http://example.com/Path');
check('urlHash 稳定', Helpers::urlHash('https://example.com/a') === Helpers::urlHash('https://example.com/a'));

check('parseDate 仅日期', ValueCodec::parseDate('2026-01-01') === '2026-01-01 00:00:00');
check('parseDate 到分钟', ValueCodec::parseDate('2026-01-01 12:30') === '2026-01-01 12:30:00');
check('parseDate 到秒', ValueCodec::parseDate('2026-01-01 12:30:45') === '2026-01-01 12:30:45');
check('parseDate 非法', ValueCodec::parseDate('2026/01/01') === null);

$text = ['id' => 1, 'key' => 'c', 'label' => '颜色', 'type' => Attribute::TYPE_TEXT, 'required' => 1, 'constrained' => 1, 'options' => ['红', '蓝']];
check('text 必填缺失', ValueCodec::validate($text, '') !== null);
check('text 受约束非法值', ValueCodec::validate($text, '绿') !== null);
check('text 合法值', ValueCodec::validate($text, '红') === null);

$optText = ['id' => 2, 'key' => 'n', 'label' => '名称', 'type' => Attribute::TYPE_TEXT, 'required' => 0, 'constrained' => 0, 'options' => []];
check('text 选填可空', ValueCodec::validate($optText, null) === null);

$date = ['id' => 3, 'key' => 'd', 'label' => '日期', 'type' => Attribute::TYPE_DATE, 'required' => 0, 'constrained' => 0, 'options' => []];
check('date 非法', ValueCodec::validate($date, '2026/01/01') !== null);
check('date 合法', ValueCodec::validate($date, '2026-01-01') === null);

$size = ['id' => 4, 'key' => 's', 'label' => '大小', 'type' => Attribute::TYPE_FILESIZE, 'required' => 0, 'constrained' => 0, 'options' => []];
check('filesize 非法', ValueCodec::validate($size, 'big') !== null);
check('filesize 合法', ValueCodec::validate($size, '1.5GB') === null);

check('toColumns 文件大小', ValueCodec::toColumns($size, '2MB')['value_num'] === 2097152);
check('toColumns 日期', ValueCodec::toColumns($date, '2026-01-01')['value_date'] === '2026-01-01 00:00:00');
check('toColumns 空值返回 null', ValueCodec::toColumns($optText, '') === null);

echo "\n汇总：PASS={$passed} FAIL={$failed}\n";
exit($failed > 0 ? 1 : 0);
