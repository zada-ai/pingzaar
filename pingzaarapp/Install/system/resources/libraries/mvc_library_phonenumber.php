<?php

use Brick\PhoneNumber\PhoneNumber;

class MVC_Library_PhoneNumber
{
    public static function __callStatic($method, $arguments)
    {
        if (method_exists(PhoneNumber::class, $method)) {
            return call_user_func_array([PhoneNumber::class, $method], $arguments);
        } else {
            throw new \BadMethodCallException("Method {$method} does not exist");
        }
    }

    public function __call($method, $arguments)
    {
        if (method_exists(PhoneNumber::class, $method)) {
            return call_user_func_array([PhoneNumber::class, $method], $arguments);
        } else {
            throw new \BadMethodCallException("Method {$method} does not exist");
        }
    }
}
