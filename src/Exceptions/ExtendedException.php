<?php
declare(strict_types=1);
namespace Glued\Lib\Exceptions;

class ExtendedException extends \Exception
{
    protected $details;
    public function __construct($message = "", $code = 0, string|array $details = [])
    {
        parent::__construct($message, $code);
        $this->details = $details;
    }

    public function getDetails()
    {
        return $this->details;
    }
}


