#!/bin/bash
# ============================================================
# AUTOMATIZADOR TALL STACK SUPREME V5 - LARAVEL 13 (POSTGRESQL)
# ============================================================
export COMPOSER_ALLOW_SUPERUSER=1
umask 002

NOME="$1"
SENHA="$2"
DB_HOST="127.0.0.1"
DB_PORT="5432"
DB_ADMIN="postgres"
DB_ADMIN_PASS="B1r02012;"
DIRETORIO_PAI="/var/www/html/projetos"
AI_DEV_CORE="$DIRETORIO_PAI/ai-dev/ai-dev-core"
URL_BASE="http://10.1.1.86/projetos"
ADMIN_EMAIL="contato@andradeitalo.ai"
ADMIN_SENHA="B1r02012;"
ADMIN_NOME="Italo Andrade"

if [ -z "$NOME" ] || [ -z "$SENHA" ]; then
    echo "Erro: Use ./instalar_projeto.sh [nome] [senha]"
    exit 1
fi

copy_from_core() {
    local source="$AI_DEV_CORE/$1"
    local target="$2"

    if [ ! -e "$source" ]; then
        echo "Erro: arquivo padrão não encontrado em $source"
        exit 1
    fi

    mkdir -p "$(dirname "$target")"
    cp -R "$source" "$target"
}

cd "$DIRETORIO_PAI" || exit

# 1. Limpeza Radical e Banco de Dados (PostgreSQL)
echo "🗑️ Removendo vestígios anteriores..."
rm -rf "$NOME"
export PGPASSWORD="$DB_ADMIN_PASS"
psql -h "$DB_HOST" -U "$DB_ADMIN" -c "DROP DATABASE IF EXISTS \"$NOME\";"
psql -h "$DB_HOST" -U "$DB_ADMIN" -c "DROP USER IF EXISTS \"$NOME\";"
psql -h "$DB_HOST" -U "$DB_ADMIN" -c "CREATE DATABASE \"$NOME\" WITH ENCODING 'UTF8';"
psql -h "$DB_HOST" -U "$DB_ADMIN" -c "CREATE USER \"$NOME\" WITH PASSWORD '$SENHA';"
psql -h "$DB_HOST" -U "$DB_ADMIN" -c "GRANT ALL PRIVILEGES ON DATABASE \"$NOME\" TO \"$NOME\";"
# Grant on public schema for modern Laravel migrations
psql -h "$DB_HOST" -U "$DB_ADMIN" -d "$NOME" -c "GRANT ALL ON SCHEMA public TO \"$NOME\";"
psql -h "$DB_HOST" -U "$DB_ADMIN" -d "$NOME" -c "CREATE EXTENSION IF NOT EXISTS vector;"

# 2. Instalação Laravel 13
echo "📦 Baixando Laravel 13..."
composer create-project laravel/laravel:^13.0 "$NOME" --no-interaction
cd "$NOME" || exit

# 3. Configuração de Roteamento e .Env
echo "⚙️ Configurando .env..."
cp .env.example .env
sed -i "s|^#\?DB_CONNECTION=.*|DB_CONNECTION=pgsql|" .env
sed -i "s|^#\?DB_HOST=.*|DB_HOST=$DB_HOST|" .env
sed -i "s|^#\?DB_PORT=.*|DB_PORT=$DB_PORT|" .env
sed -i "s|^#\?DB_DATABASE=.*|DB_DATABASE=$NOME|" .env
sed -i "s|^#\?DB_USERNAME=.*|DB_USERNAME=$NOME|" .env
sed -i "s|^#\?DB_PASSWORD=.*|DB_PASSWORD=\"$SENHA\"|" .env
sed -i "s|^#\?APP_URL=.*|APP_URL=$URL_BASE/$NOME/public|" .env
echo "ASSET_URL=$URL_BASE/$NOME/public" >> .env

# 4. Solução Nuclear (Blindagem mod_php)
echo "🛡️ Blindando variáveis no public/.htaccess..."
cat <<HTACCESS >> public/.htaccess

# Blindagem ANDRADEITALO.ai - Injeção Direta de Ambiente
SetEnv DB_CONNECTION pgsql
SetEnv DB_HOST $DB_HOST
SetEnv DB_PORT $DB_PORT
SetEnv DB_DATABASE $NOME
SetEnv DB_USERNAME $NOME
SetEnv DB_PASSWORD "$SENHA"
SetEnv ASSET_URL $URL_BASE/$NOME/public
HTACCESS

# 5. Instalação da Stack Principal (Filament v5 + Tailwind v4)
echo "📦 Instalando Filament v5..."
COMPOSER_MEMORY_LIMIT=-1 composer require filament/filament:"^5.5" -W --no-interaction
php artisan filament:install --panels --no-interaction

# 6. Suíte de Testes (Browser Simulation)
echo "🧪 Instalando Simulador de Browser (Dusk)..."
COMPOSER_MEMORY_LIMIT=-1 composer require laravel/dusk --dev --no-interaction
php artisan dusk:install --no-interaction

# 7. Ecossistema AI (Laravel AI SDK, MCP, Boost)
echo "🤖 Instalando Ecossistema AI..."
COMPOSER_MEMORY_LIMIT=-1 composer require laravel/ai laravel/mcp --no-interaction
COMPOSER_MEMORY_LIMIT=-1 composer require laravel/boost --dev --no-interaction
COMPOSER_MEMORY_LIMIT=-1 composer require beyondcode/laravel-er-diagram-generator --dev --no-interaction
php artisan vendor:publish --tag=ai-config --tag=ai-migrations --no-interaction
php artisan vendor:publish --tag=mcp-config --no-interaction
cat <<'MCPJSON' > .mcp.json
{
    "mcpServers": {
        "laravel-boost": {
            "command": "php",
            "args": [
                "artisan",
                "boost:mcp"
            ]
        }
    }
}
MCPJSON
echo "" >> .env
echo "OPENAI_API_KEY=\"sua_chave_openai_aqui\"" >> .env
echo "OPENAI_MODEL=\"gpt-5-nano\"" >> .env
grep -qxF "/database/ai_dev_architecture.sqlite" .gitignore || echo "/database/ai_dev_architecture.sqlite" >> .gitignore

# 8. Core padrão obrigatório (Chatbox + Segurança)
echo "🔐 Instalando Core padrão: Chatbox e Segurança..."
COMPOSER_MEMORY_LIMIT=-1 composer require \
    bezhansalleh/filament-shield:"^4.2" \
    spatie/laravel-permission:"^6.0" \
    spatie/laravel-activitylog:"^4.12" \
    -W --no-interaction

copy_from_core "app/Ai/Agents/SystemAssistantAgent.php" "app/Ai/Agents/SystemAssistantAgent.php"
copy_from_core "app/Ai/Tools/BoostTool.php" "app/Ai/Tools/BoostTool.php"
copy_from_core "app/Ai/Tools/FileReadTool.php" "app/Ai/Tools/FileReadTool.php"
copy_from_core "app/Filament/Resources/ActivityLogs" "app/Filament/Resources/ActivityLogs"
copy_from_core "app/Filament/Resources/RoleResource.php" "app/Filament/Resources/RoleResource.php"
copy_from_core "app/Filament/Resources/Users" "app/Filament/Resources/Users"
copy_from_core "app/Filament/Widgets/DashboardChat.php" "app/Filament/Widgets/DashboardChat.php"
copy_from_core "app/Models/SystemSetting.php" "app/Models/SystemSetting.php"
copy_from_core "app/Models/User.php" "app/Models/User.php"
copy_from_core "app/Services/ActivityAuditService.php" "app/Services/ActivityAuditService.php"
copy_from_core "app/Services/AiRuntimeConfigService.php" "app/Services/AiRuntimeConfigService.php"
copy_from_core "app/Services/FilamentShieldPermissionSyncService.php" "app/Services/FilamentShieldPermissionSyncService.php"
copy_from_core "app/Services/SystemSurfaceMapService.php" "app/Services/SystemSurfaceMapService.php"
copy_from_core "config/filament-shield.php" "config/filament-shield.php"
copy_from_core "config/permission.php" "config/permission.php"
copy_from_core "database/migrations/2026_04_12_183155_create_system_settings_table.php" "database/migrations/2026_04_12_183155_create_system_settings_table.php"
copy_from_core "database/migrations/2026_04_21_140127_create_permission_tables.php" "database/migrations/2026_04_21_140127_create_permission_tables.php"
copy_from_core "database/migrations/2026_04_21_140128_create_activity_log_table.php" "database/migrations/2026_04_21_140128_create_activity_log_table.php"
copy_from_core "database/migrations/2026_04_21_140129_add_event_column_to_activity_log_table.php" "database/migrations/2026_04_21_140129_add_event_column_to_activity_log_table.php"
copy_from_core "database/migrations/2026_04_21_140130_add_batch_uuid_column_to_activity_log_table.php" "database/migrations/2026_04_21_140130_add_batch_uuid_column_to_activity_log_table.php"
copy_from_core "database/migrations/2026_04_21_150910_fix_activity_log_ids_type.php" "database/migrations/2026_04_21_150910_fix_activity_log_ids_type.php"
copy_from_core "resources/views/filament/widgets/dashboard-chat.blade.php" "resources/views/filament/widgets/dashboard-chat.blade.php"

php <<'PHP'
<?php

$provider = 'app/Providers/AppServiceProvider.php';
$content = file_get_contents($provider);

foreach ([
    'use App\Services\ActivityAuditService;',
    'use App\Services\FilamentShieldPermissionSyncService;',
] as $import) {
    if (! str_contains($content, $import)) {
        $content = preg_replace('/namespace App\\\\Providers;\s*/', "namespace App\\Providers;\n\n{$import}\n", $content, 1);
    }
}

if (! str_contains($content, 'ActivityAuditService::register();')) {
    $content = preg_replace(
        '/public function boot\(\): void\s*\{\s*/',
        "public function boot(): void\n    {\n        ActivityAuditService::register();\n        FilamentShieldPermissionSyncService::sync();\n\n",
        $content,
        1
    );
}

file_put_contents($provider, $content);

$panelProvider = 'app/Providers/Filament/AdminPanelProvider.php';
$content = file_get_contents($panelProvider);

if (! str_contains($content, 'use BezhanSalleh\FilamentShield\FilamentShieldPlugin;')) {
    $content = preg_replace(
        '/namespace App\\\\Providers\\\\Filament;\s*/',
        "namespace App\\Providers\\Filament;\n\nuse BezhanSalleh\\FilamentShield\\FilamentShieldPlugin;\n",
        $content,
        1
    );
}

if (! str_contains($content, 'FilamentShieldPlugin::make()')) {
    $plugin = <<<'PLUGIN'
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup('Segurança')
                    ->navigationLabel('Perfis de Usuários')
                    ->navigationSort(70)
                    ->navigationIcon('heroicon-o-shield-check'),
            ])
PLUGIN;

    $content = preg_replace(
        '/(\n\s*->authMiddleware\(\[[\s\S]*?\]\))(\s*;)/',
        "$1\n{$plugin}$2",
        $content,
        1
    );
}

file_put_contents($panelProvider, $content);
PHP

# 9. Configuração de Middleware de Proxy
# Laravel 13 já vem com uma estrutura moderna, vamos apenas garantir o TrustProxies
sed -i "s|// \$middleware->trustProxies(|\$middleware->trustProxies(|" bootstrap/app.php 2>/dev/null

# 10. Assets
echo "🎨 Compilando Assets..."
npm install && npm run build

# 11. Finalização
echo "🏗️ Finalizando Base de Dados..."
php artisan key:generate
rm -f bootstrap/cache/*.php
php artisan optimize:clear

# Força migração e admin injetando env
env DB_CONNECTION=pgsql DB_HOST=$DB_HOST DB_PORT=$DB_PORT DB_DATABASE=$NOME DB_USERNAME=$NOME DB_PASSWORD="$SENHA" \
php artisan migrate --force

env DB_CONNECTION=pgsql DB_HOST=$DB_HOST DB_PORT=$DB_PORT DB_DATABASE=$NOME DB_USERNAME=$NOME DB_PASSWORD="$SENHA" \
php artisan make:filament-user --name="$ADMIN_NOME" --email="$ADMIN_EMAIL" --password="$ADMIN_SENHA"

env DB_CONNECTION=pgsql DB_HOST=$DB_HOST DB_PORT=$DB_PORT DB_DATABASE=$NOME DB_USERNAME=$NOME DB_PASSWORD="$SENHA" \
php artisan tinker --execute='$user = App\Models\User::where("email", "'"$ADMIN_EMAIL"'")->first(); $role = Spatie\Permission\Models\Role::firstOrCreate(["name" => config("filament-shield.super_admin.name", "super_admin"), "guard_name" => "web"]); if ($user) { $user->assignRole($role); } App\Models\SystemSetting::set("ai_system_provider", "openai"); App\Models\SystemSetting::set("ai_system_model", env("OPENAI_MODEL", "gpt-5-nano")); App\Models\SystemSetting::set("ai_system_key", env("OPENAI_API_KEY")); App\Services\FilamentShieldPermissionSyncService::sync();'

# Permissões
chown -R www-data:www-data .
chmod -R 775 storage bootstrap/cache

echo "------------------------------------------------------------"
echo "✅ INSTALAÇÃO TALL SUPREME V5 CONCLUÍDA! (POSTGRESQL + L13)"
echo "🌐 URL: $URL_BASE/$NOME/public/admin"
echo "👤 Acesso: $ADMIN_EMAIL / $ADMIN_SENHA"
echo "------------------------------------------------------------"
