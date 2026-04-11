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
URL_BASE="http://10.1.1.86/projetos"
ADMIN_EMAIL="contato@andradeitalo.ai"
ADMIN_SENHA="B1r02012;"
ADMIN_NOME="Italo Andrade"

if [ -z "$NOME" ] || [ -z "$SENHA" ]; then
    echo "Erro: Use ./instalar_projeto.sh [nome] [senha]"
    exit 1
fi

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
php artisan vendor:publish --tag=ai-config --tag=ai-migrations --no-interaction
php artisan vendor:publish --tag=mcp-config --no-interaction
echo "" >> .env
echo "OPENAI_API_KEY=\"sua_chave_openai_aqui\"" >> .env
echo "OPENAI_MODEL=\"gpt-5-nano\"" >> .env

# 8. Configuração de Middleware de Proxy
# Laravel 13 já vem com uma estrutura moderna, vamos apenas garantir o TrustProxies
sed -i "s|// \$middleware->trustProxies(|\$middleware->trustProxies(|" bootstrap/app.php 2>/dev/null

# 9. Assets
echo "🎨 Compilando Assets..."
npm install && npm run build

# 10. Finalização
echo "🏗️ Finalizando Base de Dados..."
php artisan key:generate
rm -f bootstrap/cache/*.php

# Força migração e admin injetando env
env DB_CONNECTION=pgsql DB_HOST=$DB_HOST DB_PORT=$DB_PORT DB_DATABASE=$NOME DB_USERNAME=$NOME DB_PASSWORD="$SENHA" \
php artisan migrate --force

env DB_CONNECTION=pgsql DB_HOST=$DB_HOST DB_PORT=$DB_PORT DB_DATABASE=$NOME DB_USERNAME=$NOME DB_PASSWORD="$SENHA" \
php artisan make:filament-user --name="$ADMIN_NOME" --email="$ADMIN_EMAIL" --password="$ADMIN_SENHA"

# Permissões
chown -R www-data:www-data .
chmod -R 775 storage bootstrap/cache

echo "------------------------------------------------------------"
echo "✅ INSTALAÇÃO TALL SUPREME V5 CONCLUÍDA! (POSTGRESQL + L13)"
echo "🌐 URL: $URL_BASE/$NOME/public/admin"
echo "👤 Acesso: $ADMIN_EMAIL / $ADMIN_SENHA"
echo "------------------------------------------------------------"
