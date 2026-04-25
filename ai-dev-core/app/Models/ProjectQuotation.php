<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Jobs\ScaffoldProjectJob;
use App\Jobs\SyncProjectRepositoryJob;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProjectQuotation extends Model
{
    use HasUuids, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Orçamento {$eventName}");
    }

    protected $fillable = [
        'project_id',
        'client_name',
        'project_name',
        'project_description',
        'complexity_level',
        'required_areas',
        'backend_hours',
        'frontend_hours',
        'mobile_hours',
        'database_hours',
        'devops_hours',
        'design_hours',
        'testing_hours',
        'security_hours',
        'pm_hours',
        'urgency_level',
        'delivery_days',
        'hourly_rate_backend',
        'hourly_rate_frontend',
        'hourly_rate_mobile',
        'hourly_rate_database',
        'hourly_rate_devops',
        'hourly_rate_design',
        'hourly_rate_testing',
        'hourly_rate_security',
        'hourly_rate_pm',
        'urgency_multiplier',
        'complexity_multiplier',
        'team_size',
        'total_human_hours',
        'total_human_cost',
        'ai_dev_cost',
        'savings_amount',
        'savings_percentage',
        'ai_dev_price',
        'actual_token_cost_usd',
        'actual_infra_cost',
        'status',
        'notes',
        'sent_at',
        'approved_at',
    ];

    protected $casts = [
        'required_areas' => 'array',
        'complexity_level' => 'integer',
        'urgency_level' => 'integer',
        'delivery_days' => 'integer',
        'backend_hours' => 'integer',
        'frontend_hours' => 'integer',
        'mobile_hours' => 'integer',
        'database_hours' => 'integer',
        'devops_hours' => 'integer',
        'design_hours' => 'integer',
        'testing_hours' => 'integer',
        'security_hours' => 'integer',
        'pm_hours' => 'integer',
        'team_size' => 'integer',
        'hourly_rate_backend' => 'decimal:2',
        'hourly_rate_frontend' => 'decimal:2',
        'hourly_rate_mobile' => 'decimal:2',
        'hourly_rate_database' => 'decimal:2',
        'hourly_rate_devops' => 'decimal:2',
        'hourly_rate_design' => 'decimal:2',
        'hourly_rate_testing' => 'decimal:2',
        'hourly_rate_security' => 'decimal:2',
        'hourly_rate_pm' => 'decimal:2',
        'urgency_multiplier' => 'decimal:2',
        'complexity_multiplier' => 'decimal:2',
        'total_human_hours' => 'decimal:2',
        'total_human_cost' => 'decimal:2',
        'ai_dev_cost' => 'decimal:2',
        'savings_amount' => 'decimal:2',
        'savings_percentage' => 'decimal:2',
        'ai_dev_price' => 'decimal:2',
        'actual_token_cost_usd' => 'decimal:6',
        'actual_infra_cost' => 'decimal:2',
        'sent_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    // Multiplicadores de urgência
    public const URGENCY_MULTIPLIERS = [
        1 => 1.00, // Normal — prazo padrão
        2 => 1.30, // Moderada — 30% mais caro (equipe maior)
        3 => 1.60, // Urgente — 60% mais caro
        4 => 2.00, // Crítica — dobro (equipe máxima, trabalho contínuo)
    ];

    // Multiplicadores de complexidade
    public const COMPLEXITY_MULTIPLIERS = [
        1 => 0.80, // Simples
        2 => 1.00, // Médio
        3 => 1.40, // Complexo
        4 => 1.80, // Enterprise
    ];

    // Tamanho base de equipe por urgência
    public const URGENCY_TEAM_SIZES = [
        1 => 1, // Normal — 1 dev por área
        2 => 2, // Moderada — 2 devs por área
        3 => 3, // Urgente — 3 devs por área
        4 => 4, // Crítica — 4 devs por área
    ];

    // Labels
    public const COMPLEXITY_LABELS = [
        1 => 'Simples',
        2 => 'Médio',
        3 => 'Complexo',
        4 => 'Enterprise',
    ];

    public const URGENCY_LABELS = [
        1 => 'Normal',
        2 => 'Moderada',
        3 => 'Urgente',
        4 => 'Crítica',
    ];

    public const STATUS_LABELS = [
        'draft' => 'Rascunho',
        'sent' => 'Enviado',
        'approved' => 'Aprovado',
        'rejected' => 'Rejeitado',
        'in_progress' => 'Em Execução',
        'completed' => 'Concluído',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approveAndStartScaffold(): void
    {
        $this->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $project = $this->project?->fresh();

        if (! $project) {
            return;
        }

        if ($project->isTargetScaffoldReady()) {
            SyncProjectRepositoryJob::dispatch($project);

            return;
        }

        if ($project->status === ProjectStatus::Scaffolding) {
            return;
        }

        ScaffoldProjectJob::dispatch($project, Str::random(32));
    }

    /**
     * Recalcula todos os valores do orçamento.
     */
    public function recalculate(): void
    {
        $urgency = (int) $this->urgency_level;
        $complexity = (int) $this->complexity_level;

        $this->urgency_multiplier = self::URGENCY_MULTIPLIERS[$urgency] ?? 1.00;
        $this->complexity_multiplier = self::COMPLEXITY_MULTIPLIERS[$complexity] ?? 1.00;
        $this->team_size = self::URGENCY_TEAM_SIZES[$urgency] ?? 1;

        $areas = [
            'backend' => [$this->backend_hours,  $this->hourly_rate_backend],
            'frontend' => [$this->frontend_hours, $this->hourly_rate_frontend],
            'mobile' => [$this->mobile_hours,   $this->hourly_rate_mobile],
            'database' => [$this->database_hours, $this->hourly_rate_database],
            'devops' => [$this->devops_hours,   $this->hourly_rate_devops],
            'design' => [$this->design_hours,   $this->hourly_rate_design],
            'testing' => [$this->testing_hours,  $this->hourly_rate_testing],
            'security' => [$this->security_hours, $this->hourly_rate_security],
            'pm' => [$this->pm_hours,       $this->hourly_rate_pm],
        ];

        $totalHours = 0;
        $totalCost = 0;

        foreach ($areas as [$hours, $rate]) {
            $totalHours += $hours;
            $totalCost += $hours * $rate;
        }

        // Aplica multiplicadores
        $totalCost *= $this->urgency_multiplier * $this->complexity_multiplier;

        $this->total_human_hours = $totalHours;
        $this->total_human_cost = round($totalCost, 2);

        // Custo AI-Dev: token cost em BRL (USD * 5.8) + infra
        $usdToBrl = 5.80;
        $this->ai_dev_cost = round(($this->actual_token_cost_usd * $usdToBrl) + $this->actual_infra_cost, 2);

        // Preço sugerido AI-Dev: 15% do custo humano (mínimo R$500)
        $this->ai_dev_price = max(500.00, round($this->total_human_cost * 0.15, 2));
        $this->savings_amount = round($this->total_human_cost - $this->ai_dev_price, 2);
        $this->savings_percentage = $this->total_human_cost > 0
            ? round(($this->savings_amount / $this->total_human_cost) * 100, 2)
            : 0;
    }

    /**
     * Horas totais sem multiplicadores (base).
     */
    public function totalBaseHours(): int
    {
        return $this->backend_hours + $this->frontend_hours + $this->mobile_hours
            + $this->database_hours + $this->devops_hours + $this->design_hours
            + $this->testing_hours + $this->security_hours + $this->pm_hours;
    }

    /**
     * Profissionais necessários por urgência e áreas ativas.
     */
    public function professionalCount(): int
    {
        $activeAreas = collect($this->required_areas ?? [])->filter()->count();

        return max(1, $activeAreas) * ($this->team_size ?? 1);
    }

    /**
     * Custo real acumulado da execução AI-Dev (tokens + infra) em BRL.
     */
    public function actualCostBrl(): float
    {
        return round(($this->actual_token_cost_usd * 5.80) + $this->actual_infra_cost, 2);
    }
}
