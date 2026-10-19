<?php
declare(strict_types=1);

namespace Dlm\Support;

final class Validator
{
    public static function required(mixed $value, string $label): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === [])) {
            return $label . '不能为空';
        }
        return null;
    }

    public static function maxLength(mixed $value, int $max, string $label): ?string
    {
        if (is_string($value) && mb_strlen($value) > $max) {
            return $label . '长度不能超过 ' . $max . ' 个字符';
        }
        return null;
    }

    public static function attributeKey(mixed $key): ?string
    {
        $key = (string) $key;
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $key) !== 1) {
            return '属性 key 只能由小写字母、数字、下划线、连字符组成，且以字母开头';
        }
        if (strlen($key) > 50) {
            return '属性 key 长度不能超过 50 个字符';
        }
        return null;
    }

    public static function inList(mixed $value, array $allowed, string $label): ?string
    {
        if (!in_array($value, $allowed, true)) {
            return $label . '的取值不在允许范围内';
        }
        return null;
    }

    public static function integer(mixed $value, string $label, ?int $min = null, ?int $max = null): ?string
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $label . '必须是整数';
        }
        $int = (int) $value;
        if ($min !== null && $int < $min) {
            return $label . '不能小于 ' . $min;
        }
        if ($max !== null && $int > $max) {
            return $label . '不能大于 ' . $max;
        }
        return null;
    }
}
