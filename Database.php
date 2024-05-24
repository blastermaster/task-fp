<?php

namespace FpDbTest;

use Exception;
use InvalidArgumentException;
use mysqli;

class Database implements DatabaseInterface
{
    const string PATTERN_PLACEHOLDER = '/\?[adf#]*/';
    const string PATTERN_PLACEHOLDER_WITH_BLOCKS = '/(?:\{[^{}]*?)?\?[adf#]*(?:[^{}]*?})?/';
    const string PATTERN_CHECK_BLOCK = '/{s*.*s*}/';
    const string PATTERN_CHECK_NESTED_BLOCK = '/{s*.*{.*}.*s*}/';
    const string SKIP = '__skip__';
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    private function formatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        } elseif (is_null($value)) {
            return 'NULL';
        } elseif (is_string($value)) {
            $value = $this->escapeString($value);
            return "'$value'";
        }

        return $value;
    }

    /**
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        $this->validate($query, $args);
        return $this->match(self::PATTERN_PLACEHOLDER_WITH_BLOCKS, $args, $query);
    }

    /**
     * @throws Exception
     */
    private function validate(string $query, ?array $args): void
    {
        if (preg_match(self::PATTERN_CHECK_NESTED_BLOCK, $query)) {
            throw new Exception('Строка содержит вложенные условные блоки');
        }

        preg_match_all(self::PATTERN_PLACEHOLDER, $query, $matches);
        $placeholders = $matches[0];

        if (count($placeholders) !== count($args)) {
            throw new Exception('Кол-во параметров не совпадает с кол-вом спецификаторов');
        }
    }

    private function escapeString($str): string
    {
        return str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $str
        );
    }

    private function match(string $pattern, array $args, string $query, int &$counter = 0): string|null
    {
        $placeholderTransformations = [
            '?d' => function ($arg) {
                if (is_null($arg)) {
                    return 'NULL';
                }

                return (int)$arg;
            },
            '?f' => function ($arg) {
                if (is_null($arg)) {
                    return 'NULL';
                }

                return (float)$arg;
            },
            '?a' => function ($arg) {
                $formattedValues = [];

                foreach ($arg as $key => $value) {
                    if (is_int($key)) {
                        $formattedValues[] = $this->formatValue($value);
                    } else {
                        $formattedValues[] = "`$key`" . ' = ' . $this->formatValue($value);
                    }
                }

                return implode(', ', $formattedValues);
            },
            '?#' => function ($arg) {
                return is_array($arg) ? implode(', ', array_map(function ($item) {
                    return '`' . $this->escapeString($item) . '`';
                }, $arg)) : '`' . $this->escapeString($arg) . '`';
            },
            '?' => function ($arg) {
                $validTypes = ['string', 'integer', 'float', 'bool', 'null'];

                $paramType = gettype($arg);

                if (!in_array($paramType, $validTypes)) {
                    throw new Exception('Параметр для спецификатора ? может быть только типом string, int, float, bool или null');
                }

                return $this->formatValue($arg);
            },
        ];

        return preg_replace_callback($pattern, function ($matches) use (
            $args,
            $placeholderTransformations,
            &$counter
        ) {
            $placeholder = $matches[0];
            $arg = $args[$counter];

            if (preg_match(self::PATTERN_CHECK_BLOCK, $placeholder)) {
                if ($arg === $this->skip()) {
                    return null;
                } else {
                    $placeholder = str_replace(['{', '}'], '', $placeholder);
                    return $this->match(self::PATTERN_PLACEHOLDER, $args, $placeholder, $counter);
                }
            }

            if (!isset($placeholderTransformations[$placeholder])) {
                throw new InvalidArgumentException('Неправильный спецификатор ' . $placeholder);
            }

            $counter++;
            return $placeholderTransformations[$placeholder]($arg);
        }, $query);
    }

    public function skip(): string
    {
        return self::SKIP;
    }
}
