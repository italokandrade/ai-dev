<?php

namespace App\Support;

final class AiJson
{
    /**
     * @return array<string, mixed>
     */
    public static function object(string $raw, string $context = 'resposta da IA'): array
    {
        $data = self::value($raw, $context);

        if (! is_array($data) || $data === [] || array_is_list($data)) {
            throw new \RuntimeException("JSON invalido retornado pela IA: objeto esperado em {$context}.");
        }

        return $data;
    }

    public static function value(string $raw, string $context = 'resposta da IA'): mixed
    {
        $lastError = 'Nenhum JSON encontrado.';

        foreach (self::candidates($raw) as $candidate) {
            $decoded = json_decode($candidate, true);
            $lastError = json_last_error_msg();

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        throw new \RuntimeException("JSON invalido retornado pela IA em {$context}: {$lastError}");
    }

    /**
     * @return array<int, string>
     */
    private static function candidates(string $raw): array
    {
        $candidates = [];
        $trimmed = trim($raw);

        if ($trimmed !== '') {
            $candidates[] = $trimmed;
            $candidates[] = self::stripWrappingFence($trimmed);
        }

        if (preg_match_all('/```(?:json)?\s*(.*?)```/is', $raw, $matches)) {
            foreach ($matches[1] as $match) {
                $candidates[] = trim($match);
            }
        }

        foreach (self::jsonStartPositions($raw) as $position) {
            $segment = self::balancedSegment($raw, $position);

            if ($segment !== null) {
                $candidates[] = $segment;
            }
        }

        return collect($candidates)
            ->map(fn (string $candidate): string => trim($candidate))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function stripWrappingFence(string $raw): string
    {
        return trim((string) preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', $raw));
    }

    /**
     * @return array<int, int>
     */
    private static function jsonStartPositions(string $raw): array
    {
        $positions = [];
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            if ($raw[$i] === '{' || $raw[$i] === '[') {
                $positions[] = $i;
            }
        }

        return $positions;
    }

    private static function balancedSegment(string $raw, int $start): ?string
    {
        $stack = [];
        $inString = false;
        $escaped = false;
        $length = strlen($raw);

        for ($i = $start; $i < $length; $i++) {
            $char = $raw[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                $stack[] = '}';

                continue;
            }

            if ($char === '[') {
                $stack[] = ']';

                continue;
            }

            if ($char === '}' || $char === ']') {
                $expected = array_pop($stack);

                if ($expected !== $char) {
                    return null;
                }

                if ($stack === []) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
