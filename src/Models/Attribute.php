<?php
declare(strict_types=1);

namespace Dlm\Models;

use Dlm\Support\Db;
use Dlm\Support\Validator;

final class Attribute
{
    public const TYPE_TEXT = 'text';
    public const TYPE_DATE = 'date';
    public const TYPE_FILESIZE = 'filesize';

    public const TYPES = [self::TYPE_TEXT, self::TYPE_DATE, self::TYPE_FILESIZE];

    public static function forRepository(int $repositoryId): array
    {
        $rows = Db::all(
            'SELECT * FROM `attributes` WHERE `repository_id` = :r ORDER BY `sort_order` ASC, `id` ASC',
            [':r' => $repositoryId]
        );
        return array_map([self::class, 'withOptions'], $rows);
    }

    public static function find(int $id): ?array
    {
        $row = Db::one('SELECT * FROM `attributes` WHERE `id` = :id', [':id' => $id]);
        return $row === null ? null : self::withOptions($row);
    }

    public static function findByKey(int $repositoryId, string $key): ?array
    {
        $row = Db::one(
            'SELECT * FROM `attributes` WHERE `repository_id` = :r AND `key` = :k',
            [':r' => $repositoryId, ':k' => $key]
        );
        return $row === null ? null : self::withOptions($row);
    }

    public static function withOptions(array $row): array
    {
        $row['options'] = ((int) $row['constrained'] === 1) ? Option::list((int) $row['id']) : [];
        return $row;
    }

    public static function validateDefinition(array $data, int $repositoryId, ?int $exceptId = null): array
    {
        $errors = [];
        $key = trim((string) ($data['key'] ?? ''));
        $label = trim((string) ($data['label'] ?? ''));
        $type = (string) ($data['type'] ?? '');

        if (($e = Validator::attributeKey($key)) !== null) {
            $errors[] = $e;
        }
        if (($e = Validator::required($label, '属性名称')) !== null) {
            $errors[] = $e;
        }
        if (($e = Validator::maxLength($label, 100, '属性名称')) !== null) {
            $errors[] = $e;
        }
        if (($e = Validator::inList($type, self::TYPES, '属性类型')) !== null) {
            $errors[] = $e;
        }
        if ($errors === []) {
            $sql = 'SELECT COUNT(*) FROM `attributes` WHERE `repository_id` = :r AND `key` = :k';
            $params = [':r' => $repositoryId, ':k' => $key];
            if ($exceptId !== null) {
                $sql .= ' AND `id` <> :id';
                $params[':id'] = $exceptId;
            }
            if ((int) Db::value($sql, $params) > 0) {
                $errors[] = '该存储库中已存在相同的属性 key：' . $key;
            }
        }
        if ((int) ($data['constrained'] ?? 0) === 1 && $type !== self::TYPE_TEXT) {
            $errors[] = '只有文本类型支持“仅允许指定值”约束';
        }
        if ((int) ($data['constrained'] ?? 0) === 1) {
            $options = array_values(array_filter(array_map('trim', (array) ($data['options'] ?? [])), static fn ($v) => $v !== ''));
            if ($options === []) {
                $errors[] = '启用取值约束时至少要提供一个可选值';
            }
        }
        return $errors;
    }

    public static function create(int $repositoryId, array $data): int
    {
        return Db::tx(function () use ($repositoryId, $data): int {
            $id = Db::insert('attributes', [
                'repository_id' => $repositoryId,
                'key' => (string) $data['key'],
                'label' => (string) $data['label'],
                'type' => (string) $data['type'],
                'required' => (int) ($data['required'] ?? 0),
                'constrained' => (int) ($data['constrained'] ?? 0),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if ((int) ($data['constrained'] ?? 0) === 1) {
                Option::replace($id, (array) ($data['options'] ?? []));
            }
            return $id;
        });
    }

    public static function update(int $id, array $data): void
    {
        Db::tx(function () use ($id, $data): void {
            Db::update('attributes', [
                'key' => (string) $data['key'],
                'label' => (string) $data['label'],
                'type' => (string) $data['type'],
                'required' => (int) ($data['required'] ?? 0),
                'constrained' => (int) ($data['constrained'] ?? 0),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`id` = :id', [':id' => $id]);

            if ((int) ($data['constrained'] ?? 0) === 1) {
                Option::replace($id, (array) ($data['options'] ?? []));
            } else {
                Option::replace($id, []);
            }
        });
    }

    public static function delete(int $id): void
    {
        Db::delete('attributes', '`id` = :id', [':id' => $id]);
    }
}
