<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$jobs = \App\Models\CharacterJob::with('jobClass')->get();
foreach ($jobs as $cj) {
    echo "ID: {$cj->id}, JobLevel: {$cj->job_level}, Exp: {$cj->job_exp}, Name: " . ($cj->jobClass->name ?? 'Unknown') . "\n";
}

echo "\n--- Job Exp Tables ---\n";
$tables = \App\Models\JobExpTable::all();
foreach ($tables as $t) {
    echo "Level: {$t->job_level}, Required: {$t->required_exp}\n";
}
