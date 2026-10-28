<?php
require __DIR__."/../vendor/autoload.php";
$app = require __DIR__."/../bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (App\Models\Lead::orderBy("score")->get() as $l) { echo str_pad($l->score, 4), str_pad($l->tier, 6), str_pad($l->status, 10), $l->fullName(), PHP_EOL; }
