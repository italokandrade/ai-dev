import os
import subprocess
import shutil

# Configuração dos Repositórios
DOCS_MAP = {
    "laravel13-docs": {
        "repo": "https://github.com/laravel/docs.git",
        "branch": "master",
        "is_monorepo": False
    },
    "filament5-docs": {
        "repo": "https://github.com/filamentphp/filament.git",
        "branch": "5.x",
        "doc_path": "docs",
        "is_monorepo": True
    },
    "livewire4-docs": {
        "repo": "https://github.com/livewire/docs.git",
        "branch": "master",
        "is_monorepo": False
    },
    "alpine-docs": {
        "repo": "https://github.com/alpinejs/alpine.git",
        "branch": "main",
        "doc_path": "packages/docs",
        "is_monorepo": True
    },
    "tailwind-docs": {
        "repo": "https://github.com/tailwindlabs/tailwindcss.com.git",
        "branch": "master",
        "doc_path": "src/pages/docs",
        "is_monorepo": True
    },
    "animejs-docs": {
        "repo": "https://github.com/juliangarnier/anime.git",
        "branch": "master",
        "is_monorepo": False
    }
}

BASE_DIR = "/var/www/html/projetos/ai-dev/docs_tecnicos"

def run_command(cmd, cwd=None):
    try:
        # Usamos stderr=subprocess.STDOUT para capturar tudo
        subprocess.run(cmd, check=True, shell=True, cwd=cwd, capture_output=True, text=True)
        return True
    except subprocess.CalledProcessError as e:
        print(f"  ❌ Erro: {e.stderr.strip() if e.stderr else 'Erro desconhecido'}")
        return False

def sync_docs():
    if not os.path.exists(BASE_DIR):
        os.makedirs(BASE_DIR)

    for folder, info in DOCS_MAP.items():
        target_path = os.path.join(BASE_DIR, folder)
        print(f"🔄 Sincronizando: {folder}...")

        if not os.path.exists(os.path.join(target_path, ".git")):
            # --- CASO 1: Instalação Inicial (Clone) ---
            print(f"  📦 Inicializando novo repositório para {folder}...")
            if os.path.exists(target_path):
                shutil.rmtree(target_path)
            os.makedirs(target_path)

            if info.get("is_monorepo"):
                run_command(f"git clone --depth 1 --branch {info['branch']} --filter=blob:none --sparse {info['repo']} .", cwd=target_path)
                run_command(f"git sparse-checkout set {info['doc_path']}", cwd=target_path)
            else:
                run_command(f"git clone --depth 1 --branch {info['branch']} {info['repo']} .", cwd=target_path)
        else:
            # --- CASO 2: Sincronização Incremental (Update) ---
            print(f"  📡 Buscando atualizações (Pull)...")
            run_command("git fetch --depth 1 origin " + info['branch'], cwd=target_path)
            run_command("git reset --hard origin/" + info['branch'], cwd=target_path)
            run_command("git clean -fd", cwd=target_path)
            
            if info.get("is_monorepo"):
                # Garante que o sparse-checkout ainda está apontando para a pasta certa
                run_command(f"git sparse-checkout set {info['doc_path']}", cwd=target_path)

        # Para Monorepos, após o pull/clone, movemos o conteúdo da subpasta para a raiz do diretório do componente
        # para que a IA não tenha que navegar em estruturas complexas.
        if info.get("is_monorepo"):
            doc_full_path = os.path.join(target_path, info['doc_path'])
            if os.path.exists(doc_full_path):
                for item in os.listdir(doc_full_path):
                    s = os.path.join(doc_full_path, item)
                    d = os.path.join(target_path, item)
                    if os.path.exists(d):
                        if os.path.isdir(d): shutil.rmtree(d)
                        else: os.remove(d)
                    shutil.move(s, d)

        # Verifica quantidade de arquivos atuais
        files_count = len([f for f in os.listdir(target_path) if os.path.isfile(os.path.join(target_path, f))])
        print(f"  ✅ Sincronizado. Total de arquivos: {files_count}")

if __name__ == "__main__":
    print("=== MONITOR DE DOCUMENTAÇÃO TALL STACK (MODO SINCRONIA) ===")
    sync_docs()
    print(f"\n✨ Sincronização finalizada em: {BASE_DIR}")
