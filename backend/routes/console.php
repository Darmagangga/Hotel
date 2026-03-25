<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pms:night-audit', function () {
    $this->call('pms:night-audit');
})->purpose('Perform daily night audit process to post room charges')->hourly();
