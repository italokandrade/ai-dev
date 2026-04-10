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

def run_gemini_with_session(prompt, session_id=None):
    try:
        # Se não temos um ID, usamos 'latest' para criar ou pegar a última
        resume_arg = session_id if session_id else "latest"
        
        escaped_prompt = prompt.replace("'", "'\\''")
        cmd = f"script -q -c \"gemini -r {resume_arg} -p '{escaped_prompt}'\" /dev/null"
        
        result = subprocess.run(["bash", "-c", cmd], capture_output=True, text=True, timeout=120)
        output = result.stdout or ""
        
        # Se não enviamos session_id, tentamos descobrir qual foi usada/criada
        used_session_id = session_id
        if not used_session_id:
            used_session_id = discover_latest_session_id()

        # Limpeza de ruído
        noise = [
            "Keychain initialization", "Using FileKeychain", "Loaded cached credentials",
            "Checking for updates", "Signed in with Google", "Resuming existing session",
            "No session found to resume"
        ]
        
        lines = output.splitlines()
        clean_lines = []
        for line in lines:
            l = re.sub(r'\x1B(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~])', '', line)
            if not any(n in l for n in noise) and l.strip():
                clean_lines.append(l)
        
        return "\n".join(clean_lines).strip(), used_session_id
    except subprocess.TimeoutExpired:
        return "Erro: Timeout na resposta do Gemini.", session_id
    except Exception as e:
        return f"Erro ao executar Gemini: {str(e)}", session_id

# --- ENDPOINTS API ---

@app.route('/v1/chat/completions', methods=['POST'])
def openai_chat():
    data = request.json
    session_id = data.get("session_id") or request.headers.get("X-Session-Id")
    
    messages = data.get("messages", [])
    user_msg = messages[-1].get("content", "oi") if messages else "oi"
    if isinstance(user_msg, list):
        user_msg = " ".join([p.get("text", "") for p in user_msg if p.get("type") == "text"])
    
    response_text, used_id = run_gemini_with_session(user_msg, session_id)
    
    return jsonify({
        "id": "chatcmpl-" + str(used_id),
        "object": "chat.completion",
        "created": int(time.time()),
        "model": "claude-3-5-sonnet-20241022",
        "session_id": used_id, # Retorna o ID para o Laravel salvar
        "choices": [{"index": 0, "message": {"role": "assistant", "content": response_text}, "finish_reason": "stop"}]
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
    
    response_text, used_id = run_gemini_with_session(user_msg, session_id)
    
    return jsonify({
        "id": "msg-" + str(used_id),
        "type": "message",
        "role": "assistant",
        "model": "claude-3-5-sonnet-20241022",
        "session_id": used_id, # Retorna o ID para o Laravel salvar
        "content": [{"type": "text", "text": response_text}],
        "stop_reason": "end_turn",
        "usage": {"input_tokens": 0, "output_tokens": 0}
    })

@app.route('/v1/models', methods=['GET'])
def list_models():
    return jsonify({"object": "list", "data": [{"id": "claude-3-5-sonnet-20241022", "object": "model", "owned_by": "anthropic"}]})

if __name__ == '__main__':
    if len(sys.argv) > 1:
        prompt = " ".join(sys.argv[1:])
        res, sid = run_gemini_with_session(prompt)
        print(f"ID: {sid}\n---\n{res}")
    else:
        print("Proxy Gemini Ativo (Stateless - Esperando Session ID na Request) na Porta 8001")
        app.run(port=8001, host='0.0.0.0', threaded=True)
