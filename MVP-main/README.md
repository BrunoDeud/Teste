# Encontrei — Achados e Perdidos

MVP acadêmico para registrar itens achados ou perdidos dentro de um campus, pesquisar anúncios, reivindicar objetos de forma segura e organizar a devolução sem revelar e-mail ou telefone entre usuários.

## Objetivo do MVP

Entregar o fluxo principal descrito nos RF01–RF17: cadastro institucional e verificação de e-mail; publicação e pesquisa de anúncios; detalhes públicos sem dados de verificação; reivindicações moderadas; chat interno após aprovação; denúncias; notificações; vencimento de anúncios; ponto de guarda e telas administrativas.

A entrega parte da documentação técnica presente em `docs/`, preserva a arquitetura decidida nos ADRs e inclui implementação inicial do backend, frontend, migrações, dados de demonstração, testes e contrato OpenAPI.

## Tecnologias

- **Frontend:** React, JavaScript, Vite, React Router e CSS responsivo; manifesto e service worker para instalação como PWA.
- **Backend:** PHP 8.2+ com Laravel 11 e Laravel Sanctum, disponibilizando API REST.
- **Banco:** MySQL 8.0+ / InnoDB / `utf8mb4`.
- **Testes:** PHPUnit e testes de funcionalidades da API.
- **Documentação API:** OpenAPI 3.0 em [`docs/api/openapi.yaml`](docs/api/openapi.yaml).

> O ADR-002 fixou Laravel 11. A [documentação oficial de atualização](https://laravel.com/framework/docs/11.x/upgrade) indica que essa versão já é antiga; ela foi mantida aqui para respeitar a decisão técnica da etapa anterior. Para uma publicação real, planeje a atualização do framework e das dependências antes do deploy.

## Arquitetura

```text
Navegador / PWA (React + Vite)
          |
          | HTTPS / JSON / multipart + Bearer token
          v
API REST (Laravel 11 + Sanctum)
          |
          | Eloquent / transações / validação / regras de negócio
          v
Banco MySQL (InnoDB)

Arquivos: storage público do Laravel (fotos)
Tarefas agendadas: expiração de anúncios e encaminhamento de reivindicações atrasadas
```

A interface consome a API pelo módulo `frontend/src/api.js`. A lógica de autenticação, autorização e regras de negócio fica no backend. O DER completo está em [`docs/Modelagem-de-dados/der.md`](docs/Modelagem-de-dados/der.md), e os wireframes/requisitos originais permanecem em `docs/`.

## Pré-requisitos

Instale no computador:

- PHP 8.2 ou superior, com extensões `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `tokenizer`, `xml`, `ctype` e `json`.
- Composer 2.
- MySQL 8.0 ou superior.
- Node.js 20 ou superior e npm.
- Git (opcional).

No ambiente em que este pacote foi preparado não havia Composer nem driver PDO de banco; por isso, o código PHP foi validado sintaticamente, mas a instalação integrada Laravel/MySQL e o build Vite precisam ser executados no ambiente com as dependências acima.

## Instalação

### 1. Criar o banco

Entre no MySQL e execute:

```sql
CREATE DATABASE achados_perdidos
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

### 2. Configurar e iniciar o backend

No terminal:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Edite `backend/.env` e configure ao menos `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `APP_URL`, `FRONTEND_URL` e as configurações de e-mail.

Em seguida:

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```

A API ficará disponível em `http://localhost:8000/api`. O endpoint `/up` pode ser usado para verificar se a aplicação Laravel inicializou.

### 3. Configurar e iniciar o frontend

Em outro terminal:

```bash
cd frontend
cp .env.example .env
npm install
npm run dev
```

Abra `http://localhost:5173`. Se a API estiver em outro endereço, ajuste `VITE_API_URL` em `frontend/.env` (por exemplo, `http://localhost:8000/api`) e mantenha `FRONTEND_URL` do backend alinhado com a origem usada pelo navegador.

### 4. Domínio institucional e verificação de e-mail

O seeder cadastra o domínio de demonstração `campus.edu.br`. **Antes de usar a plataforma com usuários reais, substitua esse domínio** no painel administrativo ou diretamente na tabela `dominios_institucionais` pelo domínio oficial da instituição.

Para desenvolvimento, o `.env.example` configura `MAIL_MAILER=log`: as mensagens de verificação e notificações são gravadas no log de e-mail do Laravel, em vez de serem enviadas para uma caixa real. A resposta de cadastro também inclui `verification_token_dev` somente quando `APP_ENV=local` ou `testing`, para permitir simular a verificação. Em produção, configure SMTP/serviço transacional, use HTTPS, desligue `APP_DEBUG` e nunca exponha tokens de verificação em logs ou respostas.

## Usuários de demonstração

O seeder cria três contas ativas, somente para desenvolvimento local:

| Perfil | E-mail | Senha |
|---|---|---|
| Administrador | `admin@campus.edu.br` | `Admin@12345` |
| Moderador | `moderador@campus.edu.br` | `Moderador@12345` |
| Usuário | `estudante@campus.edu.br` | `Estudante@12345` |

Essas credenciais são públicas e previsíveis. **Não use essas contas nem essas senhas em produção.** Altere ou remova as contas antes de publicar o sistema.

O seeder também adiciona categorias, locais, um ponto de guarda e um anúncio de exemplo com uma imagem ilustrativa. O `.env.example` usa sessão em arquivo, cache em arquivo e fila síncrona para evitar a necessidade de tabelas auxiliares extras no ambiente local.

## Funcionalidades incluídas

- Cadastro com e-mail de domínio autorizado, verificação com validade de 24 horas, login/logout por token e preferência de e-mail.
- Publicação de achados e perdidos, upload validado de até 3 fotos, edição de título/descrição/fotos e limites de anúncio por usuário.
- Busca por texto e filtros combinados por tipo, categoria, local, status e data.
- Detalhes públicos que não retornam pergunta nem resposta de verificação.
- Reivindicação com bloqueio de item próprio e de duplicidade ativa; aprovação/recusa e cancelamento das demais solicitações pendentes quando uma é aprovada.
- Chat limitado a reivindicações aprovadas; notificação interna e tentativa de e-mail sem expor contato pessoal.
- Denúncias únicas por usuário/item; ocultação automática ao atingir três denunciantes distintos; painel de moderação com justificativa e histórico.
- Cadastro de objeto no ponto de guarda por perfil autorizado e acompanhamento, aprovação e encerramento de reivindicações desses itens pela equipe de moderação.
- Notificações in-app e preferências de e-mail.
- Painel administrativo para categorias, locais, pontos de guarda, domínios institucionais, papéis e status de usuários.
- Comando agendado para expiração, aviso prévio do prazo padrão de ponto de guarda e encaminhamento de reivindicações sem resposta há sete dias.

## Banco de dados

O schema é criado por `backend/database/migrations/2026_01_01_000000_create_mvp_tables.php`. Ele inclui usuários, domínios institucionais, categorias, locais, pontos de guarda, itens, fotos, reivindicações, mensagens, denúncias, notificações, logs de moderação, correspondências e tokens de acesso do Sanctum. Cache, sessão e fila usam drivers de arquivo/síncrono no ambiente local; suas tabelas técnicas não fazem parte do schema de domínio. As regras de integridade mais relevantes estão descritas no DER.

A API criptografa a resposta esperada da pergunta de verificação com a chave da aplicação. Por isso, configure `APP_KEY` antes de criar dados de demonstração e mantenha essa chave protegida; sem a mesma chave, dados criptografados não poderão ser descriptografados.

## Execução de tarefas agendadas

Para rodar os jobs enquanto desenvolve, abra um terminal adicional:

```bash
cd backend
php artisan schedule:work
```

A rotina `items:expire` está programada para ocorrer diariamente às 02:00. Também pode ser executada manualmente:

```bash
php artisan items:expire
```

Em produção, configure o cron do Laravel Scheduler conforme a documentação do framework.

## Testes

No backend:

```bash
cd backend
php artisan test
```

Os testes de funcionalidade incluídos verificam obrigatoriedade de foto em achados, publicação de item perdido sem foto, criação de anúncio válido, privacidade da pergunta/resposta de verificação e dos e-mails no chat, bloqueio de reivindicação própria e duplicada, impedimento de autodenúncia, restauração de anúncio após decisão favorável, suspensão após três denúncias procedentes e recusa de domínio não institucional. O middleware bloqueia chamadas autenticadas de contas suspensas, inclusive se elas já tiverem um token ativo. O arquivo `backend/phpunit.xml` está preparado para SQLite em memória; confira se a instalação PHP possui `pdo_sqlite`. Como não foi possível instalar dependências no ambiente de preparação, os testes ainda precisam ser executados após `composer install`.

No frontend, valide o build de produção com:

```bash
cd frontend
npm install
npm run build
```

## Endpoints principais

A documentação completa está em [`docs/api/openapi.yaml`](docs/api/openapi.yaml). Principais rotas:

| Método | Endpoint | Finalidade |
|---|---|---|
| POST | `/api/auth/register` | Cadastro institucional pendente |
| POST | `/api/auth/verify-email` | Verificar e ativar conta |
| POST | `/api/auth/login` | Login e emissão de token |
| GET | `/api/items` | Busca e filtros de anúncios |
| POST | `/api/items` | Publicar item autenticado |
| GET | `/api/items/{id}` | Detalhes públicos do item |
| GET | `/api/items/{id}/verification-question` | Obter a pergunta privada antes de reivindicar |
| POST | `/api/items/{id}/claims` | Reivindicar um achado |
| PATCH | `/api/claims/{id}/decision` | Aprovar/recusar reivindicação |
| GET/POST | `/api/claims/{id}/messages` | Consultar/enviar mensagens internas |
| POST | `/api/claims/{id}/returned` | Confirmar devolução |
| POST | `/api/items/{id}/reports` | Denunciar anúncio |
| GET | `/api/my-items` | Anúncios e reivindicações do usuário |
| GET | `/api/notifications` | Notificações in-app |
| GET | `/api/moderation/claims` | Reivindicações encaminhadas à moderação ou de itens guardados |
| GET/PATCH | `/api/moderation/reports` e `/api/moderation/reports/{id}` | Fila e decisão de moderação |
| GET/POST/PATCH | `/api/admin/...` | Administração de cadastros e perfis |

Rotas protegidas exigem `Authorization: Bearer <token>` e retornam `401` quando não autenticadas, `403` quando o perfil não tem permissão, `404` para recurso inexistente/indisponível e `422` para validação ou regra de negócio violada.

## Rastreamento dos requisitos

A matriz [`docs/implementacao/escopo-mvp.md`](docs/implementacao/escopo-mvp.md) relaciona os RF, as histórias de usuário, os endpoints/telas e o estado de implementação para diferenciar o que está incluído no MVP e o que permanece parcial.

## Protótipos e documentação técnica

- [Wireframes das telas](docs/prototipos/Wireframes.md)
- [Arquivo editável dos wireframes](docs/prototipos/wireframes-achados-perdidos.excalidraw)
- [Requisitos funcionais](docs/requisitos/Requisitos-funcionais%20.md)
- [Histórias de usuário e critérios de aceite](docs/requisitos/historias-de-usuario.md)
- [Regras de negócio](docs/requisitos/regras-de-negocios.md)
- [Requisitos não funcionais](docs/requisitos/Requisitos-nao-funcionais.md)
- [DER](docs/Modelagem-de-dados/der.md)
- [Contrato OpenAPI](docs/api/openapi.yaml)

## Limitações conhecidas do MVP

- O frontend usa CSS responsivo próprio nesta versão; o ADR-001 menciona Tailwind CSS, cuja integração no pipeline de build ficou pendente. O build, migrações e testes integrados precisam ser executados em ambiente com Composer, extensões PHP e MySQL/SQLite instalados; esse ambiente de preparação não dispunha dessas dependências.
- As mensagens transacionais são síncronas neste MVP. Para atender metas de entrega e volume de produção, configure fila, provedor SMTP transacional, observabilidade e monitoramento.
- A busca textual usa índice FULLTEXT no MySQL e recorre a `LIKE` em drivers de desenvolvimento que não suportam esse recurso.
- O fluxo de exclusão/anonimização de conta, denúncia direta de perfil (a denúncia de anúncios está implementada), e-mail formal de aviso prévio de descarte/doação e consulta histórica detalhada pelo usuário ainda precisam de acabamento antes de considerar todos os RF/RN e RNF integralmente atendidos.
- Antes de publicar, conclua revisão de segurança, privacidade/LGPD, cobertura mínima de 70%, pipeline CI, backups, HTTPS/HSTS, análise de vulnerabilidade e testes de carga descritos nos RNF.
