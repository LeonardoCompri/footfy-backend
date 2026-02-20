# ⚽ Football Championship Manager API

API RESTful para gerenciamento de campeonatos de futebol desenvolvida
com Laravel, utilizando autenticação via Bearer Token, controle de
permissões por Role e arquitetura 100% API.

------------------------------------------------------------------------

## 🚀 Tecnologias Utilizadas

-   PHP 8+
-   Laravel 11
-   Laravel Sail (Docker)
-   Laravel Sanctum (Autenticação via Token)
-   Spatie Laravel Permission (Roles & Permissions)
-   MySQL
-   Docker + WSL

------------------------------------------------------------------------

## 📌 Funcionalidades

-   🔐 Autenticação via CPF (document) + senha
-   🎟️ Geração de Bearer Token
-   👤 Sistema de Roles:
    -   master
    -   admin
    -   coach
    -   player
-   🔑 Controle granular de permissões:
    -   model:all
    -   model:create
    -   model:read
    -   model:update
    -   model:delete
-   🏆 CRUD completo para:
    -   Campeonatos
    -   Times
    -   Jogadores
    -   Estádios
-   🛡 Controle de acesso baseado em permissões (sem Policies)

------------------------------------------------------------------------

## 📂 Estrutura da API

Base URL:

http://localhost/api

------------------------------------------------------------------------

## 🔐 Autenticação

### Login

POST /api/login

Body:

{ "document": "12345678900", "password": "123456" }

Resposta:

{ "token": "1\|xxxxxxxxxxxxxxxx", "user": { ... } }

------------------------------------------------------------------------

### Usando o Token

Adicionar no Header:

Authorization: Bearer {seu_token} Accept: application/json

------------------------------------------------------------------------

## 📚 Rotas Principais

### 🏆 Campeonatos

GET /api/tournaments\
POST /api/tournaments\
GET /api/tournaments/{id}\
PUT /api/tournaments/{id}\
DELETE /api/tournaments/{id}

------------------------------------------------------------------------

### 🏟 Times

GET /api/teams\
POST /api/teams\
GET /api/teams/{id}\
PUT /api/teams/{id}\
DELETE /api/teams/{id}

------------------------------------------------------------------------

### 👤 Jogadores

GET /api/players\
POST /api/players\
GET /api/players/{id}\
PUT /api/players/{id}\
DELETE /api/players/{id}

------------------------------------------------------------------------

### 🏟 Estádios

GET /api/stadiums\
POST /api/stadiums\
GET /api/stadiums/{id}\
PUT /api/stadiums/{id}\
DELETE /api/stadiums/{id}

------------------------------------------------------------------------

## 🛠️ Instalação

### 1️⃣ Clonar o repositório

git clone https://github.com/seu-usuario/seu-repositorio.git\
cd seu-repositorio

------------------------------------------------------------------------

### 2️⃣ Copiar .env

cp .env.example .env

------------------------------------------------------------------------

### 3️⃣ Subir containers

./vendor/bin/sail up -d

------------------------------------------------------------------------

### 4️⃣ Instalar dependências

./vendor/bin/sail composer install

------------------------------------------------------------------------

### 5️⃣ Gerar chave da aplicação

./vendor/bin/sail artisan key:generate

------------------------------------------------------------------------

### 6️⃣ Rodar migrations + seeders

./vendor/bin/sail artisan migrate:fresh --seed

------------------------------------------------------------------------

## 🛡 Sistema de Permissões

Formato das permissões:

tournament:all\
tournament:create\
tournament:read\
tournament:update\
tournament:delete

Aplicado também para:

-   team
-   player
-   stadium

------------------------------------------------------------------------

## 👤 Roles do Sistema

  Role     Acesso
  -------- --------------------------------------
  master   Acesso total
  admin    Acesso administrativo
  coach    Gerencia seu time e jogadores
  player   Atualiza apenas seu próprio cadastro

------------------------------------------------------------------------

## 🧠 Arquitetura

-   API Stateless
-   Autenticação via Token
-   Controle de acesso por Middleware
-   Separação clara de responsabilidades
-   Pronto para integração com frontend (React, Vue, Mobile)

------------------------------------------------------------------------

## 🐳 Ambiente de Desenvolvimento

-   Windows + WSL2
-   Docker via Laravel Sail

------------------------------------------------------------------------

## 📈 Próximos Passos

-   Documentação Swagger
-   Versionamento de API
-   Logs estruturados
-   Testes automatizados
-   Sistema de estatísticas do campeonato

------------------------------------------------------------------------

## 📄 Licença

Projeto para fins educacionais e desenvolvimento pessoal.
