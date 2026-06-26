<?php

/** @var Nutgram $bot */

use App\Services\TelegramHandlerService;

/*
|--------------------------------------------------------------------------
| Nutgram Handlers
|--------------------------------------------------------------------------
|
| Here is where you can register telegram handlers for Nutgram. These
| handlers are loaded by the NutgramServiceProvider. Enjoy!
|
*/

$handler = new TelegramHandlerService($bot);
$handler->registerHandlers();
