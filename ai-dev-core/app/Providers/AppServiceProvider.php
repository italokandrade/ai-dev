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
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
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

        // Log tool calls for audit
        Event::listen(\Laravel\Ai\Events\ToolInvoked::class, function (\Laravel\Ai\Events\ToolInvoked $event) {
            \App\Models\ToolCallLog::create([
                'invocation_id' => $event->invocationId,
                'agent_class' => is_object($event->agent) ? get_class($event->agent) : (string) $event->agent,
                'tool_class' => is_object($event->tool) ? get_class($event->tool) : (string) $event->tool,
                'arguments' => $event->arguments,
                'result' => is_string($event->result) ? $event->result : json_encode($event->result),
            ]);
        });
    }

    /**
     * Monitora globalmente todas as mudanças no banco de dados sem necessidade de traits nos Models.
     */
    protected function registerGlobalAuditor(): void
    {
        // Eventos que queremos rastrear
        $events = [
            'eloquent.created: *',
            'eloquent.updated: *',
            'eloquent.deleted: *',
        ];

        Event::listen($events, function ($eventName, array $data) {
            $model = $data[0];

            if (! $model instanceof Model) {
                return;
            }

            // Ignorar modelos de infraestrutura e o próprio log para evitar loop
            $ignoredModels = [
                Activity::class,
                \App\Models\ToolCallLog::class,
                \Illuminate\Notifications\DatabaseNotification::class,
            ];

            if (in_array(get_class($model), $ignoredModels)) {
                return;
            }

            // Ignorar tabelas de cache, sessão e filas (identificadas pelo nome da tabela)
            $ignoredTables = ['sessions', 'cache', 'jobs', 'failed_jobs', 'job_batches', 'pulse_entries', 'pulse_values', 'pulse_aggregates'];
            if (in_array($model->getTable(), $ignoredTables)) {
                return;
            }

            $action = str_replace('eloquent.', '', explode(':', $eventName)[0]);
            
            $log = activity()
                ->performedOn($model)
                ->causedBy(auth()->user());

            if ($action === 'updated') {
                $old = array_intersect_key($model->getOriginal(), $model->getDirty());
                $new = $model->getDirty();
                
                unset($old['updated_at'], $new['updated_at']);
                
                if (empty($new)) return;

                $log->withProperties(['old' => $old, 'attributes' => $new])
                    ->log("Registro atualizado em {$model->getTable()}");
            } elseif ($action === 'created') {
                $log->withProperties(['attributes' => $model->getAttributes()])
                    ->log("Novo registro em {$model->getTable()}");
            } elseif ($action === 'deleted') {
                $log->withProperties(['old' => $model->getOriginal()])
                    ->log("Registro excluído em {$model->getTable()}");
            }
        });
    }
}
