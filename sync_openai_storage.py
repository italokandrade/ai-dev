import os
import glob
import time
import httpx
from dotenv import load_dotenv

# Carrega a chave do .env
load_dotenv("/var/www/html/projetos/ai-dev/ai-dev-core/.env")

API_KEY = os.getenv("OPENAI_API_KEY")
BASE_URL = "https://api.openai.com/v1"
HEADERS = {
    "Authorization": f"Bearer {API_KEY}",
    "OpenAI-Beta": "assistants=v2"
}

LOCAL_DOCS_DIR = "/var/www/html/projetos/ai-dev/docs_tecnicos"
VECTOR_STORE_NAME = "TALL_STACK_SUPREME_DOCS"

# Lista oficial de extensões suportadas pela OpenAI Vector Store
SUPPORTED_EXTENSIONS = [
    ".art", ".bat", ".brf", ".c", ".cls", ".cpp", ".cs", ".css", ".csv", ".diff", 
    ".doc", ".docx", ".dot", ".eml", ".es", ".gif", ".go", ".h", ".hs", ".htm", 
    ".html", ".hwp", ".hwpx", ".ics", ".ifb", ".java", ".jpeg", ".jpg", ".js", 
    ".json", ".keynote", ".ksh", ".ltx", ".mail", ".markdown", ".md", ".mht", 
    ".mhtml", ".mjs", ".nws", ".odt", ".pages", ".patch", ".pdf", ".php", ".pkl", 
    ".pl", ".pm", ".png", ".pot", ".ppa", ".pps", ".ppt", ".pptx", ".pwz", ".py", 
    ".rb", ".rst", ".rtf", ".scala", ".sh", ".shtml", ".srt", ".sty", ".svg", 
    ".svgz", ".tar", ".tex", ".text", ".ts", ".txt", ".vcf", ".vtt", ".webp", 
    ".wiz", ".xla", ".xlb", ".xlc", ".xlm", ".xls", ".xlsx", ".xlt", ".xlw", 
    ".xml", ".yaml", ".yml", ".zip"
]

def get_or_create_vector_store():
    with httpx.Client(headers=HEADERS, timeout=30) as client:
        res = client.get(f"{BASE_URL}/vector_stores")
        res.raise_for_status()
        stores = res.json().get("data", [])
        for store in stores:
            if store.get("name") == VECTOR_STORE_NAME:
                return store["id"]
        res = client.post(f"{BASE_URL}/vector_stores", json={"name": VECTOR_STORE_NAME})
        return res.json()["id"]

def list_openai_files_in_store(store_id):
    files_map = {}
    with httpx.Client(headers=HEADERS, timeout=60) as client:
        res = client.get(f"{BASE_URL}/vector_stores/{store_id}/files", params={"limit": 100})
        if res.status_code != 200: return {}
        
        vs_files = res.json().get("data", [])
        for vs_file in vs_files:
            fid = vs_file["id"]
            f_res = client.get(f"{BASE_URL}/files/{fid}")
            if f_res.status_code == 200:
                f_data = f_res.json()
                # Armazenamos o nome como ele aparece na OpenAI
                files_map[f_data["filename"]] = fid
    return files_map

def upload_file(file_path, display_name):
    # A engenharia reversa ocorre aqui: display_name já vem como o nome 'virtual' esperado
    with httpx.Client(headers={"Authorization": f"Bearer {API_KEY}", "OpenAI-Beta": "assistants=v2"}, timeout=60) as client:
        try:
            with open(file_path, "rb") as f:
                res = client.post(
                    f"{BASE_URL}/files",
                    data={"purpose": "assistants"},
                    files={"file": (display_name, f)}
                )
                if res.status_code != 200:
                    print(f"    ❌ Erro upload {display_name}: {res.text}")
                    return None
                return res.json()["id"]
        except Exception as e:
            print(f"    ❌ Erro ao ler {display_name}: {str(e)}")
            return None

def add_file_to_store(vector_store_id, file_id):
    with httpx.Client(headers=HEADERS) as client:
        res = client.post(f"{BASE_URL}/vector_stores/{vector_store_id}/files", json={"file_id": file_id})
        return res.status_code == 200

def sync():
    print(f"📡 Sincronia TOTAL de Conhecimento: {VECTOR_STORE_NAME}")
    store_id = get_or_create_vector_store()
    
    # 1. Mapear arquivos locais
    all_files = []
    for root, dirs, files in os.walk(LOCAL_DOCS_DIR):
        if '.git' in dirs:
            dirs.remove('.git')
        for file in files:
            full_path = os.path.join(root, file)
            # Ignora arquivos minúsculos (falham na indexação)
            if os.path.getsize(full_path) < 20:
                continue
            all_files.append(full_path)

    local_files = {}
    for path in all_files:
        # Gera o nome base (substituindo / por __)
        rel_path = os.path.relpath(path, LOCAL_DOCS_DIR).replace("/", "__")
        
        # APLICAR ENGENHARIA REVERSA NO MAPEAMENTO
        # Se for .d.ts, mapeamos como .ts para que a comparação com a OpenAI seja idêntica
        virtual_name = rel_path
        if virtual_name.endswith(".d.ts"):
            virtual_name = virtual_name.replace(".d.ts", ".ts")
        
        # Filtro final de extensões (com base no nome virtual que será enviado)
        ext = os.path.splitext(virtual_name)[1].lower()
        if ext in SUPPORTED_EXTENSIONS:
            local_files[virtual_name] = path

    print(f"📂 {len(local_files)} arquivos identificados no diretório de DOCS.")

    # 2. Mapear arquivos na OpenAI
    print("🔍 Consultando estado atual na OpenAI...")
    openai_files = list_openai_files_in_store(store_id)
    
    # 3. Identificar o que SUBIR (Comparação 1:1 de nomes virtuais)
    to_upload = sorted(list(set(local_files.keys()) - set(openai_files.keys())))
    
    if to_upload:
        print(f"🚀 Enviando {len(to_upload)} novos arquivos compatíveis...")
        for name in to_upload:
            # O 'name' aqui já é o virtual_name (ex: ...__index.ts em vez de .d.ts)
            fid = upload_file(local_files[name], name)
            if fid:
                if add_file_to_store(store_id, fid):
                    print(f"    + Enviado: {name}")
                else:
                    print(f"    ⚠️ Erro ao vincular {name} à Vector Store.")
            time.sleep(0.2)
    else:
        print("✅ OpenAI já possui todos os arquivos da pasta DOCS.")

if __name__ == "__main__":
    try:
        sync()
        print("\n✨ Sincronia de documentação completa finalizada!")
    except Exception as e:
        print(f"❌ Erro: {str(e)}")
