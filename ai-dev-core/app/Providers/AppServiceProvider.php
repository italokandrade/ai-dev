<?php

namespace App\Providers;

use App\Ai\Providers\FailoverProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Illuminate\Database\Eloquent\Model;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Ai::extend('failover', function ($app, array $config) {
            return new FailoverProvider(new PrismGateway($app['events']), $config, $app['events']);
        });

        $this->registerGlobalAuditor();

        // Auditor de Ferramentas - Blindado contra erro de get_class
        Event::listen(\Laravel\Ai\Events\ToolInvoked::class, function ($event) {
            try {
                $agentName = is_object($event->agent ?? null) ? $event->agent::class : (string)($event->agent ?? 'Unknown');
                $toolName = is_object($event->tool ?? null) ? $event->tool::class : (string)($event->tool ?? 'Unknown');

                \App\Models\ToolCallLog::create([
                    'invocation_id' => $event->invocationId ?? null,
                    'agent_class' => $agentName,
                    'tool_class' => $toolName,
                    'arguments' => $event->arguments ?? [],
                    'result' => is_string($event->result ?? '') ? $event->result : json_encode($event->result ?? ''),
                ]);
            } catch (\Throwable) {}
        });
    }

    protected function registerGlobalAuditor(): void
    {
        Event::listen(['eloquent.created: *', 'eloquent.updated: *', 'eloquent.deleted: *'], function ($eventName, array $data) {
            try {
                $model = $data[0] ?? null;
                if (! $model instanceof Model) return;

                $table = $model->getTable();
                // Ignorar tabelas de log e infra para evitar loop e erros
                if (in_array($table, ['activity_log', 'tool_calls_log', 'sessions', 'cache', 'jobs', 'failed_jobs'])) return;

                $action = str_contains($eventName, 'created') ? 'created' : (str_contains($eventName, 'updated') ? 'updated' : 'deleted');
                
                $log = activity()->performedOn($model);
                if ($user = auth()->user()) $log->causedBy($user);

                if ($action === 'updated') {
                    $old = array_intersect_key($model->getOriginal(), $model->getDirty());
                    $new = $model->getDirty();
                    unset($old['updated_at'], $new['updated_at']);
                    if (empty($new)) return;
                    $log->withProperties(['old' => $old, 'attributes' => $new])->log("Editou {$table}");
                } elseif ($action === 'created') {
                    $log->withProperties(['attributes' => $model->getAttributes()])->log("Criou em {$table}");
                } elseif ($action === 'deleted') {
                    $log->withProperties(['old' => $model->getOriginal()])->log("Deletou de {$table}");
                }
            } catch (\Throwable) {}
        });
    }
}
