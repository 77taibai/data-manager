<?php
declare(strict_types=1);

namespace Dlm\Models;

use Dlm\Support\Db;

final class Option
{
    public static function list(int $attributeId): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['value'],
            Db::all(
                'SELECT `value` FROM `attribute_options` WHERE `attribute_id` = :a ORDER BY `sort_order` ASC, `id` ASC',
                [':a' => $attributeId]
            )
        );
    }

    public static function replace(int $attributeId, array $values): void
    {
        Db::delete('attribute_options', '`attribute_id` = :a', [':a' => $attributeId]);
        $seen = [];
        $sort = 0;
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            Db::insert('attribute_options', [
                'attribute_id' => $attributeId,
                'value' => mb_substr($value, 0, 180),
                'sort_order' => $sort++,
            ]);
        }
    }
}
