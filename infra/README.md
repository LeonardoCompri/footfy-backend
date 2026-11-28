# Infraestrutura do Footfy

Arquivos que sobem o Footfy no servidor da Analitix (Ubuntu + Docker + Tailscale Funnel). O `compose.yaml` da raiz do repositório é o Sail, só para desenvolvimento, e não é usado aqui.

```
infra/
  docker-compose.yml       db, backend e web; um compose para todos os ambientes
  env.template             modelo do .env de cada ambiente (o setup.sh preenche)
  gateway.conf.template    rota do ambiente no portão (só quando há caminho, ex.: /footfy)
  setup.sh                 primeira instalação de um ambiente (pode rodar de novo)
  deploy.sh                deploy.sh backend|frontend|all
  backup.sh                dump do banco + uploads em <pasta>/backups; cópia do dump no rclone (BACKUP_REMOTE)
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
  footfy-staging/          staging: mesma estrutura, branch staging (seção Staging)
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
| `SEED_PASSWORD` | não usado (o seed já rodou) | senha forte, gerada pelo `setup.sh` | Senha dos usuários do seed de demonstração. Fora de `local`/`testing`, o `UserSeeder` falha se ela estiver vazia; `123456` só no Sail e nos testes |
| `BACKUP_REMOTE` | `footfy-crypt:footfy` (depois do `rclone config`) | vazio | Destino da cópia externa do dump do banco (seção Backup). Vazio ou ausente = backup só local |
| `BACKUP_REMOTE_KEEP_DAYS` | `30` | `30` | Dias que o dump fica no remote. Ausente = 30 |

Sem as variáveis novas, o compose usa os valores de hoje: projeto `footfy`, tag `latest`, portas 8081 e 3307 e caminho `/footfy`. O `deploy.sh` e o `setup.sh` exigem `COMPOSE_PROJECT_NAME`, `DEPLOY_BRANCH` e `IMAGE_TAG` no `.env`, para um ambiente nunca cair no projeto do outro.

Sem `PUBLIC_PORT` ou `APP_BASE_PATH` no `.env`, o `setup.sh` usa o padrão do ambiente (produção: sem porta e `/footfy`), como o compose.

Ainda não entram (tarefa seguinte, FD-0c): `COMPOSER_NO_DEV` e `SEED_ON_FIRST_RUN=false` na produção.

## Primeira instalação (`setup.sh`)

```bash
mkdir -p ~/analitix/footfy && cd ~/analitix/footfy
git clone https://github.com/LeonardoCompri/footfy-backend.git footfy-backend
footfy-backend/infra/setup.sh production
```

O `setup.sh production|staging`:
1. cria o atalho `<pasta>/deploy.sh` (recusa se já existe um `deploy.sh` diferente: mova-o para `_legacy/` antes);
2. clona o `footfy-frontend` na branch do ambiente, se faltar;
3. gera o `.env` pelo `env.template` com senhas aleatórias (inclusive `SEED_PASSWORD`), só se ele não existir. Se existir, mantém senhas e chave, confere `COMPOSE_PROJECT_NAME`/`DEPLOY_BRANCH`/`IMAGE_TAG` (no staging, também `SEED_PASSWORD` preenchida) e só atualiza `APP_URL` (`https://<host><PUBLIC_PORT><APP_BASE_PATH>`) e `PUBLIC_ORIGIN`;
4. roda `up -d --build` e espera o `/up`;
5. com `APP_BASE_PATH` preenchido (produção), gera `gateway/projects/<projeto>.conf` e chama o `reload.sh` do portão. Sem caminho (staging), só imprime o comando do Funnel: `sudo tailscale funnel --bg --https=8443 http://127.0.0.1:8082` (antes, `tailscale funnel status` precisa mostrar a 8443 livre).

Pode ser repetido.

## Deploy

Os workflows dos dois repositórios fazem o deploy depois dos testes, pela branch do push:

| Push em | Environment do GitHub | Pasta (`FOOTFY_SERVER_DIR` do environment) |
|---|---|---|
| `main` | `production` | `~/analitix/footfy` (padrão quando a variável não existe) |
| `staging` | `staging` | obrigatória; sem ela o deploy falha sem tocar em nada |

Cada environment tem o próprio `concurrency` (`deploy-production` e `deploy-staging`): um deploy do staging não espera nem cancela o da produção. O comando é `"$FOOTFY_SERVER_DIR/deploy.sh" backend|frontend`. À mão:

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

## Staging

Ambiente público para testar antes da `main`: `https://<host>.ts.net:8443/`, na pasta `~/analitix/footfy-staging`, projeto compose `footfy-staging` (volumes `footfy-staging_*`), branch `staging`, imagens `:staging`, web em `127.0.0.1:8082` e MySQL em `127.0.0.1:3308`.

- **Sem portão:** o Funnel publica a porta 8443 direto no web do staging. `APP_BASE_PATH` vazio: o site e a API ficam na raiz (`/` e `/api`).
- **Sessão separada:** porta diferente é outra origem, então o login do staging não mexe no da produção no mesmo navegador.
- **Senha do seed:** o seed de demonstração roda na primeira subida e cria o master (e-mail e CPF reais) e o admin com a senha `SEED_PASSWORD` do `.env`. Como o staging é público, `123456` não funciona lá.
- **Deploy:** o push na branch `staging` (merge do PR para `staging`) atualiza só o staging. O push na `main` continua indo só para a produção.
- **O que o staging não testa:** o caminho `/footfy/` e o portão. Mudanças nesses pontos são conferidas na produção logo depois do deploy.

### Primeira instalação do staging

Pré-requisito: a branch `staging` dos dois repositórios com a FUTY-0018 (backend) e a FUTY-0011 (frontend). Rode um comando por vez, na ordem.

**No GitHub (antes do primeiro push em `staging`):** crie o environment `staging` nos dois repositórios com a variável `FOOTFY_SERVER_DIR`. O valor é o caminho completo da pasta (o `~` não é expandido). Para saber o caminho, rode no servidor:

```bash
echo "$HOME/analitix/footfy-staging"
```

Pela tela: Settings → Environments → New environment → `staging` → Add environment variable → `FOOTFY_SERVER_DIR` = o caminho acima. Faça no `footfy-backend` e no `footfy-frontend`. Ou pelo `gh`, trocando `<caminho>`:

```bash
gh api -X PUT repos/LeonardoCompri/footfy-backend/environments/staging
```

```bash
gh variable set FOOTFY_SERVER_DIR --env staging --repo LeonardoCompri/footfy-backend --body "<caminho>"
```

```bash
gh api -X PUT repos/LeonardoCompri/footfy-frontend/environments/staging
```

```bash
gh variable set FOOTFY_SERVER_DIR --env staging --repo LeonardoCompri/footfy-frontend --body "<caminho>"
```

Se o push em `staging` acontecer antes do servidor estar pronto, o job de deploy falha ("FOOTFY_SERVER_DIR não definida no environment staging", ou o `deploy.sh` não encontrado). É esperado e não toca na produção: reexecute o workflow no fim (passo 9).

**No servidor:**

1. Conferir que a 8443 está livre (nem `serve` nem `funnel` nela; a produção usa só a 443):

   ```bash
   tailscale funnel status
   ```

2. Criar a pasta:

   ```bash
   mkdir -p ~/analitix/footfy-staging
   ```

   ```bash
   cd ~/analitix/footfy-staging
   ```

3. Clonar o backend na branch `staging` (o frontend o `setup.sh` clona sozinho, na mesma branch):

   ```bash
   git clone --branch staging https://github.com/LeonardoCompri/footfy-backend.git footfy-backend
   ```

4. Instalar. Gera o `.env` do staging (com `SEED_PASSWORD` aleatória e forte), cria o atalho `deploy.sh`, sobe os containers e roda o seed na primeira subida:

   ```bash
   footfy-backend/infra/setup.sh staging
   ```

5. Publicar a 8443 pelo Funnel (uma vez; fica salvo):

   ```bash
   sudo tailscale funnel --bg --https=8443 http://127.0.0.1:8082
   ```

6. Conferir: a 443 continua no portão (produção) e a 8443 aponta para `127.0.0.1:8082`:

   ```bash
   tailscale funnel status
   ```

7. Ver a senha dos usuários de demonstração e guardá-la no gerenciador de senhas:

   ```bash
   grep '^SEED_PASSWORD=' ~/analitix/footfy-staging/.env
   ```

8. Testar: abrir `https://<host>.ts.net:8443/`, entrar com o CPF do master e a senha do passo 7 (`123456` precisa ser recusada) e conferir que a produção (`https://<host>.ts.net/footfy/`) continua logada no mesmo navegador.

9. Reexecutar o último workflow da branch `staging` nos dois repositórios (Actions → o run que falhou → Re-run jobs), ou pelo `gh`:

   ```bash
   gh run list --repo LeonardoCompri/footfy-backend --branch staging --limit 1
   ```

   ```bash
   gh run rerun <id do run> --repo LeonardoCompri/footfy-backend
   ```

   O mesmo com `footfy-frontend`. O deploy precisa terminar em verde e mexer só em `~/analitix/footfy-staging`.

Para trocar a senha depois, entre no staging e mude pelo perfil. Trocar `SEED_PASSWORD` no `.env` só vale para um seed novo (seção abaixo).

### Comandos do staging

Os mesmos da produção, rodados em `~/analitix/footfy-staging` e com `-p footfy-staging`. Exemplo (logs):

```bash
cd ~/analitix/footfy-staging
```

```bash
docker compose -p footfy-staging -f footfy-backend/infra/docker-compose.yml --env-file .env logs --tail=200 -f backend
```

Deploy à mão: `~/analitix/footfy-staging/deploy.sh backend` (ou `frontend`, ou `all`).

### Recriar o banco do staging (`migrate:fresh --seed`)

A branch `staging` é recriada a partir da `main` de tempos em tempos, e migrations que só existiam no staging antigo continuam no banco. Para voltar a um banco limpo com os dados de demonstração. **Só no staging, nunca na pasta da produção: apaga todos os dados.**

1. Entrar na pasta do staging:

   ```bash
   cd ~/analitix/footfy-staging
   ```

2. Conferir que é o staging (precisa mostrar `APP_ENV=staging`):

   ```bash
   grep '^APP_ENV=' .env
   ```

3. Se mudou a `SEED_PASSWORD` no `.env`, reinicie o backend antes (a configuração fica em cache no container):

   ```bash
   docker compose -p footfy-staging -f footfy-backend/infra/docker-compose.yml --env-file .env restart backend
   ```

4. Recriar o banco e rodar o seed (sem `--force`: com `APP_ENV=production` o Laravel pede confirmação; responda `no`):

   ```bash
   docker compose -p footfy-staging -f footfy-backend/infra/docker-compose.yml --env-file .env exec backend php artisan migrate:fresh --seed
   ```

Os uploads (`footfy-staging_backend-storage`) não são apagados por esse comando.

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

O que o script faz, nesta ordem:

1. **Local:** gera em `<pasta>/backups/` o dump do banco (`db_daily_AAAA-MM-DD_HHMM.sql.gz`, `--single-transaction`) e o tar dos uploads (`uploads_*.tar.gz`), guardando os 14 mais recentes de cada. Os dumps antigos `db_AAAA-MM-DD_HHMM.sql.gz` (de antes da FUTY-0036) contam junto com os `db_daily_*` e saem sozinhos em 14 dias. Se o dump falhar, nenhum arquivo novo fica em `backups/` e o script sai com erro.
2. **Externo (só com `BACKUP_REMOTE` no `.env`):** `rclone copy` dos `db_daily_*` dos últimos `BACKUP_REMOTE_KEEP_DAYS` dias (padrão 30) que ainda não estão no remote, e `rclone delete --min-age` dos que passaram desse prazo no remote. Só o dump do banco vai para fora, criptografado pelo remote `crypt` do rclone; os uploads ficam só no disco. Se o envio falhar (rede, credencial, rclone ausente), o script sai com código diferente de 0 e uma linha `ERRO:` no log, e o backup local fica. Um dump que não subiu num dia sobe na execução seguinte.

Com `BACKUP_REMOTE` vazio ou ausente (staging), é só o passo 1.

O script acha o rclone no `PATH`, em `~/.local/bin/rclone` (o cron não carrega o `PATH` do perfil) ou no caminho de `RCLONE_BIN`.

### Cópia externa: configuração (uma vez, na produção)

Rode um comando por vez, na ordem.

**1. Instalar o rclone sem sudo** em `~/.local/bin`. Conferir a arquitetura (`x86_64` = `amd64`, `aarch64` = `arm64`; abaixo, `amd64`):

```bash
uname -m
```

```bash
mkdir -p ~/.local/bin ~/rclone-install
```

```bash
cd ~/rclone-install
```

```bash
curl -fsSLO https://downloads.rclone.org/rclone-current-linux-amd64.zip
```

```bash
python3 -m zipfile -e rclone-current-linux-amd64.zip .
```

```bash
cp rclone-*-linux-amd64/rclone ~/.local/bin/rclone
```

```bash
chmod 755 ~/.local/bin/rclone
```

```bash
cd ~
```

```bash
rm -rf ~/rclone-install
```

```bash
~/.local/bin/rclone version
```

Se `rclone version` (sem o caminho) não funcionar, abra um terminal novo: o Ubuntu põe `~/.local/bin` no `PATH` quando a pasta existe no login. Os comandos abaixo usam `rclone`; se ainda não funcionar, troque por `~/.local/bin/rclone`.

**2. Criar a conta na nuvem** (escolha uma):

- **Backblaze B2:** no site da Backblaze, crie um bucket **privado** (ex.: `footfy-backup-<algo>`; o nome é único no mundo) e uma *Application Key* com acesso só a esse bucket. Anote o `keyID` e a `applicationKey` (ela só aparece uma vez).
- **Google Drive:** use uma conta Google. Como o servidor não tem navegador, a autorização é feita num computador com navegador e o rclone instalado (passo 3).

**3. Remote da nuvem** com o nome `nuvem`:

```bash
rclone config
```

Responda: `n` (new remote) → name `nuvem` → Storage:

- **B2:** `b2` → `account` = o `keyID` → `key` = a `applicationKey` → `hard_delete` = `true` → `Edit advanced config?` `n` → `y` para salvar → `q` para sair.
- **Drive:** `drive` → `client_id` e `client_secret` Enter → `scope` = `drive.file` (o rclone só vê o que ele mesmo criou) → `service_account_file` Enter → `Edit advanced config?` `n` → `Use web browser to automatically authenticate?` → `n`. O rclone mostra um comando `rclone authorize "drive" "..."`: rode-o no computador com navegador, faça o login e cole a resposta no servidor → `Configure this as a Shared Drive?` `n` → `y` para salvar → `q` para sair.

Para a limpeza de 30 dias liberar espaço de verdade (sem isso o B2 guarda as versões antigas e o Drive manda para a lixeira), rode só o comando do seu caso. B2:

```bash
rclone config update nuvem hard_delete true
```

Drive:

```bash
rclone config update nuvem use_trash false
```

Conferir o acesso (no B2 lista os buckets; no Drive pode vir vazio):

```bash
rclone lsd nuvem:
```

**4. Remote `crypt` por cima**, com o nome `footfy-crypt`:

```bash
rclone config
```

Responda: `n` → name `footfy-crypt` → Storage `crypt` → `remote`:
- B2: `nuvem:<nome do bucket>`
- Drive: `nuvem:footfy-backups`

→ `filename_encryption` `standard` → `directory_name_encryption` `true` → senha (`password`): `g` (gerar) e `128`; o rclone mostra a senha gerada: copie para o gerenciador de senhas e responda `y` → senha do sal (`password2`): `g` e `128`, copie também e `y` → `Edit advanced config?` `n` → `y` para salvar → `q` para sair. Não use a opção "Set configuration password" do menu: o cron não tem como digitá-la.

**5. Guardar fora do servidor** (gerenciador de senhas), antes de seguir: as duas senhas do `crypt` (`password` e `password2`), as credenciais da nuvem e o `.env` da produção (`APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`). Sem as senhas do `crypt` o backup externo não se abre, nem por você. Para ver o `.env`:

```bash
cat ~/analitix/footfy/.env
```

As senhas do `crypt` também ficam no `~/.config/rclone/rclone.conf`, só ofuscadas (não é criptografia; o arquivo não sai do servidor). Se não copiou alguma no passo 4, mostre o valor ofuscado da linha `password =` (ou `password2 =`) e revele:

```bash
rclone config show footfy-crypt
```

```bash
rclone reveal <valor ofuscado>
```

**6. Ligar a cópia externa no `.env` da produção** (o `\n` do começo garante a quebra de linha se o arquivo terminar sem ela):

```bash
printf '\nBACKUP_REMOTE=footfy-crypt:footfy\n' >> ~/analitix/footfy/.env
```

```bash
grep '^BACKUP_REMOTE=' ~/analitix/footfy/.env
```

Precisa aparecer uma linha só. `BACKUP_REMOTE_KEEP_DAYS` é opcional (padrão 30). Não precisa reiniciar nada: o `backup.sh` lê o `.env` a cada execução.

**7. Testar à mão:**

```bash
~/analitix/footfy/footfy-backend/infra/backup.sh
```

A saída termina com `Dump enviado para footfy-crypt:footfy`. O dump aparece com o nome normal pelo `crypt`:

```bash
rclone ls footfy-crypt:footfy
```

E na nuvem, sem a senha, só há nomes embaralhados (troque `<bucket>` por `footfy-backups` no Drive):

```bash
rclone ls nuvem:<bucket>
```

**8. Cron** (já existe desde a virada; não muda). Conferir que a linha está assim:

```bash
crontab -l
```

```
0 3 * * * ~/analitix/footfy/footfy-backend/infra/backup.sh >> ~/analitix/footfy/backups/backup.log 2>&1
```

Depois das 3h, conferir o log (falha aparece como `ERRO:`):

```bash
tail -n 20 ~/analitix/footfy/backups/backup.log
```

### Restaurar

Banco, a partir do dump local:

```bash
cd ~/analitix/footfy
gunzip -c backups/db_daily_<data>.sql.gz | docker compose -p footfy -f footfy-backend/infra/docker-compose.yml --env-file .env \
  exec -T db sh -c 'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

Uploads:

```bash
docker compose -p footfy -f footfy-backend/infra/docker-compose.yml --env-file .env \
  exec -T backend tar -xzf - -C /var/www/html/storage/app < backups/uploads_<data>.tar.gz
```

### Restaurar o dump externo (teste no staging)

Faça uma vez para provar que o backup externo abre. **Só no staging**: troca todos os dados dele pelos da produção. Um comando por vez:

1. Listar os dumps no remote e escolher um:

   ```bash
   rclone ls footfy-crypt:footfy
   ```

2. Baixar para a pasta do staging (o `crypt` descriptografa):

   ```bash
   mkdir -p ~/analitix/footfy-staging/backups/restore
   ```

   ```bash
   rclone copy footfy-crypt:footfy/db_daily_<data>.sql.gz ~/analitix/footfy-staging/backups/restore/
   ```

3. Entrar na pasta do staging e conferir que é ela (precisa mostrar `APP_ENV=staging`):

   ```bash
   cd ~/analitix/footfy-staging
   ```

   ```bash
   grep '^APP_ENV=' .env
   ```

4. Importar no banco do staging:

   ```bash
   gunzip -c backups/restore/db_daily_<data>.sql.gz | docker compose -p footfy-staging -f footfy-backend/infra/docker-compose.yml --env-file .env exec -T db sh -c 'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
   ```

5. Abrir `https://<host>.ts.net:8443/` e entrar com um usuário da produção: os dados da produção aparecem (escudos e imagens não, porque os uploads não vão para fora).

6. Apagar o arquivo baixado:

   ```bash
   rm -rf ~/analitix/footfy-staging/backups/restore
   ```

7. O staging é público e agora tem os dados e as senhas da produção. Volte ao banco de demonstração logo depois do teste (seção "Recriar o banco do staging").

Num servidor novo, o mesmo vale para a produção: instalar o rclone, recriar os remotes `nuvem` e `footfy-crypt` com **as mesmas senhas do `crypt`** guardadas no passo 5 (em vez de `g`, escolha `y` e digite cada uma), baixar o dump e importar com `-p footfy` em `~/analitix/footfy`.

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
