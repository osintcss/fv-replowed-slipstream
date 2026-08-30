<?php

namespace App\Exceptions;

use RuntimeException;

class RegistrationCapacityReached extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Registration is currently full.');
    }
}
