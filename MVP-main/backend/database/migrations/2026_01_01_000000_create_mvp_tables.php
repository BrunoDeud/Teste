<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome', 120);
            $table->string('email')->unique();
            $table->string('senha_hash');
            $table->string('papel', 20)->default('USUARIO')->index();
            $table->string('status', 20)->default('PENDENTE')->index();
            $table->boolean('emails_ativados')->default(true);
            $table->string('token_verificacao_hash', 64)->nullable()->index();
            $table->timestamp('verificacao_expira_em')->nullable();
            $table->timestamp('email_verificado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('dominios_institucionais', function (Blueprint $table) {
            $table->id();
            $table->string('dominio', 190)->unique();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('categorias', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome', 80)->unique();
            $table->string('descricao', 255)->nullable();
            $table->boolean('ativo')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('locais_campus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome', 120);
            $table->string('bloco', 80)->nullable();
            $table->boolean('ativo')->default(true)->index();
            $table->timestamps();
            $table->unique(['nome', 'bloco']);
        });

        Schema::create('pontos_guarda', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('local_id');
            $table->string('nome', 120);
            $table->string('referencia', 255)->nullable();
            $table->boolean('ativo')->default(true)->index();
            $table->timestamps();
            $table->foreign('local_id')->references('id')->on('locais_campus')->restrictOnDelete();
        });

        Schema::create('itens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id')->nullable();
            $table->uuid('categoria_id');
            $table->uuid('local_id');
            $table->uuid('ponto_guarda_id')->nullable();
            $table->string('tipo', 15)->index();
            $table->string('titulo', 140);
            $table->text('descricao');
            $table->date('data_ocorrencia')->index();
            $table->string('pergunta_verificacao', 255)->nullable();
            $table->text('resposta_verificacao')->nullable();
            $table->string('status', 20)->default('ABERTO')->index();
            $table->timestamp('expira_em')->nullable()->index();
            $table->timestamp('aviso_expiracao_enviado_em')->nullable();
            $table->timestamp('resolvido_em')->nullable();
            $table->timestamp('atualizado_conteudo_em')->nullable();
            $table->timestamps();
            $table->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
            $table->foreign('categoria_id')->references('id')->on('categorias')->restrictOnDelete();
            $table->foreign('local_id')->references('id')->on('locais_campus')->restrictOnDelete();
            $table->foreign('ponto_guarda_id')->references('id')->on('pontos_guarda')->nullOnDelete();
            $table->index(['status', 'tipo', 'categoria_id', 'local_id', 'created_at']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE itens ADD FULLTEXT INDEX ft_itens_titulo_descricao (titulo, descricao)');
            DB::statement("ALTER TABLE itens ADD CONSTRAINT chk_item_achado_verificacao CHECK (tipo <> 'ACHADO' OR (pergunta_verificacao IS NOT NULL AND resposta_verificacao IS NOT NULL))");
        }

        Schema::create('item_fotos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id');
            $table->unsignedTinyInteger('ordem');
            $table->string('caminho', 500);
            $table->timestamps();
            $table->foreign('item_id')->references('id')->on('itens')->cascadeOnDelete();
            $table->unique(['item_id', 'ordem']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE item_fotos ADD CONSTRAINT chk_item_fotos_ordem CHECK (ordem BETWEEN 1 AND 3)');
        }

        Schema::create('reivindicacoes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id');
            $table->uuid('solicitante_id');
            $table->text('resposta_verificacao');
            $table->string('status', 20)->default('PENDENTE')->index();
            $table->text('motivo_recusa')->nullable();
            $table->uuid('decidido_por')->nullable();
            $table->timestamp('decidido_em')->nullable();
            $table->timestamp('encaminhada_moderacao_em')->nullable();
            $table->timestamps();
            $table->foreign('item_id')->references('id')->on('itens')->cascadeOnDelete();
            $table->foreign('solicitante_id')->references('id')->on('usuarios')->cascadeOnDelete();
            $table->foreign('decidido_por')->references('id')->on('usuarios')->nullOnDelete();
            $table->string('solicitante_ativo')->nullable()->storedAs("CASE WHEN status IN ('PENDENTE', 'APROVADA') THEN solicitante_id ELSE NULL END");
            $table->string('item_aprovado')->nullable()->storedAs("CASE WHEN status = 'APROVADA' THEN item_id ELSE NULL END");
            $table->unique(['item_id', 'solicitante_ativo'], 'uq_reivindicacao_ativa_usuario');
            $table->unique(['item_aprovado'], 'uq_item_com_reivindicacao_aprovada');
            $table->index(['item_id', 'status']);
        });

        Schema::create('mensagens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('reivindicacao_id');
            $table->uuid('remetente_id');
            $table->string('conteudo', 500);
            $table->timestamps();
            $table->foreign('reivindicacao_id')->references('id')->on('reivindicacoes')->cascadeOnDelete();
            $table->foreign('remetente_id')->references('id')->on('usuarios')->cascadeOnDelete();
            $table->index(['reivindicacao_id', 'created_at']);
        });

        Schema::create('denuncias', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id');
            $table->uuid('denunciante_id');
            $table->text('motivo');
            $table->string('status', 20)->default('ABERTA')->index();
            $table->string('acao_moderacao', 30)->nullable();
            $table->uuid('resolvida_por')->nullable();
            $table->text('resolucao')->nullable();
            $table->timestamp('resolvida_em')->nullable();
            $table->timestamps();
            $table->foreign('item_id')->references('id')->on('itens')->cascadeOnDelete();
            $table->foreign('denunciante_id')->references('id')->on('usuarios')->cascadeOnDelete();
            $table->foreign('resolvida_por')->references('id')->on('usuarios')->nullOnDelete();
            $table->unique(['item_id', 'denunciante_id'], 'uq_denuncia_item_usuario');
        });

        Schema::create('notificacoes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->string('tipo', 50);
            $table->string('titulo', 160);
            $table->text('mensagem');
            $table->json('dados')->nullable();
            $table->timestamp('lida_em')->nullable()->index();
            $table->boolean('email_enviado')->default(false);
            $table->timestamps();
            $table->foreign('usuario_id')->references('id')->on('usuarios')->cascadeOnDelete();
            $table->index(['usuario_id', 'created_at']);
        });

        Schema::create('logs_moderacao', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('moderador_id');
            $table->uuid('item_id')->nullable();
            $table->string('acao', 60);
            $table->text('justificativa');
            $table->json('detalhes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('moderador_id')->references('id')->on('usuarios')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('itens')->nullOnDelete();
        });

        Schema::create('correspondencias', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_perdido_id');
            $table->uuid('item_achado_id');
            $table->unsignedTinyInteger('pontuacao')->default(1);
            $table->timestamps();
            $table->foreign('item_perdido_id')->references('id')->on('itens')->cascadeOnDelete();
            $table->foreign('item_achado_id')->references('id')->on('itens')->cascadeOnDelete();
            $table->unique(['item_perdido_id', 'item_achado_id']);
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        foreach (['personal_access_tokens','correspondencias','logs_moderacao','notificacoes','denuncias','mensagens','reivindicacoes','item_fotos','itens','pontos_guarda','locais_campus','categorias','dominios_institucionais','usuarios'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
