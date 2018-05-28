<?php
Cache::config('_cake_core_', [
    'engine' => 'File',
    'prefix' => 'cake_core_one_rbkmoney_',
    'path' => CACHE . 'persistent' . DS,
    'serialize' => true,
    'duration' => '+999 days',
]);
?>