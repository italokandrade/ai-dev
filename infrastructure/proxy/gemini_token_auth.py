import json
import requests
import time
import os
import sys

CREDS_PATH = os.path.expanduser("~/.gemini/oauth_creds.json")
CLIENT_ID = os.getenv("GOOGLE_CLIENT_ID", "")
CLIENT_SECRET = os.getenv("GOOGLE_CLIENT_SECRET", "")

def exchange_code(auth_code):
    data = {
        'client_id': CLIENT_ID,
        'client_secret': CLIENT_SECRET,
        'code': auth_code,
        'grant_type': 'authorization_code',
        'redirect_uri': 'http://127.0.0.1:8080'  # Padrão usado pela CLI
    }
    
    print("Trocando código de autorização por tokens...")
    response = requests.post('https://oauth2.googleapis.com/token', data=data)
    
    if response.status_code == 200:
        new_creds = response.json()
        
        # Mantém estrutura compatível com a CLI do Gemini
        creds = {
            "access_token": new_creds['access_token'],
            "scope": new_creds.get('scope', ''),
            "token_type": new_creds.get('token_type', 'Bearer'),
            "id_token": new_creds.get('id_token', ''),
            "expiry_date": int(time.time() * 1000) + new_creds['expires_in'] * 1000,
            "refresh_token": new_creds['refresh_token']
        }
        
        os.makedirs(os.path.dirname(CREDS_PATH), exist_ok=True)
        with open(CREDS_PATH, 'w') as f:
            json.dump(creds, f, indent=2)
            
        print(f"Sucesso! Credenciais salvas em {CREDS_PATH}.")
        print("O OpenCLAUDE (Proxy Gemini) já pode usar a API.")
    else:
        print("Falha ao trocar o token:")
        print(response.status_code, response.json())

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Uso: python3 gemini_token_auth.py <SEU_CODIGO_COMEÇANDO_COM_4/>")
        sys.exit(1)
        
    exchange_code(sys.argv[1])
