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

def run_claude_with_session(prompt, session_id=None):
    try:
        # Comando base: claude -p (print mode) usando exatamente 'claude-opus-4-6'
        cmd_parts = ["claude", "-p", "--model", "claude-opus-4-6"]
        
        if session_id:
            cmd_parts.extend(["--session-id", session_id])
        
        # O prompt vai no final
        cmd_parts.append(prompt)
        
        # Executa o comando
        result = subprocess.run(cmd_parts, capture_output=True, text=True, timeout=300)
        output = result.stdout or ""
        stderr = result.stderr or ""

        # Limpeza básica de ANSI codes (cores)
        clean_text = re.sub(r'\x1B(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~])', '', output).strip()
        
        if not clean_text and stderr:
            if "Claude Code" in stderr:
                return "Erro: O Claude Code iniciou mas não retornou texto. Verifique o login.", session_id
            return f"Erro no Claude Code: {stderr}", session_id

        return clean_text, session_id
    except subprocess.TimeoutExpired:
        return "Erro: Timeout na resposta do Claude Code.", session_id
    except Exception as e:
        return f"Erro ao executar Claude Proxy: {str(e)}", session_id

# --- ENDPOINTS API ---

@app.route('/v1/chat/completions', methods=['POST'])
def openai_chat():
    data = request.json
    session_id = data.get("session_id") or request.headers.get("X-Session-Id")
    
    messages = data.get("messages", [])
    user_msg = messages[-1].get("content", "oi") if messages else "oi"
    
    response_text, used_id = run_claude_with_session(user_msg, session_id)
    
    return jsonify({
        "id": "chatcmpl-claude-" + str(used_id or "new"),
        "object": "chat.completion",
        "created": int(time.time()),
        "model": "claude-opus-4-6",
        "session_id": used_id,
        "choices": [{"index": 0, "message": {"role": "assistant", "content": response_text}, "finish_reason": "stop"}]
    })

@app.route('/v1/messages', methods=['POST'])
@app.route('/v1/v1/messages', methods=['POST'])
def anthropic_messages():
    data = request.json
    session_id = data.get("session_id") or request.headers.get("X-Session-Id")
    
    messages = data.get("messages", [])
    user_msg = messages[-1].get("content", "oi") if messages else "oi"
    
    response_text, used_id = run_claude_with_session(user_msg, session_id)
    
    return jsonify({
        "id": "msg-claude-" + str(used_id or "new"),
        "type": "message",
        "role": "assistant",
        "model": "claude-opus-4-6",
        "session_id": used_id,
        "content": [{"type": "text", "text": response_text}],
        "stop_reason": "end_turn",
        "usage": {"input_tokens": 0, "output_tokens": 0}
    })

if __name__ == '__main__':
    if len(sys.argv) > 1:
        prompt = " ".join(sys.argv[1:])
        res, sid = run_claude_with_session(prompt)
        print(f"ID: {sid}\n---\n{res}")
    else:
        print("Proxy Claude Code Ativo (Model: claude-opus-4-6) na Porta 8002")
        app.run(port=8002, host='0.0.0.0', threaded=True)
