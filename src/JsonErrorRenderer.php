<?php
declare(strict_types=1);
namespace Glued\Lib;

use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

final class JsonErrorRenderer
{
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {

        if (method_exists($exception, 'getDetails')) {
            $details = $exception->getDetails();
        }
        if (method_exists($exception, 'getRayid')) {
            $rayid = $exception->getRayid();
        }
        if (method_exists($exception, 'getTitle')) {
            $title = $exception->getTitle();
        }

        return $this->renderErrorJson($title, $exception->getMessage(), $details, $exception->getCode(), $rayid);
    }

    public function renderErrorJson(string $title = '', string $message = '', $details = null, $code = 0, $rayid): string {
        $data = [
            'title' => $title,
            'code' => $code,
            'message' => $message,
            'details' => $details ?? '',
            'rayid' => $rayid ?? '',
        ];
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}