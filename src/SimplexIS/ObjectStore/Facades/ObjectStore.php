<?php

namespace SimplexIS\ObjectStore\Facades;

use Illuminate\Support\Facades\Facade;

class ObjectStore extends Facade
{
    /**
     * 
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'object-store';
    }
}