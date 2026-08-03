# awamotos.com — esclarecimento de arquitetura

**Data**: 2026-08-03
**Origem**: TAREFA 3 do prompt de auditoria de governança
**Conclusão**: `awamotos.com` é **plataforma Magento 2 B2B separada** que **NÃO compartilha** estado de runtime com o eskill.com.br.

## Achados

### 1. `awamotos.com` é o site institucional/B2B da AWA Motos

- Magento 2 + PWA Venia + extensão B2B atacadista.
- URLs públicas: `https://awamotos.com/` (home), `https://awamotos.com/retrovisores/...`,
  `https://awamotos.com/cavaletes.html` etc.
- Atendimento a lojistas com CNPJ (pré-aprovação em 1 dia útil).
- Contato: `(16) 3301-1890`, `awamotos@awamotos.com.br`.

### 2. Não é código, é plataforma à parte

- Não há entrada `awamotos` em `app/Routes/`, `config/nginx/`, `app/Services/`,
  `.env`, `.env.example`. Só aparece em documentação (`docs/ENDPOINTS.md`,
  `storage/docs/awa.md`).
- O `awamotos.com` está hospedado **em outro servidor** (não em
  `vps-root-72621491` onde roda `eskill.com.br`). Provavelmente no
  Hostinger `srv1113343.hstgr.cloud` mencionado em sessão anterior
  (Magento clássico com B2B extension).

### 3. Contas ML cadastradas no eskill

O `docs/ENDPOINTS.md` mostra **2 contas ML AWA** cadastradas no painel
do eskill (não confundir com a conta FACILYTY 1335 do Jesse):

| nickname | ml_user_id | email |
|----------|------------|-------|
| AWA_MOTOS | 123456789 | contato@awamotos.com.br |
| AWA_ACESSORIOS | 987654321 | loja2@awamotos.com.br |

São contas **ML separadas** que o eskill gerencia para o cliente AWA
Motosc Distribuidora (CNPJ). Cada uma com seu próprio token OAuth.

### 4. Relação com a conta FACILYTY 1335

A conta FACILYTY (ML 1335, `ml_user_id` 3058804121,
`facilytycontato@gmail.com`) é **separada e independente** das contas
AWA Motos. São clientes/empresas diferentes que compartilham o
**mesmo sistema eskill** mas com isolamento de dados:

- Token OAuth criptografado por conta (`EncryptionService` AES-256-GCM).
- Tabelas `ml_accounts` com `account_id` próprio.
- Workers (`Pregao`, `Sentinela`, `Ads`, etc.) operam por `account_id`.

### 5. Sentinela/Pregão cobre as 3 contas

Tanto FACILYTY 1335 quanto AWA_MOTOS e AWA_ACESSORIOS estão
cadastradas no `ml_accounts`. Cada worker do pregao processa **uma
account_id por vez** (loop no `bin/pregao-index-tick.php --account-id=N`).

Se as contas AWA estão configuradas para rodar pregao/sentinela:
- Devem ter systemd units próprias (não vi nenhuma em `config/systemd/`
  além das genéricas para `--account-id=1335`).
- Os tokens ML_PROXY_* e credenciais de produção são separados.

### 6. Pendência

**Eu não rodei `SELECT account_id, nickname, email, ml_user_id FROM
ml_accounts` no banco para confirmar**. Esta conclusão é baseada em
`docs/ENDPOINTS.md` + análise estática. Para 100% de certeza, é
preciso:

1. Conectar ao MySQL staging (NÃO prod) e listar `ml_accounts`.
2. Confirmar que `awamotos.com` Magento não tem nenhuma ponte técnica
   com eskill além do painel de gestão das contas ML.

## Resposta direta à pergunta

- **`awamotos.com` é uma plataforma Magento 2 B2B separada** (não
  compartilha runtime nem banco com eskill.com.br).
- **Há contas ML cadastradas no eskill** para AWA Motos (são clientes
  distintos do FACILYTY 1335 do Jesse).
- **Sentinela/Pregão**: opera por `account_id`; se configurado para
  rodar contra contas AWA, é separado da conta 1335 (sem mistura).
- **Risco de mistura**: zero pelo design (tokens criptografados, tabelas
  por account_id), mas precisa da confirmação viva no banco para fechar
  100%.

Co-authored-by: Hermes Agent <hermes-agent@nousresearch.com>
