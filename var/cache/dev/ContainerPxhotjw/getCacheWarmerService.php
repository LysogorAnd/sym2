<?php

use Symfony\Component\DependencyInjection\Argument\RewindableGenerator;

// This file has been auto-generated by the Symfony Dependency Injection Component for internal use.
// Returns the public 'cache_warmer' shared service.

include_once $this->targetDirs[3].'\\vendor\\symfony\\http-kernel\\CacheWarmer\\CacheWarmerInterface.php';
include_once $this->targetDirs[3].'\\vendor\\symfony\\http-kernel\\CacheWarmer\\CacheWarmerAggregate.php';

return $this->services['cache_warmer'] = new \Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerAggregate(new RewindableGenerator(function () {
    yield 0 => ${($_ = isset($this->services['router.cache_warmer']) ? $this->services['router.cache_warmer'] : $this->load(__DIR__.'/getRouter_CacheWarmerService.php')) && false ?: '_'};
}, 1));
