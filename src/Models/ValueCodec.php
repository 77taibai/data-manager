<?php
declare(strict_types=1);

namespace Dlm\Models;

use Dlm\Support\Helpers;

final class ValueCodec
{
    public static function validate(array $attr, mixed $input): ?string
    {
        $label = (string) $attr['label'];
        $type = (string) $attr['type'];
        $isEmpty = $input === null || (is_string($input) && trim($input) === '');

        if ($isEmpty) {
            return ((int) $attr['required'] === 1) ? $label . '为必填项' : null;
        }

        switch ($type) {
            case Attribute::TYPE_TEXT:
                if (!is_scalar($input)) {
                    return $label . '必须是文本';
                }
                $text = (string) $input;
                if (mb_strlen($text) > 65535) {
                    return $label . '内容过长';
                }
                if ((int) $attr['constrained'] === 1 && !in_array($text, $attr['options'] ?? [], true)) {
                    return $label . '只能取以下值之一：' . implode('、', $attr['options'] ?? []);
                }
                return null;

            case Attribute::TYPE_DATE:
                if (self::parseDate((string) $input) === null) {
                    return $label . '的日期格式无效，应为 YYYY-MM-DD 或 YYYY-MM-DD HH:MM(:SS)';
                }
                return null;

            case Attribute::TYPE_FILESIZE:
                if (Helpers::parseSizeToBytes($input) === null) {
                    return $label . '的文件大小无效，可填字节数或如 1.5GB';
                }
                return null;

            default:
                return $label . '的类型未知';
        }
    }

    public static function toColumns(array $attr, mixed $input): ?array
    {
        if ($input === null || (is_string($input) && trim($input) === '')) {
            return null;
        }
        return match ((string) $attr['type']) {
            Attribute::TYPE_DATE => [
                'value_text' => null,
                'value_date' => self::parseDate((string) $input),
                'value_num' => null,
            ],
            Attribute::TYPE_FILESIZE => [
                'value_text' => null,
                'value_date' => null,
                'value_num' => Helpers::parseSizeToBytes($input),
            ],
            default => [
                'value_text' => (string) $input,
                'value_date' => null,
                'value_num' => null,
            ],
        };
    }

    public static function fromColumns(array $attr, array $row): array
    {
        return match ((string) $attr['type']) {
            Attribute::TYPE_DATE => [
                'raw' => $row['value_date'],
                'display' => $row['value_date'] !== null ? date('Y-m-d H:i', strtotime((string) $row['value_date'])) : '',
            ],
            Attribute::TYPE_FILESIZE => [
                'raw' => $row['value_num'] !== null ? (int) $row['value_num'] : null,
                'display' => $row['value_num'] !== null ? Helpers::formatBytes((int) $row['value_num']) : '',
            ],
            default => [
                'raw' => $row['value_text'],
                'display' => (string) ($row['value_text'] ?? ''),
            ],
        };
    }

    public static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1) {
            return $value . ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }
        return null;
    }
}
