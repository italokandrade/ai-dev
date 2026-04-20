#!/bin/bash
# ============================================================
# Sincronizador de Conhecimento TALL STACK SUPREME (GitHub -> Local -> OpenAI)
# Execução: Todos os dias às 02:00 (America/Belem)
# ============================================================

DIRETORIO="/var/www/html/projetos/ai-dev"
# Ajustado para usar o diretório de logs do core, garantindo existência
LOG_DIR="$DIRETORIO/ai-dev-core/storage/logs"
LOG_FILE="$LOG_DIR/sync_docs.log"

# Garante que o diretório de logs existe
mkdir -p "$LOG_DIR"

echo "[$(date)] --- INICIANDO SINCRONIZAÇÃO COMPLETA ---" >> "$LOG_FILE"

# 1. Sincronizar GitHub -> Local
echo "[$(date)] Step 1: Sincronizando com Repositórios Oficiais..." >> "$LOG_FILE"
python3 "$DIRETORIO/baixar_docs_tall.py" >> "$LOG_FILE" 2>&1

# 2. Sincronizar Local -> OpenAI Storage
echo "[$(date)] Step 2: Sincronizando com OpenAI Vector Store..." >> "$LOG_FILE"
python3 "$DIRETORIO/sync_openai_storage.py" >> "$LOG_FILE" 2>&1

echo "[$(date)] --- SINCRONIZAÇÃO FINALIZADA COM SUCESSO ---" >> "$LOG_FILE"
echo "--------------------------------------------------------" >> "$LOG_FILE"
