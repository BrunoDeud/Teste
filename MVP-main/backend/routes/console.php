<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('items:expire')->dailyAt('02:00');
