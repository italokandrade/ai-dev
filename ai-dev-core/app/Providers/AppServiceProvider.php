<?php

namespace App\Providers;

use App\Ai\Providers\FailoverProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Ai::extend('failover', function ($app, array $config) {
            return new FailoverProvider(
                new PrismGateway($app['events']),
                $config,
                $app['events']
            );
        });

        $this->registerGlobalAuditor();

        // Log tool calls for audit - Blindagem Máxima
        Event::listen(\Laravel\Ai\Events\ToolInvoked::class, function ($event) {
            try {
                $agentName = 'Unknown';
                if (isset($event->agent)) {
                    $agentName = is_object($event->agent) ? get_class($event->agent) : (string)$event->agent;
                }

                $toolName = 'Unknown';
                if (isset($event->tool)) {
                    $toolName = is_object($event->tool) ? get_class($event->tool) : (string)$event->tool;
                }

                \App\Models\ToolCallLog::create([
                    'invocation_id' => $event->invocationId ?? null,
                    'agent_class' => $agentName,
                    'tool_class' => $toolName,
                    'arguments' => $event->arguments ?? [],
                    'result' => is_string($event->result ?? '') ? $event->result : json_encode($event->result ?? ''),
                ]);
            } catch (\Throwable) {
                // Auditoria falhou? Vida que segue, não trava o chat.
            }
        });
    }

    protected function registerGlobalAuditor(): void
    {
        $events = ['eloquent.created: *', 'eloquent.updated: *', 'eloquent.deleted: *'];

        Event::listen($events, function ($eventName, array $data) {
            try {
                $model = $data[0] ?? null;
                if (! $model instanceof Model) return;

                $ignoredModels = [Activity::class, \App\Models\ToolCallLog::class];
                if (in_array(get_class($model), $ignoredModels)) return;

                $ignoredTables = ['sessions', 'cache', 'jobs', 'failed_jobs', 'activity_log'];
                if (in_array($model->getTable(), $ignoredTables)) return;

                $action = str_contains($eventName, 'created') ? 'created' : (str_contains($eventName, 'updated') ? 'updated' : 'deleted');
                
                $log = activity()->performedOn($model);
                if ($user = auth()->user()) $log->causedBy($user);

                if ($action === 'updated') {
                    $old = array_intersect_key($model->getOriginal(), $model->getDirty());
                    $new = $model->getDirty();
                    unset($old['updated_at'], $new['updated_at']);
                    if (empty($new)) return;
                    $log->withProperties(['old' => $old, 'attributes' => $new])->log("Registro atualizado em {$model->getTable()}");
                } elseif ($action === 'created') {
                    $log->withProperties(['attributes' => $model->getAttributes()])->log("Novo registro em {$model->getTable()}");
                } elseif ($action === 'deleted') {
                    $log->withProperties(['old' => $model->getOriginal()])->log("Registro excluído em {$model->getTable()}");
                }
            } catch (\Throwable) {
                // Silencioso
            }
        });
    }
}
