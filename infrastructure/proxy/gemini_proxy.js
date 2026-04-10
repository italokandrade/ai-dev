import { serve } from "bun";

const BACKEND_URL = "http://localhost:8001";

serve({
  port: 8000,
  async fetch(req) {
    const url = new URL(req.url);
    const pathname = url.pathname;
    
    // Roteamento inteligente para evitar prefixos duplicados /v1/v1
    let targetPath = pathname;
    if (pathname.startsWith("/v1/v1/")) {
        targetPath = pathname.substring(3); // Remove o primeiro /v1
    }

    console.log(`[Bun] Forwarding ${req.method} ${pathname} -> ${BACKEND_URL}${targetPath}`);

    try {
      const resp = await fetch(`${BACKEND_URL}${targetPath}`, {
        method: req.method,
        headers: req.headers,
        body: req.method === "POST" ? await req.text() : undefined
      });
      
      return resp;
    } catch (e) {
      console.error(`[Bun] Backend unreachable: ${e.message}`);
      return new Response(JSON.stringify({ error: "Backend proxy (8001) is down" }), { 
        status: 502,
        headers: { "Content-Type": "application/json" }
      });
    }
  },
});

console.log("Orquestrador de Entrada (Nível 1) rodando em http://localhost:8000");
