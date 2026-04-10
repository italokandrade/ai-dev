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

# Cadeia de fallback (prioridade: 3.1-pro -> 3.1-flash-lite -> 2.5-flash)
GEMINI_FALLBACK_CHAIN = [
    "gemini-3.1-pro-preview",
    "gemini-3.1-flash-lite-preview",
    "gemini-2.5-flash",
]

# Mapeamento do classificador para posição inicial na cadeia
GEMINI_TIER = {
    "complex": 0,  # começa no 3.1-pro
    "medium":  1,  # começa no 3.1-flash-lite
    "simple":  2,  # começa no 2.5-flash
}

_GEMINI_COMPLEX_KEYWORDS = [
    "arquitet", "refator", "debug", "analis", "otimiz", "algoritmo",
    "complex", "raciocin", "prove", "demonstre", "projete",
    "architect", "refactor", "optimize", "reasoning",
    "implemente um sistema", "compare profundamente",
]
_GEMINI_TECH_KEYWORDS = [
    "código", "code", "função", "function", "script", "bug", "erro",
    "explique", "explain", "escreva", "write", "teste", "test ",
    "documenta", "traduz", "translate",
]

def classify_gemini_tier(prompt: str) -> str:
    """Classifica o prompt em complex/medium/simple."""
    if not prompt:
        return "simple"
    p = prompt.lower()
    length = len(prompt)

    complex_hits = sum(1 for k in _GEMINI_COMPLEX_KEYWORDS if k in p)
    tech_hits    = sum(1 for k in _GEMINI_TECH_KEYWORDS if k in p)

    if length > 1500 or complex_hits >= 2 or (complex_hits >= 1 and length > 500):
        return "complex"
    if (tech_hits >= 2 and length > 400) or complex_hits >= 1:
        return "medium"
    return "simple"

def discover_latest_session_id():
    """Varre a lista de sessões do Gemini para pegar o UUID mais recente."""
    try:
        result = subprocess.run(["gemini", "--list-sessions"], capture_output=True, text=True, timeout=30)
        output = result.stdout
        uuids = re.findall(r'\[([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\]', output)
        if uuids:
            return uuids[0]
    except:
        pass
    return None

def _try_gemini(prompt, model, session_id=None):
    """Tenta executar o Gemini com um modelo específico. Retorna (texto, sucesso).

    Flags de segurança:
    - -p (--prompt): Modo não-interativo (headless), sem confirmações
    - --sandbox: Modo sandboxed, impede acesso direto ao filesystem/OS
    - --approval-mode plan: Modo read-only, a IA só retorna texto/JSON
      Toda execução real de comandos passa pelo ToolRouter do AI-Dev
    """
    try:
        resume_arg = session_id if session_id else "latest"
        cmd_parts = ["gemini", "--approval-mode", "plan"]
        if model:
            cmd_parts.extend(["-m", model])
        cmd_parts.extend(["-r", resume_arg, "-p", prompt])

        result = subprocess.run(cmd_parts, capture_output=True, text=True, timeout=120)
        output = result.stdout or ""
        stderr = result.stderr or ""

        if result.returncode != 0 and not output:
            return stderr, False

        # Limpeza de ruído
        noise = [
            "Keychain initialization", "Using FileKeychain", "Loaded cached credentials",
            "Checking for updates", "Signed in with Google", "Resuming existing session",
            "No session found to resume", "Talking to Gemini API", "Full report available"
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
        # Sem output -> falha
        return stderr or "Resposta vazia", False
    except subprocess.TimeoutExpired:
        return "Timeout", False
    except Exception as e:
        return str(e), False

def run_gemini_with_fallback(prompt, session_id=None, model=None):
    """Executa o Gemini com fallback automático pela cadeia de modelos."""
    errors = []

    if model:
        # Modelo explícito: tenta só esse
        text, ok = _try_gemini(prompt, model, session_id)
        used_sid = session_id or discover_latest_session_id()
        if ok:
            return text, used_sid, model
        return f"Erro na geração de resposta pelo Gemini ({model}): {text}", used_sid, model

    # Auto-seleção: determina posição inicial e percorre a cadeia
    tier = classify_gemini_tier(prompt)
    start_idx = GEMINI_TIER[tier]

    for model_name in GEMINI_FALLBACK_CHAIN[start_idx:]:
        text, ok = _try_gemini(prompt, model_name, session_id)
        if ok:
            used_sid = session_id or discover_latest_session_id()
            return text, used_sid, model_name
        errors.append(f"{model_name}: {text[:120]}")

    # Todos falharam
    used_sid = session_id or discover_latest_session_id()
    detail = " | ".join(errors)
    return f"Erro na geração de resposta pelo Gemini. Todos os modelos falharam: {detail}", used_sid, "none"

# --- ENDPOINTS API ---

@app.route('/v1/chat/completions', methods=['POST'])
def openai_chat():
    data = request.json
    session_id = data.get("session_id")
    model = data.get("model")

    messages = data.get("messages", [])
    user_msg = messages[-1].get("content", "oi") if messages else "oi"
    if isinstance(user_msg, list):
        user_msg = " ".join([p.get("text", "") for p in user_msg if p.get("type") == "text"])

    if model in (None, "", "auto"):
        model = None
    response_text, used_id, used_model = run_gemini_with_fallback(user_msg, session_id, model)

    return jsonify({
        "id": "chatcmpl-" + str(used_id),
        "object": "chat.completion",
        "created": int(time.time()),
        "model": used_model,
        "session_id": used_id,
        "choices": [{"index": 0, "message": {"role": "assistant", "content": response_text}, "finish_reason": "stop"}]
    })

@app.route('/v1/messages', methods=['POST'])
@app.route('/v1/v1/messages', methods=['POST'])
def anthropic_messages():
    data = request.json
    session_id = data.get("session_id")
    model = data.get("model")

    messages = data.get("messages", [])
    user_msg = messages[-1].get("content", "oi") if messages else "oi"
    if isinstance(user_msg, list):
        user_msg = " ".join([p.get("text", "") for p in user_msg if p.get("type") == "text"])

    if model in (None, "", "auto"):
        model = None
    response_text, used_id, used_model = run_gemini_with_fallback(user_msg, session_id, model)

    return jsonify({
        "id": "msg-" + str(used_id),
        "type": "message",
        "role": "assistant",
        "model": used_model,
        "session_id": used_id,
        "content": [{"type": "text", "text": response_text}],
        "stop_reason": "end_turn",
        "usage": {"input_tokens": 0, "output_tokens": 0}
    })

if __name__ == '__main__':
    if len(sys.argv) > 1:
        prompt = sys.argv[1]
        model = sys.argv[2] if len(sys.argv) > 2 else None
        sid = sys.argv[3] if len(sys.argv) > 3 else None
        res, used_sid, used_model = run_gemini_with_fallback(prompt, sid, model)
        print(f"ID: {used_sid}\nModel: {used_model}\n---\n{res}")
    else:
        print("Proxy Gemini Ativo (Auto-Model + Fallback: 3.1-pro->3.1-flash-lite->2.5-flash) na Porta 8001")
        app.run(port=8001, host='0.0.0.0', threaded=True)
