<?php
declare(strict_types=1);
namespace Glued\Lib\Exceptions;
use Throwable;
use Glued\Lib\Exceptions\DefaultException;
class NotFoundException extends DefaultException {

    protected $details = 'j';
    protected $code = 404;
    protected $title = 'Not found.';

}
