<?php

/*
 * This file is part of foskym/flarum-issue-tracking-youtrack.
 *
 * Copyright (c) 2024 FoskyM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoskyM\IssueTrackingYoutrack;

use Flarum\Extend;

return [
    new Extend\Locales(__DIR__.'/locale'),

    (new \FoskyM\IssueTracking\Extend\PlatformProvider())
        //Normally create the provider with a class extends AbstractStoreProvider
        ->provide(PlatformProvider::class)
];
