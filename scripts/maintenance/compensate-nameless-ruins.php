<?php

use App\Services\NamelessRuinCompensationService;
use Illuminate\Support\Facades\DB;

try {
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();


    $options = getopt('', ['preview:', 'apply:', 'sha256:']);
    if (isset($options['preview']) === isset($options['apply']) || empty($options['sha256'])) {
        throw new RuntimeException('Specify exactly one of --preview/--apply and the reviewed file --sha256.');
    }
    $contents = file_get_contents($options['preview'] ?? $options['apply']);
    if (! hash_equals($options['sha256'], hash('sha256', $contents))) {
        throw new RuntimeException('The reviewed private file hash does not match.');
    }
    $input = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    config(['cache.default' => 'array', 'session.driver' => 'array']);
    $service = app(NamelessRuinCompensationService::class);
    if (isset($options['preview'])) {
        $db = DB::connection();
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $db->statement('SET TRANSACTION READ ONLY');
        }
        $db->beginTransaction();
        try {
            echo json_encode($service->createPlan($input['operations']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
        } finally {
            $db->rollBack();
        }
        exit(0);
    }
    if (($input['policy'] ?? null) !== NamelessRuinCompensationService::POLICY || ! is_array($input['entries'] ?? null)) {
        throw new RuntimeException('The compensation policy is not supported.');
    }
    $seen = [];
    foreach ($input['entries'] as $entry) {
        if (isset($seen[$entry['operation_id']])) {
            throw new RuntimeException('The plan has duplicate source operations.');
        }
        $seen[$entry['operation_id']] = true;
    }
    $results = [];
    foreach ($input['entries'] as $entry) {
        $results[] = ['operation_id' => $entry['operation_id'], 'character_id' => $entry['character_id']] + $service->applyEntry($entry);
        usleep(100000); // One operation at a time; avoid a burst against live gameplay.
    }
    echo json_encode(['policy' => $input['policy'], 'plan_sha256' => $options['sha256'], 'results' => $results], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(count(array_filter($results, fn ($result) => $result['status'] === 'deferred')) > 0 ? 2 : 0);
} catch (\Throwable $exception) {
    $message = $exception instanceof \Illuminate\Database\QueryException ? 'Database operation failed; receipts must be checked before retry.' : $exception->getMessage();
    fwrite(STDERR, 'Compensation failed: '.$message.PHP_EOL);
    exit(1);
}
