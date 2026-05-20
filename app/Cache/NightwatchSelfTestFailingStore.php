<?php

namespace App\Cache;

use Illuminate\Cache\ArrayStore;

class NightwatchSelfTestFailingStore extends ArrayStore
{
    public function __construct()
    {
        parent::__construct(false);
    }

    public function put($key, $value, $seconds): bool
    {
        return false;
    }

    public function forever($key, $value): bool
    {
        return false;
    }

    public function forget($key): bool
    {
        return false;
    }
}
