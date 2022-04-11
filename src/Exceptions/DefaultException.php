<?php
declare(strict_types=1);
namespace Glued\Lib\Exceptions;
use Throwable;
use Ramsey\Uuid\Uuid;

class DefaultException extends \Exception {

    protected $details;
    protected $title;
    protected $rayid;

    public function __construct(Throwable $previous = null, $details = null) {
        $message = $previous->getMessage();
        $code = 0;
        $this->rayid = Uuid::uuid4();
        $this->details = $details;
        parent::__construct($message, $code, $previous);
    }

    final public function getDetails() {
        return $this->details;
    }

    final public function getTitle() {
        return $this->title;
    }

    final public function getRayid() {
        return $this->rayid;
    }

}
