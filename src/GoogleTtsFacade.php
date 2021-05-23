<?php

namespace Lengkeng\GoogleTts;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Lengkeng\GoogleTts\Skeleton\SkeletonClass
 */
class GoogleTtsFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'google-tts';
    }
}
