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

# Modelo fixo: gemini-3.1-pro-preview
# Sem auto-seleção por palavras-chave — modelo único sempre
GEMINI_MODEL = "gemini-3.1-pro-preview"


def discover_latest_session_id():
    """Varre a lista de sessões do Gemini para pegar o UUID mais recente."""
    try:
        result = subprocess.run(["gemini", "--list-sessions"], capture_output=True, text=True, timeout=30)
        output = result.stdout
        uuids = re.findall(r'\[([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\]', output)
        if uuids:
            return uuids[0]
    except Exception:
        pass
    return None


def _try_gemini(prompt, session_id=None):
    """Executa o Gemini e retorna (texto, sucesso).

    -m GEMINI_MODEL → Modelo fixo: gemini-3.1-pro-preview
    -r session      → Retoma a conversa do projeto para manter contexto
    -p prompt       → Modo headless (não-interativo, sem confirmações)
    """
    try:
        resume_arg = session_id if session_id else "latest"
        cmd_parts = [
            "gemini",
            "-m", GEMINI_MODEL,
            "-r", resume_arg,
            "-p", prompt,
        ]

        result = subprocess.run(cmd_parts, capture_output=True, text=True, timeout=120)
        output = result.stdout or ""
        stderr = result.stderr or ""

        if result.returncode != 0 and not output:
            return stderr, False

        # Limpeza de ruído de log do CLI
        noise = [
            "Keychain initialization", "Using FileKeychain", "Loaded cached credentials",
            "Checking for updates", "Signed in with Google", "Resuming existing session",
            "No session found to resume", "Talking to Gemini API", "Full report available",
        ]
        lines = output.splitlines()
        clean_lines = []
        for line in lines:
            l = re.sub(r'\x1B(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~])', '', line)
            if not any(n in l for n in noise) and l.strip():
                clean_lines.append(l)

        final_text = "\n".join(clean_lines).strip()
        if final_text:
            return final_text, True

        return stderr or "Resposta vazia", False

    except subprocess.TimeoutExpired:
        return "Timeout", False
    except Exception as e:
        return str(e), False


def run_gemini(prompt, session_id=None):
    """Executa o Gemini com modelo fixo (gemini-3.1-pro-preview)."""
    text, ok = _try_gemini(prompt, session_id)
    used_sid = session_id or discover_latest_session_id()
    if ok:
        return text, used_sid, GEMINI_MODEL
    return f"Erro na geração de resposta pelo Gemini: {text}", used_sid, GEMINI_MODEL


# --- ENDPOINTS API ---

@app.route('/v1/chat/completions', methods=['POST'])
def openai_chat():
    data = request.json
    session_id = data.get("session_id")

    messages = data.get("messages", [])
    user_msg = messages[-1].get("content", "oi") if messages else "oi"
    if isinstance(user_msg, list):
        user_msg = " ".join([p.get("text", "") for p in user_msg if p.get("type") == "text"])

    response_text, used_id, used_model = run_gemini(user_msg, session_id)

    return jsonify({
        "id": "chatcmpl-" + str(used_id),
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
    session_id = data.get("session_id")

    messages = data.get("messages", [])
    user_msg = messages[-1].get("content", "oi") if messages else "oi"
    if isinstance(user_msg, list):
        user_msg = " ".join([p.get("text", "") for p in user_msg if p.get("type") == "text"])

    response_text, used_id, used_model = run_gemini(user_msg, session_id)

    return jsonify({
        "id": "msg-" + str(used_id),
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
        # Uso direto: python gemini_proxy.py "mensagem" [session_id]
        prompt = sys.argv[1]
        sid = sys.argv[2] if len(sys.argv) > 2 else None
        res, used_sid, used_model = run_gemini(prompt, sid)
        print(f"ID: {used_sid}\nModel: {used_model}\n---\n{res}")
    else:
        print(f"Proxy Gemini Ativo — Modelo: {GEMINI_MODEL} | Modo: plan (read-only) | Porta: 8001")
        app.run(port=8001, host='0.0.0.0', threaded=True)
