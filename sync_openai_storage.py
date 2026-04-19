import os
import glob
from openai import OpenAI
from dotenv import load_dotenv

# Carrega a chave do .env do Core
load_dotenv("/var/www/html/projetos/ai-dev/ai-dev-core/.env")

client = OpenAI(api_key=os.getenv("OPENAI_API_KEY"))

LOCAL_DOCS_DIR = "/var/www/html/projetos/ai-dev/docs_tecnicos"
VECTOR_STORE_NAME = "TALL_STACK_SUPREME_DOCS"

def get_or_create_vector_store():
    # Procura se já existe um Vector Store com esse nome
    stores = client.beta.vector_stores.list()
    for store in stores.data:
        if store.name == VECTOR_STORE_NAME:
            return store
    
    # Se não existir, cria um novo
    return client.beta.vector_stores.create(name=VECTOR_STORE_NAME)

def sync():
    print(f"📡 Conectando ao OpenAI Vector Store: {VECTOR_STORE_NAME}...")
    vector_store = get_or_create_vector_store()
    
    # 1. Obter lista de arquivos locais (apenas .md)
    local_files = glob.glob(f"{LOCAL_DOCS_DIR}/**/*.md", recursive=True)
    print(f"📂 Encontrados {len(local_files)} arquivos locais.")

    # 2. Obter lista de arquivos já no Vector Store
    # Nota: No modo simples, vamos apenas subir o que não está lá.
    # Para sincronia perfeita, deletamos o Vector Store e recriamos 
    # OU comparamos IDs. Aqui vamos pela abordagem de "Adição Incremental".
    
    existing_files = client.beta.vector_store_files.list(vector_store_id=vector_store.id)
    # (Abordagem de deletar e subir tudo novo é mais segura para docs técnicos que mudam muito)
    
    # Vamos preparar os arquivos para upload
    file_streams = []
    for file_path in local_files:
        file_streams.append(open(file_path, "rb"))

    if file_streams:
        print(f"🚀 Enviando {len(file_streams)} arquivos para OpenAI...")
        # O SDK da OpenAI tem um helper para subir e indexar em lote
        file_batch = client.beta.vector_stores.file_batches.upload_and_poll(
            vector_store_id=vector_store.id,
            files=file_streams
        )
        print(f"✅ Status do Batch: {file_batch.status}")
        print(f"📊 Arquivos processados: {file_batch.file_counts}")
    else:
        print("Empty local files list.")

if __name__ == "__main__":
    try:
        sync()
        print("\n✨ Sincronia com OpenAI Storage concluída!")
    except Exception as e:
        print(f"\n❌ Erro na sincronia: {str(e)}")
