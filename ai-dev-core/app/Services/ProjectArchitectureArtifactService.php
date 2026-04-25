<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Str;

class ProjectArchitectureArtifactService
{
    public const string ARCHITECTURE_DIR = 'architecture';

    /**
     * @return array<string, string>
     */
    public function documents(Project $project): array
    {
        $blueprint = is_array($project->blueprint_payload) ? $project->blueprint_payload : [];
        $domainModel = $this->domainModel($blueprint);
        $payload = $this->domainPayload($project, $domainModel);

        return [
            self::ARCHITECTURE_DIR.'/README.md' => $this->readme($project),
            self::ARCHITECTURE_DIR.'/domain-model.mmd' => $this->mermaidFromDomainModel($domainModel),
            self::ARCHITECTURE_DIR.'/domain-model.md' => $this->domainMarkdown($project, $domainModel),
            self::ARCHITECTURE_DIR.'/domain-model.json' => $this->json($payload),
            self::ARCHITECTURE_DIR.'/checkpoint-protocol.md' => $this->checkpointProtocol(),
        ];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     */
    public function mermaidFromBlueprint(array $blueprint): string
    {
        return $this->mermaidFromDomainModel($this->domainModel($blueprint));
    }

    /**
     * @param  array<string, mixed>  $domainModel
     */
    public function mermaidFromDomainModel(array $domainModel): string
    {
        $entities = $this->entities($domainModel);
        $relationships = $this->relationships($domainModel);
        $lines = [
            'erDiagram',
        ];

        if ($entities === [] && $relationships === []) {
            $lines[] = '    %% Nenhuma entidade de dominio foi descoberta ainda.';

            return implode("\n", $lines)."\n";
        }

        foreach ($entities as $entity) {
            $name = $this->mermaidIdentifier($entity['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $lines[] = "    {$name} {";

            $columns = $this->columns($entity);
            if ($columns === []) {
                $lines[] = '        string conceito';
            }

            foreach ($columns as $column) {
                $columnName = $this->mermaidFieldName($column['name'] ?? '');
                if ($columnName === '') {
                    continue;
                }

                $columnType = $this->mermaidFieldType($column['type'] ?? 'string');
                $suffix = $this->columnSuffix($column);
                $lines[] = trim("        {$columnType} {$columnName} {$suffix}");
            }

            $lines[] = '    }';
        }

        foreach ($relationships as $relationship) {
            $source = $this->mermaidIdentifier($relationship['source'] ?? '');
            $target = $this->mermaidIdentifier($relationship['target'] ?? '');

            if ($source === '' || $target === '') {
                continue;
            }

            $connector = $this->connectorFor($relationship['type'] ?? '');
            $label = $this->relationshipLabel($relationship);
            $lines[] = "    {$source} {$connector} {$target} : {$label}";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $domainModel
     */
    public function domainMarkdown(Project $project, array $domainModel): string
    {
        $entities = $this->entities($domainModel);
        $relationships = $this->relationships($domainModel);
        $lines = [
            "# {$project->name} - Modelo de Dominio",
            '',
            'Este arquivo e gerado pelo ai-dev-core a partir do Blueprint Tecnico Global. Edicoes manuais devem voltar para o PRD/Blueprint antes de serem aplicadas no codigo.',
            '',
            '## Mermaid ERD',
            '',
            '```mermaid',
            rtrim($this->mermaidFromDomainModel($domainModel)),
            '```',
            '',
            '## Entidades',
            '',
        ];

        if ($entities === []) {
            $lines[] = '- Nenhuma entidade registrada.';
            $lines[] = '';
        }

        foreach ($entities as $entity) {
            $lines[] = '### '.$this->text($entity['name'] ?? 'Entidade');
            $lines[] = '';

            if ($description = $this->text($entity['description'] ?? '')) {
                $lines[] = $description;
                $lines[] = '';
            }

            $columns = $this->columns($entity);
            if ($columns !== []) {
                $lines[] = '| Campo | Tipo | Nullable | Fonte | Descricao |';
                $lines[] = '| --- | --- | --- | --- | --- |';

                foreach ($columns as $column) {
                    $lines[] = '| '.$this->tableValue($column['name'] ?? '').' | '
                        .$this->tableValue($column['type'] ?? 'string').' | '
                        .$this->tableValue(($column['nullable'] ?? false) ? 'sim' : 'nao').' | '
                        .$this->tableValue($column['source'] ?? '').' | '
                        .$this->tableValue($column['description'] ?? '').' |';
                }

                $lines[] = '';
            }
        }

        $lines[] = '## Relacionamentos';
        $lines[] = '';

        if ($relationships === []) {
            $lines[] = '- Nenhum relacionamento registrado.';
            $lines[] = '';
        } else {
            $lines[] = '| Origem | Tipo | Destino | FK | Descricao |';
            $lines[] = '| --- | --- | --- | --- | --- |';

            foreach ($relationships as $relationship) {
                $lines[] = '| '.$this->tableValue($relationship['source'] ?? '').' | '
                    .$this->tableValue($relationship['type'] ?? '').' | '
                    .$this->tableValue($relationship['target'] ?? '').' | '
                    .$this->tableValue($relationship['foreign_key'] ?? '').' | '
                    .$this->tableValue($relationship['description'] ?? '').' |';
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function readme(Project $project): string
    {
        return implode("\n", [
            "# {$project->name} - Arquitetura de Dados",
            '',
            'Arquivos gerenciados automaticamente pelo ai-dev-core:',
            '',
            '- `domain-model.mmd`: MER em Mermaid, versionavel e legivel por IA.',
            '- `domain-model.md`: MER renderizavel em Markdown/GitHub.',
            '- `domain-model.json`: entidades e relacionamentos normalizados a partir do Blueprint.',
            '- `checkpoint-protocol.md`: rotina obrigatoria antes de interfaces Filament, Livewire, Controllers ou Views.',
            '',
        ]);
    }

    private function checkpointProtocol(): string
    {
        return <<<'MARKDOWN'
# Protocolo de Checkpoint de Arquitetura de Dados

Antes de implementar Filament Resources, Livewire, Controllers, APIs ou Views, valide a arquitetura de dados do modulo.

## Ordem obrigatoria

1. Leia `.ai-dev/blueprint-global.json`, `.ai-dev/architecture/domain-model.json` e o PRD do modulo em `.ai-dev/modules/`.
2. Crie ou ajuste migrations, Models e relacionamentos Eloquent (`hasMany`, `belongsTo`, `belongsToMany`, `morph*`) antes de qualquer interface.
3. Para prototipagem, use SQLite em arquivo local `database/ai_dev_architecture.sqlite` com `DB_CONNECTION=sqlite` e `DB_DATABASE=database/ai_dev_architecture.sqlite`.
4. Execute as migrations nesse SQLite temporario e corrija falhas de FK, nomes, nulabilidade e ordem de criacao.
5. Gere ou atualize o ERD fisico com `php artisan generate:erd .ai-dev/architecture/erd-physical.txt` quando o pacote estiver instalado. Se GraphViz estiver disponivel, gere tambem `.svg`.
6. Antes da aprovacao do orcamento, compare o ERD fisico gerado a partir do SQLite temporario com o Mermaid oficial. Tabelas isoladas so sao permitidas quando explicitamente justificadas no PRD.
7. Depois da aprovacao do orcamento e do scaffold fisico, confira o schema real via Boost `database-schema` e valide no Postgres de desenvolvimento/staging do Projeto Alvo antes de liberar tarefas de UI. Nao rode `migrate:fresh` em banco com dados reais de producao.
8. Atualize `.ai-dev/architecture/domain-model.*` quando houver mudanca de dominio aprovada.

## Criterio de saida

O checkpoint passa somente quando migrations, Models, relacionamentos Eloquent, ERD fisico e Blueprint/Mermaid concordam.
MARKDOWN;
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array<string, mixed>
     */
    private function domainModel(array $blueprint): array
    {
        $domainModel = $blueprint['domain_model'] ?? [];

        return is_array($domainModel) ? $domainModel : [];
    }

    /**
     * @param  array<string, mixed>  $domainModel
     * @return array<int, array<string, mixed>>
     */
    private function entities(array $domainModel): array
    {
        $entities = $domainModel['entities'] ?? [];

        if (! is_array($entities)) {
            return [];
        }

        return collect($entities)
            ->filter(fn (mixed $entity): bool => is_array($entity) && $this->text($entity['name'] ?? '') !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $domainModel
     * @return array<int, array<string, mixed>>
     */
    private function relationships(array $domainModel): array
    {
        $relationships = $domainModel['relationships'] ?? [];

        if (! is_array($relationships)) {
            return [];
        }

        return collect($relationships)
            ->filter(fn (mixed $relationship): bool => is_array($relationship)
                && $this->text($relationship['source'] ?? '') !== ''
                && $this->text($relationship['target'] ?? '') !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return array<int, array<string, mixed>>
     */
    private function columns(array $entity): array
    {
        $columns = $entity['columns'] ?? [];

        if (! is_array($columns)) {
            return [];
        }

        return collect($columns)
            ->filter(fn (mixed $column): bool => is_array($column) && $this->text($column['name'] ?? '') !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $domainModel
     * @return array<string, mixed>
     */
    private function domainPayload(Project $project, array $domainModel): array
    {
        return [
            'source' => 'ai-dev-core',
            'artifact_type' => 'domain_model',
            'project_id' => $project->id,
            'project_name' => $project->name,
            'entities' => $this->entities($domainModel),
            'relationships' => $this->relationships($domainModel),
        ];
    }

    private function mermaidIdentifier(mixed $value): string
    {
        $identifier = Str::upper(Str::snake($this->text($value)));
        $identifier = preg_replace('/[^A-Z0-9_]/', '_', $identifier) ?? '';

        return trim($identifier, '_');
    }

    private function mermaidFieldName(mixed $value): string
    {
        $field = Str::snake($this->text($value));
        $field = preg_replace('/[^A-Za-z0-9_]/', '_', $field) ?? '';

        return trim($field, '_');
    }

    private function mermaidFieldType(mixed $value): string
    {
        $type = Str::lower($this->text($value) ?: 'string');
        $type = preg_replace('/[^A-Za-z0-9_]/', '_', $type) ?? 'string';

        return trim($type, '_') ?: 'string';
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function columnSuffix(array $column): string
    {
        $name = Str::lower($this->text($column['name'] ?? ''));

        if ($name === 'id') {
            return 'PK';
        }

        if (str_ends_with($name, '_id')) {
            return 'FK';
        }

        return '';
    }

    private function connectorFor(mixed $type): string
    {
        return match (Str::lower($this->text($type))) {
            'one_to_one', 'hasone', 'has_one', 'belongs_to_one' => '||--||',
            'one_to_many', 'hasmany', 'has_many' => '||--o{',
            'many_to_one', 'belongsto', 'belongs_to' => '}o--||',
            'many_to_many', 'belongstomany', 'belongs_to_many', 'pivot' => '}o--o{',
            default => '}o--o{',
        };
    }

    /**
     * @param  array<string, mixed>  $relationship
     */
    private function relationshipLabel(array $relationship): string
    {
        $description = $this->text($relationship['description'] ?? '');

        if ($description !== '') {
            return Str::slug(Str::limit($description, 48, ''), '_') ?: 'relaciona';
        }

        return Str::slug($this->text($relationship['type'] ?? 'relaciona'), '_') ?: 'relaciona';
    }

    private function text(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode(' ', array_map($this->text(...), $value)));
        }

        return trim((string) $value);
    }

    private function tableValue(mixed $value): string
    {
        return str_replace(["\n", '|'], [' ', '\|'], $this->text($value));
    }

    private function json(mixed $payload): string
    {
        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )."\n";
    }
}
