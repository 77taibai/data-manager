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

check('isDownloadUrl http', Helpers::isDownloadUrl('http://example.com/a'));
check('isDownloadUrl https', Helpers::isDownloadUrl('https://example.com/a?b=1'));
check('isDownloadUrl ed2k file', Helpers::isDownloadUrl('ed2k://|file|a.zip|104857600|31D6CFE0D16AE931B73C59D7E0C089C0|/'));
check('isDownloadUrl ed2k aich', Helpers::isDownloadUrl('ed2k://|file|a.zip|104857600|31D6CFE0D16AE931B73C59D7E0C089C0|h=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA|/'));
check('isDownloadUrl ed2k server', Helpers::isDownloadUrl('ed2k://|server|192.168.1.1|4661|/'));
check('isDownloadUrl magnet', Helpers::isDownloadUrl('magnet:?xt=urn:btih:31d6cfe0d16ae931b73c59d7e0c089c0deadbeef&dn=a.zip'));
check('isDownloadUrl magnet base32', Helpers::isDownloadUrl('magnet:?xt=urn:btih:MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U'));
check('isDownloadUrl javascript 拒绝', Helpers::isDownloadUrl('javascript:alert(1)') === false);
check('isDownloadUrl ftp 拒绝', Helpers::isDownloadUrl('ftp://example.com/a') === false);
check('isDownloadUrl data 拒绝', Helpers::isDownloadUrl('data:text/html,hi') === false);
check('isDownloadUrl ed2k 非法哈希拒绝', Helpers::isDownloadUrl('ed2k://|file|a.zip|100|not-a-hash|/') === false);
check('isDownloadUrl magnet 缺 xt 拒绝', Helpers::isDownloadUrl('magnet:?dn=a.zip') === false);
check('isDownloadUrl magnet 非法哈希拒绝', Helpers::isDownloadUrl('magnet:?xt=urn:btih:xyz') === false);
check('isDownloadUrl 文本拒绝', Helpers::isDownloadUrl('not a url') === false);

check('isHttpUrl http', Helpers::isHttpUrl('http://example.com/a'));
check('isHttpUrl magnet 拒绝', Helpers::isHttpUrl('magnet:?xt=urn:btih:31d6cfe0d16ae931b73c59d7e0c089c0deadbeef') === false);

check('normalizeUrl 小写主机', Helpers::normalizeUrl('HTTP://Example.COM/Path') === 'http://example.com/Path');
check('normalizeUrl ed2k 小写', Helpers::normalizeUrl('ED2K://|FILE|A.ZIP|100|31D6CFE0D16AE931B73C59D7E0C089C0|/') === 'ed2k://|file|a.zip|100|31d6cfe0d16ae931b73c59d7e0c089c0|/');
check('normalizeUrl magnet 参数排序', Helpers::normalizeUrl('magnet:?dn=a.zip&xt=urn:btih:ABCD') === Helpers::normalizeUrl('magnet:?xt=urn:btih:abcd&dn=a.zip'));
check('urlHash 稳定', Helpers::urlHash('https://example.com/a') === Helpers::urlHash('https://example.com/a'));
check('urlHash ed2k 稳定', Helpers::urlHash('ed2k://|file|a.zip|100|31D6CFE0D16AE931B73C59D7E0C089C0|/') === Helpers::urlHash('ed2k://|file|a.zip|100|31d6cfe0d16ae931b73c59d7e0c089c0|/'));
check('urlHash magnet 稳定', Helpers::urlHash('magnet:?xt=urn:btih:ABCD&dn=a.zip') === Helpers::urlHash('magnet:?dn=a.zip&xt=urn:btih:abcd'));

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
