<?php
declare(strict_types=1);
namespace Glued\Lib\Exceptions;
use Throwable;
use Glued\Lib\Exceptions\DefaultException;
class InternalException extends DefaultException {

    protected $details = 'j';
    protected $code = 500;
    protected $title = 'Internal request.';

}
