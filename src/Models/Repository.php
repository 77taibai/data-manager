<?php
declare(strict_types=1);

namespace Dlm\Models;

use Dlm\Support\Db;
use Dlm\Support\Helpers;

final class Repository
{
    public static function all(): array
    {
        return Db::all(
            'SELECT r.*, (SELECT COUNT(*) FROM `links` l WHERE l.repository_id = r.id) AS link_count, '
            . '(SELECT COUNT(*) FROM `attributes` a WHERE a.repository_id = r.id) AS attribute_count, '
            . '(SELECT COUNT(*) FROM `api_keys` k WHERE k.repository_id = r.id) AS key_count '
            . 'FROM `repositories` r ORDER BY r.name ASC'
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM `repositories` WHERE `id` = :id', [':id' => $id]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return Db::one('SELECT * FROM `repositories` WHERE `slug` = :s', [':s' => $slug]);
    }

    public static function create(string $name, string $description, ?string $slug): int
    {
        $slug = self::uniqueSlug($slug !== null && $slug !== '' ? $slug : $name);
        $now = date('Y-m-d H:i:s');
        return Db::insert('repositories', [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function update(int $id, string $name, string $description, ?string $slug): void
    {
        $data = [
            'name' => $name,
            'description' => $description,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($slug !== null && $slug !== '') {
            $data['slug'] = self::uniqueSlug($slug, $id);
        }
        Db::update('repositories', $data, '`id` = :id', [':id' => $id]);
    }

    public static function delete(int $id): void
    {
        Db::delete('repositories', '`id` = :id', [':id' => $id]);
    }

    public static function uniqueSlug(string $base, ?int $exceptId = null): string
    {
        $slug = Helpers::slugify($base);
        if ($slug === '') {
            $slug = 'repo-' . bin2hex(random_bytes(3));
        }
        $candidate = $slug;
        $i = 1;
        while (true) {
            $sql = 'SELECT COUNT(*) FROM `repositories` WHERE `slug` = :s';
            $params = [':s' => $candidate];
            if ($exceptId !== null) {
                $sql .= ' AND `id` <> :id';
                $params[':id'] = $exceptId;
            }
            if ((int) Db::value($sql, $params) === 0) {
                return $candidate;
            }
            $candidate = $slug . '-' . (++$i);
        }
    }
}
