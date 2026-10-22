# Diagrama Entidade-Relacionamento (DER)

O esquema abaixo corresponde às migrações do MVP em `backend/database/migrations`. Os identificadores das entidades principais são UUIDs; `dominios_institucionais` usa chave numérica autoincremental por ser uma tabela de configuração.

```mermaid
erDiagram
    USUARIO ||--o{ ITEM : publica
    USUARIO ||--o{ REIVINDICACAO : solicita
    USUARIO ||--o{ MENSAGEM : envia
    USUARIO ||--o{ DENUNCIA : registra
    USUARIO ||--o{ NOTIFICACAO : recebe
    USUARIO ||--o{ LOG_MODERACAO : executa
    CATEGORIA ||--o{ ITEM : classifica
    LOCAL_CAMPUS ||--o{ ITEM : localiza
    LOCAL_CAMPUS ||--o{ PONTO_GUARDA : abriga
    PONTO_GUARDA o|--o{ ITEM : armazena
    ITEM ||--o{ ITEM_FOTO : possui
    ITEM ||--o{ REIVINDICACAO : recebe
    ITEM ||--o{ DENUNCIA : recebe
    REIVINDICACAO ||--o{ MENSAGEM : contem
    ITEM ||--o{ CORRESPONDENCIA : item_perdido
    ITEM ||--o{ CORRESPONDENCIA : item_achado

    USUARIO {
        uuid id PK
        string nome
        string email UK
        string senha_hash
        string papel
        string status
        boolean emails_ativados
        string token_verificacao_hash
        timestamp verificacao_expira_em
        timestamp email_verificado_em
        timestamp created_at
        timestamp updated_at
    }
    DOMINIO_INSTITUCIONAL {
        bigint id PK
        string dominio UK
        boolean ativo
    }
    CATEGORIA {
        uuid id PK
        string nome UK
        string descricao
        boolean ativo
    }
    LOCAL_CAMPUS {
        uuid id PK
        string nome
        string bloco
        boolean ativo
    }
    PONTO_GUARDA {
        uuid id PK
        uuid local_id FK
        string nome
        string referencia
        boolean ativo
    }
    ITEM {
        uuid id PK
        uuid usuario_id FK
        uuid categoria_id FK
        uuid local_id FK
        uuid ponto_guarda_id FK
        string tipo
        string titulo
        text descricao
        date data_ocorrencia
        string pergunta_verificacao
        text resposta_verificacao
        string status
        timestamp expira_em
        timestamp aviso_expiracao_enviado_em
        timestamp resolvido_em
        timestamp atualizado_conteudo_em
    }
    ITEM_FOTO {
        uuid id PK
        uuid item_id FK
        tinyint ordem
        string caminho
    }
    REIVINDICACAO {
        uuid id PK
        uuid item_id FK
        uuid solicitante_id FK
        text resposta_verificacao
        string status
        text motivo_recusa
        uuid decidido_por FK
        timestamp decidido_em
        timestamp encaminhada_moderacao_em
    }
    MENSAGEM {
        uuid id PK
        uuid reivindicacao_id FK
        uuid remetente_id FK
        string conteudo
        timestamp created_at
    }
    DENUNCIA {
        uuid id PK
        uuid item_id FK
        uuid denunciante_id FK
        text motivo
        string status
        string acao_moderacao
        uuid resolvida_por FK
        text resolucao
        timestamp resolvida_em
    }
    NOTIFICACAO {
        uuid id PK
        uuid usuario_id FK
        string tipo
        string titulo
        text mensagem
        json dados
        timestamp lida_em
        boolean email_enviado
    }
    LOG_MODERACAO {
        uuid id PK
        uuid moderador_id FK
        uuid item_id FK
        string acao
        text justificativa
        json detalhes
        timestamp created_at
    }
    CORRESPONDENCIA {
        uuid id PK
        uuid item_perdido_id FK
        uuid item_achado_id FK
        tinyint pontuacao
    }
```

## Restrições e integridade

A tabela técnica `personal_access_tokens`, criada para o Laravel Sanctum, armazena tokens Bearer com expiração. Ela não faz parte das entidades de negócio do diagrama. As tabelas de cache, sessão e fila não são necessárias no ambiente local (drivers em arquivo/síncrono) e não são criadas pela migração do domínio.

- **Usuário → item:** o publicador é opcional apenas para aceitar cadastro administrativo de itens recebidos presencialmente. Se a conta for excluída no futuro, o anúncio histórico pode permanecer sem referência ao usuário.
- **Fotos:** no máximo três fotos por publicação, em ordem de 1 a 3; a API verifica formatos JPG/JPEG, PNG e WEBP e limite de 5 MB por arquivo. `UNIQUE (item_id, ordem)` evita repetir a posição.
- **Itens achados:** a API exige no mínimo uma foto e uma pergunta/resposta de verificação. A resposta esperada é criptografada na aplicação e tanto a pergunta quanto a resposta são excluídas das respostas públicas.
- **Categorias sensíveis:** anúncios achados de Documentos, Cartões e Eletrônicos exigem um perfil de moderação/administrador e ponto de guarda associado.
- **Reivindicações ativas:** MySQL não possui índices únicos parciais como os descritos originalmente. A migração cria colunas geradas `solicitante_ativo` e `item_aprovado`: a primeira possibilita apenas uma reivindicação pendente/aprovada de cada pessoa por item; a segunda garante só uma reivindicação aprovada por item.
- **Denúncias:** `UNIQUE (item_id, denunciante_id)` bloqueia uma segunda denúncia do mesmo usuário sobre o mesmo anúncio. A ação da moderação fica registrada para contar decisões procedentes e suspender automaticamente contas ao atingir três ocorrências confirmadas.
- **Integridade referencial:** chaves estrangeiras protegem categorias, locais, pontos de guarda, fotos, reivindicações, mensagens, notificações, denúncias e correspondências. Exclusões de categorias/locais referenciados são restritas; fotos e registros dependentes têm exclusão em cascata quando apropriado.
- **Busca:** índice composto em status, tipo, categoria, local e data de criação. O MySQL usa um índice FULLTEXT para a busca textual por título/descrição; drivers de desenvolvimento sem esse recurso usam `LIKE`.
- **Estados principais do item:** `ABERTO`, `PONTO_GUARDA`, `RESOLVIDO`, `EXPIRADO`, `OCULTO`.
- **Estados principais da reivindicação:** `PENDENTE`, `APROVADA`, `RECUSADA`, `CANCELADA`, `DEVOLVIDA`.
- **Histórico:** itens resolvidos, expirados e ocultos continuam armazenados e não aparecem na busca pública. As decisões relevantes são registradas em `logs_moderacao`.
