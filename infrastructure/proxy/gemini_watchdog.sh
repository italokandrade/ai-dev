#!/bin/bash

# Watchdog para garantir que os proxies (Bun, Gemini Python e Claude Python) estejam sempre rodando
# Localização oficial: /var/www/html/projetos/ai-dev/infrastructure/proxy/

DIR="/var/www/html/projetos/ai-dev/infrastructure/proxy"

while true; do
    # Verifica Proxy Gemini Python (8001)
    if ! pgrep -f "gemini_proxy.py" > /dev/null; then
        echo "[$(date)] Iniciando gemini_proxy.py..."
        nohup /root/venv/bin/python3 $DIR/gemini_proxy.py >> $DIR/gemini_proxy_py.log 2>&1 &
    fi

    # Verifica Proxy Claude Python (8002)
    if ! pgrep -f "claude_proxy.py" > /dev/null; then
        echo "[$(date)] Iniciando claude_proxy.py..."
        nohup /root/venv/bin/python3 $DIR/claude_proxy.py >> $DIR/claude_proxy_py.log 2>&1 &
    fi

    # Verifica Proxy Bun (8000)
    if ! pgrep -f "gemini_proxy.js" > /dev/null; then
        echo "[$(date)] Iniciando gemini_proxy.js..."
        nohup bun $DIR/gemini_proxy.js >> $DIR/gemini_proxy_bun.log 2>&1 &
    fi

    sleep 5
done
