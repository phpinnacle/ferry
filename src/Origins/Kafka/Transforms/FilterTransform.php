<?php

namespace PHPinnacle\Ferry\Origins\Kafka\Transforms;

use PHPinnacle\Ferry\Origins\Kafka\Contracts\TransformHandler;

final class FilterTransform implements TransformHandler
{
    public function connector(string $name, string $field, array $params): array
    {
        return [
            $name => [
                'type' => 'io.confluent.connect.transforms.Filter$Value',
                'filter.condition' => $this->condition($field, $params['condition']),
                'filter.type' => $params['mode'],
                'missing.or.null.behavior' => $params['missing_or_null_behavior'],
                'predicate' => 'tombstone',
                'negate' => 'true',
            ],
        ];
    }

    private function condition(string $field, string $predicate): string
    {
        $path = "['" . addcslashes($field, "\\'") . "']";
        // Bind references without rewriting quoted values, regexes, or nested filter item references.
        preg_match_all(<<<'REGEX'
            ~'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|/(?:\\.|[^/\\])*/[a-z]*|\[\s*\?\s*\(|\[|\]|(?<reference>(?<![\pL\pN_.-])[@$])|.~us
            REGEX, $predicate, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $brackets = [];
        $filters = 0;
        $condition = '';
        foreach ($matches as $match) {
            $fragment = $match[0];
            if ($fragment === '[') {
                $brackets[] = false;
            } elseif (str_starts_with($fragment, '[')) {
                $brackets[] = true;
                $filters++;
            } elseif ($fragment === ']') {
                if (array_pop($brackets)) {
                    $filters--;
                }
            } elseif ($match['reference'] !== null && ($fragment === '$' || $filters === 0)) {
                $fragment .= $path;
            }
            $condition .= $fragment;
        }

        return '$[?(' . $condition . ')]';
    }
}
