import os
import json
import subprocess
import time
import sys
import re
from flask import Flask, request, jsonify
from flask_cors import CORS

app = Flask(__name__)
CORS(app)

# Modelo fixo: claude-sonnet-4-6 (primário)
# Advisor/fallback: claude-opus-4-6 — ativado automaticamente pelo --fallback-model
# Sem auto-seleção por palavras-chave — modelo único sempre
CLAUDE_MODEL = "claude-sonnet-4-6"
CLAUDE_ADVISOR = "claude-opus-4-6"


def _try_claude(prompt, session_id=None):
    """Executa o Claude e retorna (texto, sucesso).

    Flags de segurança obrigatórias:
    -p (--print)             → Modo não-interativo, sem confirmações, saída direta
    --model CLAUDE_MODEL     → Modelo fixo: claude-sonnet-4-6
    --fallback-model ADVISOR → Advisor: claude-opus-4-6 (escala automaticamente
                               quando Sonnet está sobrecarregado ou para tarefas
                               que exigem mais raciocínio — equivalente ao /advisor
                               do Claude Code)
    --tools ""               → Desabilita TODAS as ferramentas internas do Claude
                               Code (Bash, Edit, Read, Write, etc.). A IA retorna
                               apenas texto — toda execução real passa pelas Tools
                               do AI-Dev (ShellTool, FileTool, etc.) com sandboxing
                               próprio. Sem tools, nenhuma manipulação direta do SO
                               é possível — arquivos, permissões, processos, etc.
    --permission-mode plan   → Modo read-only: impede qualquer escrita direta no
                               sistema operacional. Combinado com --tools "" garante
                               isolamento total. Nenhuma confirmação é solicitada —
                               o modelo processa e responde sem interrupções.
    """
    try:
        cmd_parts = [
            "claude", "-p",
            "--model", CLAUDE_MODEL,
            "--fallback-model", CLAUDE_ADVISOR,
            "--tools", "",
            "--permission-mode", "plan",
        ]
        if session_id:
            cmd_parts.extend(["--session-id", session_id])
        cmd_parts.append(prompt)

        result = subprocess.run(cmd_parts, capture_output=True, text=True, timeout=300)
        output = result.stdout or ""
        stderr = result.stderr or ""

        clean_text = re.sub(r'\x1B(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~])', '', output).strip()

        if clean_text:
            return clean_text, True
        return stderr, False

    except subprocess.TimeoutExpired:
        return "Timeout", False
    except Exception as e:
        return str(e), False


def run_claude(prompt, session_id=None):
    """Executa o Claude com modelo fixo (claude-sonnet-4-6) e advisor (claude-opus-4-6)."""
    text, ok = _try_claude(prompt, session_id)
    if ok:
        return text, session_id, CLAUDE_MODEL
    return f"Erro na geração de resposta pelo Claude: {text}", session_id, CLAUDE_MODEL


# --- ENDPOINTS API ---

@app.route('/v1/chat/completions', methods=['POST'])
def openai_chat():
    data = request.json
    session_id = data.get("session_id") or request.headers.get("X-Session-Id")

    messages = data.get("messages", [])
    user_msg = messages[-1].get("content", "oi") if messages else "oi"
    if isinstance(user_msg, list):
        user_msg = " ".join([p.get("text", "") for p in user_msg if p.get("type") == "text"])

    response_text, used_id, used_model = run_claude(user_msg, session_id)

    return jsonify({
        "id": "chatcmpl-claude-" + str(used_id or "new"),
        "object": "chat.completion",
        "created": int(time.time()),
        "model": used_model,
        "session_id": used_id,
        "choices": [{"index": 0, "message": {"role": "assistant", "content": response_text}, "finish_reason": "stop"}],
    })


@app.route('/v1/messages', methods=['POST'])
@app.route('/v1/v1/messages', methods=['POST'])
def anthropic_messages():
    data = request.json
    session_id = data.get("session_id") or request.headers.get("X-Session-Id")

    messages = data.get("messages", [])
    user_msg = messages[-1].get("content", "oi") if messages else "oi"
    if isinstance(user_msg, list):
        user_msg = " ".join([p.get("text", "") for p in user_msg if p.get("type") == "text"])

    response_text, used_id, used_model = run_claude(user_msg, session_id)

    return jsonify({
        "id": "msg-claude-" + str(used_id or "new"),
        "type": "message",
        "role": "assistant",
        "model": used_model,
        "session_id": used_id,
        "content": [{"type": "text", "text": response_text}],
        "stop_reason": "end_turn",
        "usage": {"input_tokens": 0, "output_tokens": 0},
    })


if __name__ == '__main__':
    if len(sys.argv) > 1:
        # Uso direto: python claude_proxy.py "mensagem" [session_id]
        prompt = sys.argv[1]
        sid = sys.argv[2] if len(sys.argv) > 2 else None
        res, used_sid, used_model = run_claude(prompt, sid)
        print(f"ID: {used_sid}\nModel: {used_model}\n---\n{res}")
    else:
        print(f"Proxy Claude Ativo — Modelo: {CLAUDE_MODEL} | Advisor: {CLAUDE_ADVISOR} | Modo: plan (read-only) | Porta: 8002")
        app.run(port=8002, host='0.0.0.0', threaded=True)
