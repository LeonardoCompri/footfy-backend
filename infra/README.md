# Infraestrutura do Footfy

Arquivos que sobem o Footfy no servidor da Analitix (Ubuntu + Docker + Tailscale Funnel). O `compose.yaml` da raiz do repositório é o Sail, só para desenvolvimento, e não é usado aqui.

```
infra/
  docker-compose.yml       db, backend e web; um compose para todos os ambientes
  env.template             modelo do .env de cada ambiente (o setup.sh preenche)
  gateway.conf.template    rota do ambiente no portão (só quando há caminho, ex.: /footfy)
  setup.sh                 primeira instalação de um ambiente (pode rodar de novo)
  deploy.sh                deploy.sh backend|frontend|all
  backup.sh                dump do banco + uploads em <pasta>/backups
  docker/backend/          Dockerfile, entrypoint.sh e php.ini da imagem do backend
```

A imagem do web (Vue + Nginx) fica no repositório `footfy-frontend`, em `infra/docker/web/`.

## Pastas no servidor

Cada ambiente é uma pasta com o `.env`, os dois clones e o atalho `deploy.sh`:

```
~/analitix/
  gateway/                 portão (Nginx) com projects/footfy.conf
  footfy/                  produção (o nome da pasta não muda)
    .env  .deploy.lock  backups/
    deploy.sh              atalho de 1 linha para footfy-backend/infra/deploy.sh (os workflows chamam este caminho)
    footfy-backend/        branch main
    footfy-frontend/       branch main
  footfy-staging/          staging (FUTY-0018): mesma estrutura, branch staging
```

Os scripts descobrem o ambiente pela pasta onde estão: `<pasta>/footfy-backend/infra/deploy.sh` mexe só em `<pasta>`, lendo o `<pasta>/.env`. Não há argumento de ambiente.

Todo comando do compose usa a mesma forma (rodar dentro da pasta do ambiente):

```bash
cd ~/analitix/footfy
docker compose -p footfy -f footfy-backend/infra/docker-compose.yml --env-file .env <comando>
```

No staging, troque `-p footfy` por `-p footfy-staging`. Variáveis exportadas no shell têm prioridade sobre o `--env-file`; não exporte `IMAGE_TAG`, `WEB_PORT` etc. na sessão do servidor nem no runner.

## Variáveis do `.env`

Um `.env` por pasta, que serve ao compose e ao Laravel (montado em `/var/www/html/.env`, somente leitura).

| Variável | Produção | Staging | Para quê |
|---|---|---|---|
| `COMPOSE_PROJECT_NAME` | `footfy` (**não mudar**) | `footfy-staging` | Nome do projeto do compose. Os volumes reais da produção são `footfy_db-data` e `footfy_backend-storage`; outro nome cria volumes vazios e roda o seed de demonstração |
| `DEPLOY_BRANCH` | `main` | `staging` | Branch que o `deploy.sh` publica |
| `IMAGE_TAG` | `production` | `staging` | Tag das imagens `footfy/backend` e `footfy/web`; o rollback usa `${IMAGE_TAG}-previous` |
| `APP_ENV` | `production` | `staging` | Só a produção confere o host nas rotas públicas |
| `APP_BASE_PATH` | `/footfy` | vazio | Caminho do site. Vazio = site na raiz, sem rota no portão |
| `APP_URL` | `https://<host>.ts.net/footfy` | `https://<host>.ts.net:8443` | Montado pelo `setup.sh` |
| `PUBLIC_ORIGIN` | `<host>.ts.net` | `<host>.ts.net` | Host repassado ao Laravel pelo Nginx do web |
| `PUBLIC_PORT` | vazio | `:8443` | Porta pública do Funnel (entra no `APP_URL`) |
| `WEB_PORT` | `8081` | `8082` | Porta do web em `127.0.0.1` |
| `DB_FORWARD_PORT` | `3307` | `3308` | Porta do MySQL em `127.0.0.1` (túnel SSH) |
| `SEED_ON_FIRST_RUN` | `true` (hoje) | `true` | Seed de demonstração na primeira subida (marcador `storage/app/.seeded`) |

Sem as variáveis novas, o compose usa os valores de hoje: projeto `footfy`, tag `latest`, portas 8081 e 3307 e caminho `/footfy`. O `deploy.sh` e o `setup.sh` exigem `COMPOSE_PROJECT_NAME`, `DEPLOY_BRANCH` e `IMAGE_TAG` no `.env`, para um ambiente nunca cair no projeto do outro.

Ainda não entram (tarefas seguintes): `SEED_PASSWORD` (FUTY-0018), `BACKUP_REMOTE` (FD-0e), `COMPOSER_NO_DEV` e `SEED_ON_FIRST_RUN=false` na produção (FD-0c).

## Primeira instalação (`setup.sh`)

```bash
mkdir -p ~/analitix/footfy && cd ~/analitix/footfy
git clone https://github.com/LeonardoCompri/footfy-backend.git footfy-backend
footfy-backend/infra/setup.sh production
```

O `setup.sh production|staging`:
1. cria o atalho `<pasta>/deploy.sh` (recusa se já existe um `deploy.sh` diferente: mova-o para `_legacy/` antes);
2. clona o `footfy-frontend` na branch do ambiente, se faltar;
3. gera o `.env` pelo `env.template` com senhas aleatórias, só se ele não existir. Se existir, mantém senhas e chave, confere `COMPOSE_PROJECT_NAME`/`DEPLOY_BRANCH`/`IMAGE_TAG` e só atualiza `APP_URL` e `PUBLIC_ORIGIN`;
4. roda `up -d --build` e espera o `/up`;
5. com `APP_BASE_PATH` preenchido (produção), gera `gateway/projects/<projeto>.conf` e chama o `reload.sh` do portão. Sem caminho (staging), só imprime o comando do Funnel: `sudo tailscale funnel --bg --https=8443 http://127.0.0.1:8082` (antes, `tailscale funnel status` precisa mostrar a 8443 livre).

Pode ser repetido. Não suba o staging antes da FUTY-0018: o seed de demonstração cria usuários com senha fixa, e o staging é público.

## Deploy

Os workflows chamam `"$FOOTFY_SERVER_DIR/deploy.sh" backend|frontend` depois dos testes. À mão:

```bash
~/analitix/footfy/deploy.sh backend    # ou frontend, ou all
```

Fluxo (igual ao script antigo):
1. `flock` em `<pasta>/.deploy.lock` (um deploy por vez por ambiente);
2. `fetch` + `checkout` + `reset --hard origin/$DEPLOY_BRANCH` no clone;
3. tag da imagem atual como `${IMAGE_TAG}-previous`;
4. `build` do serviço (se falhar, volta o commit e nada muda no ar);
5. `up -d --no-deps` do serviço;
6. health check: `/up` e `<div id="app"` em `http://127.0.0.1:$WEB_PORT/`, até 40 × 3 s;
7. se falhar: volta o commit, devolve a imagem `-previous` e recria o container.

O deploy não roda migrations: quem roda é o entrypoint do backend, a cada start do container (inclusive reboot). O rollback não desfaz migrations. Ambos mudam no FD-0c.

## Rollback manual

```bash
cd ~/analitix/footfy
git -C footfy-backend reset --hard <commit anterior>
docker tag footfy/backend:production-previous footfy/backend:production
docker compose -p footfy -f footfy-backend/infra/docker-compose.yml --env-file .env up -d --no-deps backend
```

Para o web, o mesmo com `footfy-frontend`, `footfy/web` e o serviço `web`. A tag `-previous` guarda só a versão imediatamente anterior.

## Logs

```bash
cd ~/analitix/footfy
docker compose -p footfy -f footfy-backend/infra/docker-compose.yml --env-file .env logs --tail=200 -f backend
docker compose -p footfy -f footfy-backend/infra/docker-compose.yml --env-file .env exec backend tail -n 200 storage/logs/laravel.log
```

`storage/logs` ainda não está em volume: o log do Laravel some quando o container é recriado (deploy). Volume e `daily` de 14 dias no FD-0c.

## Backup

```bash
~/analitix/footfy/footfy-backend/infra/backup.sh
```

Gera em `<pasta>/backups/` o dump do banco (`db_AAAA-MM-DD_HHMM.sql.gz`, `--single-transaction`) e o tar dos uploads (`uploads_*.tar.gz`), guardando os 14 mais recentes de cada. Agendamento diário (pendente no servidor):

```
0 3 * * * ~/analitix/footfy/footfy-backend/infra/backup.sh >> ~/analitix/footfy/backups/backup.log 2>&1
```

Restaurar o banco:

```bash
cd ~/analitix/footfy
gunzip -c backups/db_<data>.sql.gz | docker compose -p footfy -f footfy-backend/infra/docker-compose.yml --env-file .env \
  exec -T db sh -c 'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

Restaurar os uploads:

```bash
docker compose -p footfy -f footfy-backend/infra/docker-compose.yml --env-file .env \
  exec -T backend tar -xzf - -C /var/www/html/storage/app < backups/uploads_<data>.tar.gz
```

Acesso ao banco de fora do servidor: túnel SSH até `127.0.0.1:3307` (`ssh -L 3307:127.0.0.1:3307 <servidor>`).

## Virada da produção para `infra/` (FUTY-0017)

Pré-requisito: FUTY-0016 (backend) e FUTY-0007 (frontend, `infra/docker/web`) em `main`. Fazer numa janela curta, em `~/analitix/footfy`. Nada aqui apaga volume.

1. **Backup** com o script antigo: `./backup.sh`. Confira os arquivos novos em `backups/`.
2. **Marcador do seed:** `docker compose exec backend ls -la storage/app/.seeded`. Se não existir, ponha `SEED_ON_FIRST_RUN=false` no `.env` antes de seguir (senão o seed de demonstração roda de novo no primeiro start).
3. **Imagens para o primeiro rollback:**
   ```bash
   docker tag footfy/backend:latest footfy/backend:production
   docker tag footfy/web:latest footfy/web:production
   ```
4. **Completar o `.env`** (sem mexer no resto):
   ```bash
   printf 'COMPOSE_PROJECT_NAME=footfy\nDEPLOY_BRANCH=main\nIMAGE_TAG=production\n' >> .env
   grep -E '^(COMPOSE_PROJECT_NAME|DEPLOY_BRANCH|IMAGE_TAG|APP_BASE_PATH|WEB_PORT|DB_FORWARD_PORT|SEED_ON_FIRST_RUN)=' .env
   ```
5. **Atualizar os clones** para a `main` com `infra/`: `git -C footfy-backend pull --ff-only` e `git -C footfy-frontend pull --ff-only`.
6. **Comparar o compose antigo com o novo:**
   ```bash
   docker compose config > /tmp/compose-antigo.yml
   docker compose -p footfy -f footfy-backend/infra/docker-compose.yml --env-file .env config > /tmp/compose-novo.yml
   diff /tmp/compose-antigo.yml /tmp/compose-novo.yml
   ```
   Diferenças esperadas: só as imagens (`latest` → `production`) e o `build` (contexto e Dockerfile). O resto precisa ser igual: `name: footfy`, volumes `footfy_db-data` e `footfy_backend-storage`, portas `127.0.0.1:8081` e `127.0.0.1:3307`, variáveis do `db`/`backend`/`web` e o bind do `.env` (`~/analitix/footfy/.env`).
7. **Deploy à mão pelo script novo:** `footfy-backend/infra/deploy.sh all`. Conferir `https://<host>.ts.net/footfy/`, login e upload de imagem (escudo de time).
8. **Arquivos antigos para `_legacy/`:**
   ```bash
   mkdir -p _legacy
   mv docker docker-compose.yml deploy.sh setup.sh backup.sh env.template gateway.conf.template update.sh _legacy/ 2>/dev/null
   ls _legacy
   ```
9. **Atalho e portão:** `footfy-backend/infra/setup.sh production` (cria `~/analitix/footfy/deploy.sh`, mantém o `.env`, sobe sem recriar nada se não mudou e regrava `gateway/projects/footfy.conf` igual ao atual).
10. **Caminho completo pelo GitHub:** re-run do último workflow da `main` (ou `workflow_dispatch`) nos dois repositórios. Os workflows continuam chamando `~/analitix/footfy/deploy.sh`.
11. **Cron do backup** (se for criar agora): use o caminho novo da seção Backup; o `backup.sh` antigo não funciona depois do passo 8.

**Para voltar atrás** (a qualquer momento depois do passo 7):

```bash
cd ~/analitix/footfy
rm -f deploy.sh
mv _legacy/* . && rmdir _legacy
docker compose up -d
```

O compose antigo volta a usar `footfy/backend:latest` e `footfy/web:latest` (continuam no disco; o `image prune` só apaga imagens sem tag) com os mesmos volumes. Se o `setup.sh` antigo tiver de ser rodado de novo, as linhas novas do `.env` não atrapalham.
