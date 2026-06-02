<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Facebook\FacebookPageService;

class FacebookController extends Controller
{
    public function page(FacebookPageService $pages)
    {
        return $pages->publicStats();
    }

    public function pluginConfig(FacebookPageService $pages)
    {
        return $pages->pluginConfig();
    }
}
