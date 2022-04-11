<?php
declare(strict_types=1);
namespace Glued\Lib\Exceptions;
use Throwable;
use Glued\Lib\Exceptions\DefaultException;
class BadRequestException extends DefaultException {

    protected $details = 'j';
    protected $code = 400;
    protected $title = 'Bad request.';

}
