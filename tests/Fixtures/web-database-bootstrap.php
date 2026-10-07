<?php

use App\Support\WebDatabaseConnection;
use Illuminate\Contracts\Http\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->bind(WebDatabaseConnection::class, fn ($app) => new class($app['config'], $app['db']) extends WebDatabaseConnection
{
    public function apply(bool $console, ?int $slot = null): void
    {
        parent::apply($console, (int) getenv('PROBE_SLOT'));
    }
});
$app->make(Kernel::class)->bootstrap();
$name = config('database.default');
echo json_encode(['connection' => $name, 'username' => config('database.connections.'.$name.'.username'),
    'database' => config('database.connections.'.$name.'.database'), 'open_connections' => count(app('db')->getConnections())]);
