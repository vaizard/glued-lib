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

namespace Ergebnis\Json\Normalizer;

use Ergebnis\Json\Printer;

final class IndentNormalizer implements NormalizerInterface
{
    private Format\Indent $indent;
    private Printer\PrinterInterface $printer;

    public function __construct(
        Format\Indent $indent,
        Printer\PrinterInterface $printer
    ) {
        $this->indent = $indent;
        $this->printer = $printer;
    }

    public function normalize(Json $json): Json
    {
        $withIndent = $this->printer->print(
            $json->encoded(),
            $this->indent->toString(),
        );

        return Json::fromEncoded($withIndent);
    }
}
