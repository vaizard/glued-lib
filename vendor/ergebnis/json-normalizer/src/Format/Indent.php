<?php

declare(strict_types=1);

/**
 * Copyright (c) 2018-2022 Andreas Möller
 *
 * For the full copyright and license information, please view
 * the LICENSE.md file that was distributed with this source code.
 *
 * @see https://github.com/ergebnis/json-normalizer
 */

namespace Ergebnis\Json\Normalizer\Format;

use Ergebnis\Json\Normalizer\Exception;
use Ergebnis\Json\Normalizer\Json;

/**
 * @psalm-immutable
 */
final class Indent
{
    public const CHARACTERS = [
        'space' => ' ',
        'tab' => "\t",
    ];
    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws Exception\InvalidIndentStringException
     */
    public static function fromString(string $value): self
    {
        if (1 !== \preg_match('/^( *|\t+)$/', $value)) {
            throw Exception\InvalidIndentStringException::fromString($value);
        }

        return new self($value);
    }

    /**
     * @throws Exception\InvalidIndentSizeException
     * @throws Exception\InvalidIndentStyleException
     */
    public static function fromSizeAndStyle(
        int $size,
        string $style
    ): self {
        $minimumSize = 1;

        if ($minimumSize > $size) {
            throw Exception\InvalidIndentSizeException::fromSizeAndMinimumSize(
                $size,
                $minimumSize,
            );
        }

        if (!\array_key_exists($style, self::CHARACTERS)) {
            throw Exception\InvalidIndentStyleException::fromStyleAndAllowedStyles(
                $style,
                ...\array_keys(self::CHARACTERS),
            );
        }

        $value = \str_repeat(
            self::CHARACTERS[$style],
            $size,
        );

        return new self($value);
    }

    public static function fromJson(Json $json): self
    {
        if (1 === \preg_match('/^(?P<indent>( +|\t+)).*/m', $json->encoded(), $match)) {
            return self::fromString($match['indent']);
        }

        return self::fromSizeAndStyle(
            4,
            'space',
        );
    }

    public function toString(): string
    {
        return $this->value;
    }
}
