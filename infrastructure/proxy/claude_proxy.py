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

# Cadeia de fallback (prioridade: opus -> sonnet -> haiku)
CLAUDE_FALLBACK_CHAIN = [
    "claude-opus-4-6",
    "claude-sonnet-4-6",
    "claude-haiku-4-5",
]

# Mapeamento do classificador para posição inicial na cadeia
CLAUDE_TIER = {
    "complex": 0,  # começa no opus
    "medium":  1,  # começa no sonnet
    "simple":  2,  # começa no haiku
}

# Palavras que sugerem tarefa complexa
_COMPLEX_KEYWORDS = [
    "arquitet", "refator", "debug", "analis", "otimiz", "algoritmo",
    "complex", "design pattern", "raciocin", "prove", "demonstre",
    "implemente um sistema", "projete", "compare profundamente",
    "architect", "refactor", "optimize", "reasoning", "prove ",
]
# Palavras que sugerem tarefa média
_MEDIUM_KEYWORDS = [
    "código", "code", "função", "function", "script", "bug", "erro",
    "explique", "explain", "escreva", "write", "traduz", "translate",
    "resum", "summar", "documenta", "teste", "test ",
]

def classify_claude_tier(prompt: str) -> str:
    """Classifica o prompt em complex/medium/simple."""
    if not prompt:
        return "simple"
    p = prompt.lower()
    length = len(prompt)

    complex_hits = sum(1 for k in _COMPLEX_KEYWORDS if k in p)
    medium_hits  = sum(1 for k in _MEDIUM_KEYWORDS if k in p)

    if length > 1500 or complex_hits >= 2 or (complex_hits >= 1 and length > 600):
        return "complex"
    if medium_hits >= 1 or complex_hits >= 1 or length > 250:
        return "medium"
    return "simple"

def _try_claude(prompt, model, session_id=None):
    """Tenta executar o Claude com um modelo específico. Retorna (texto, sucesso).

    Flags de segurança:
    - -p (--print): Modo não-interativo, sem confirmações
    - --tools "": Desabilita TODAS as ferramentas internas do Claude (Bash, Edit, etc.)
      A IA retorna apenas texto/JSON — toda execução real passa pelo ToolRouter do AI-Dev
    - --permission-mode plan: Modo read-only, impede qualquer escrita direta no OS/DB
    """
    try:
        cmd_parts = [
            "claude", "-p",
            "--model", model,
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
        # Sem output -> falha
        return stderr, False
    except subprocess.TimeoutExpired:
        return "Timeout", False
    except Exception as e:
        return str(e), False

def run_claude_with_fallback(prompt, session_id=None, model=None):
    """Executa o Claude com fallback automático pela cadeia de modelos."""
    errors = []

    if model:
        # Modelo explícito: tenta só esse
        text, ok = _try_claude(prompt, model, session_id)
        if ok:
            return text, session_id, model
        return f"Erro na geração de resposta pelo Claude ({model}): {text}", session_id, model

    # Auto-seleção: determina posição inicial e percorre a cadeia
    tier = classify_claude_tier(prompt)
    start_idx = CLAUDE_TIER[tier]

    for model_name in CLAUDE_FALLBACK_CHAIN[start_idx:]:
        text, ok = _try_claude(prompt, model_name, session_id)
        if ok:
            return text, session_id, model_name
        errors.append(f"{model_name}: {text[:120]}")

    # Todos falharam
    detail = " | ".join(errors)
    return f"Erro na geração de resposta pelo Claude. Todos os modelos falharam: {detail}", session_id, "none"

# --- ENDPOINTS API ---

@app.route('/v1/chat/completions', methods=['POST'])
def openai_chat():
    data = request.json
    session_id = data.get("session_id") or request.headers.get("X-Session-Id")
    req_model = data.get("model")
    if req_model in (None, "", "auto"):
        req_model = None

    messages = data.get("messages", [])
    user_msg = messages[-1].get("content", "oi") if messages else "oi"

    response_text, used_id, used_model = run_claude_with_fallback(user_msg, session_id, req_model)

    return jsonify({
        "id": "chatcmpl-claude-" + str(used_id or "new"),
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
    session_id = data.get("session_id") or request.headers.get("X-Session-Id")
    req_model = data.get("model")
    if req_model in (None, "", "auto"):
        req_model = None

    messages = data.get("messages", [])
    user_msg = messages[-1].get("content", "oi") if messages else "oi"

    response_text, used_id, used_model = run_claude_with_fallback(user_msg, session_id, req_model)

    return jsonify({
        "id": "msg-claude-" + str(used_id or "new"),
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
        prompt = " ".join(sys.argv[1:])
        res, sid, used = run_claude_with_fallback(prompt)
        print(f"ID: {sid}\nModel: {used}\n---\n{res}")
    else:
        print("Proxy Claude Ativo (Auto-Model + Fallback: opus->sonnet->haiku) na Porta 8002")
        app.run(port=8002, host='0.0.0.0', threaded=True)
