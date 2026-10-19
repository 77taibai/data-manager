<?php
declare(strict_types=1);

namespace Dlm\Models;

use Dlm\Support\Db;
use Dlm\Support\Helpers;

final class Link
{
    public static function columnForType(string $type): string
    {
        return match ($type) {
            Attribute::TYPE_DATE => 'value_date',
            Attribute::TYPE_FILESIZE => 'value_num',
            default => 'value_text',
        };
    }

    public static function query(int $repositoryId, array $filters, string $q, string $sort, int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = min(200, max(1, $pageSize));

        $where = ['l.`repository_id` = :rid'];
        $params = [':rid' => $repositoryId];

        $i = 0;
        foreach ($filters as $filter) {
            $attr = $filter['attr'];
            $op = $filter['op'];
            $alias = 'lvf' . $i;
            $col = self::columnForType((string) $attr['type']);
            $value = $filter['value'];

            if ($attr['type'] === Attribute::TYPE_FILESIZE) {
                $value = Helpers::parseSizeToBytes($value);
            } elseif ($attr['type'] === Attribute::TYPE_DATE) {
                $value = ValueCodec::parseDate((string) $value);
            }
            if ($value === null) {
                continue;
            }

            $operator = match ($op) {
                'gte' => '>=',
                'lte' => '<=',
                'gt' => '>',
                'lt' => '<',
                'contains' => 'LIKE',
                default => '=',
            };
            if (!in_array($op, ['eq', 'gte', 'lte', 'gt', 'lt', 'contains'], true)) {
                continue;
            }

            $ph = 'f' . $i;
            $where[] = "EXISTS (SELECT 1 FROM `link_values` `{$alias}` WHERE `{$alias}`.`link_id` = l.`id` "
                . "AND `{$alias}`.`attribute_id` = :aid_{$ph} AND `{$alias}`.`{$col}` {$operator} :val_{$ph})";
            $params[':aid_' . $ph] = (int) $attr['id'];
            $params[':val_' . $ph] = $op === 'contains' ? '%' . $value . '%' : $value;
            $i++;
        }

        if (trim($q) !== '') {
            $where[] = '(l.`url` LIKE :qtext_url OR EXISTS (SELECT 1 FROM `link_values` `lvq` '
                . 'WHERE `lvq`.`link_id` = l.`id` AND `lvq`.`value_text` LIKE :qtext_val))';
            $params[':qtext_url'] = '%' . trim($q) . '%';
            $params[':qtext_val'] = '%' . trim($q) . '%';
        }

        $whereSql = implode(' AND ', $where);
        $offset = ($page - 1) * $pageSize;

        $sortKey = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'DESC' : 'ASC';
        $join = '';
        $orderSql = 'l.`created_at` DESC';
        $dataParams = $params;
        if (in_array($sortKey, ['created_at', 'updated_at', 'url'], true)) {
            $orderSql = 'l.`' . $sortKey . '` ' . $direction;
        } else {
            $sortAttr = Attribute::findByKey($repositoryId, $sortKey);
            if ($sortAttr !== null) {
                $col = self::columnForType((string) $sortAttr['type']);
                $join = 'LEFT JOIN `link_values` `lvs` ON `lvs`.`link_id` = l.`id` AND `lvs`.`attribute_id` = :sort_aid';
                $dataParams[':sort_aid'] = (int) $sortAttr['id'];
                $orderSql = 'lvs.`' . $col . '` ' . $direction;
            }
        }

        $items = Db::all(
            "SELECT l.* FROM `links` l {$join} WHERE {$whereSql} ORDER BY {$orderSql}, l.`id` DESC LIMIT {$offset}, {$pageSize}",
            $dataParams
        );
        $total = (int) Db::value("SELECT COUNT(*) FROM `links` l WHERE {$whereSql}", $params);

        $items = self::attachValues($repositoryId, $items);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public static function find(int $id, int $repositoryId): ?array
    {
        return Db::one(
            'SELECT * FROM `links` WHERE `id` = :id AND `repository_id` = :r',
            [':id' => $id, ':r' => $repositoryId]
        );
    }

    public static function findWithValues(int $id, int $repositoryId): ?array
    {
        $link = self::find($id, $repositoryId);
        if ($link === null) {
            return null;
        }
        $withValues = self::attachValues($repositoryId, [$link]);
        return $withValues[0];
    }

    public static function attachValues(int $repositoryId, array $links): array
    {
        if ($links === []) {
            return [];
        }
        $attrs = Attribute::forRepository($repositoryId);
        $attrById = [];
        foreach ($attrs as $attr) {
            $attrById[(int) $attr['id']] = $attr;
        }

        $ids = array_map(static fn (array $l): int => (int) $l['id'], $links);
        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $ph = ':id' . $index;
            $placeholders[] = $ph;
            $params[$ph] = $id;
        }
        $rows = Db::all(
            'SELECT * FROM `link_values` WHERE `link_id` IN (' . implode(', ', $placeholders) . ')',
            $params
        );

        $valuesByLink = [];
        foreach ($rows as $row) {
            $attr = $attrById[(int) $row['attribute_id']] ?? null;
            if ($attr === null) {
                continue;
            }
            $valuesByLink[(int) $row['link_id']][(string) $attr['key']] = ValueCodec::fromColumns($attr, $row);
        }

        foreach ($links as &$link) {
            $link['values'] = $valuesByLink[(int) $link['id']] ?? [];
        }
        unset($link);
        return $links;
    }

    public static function existsUrl(int $repositoryId, string $url, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM `links` WHERE `repository_id` = :r AND `url_hash` = :h';
        $params = [':r' => $repositoryId, ':h' => Helpers::urlHash($url)];
        if ($exceptId !== null) {
            $sql .= ' AND `id` <> :id';
            $params[':id'] = $exceptId;
        }
        return (int) Db::value($sql, $params) > 0;
    }

    public static function validateValues(int $repositoryId, array $valuesByKey, bool $partial = false): array
    {
        $attrs = Attribute::forRepository($repositoryId);
        $attrByKey = [];
        foreach ($attrs as $attr) {
            $attrByKey[(string) $attr['key']] = $attr;
        }

        $errors = [];
        foreach (array_keys($valuesByKey) as $key) {
            if (!isset($attrByKey[$key])) {
                $errors[] = '未知属性：' . $key;
            }
        }

        $normalized = [];
        foreach ($attrs as $attr) {
            $key = (string) $attr['key'];
            if (array_key_exists($key, $valuesByKey)) {
                $error = ValueCodec::validate($attr, $valuesByKey[$key]);
                if ($error !== null) {
                    $errors[] = $error;
                } else {
                    $normalized[$key] = ValueCodec::toColumns($attr, $valuesByKey[$key]);
                }
            } elseif (!$partial && (int) $attr['required'] === 1) {
                $errors[] = $attr['label'] . '为必填项';
            }
        }

        return ['errors' => $errors, 'values' => $normalized, 'attrs' => $attrByKey];
    }

    public static function create(int $repositoryId, string $url, array $valuesByKey): array
    {
        $url = trim($url);
        $urlError = Helpers::downloadUrlError($url);
        if ($urlError !== null) {
            return ['id' => null, 'errors' => [$urlError]];
        }
        $validated = self::validateValues($repositoryId, $valuesByKey, false);
        if ($validated['errors'] !== []) {
            return ['id' => null, 'errors' => $validated['errors']];
        }
        if (self::existsUrl($repositoryId, $url)) {
            return ['id' => null, 'errors' => ['该存储库中已存在相同的下载 URL']];
        }

        $id = Db::tx(function () use ($repositoryId, $url, $validated): int {
            $now = date('Y-m-d H:i:s');
            $id = Db::insert('links', [
                'repository_id' => $repositoryId,
                'url' => $url,
                'url_hash' => Helpers::urlHash($url),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::saveValues($id, $validated['values'], $validated['attrs']);
            return $id;
        });

        return ['id' => $id, 'errors' => []];
    }

    public static function update(int $id, int $repositoryId, ?string $url, array $valuesByKey): array
    {
        $link = self::find($id, $repositoryId);
        if ($link === null) {
            return ['errors' => ['链接不存在']];
        }

        $errors = [];
        $newUrl = null;
        if ($url !== null) {
            $url = trim($url);
            $urlError = Helpers::downloadUrlError($url);
            if ($urlError !== null) {
                $errors[] = $urlError;
            } elseif (self::existsUrl($repositoryId, $url, $id)) {
                $errors[] = '该存储库中已存在相同的下载 URL';
            } else {
                $newUrl = $url;
            }
        }

        $validated = self::validateValues($repositoryId, $valuesByKey, true);
        $errors = array_merge($errors, $validated['errors']);
        if ($errors !== []) {
            return ['errors' => $errors];
        }

        Db::tx(function () use ($id, $repositoryId, $newUrl, $validated): void {
            $data = ['updated_at' => date('Y-m-d H:i:s')];
            if ($newUrl !== null) {
                $data['url'] = $newUrl;
                $data['url_hash'] = Helpers::urlHash($newUrl);
            }
            Db::update('links', $data, '`id` = :id AND `repository_id` = :r', [':id' => $id, ':r' => $repositoryId]);
            self::saveValues($id, $validated['values'], $validated['attrs']);
        });

        return ['errors' => []];
    }

    private static function saveValues(int $linkId, array $columnsByKey, array $attrByKey): void
    {
        foreach ($columnsByKey as $key => $columns) {
            $attr = $attrByKey[$key] ?? null;
            if ($attr === null) {
                continue;
            }
            $attributeId = (int) $attr['id'];
            if ($columns === null) {
                Db::delete('link_values', '`link_id` = :l AND `attribute_id` = :a', [':l' => $linkId, ':a' => $attributeId]);
                continue;
            }
            Db::run(
                'INSERT INTO `link_values` (`link_id`, `attribute_id`, `value_text`, `value_date`, `value_num`) '
                . 'VALUES (:l, :a, :t, :d, :n) ON DUPLICATE KEY UPDATE '
                . '`value_text` = VALUES(`value_text`), `value_date` = VALUES(`value_date`), `value_num` = VALUES(`value_num`)',
                [
                    ':l' => $linkId,
                    ':a' => $attributeId,
                    ':t' => $columns['value_text'],
                    ':d' => $columns['value_date'],
                    ':n' => $columns['value_num'],
                ]
            );
        }
    }

    public static function delete(int $id, int $repositoryId): bool
    {
        return Db::delete('links', '`id` = :id AND `repository_id` = :r', [':id' => $id, ':r' => $repositoryId]) > 0;
    }

    public static function deleteMany(int $repositoryId, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return 0;
        }
        $params = [':r' => $repositoryId];
        $ph = [];
        foreach ($ids as $index => $id) {
            $key = ':id' . $index;
            $ph[] = $key;
            $params[$key] = $id;
        }
        return Db::delete('links', '`repository_id` = :r AND `id` IN (' . implode(', ', $ph) . ')', $params);
    }

    public static function bulkSetAttribute(int $repositoryId, array $ids, string $key, mixed $value): array
    {
        $attr = Attribute::findByKey($repositoryId, $key);
        if ($attr === null) {
            return ['updated' => 0, 'errors' => ['未知属性：' . $key]];
        }
        $error = ValueCodec::validate($attr, $value);
        if ($error !== null) {
            return ['updated' => 0, 'errors' => [$error]];
        }
        $columns = ValueCodec::toColumns($attr, $value);

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return ['updated' => 0, 'errors' => []];
        }

        $params = [':r' => $repositoryId];
        $ph = [];
        foreach ($ids as $index => $id) {
            $paramKey = ':b' . $index;
            $ph[] = $paramKey;
            $params[$paramKey] = $id;
        }
        $validIds = array_map(
            static fn (array $r): int => (int) $r['id'],
            Db::all(
                'SELECT `id` FROM `links` WHERE `repository_id` = :r AND `id` IN (' . implode(', ', $ph) . ')',
                $params
            )
        );
        if ($validIds === []) {
            return ['updated' => 0, 'errors' => []];
        }

        Db::tx(function () use ($validIds, $attr, $columns): void {
            foreach ($validIds as $linkId) {
                if ($columns === null) {
                    Db::delete('link_values', '`link_id` = :l AND `attribute_id` = :a', [':l' => $linkId, ':a' => (int) $attr['id']]);
                } else {
                    Db::run(
                        'INSERT INTO `link_values` (`link_id`, `attribute_id`, `value_text`, `value_date`, `value_num`) '
                        . 'VALUES (:l, :a, :t, :d, :n) ON DUPLICATE KEY UPDATE '
                        . '`value_text` = VALUES(`value_text`), `value_date` = VALUES(`value_date`), `value_num` = VALUES(`value_num`)',
                        [
                            ':l' => $linkId,
                            ':a' => (int) $attr['id'],
                            ':t' => $columns['value_text'],
                            ':d' => $columns['value_date'],
                            ':n' => $columns['value_num'],
                        ]
                    );
                }
            }
        });

        return ['updated' => count($validIds), 'errors' => []];
    }

    public static function count(int $repositoryId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM `links` WHERE `repository_id` = :r', [':r' => $repositoryId]);
    }

    public static function findManyByIds(int $repositoryId, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }
        $params = [':r' => $repositoryId];
        $ph = [];
        foreach ($ids as $index => $id) {
            $key = ':e' . $index;
            $ph[] = $key;
            $params[$key] = $id;
        }
        $links = Db::all(
            'SELECT * FROM `links` WHERE `repository_id` = :r AND `id` IN (' . implode(', ', $ph) . ') ORDER BY `id` ASC',
            $params
        );
        return self::attachValues($repositoryId, $links);
    }
}
