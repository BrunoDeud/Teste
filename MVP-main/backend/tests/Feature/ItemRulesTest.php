<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Denuncia;
use App\Models\Item;
use App\Models\LocalCampus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ItemRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Categoria $category;
    private LocalCampus $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email','estudante@campus.edu.br')->firstOrFail();
        $this->category = Categoria::where('nome','Roupas e acessórios')->firstOrFail();
        $this->location = LocalCampus::where('nome','Biblioteca')->firstOrFail();
        Storage::fake('public');
    }

    public function test_achado_requires_verification_fields_and_at_least_one_photo(): void
    {
        $this->actingAs($this->owner,'sanctum')->postJson('/api/items', [
            'tipo'=>'ACHADO','titulo'=>'Chave encontrada','descricao'=>'Chave encontrada no corredor.',
            'categoria_id'=>$this->category->id,'local_id'=>$this->location->id,'data_ocorrencia'=>today()->toDateString(),
            'pergunta_verificacao'=>'Qual chaveiro estava preso?','resposta_verificacao'=>'Um foguete',
        ])->assertUnprocessable()->assertJsonValidationErrors('fotos');
    }

    public function test_found_item_can_be_created_with_valid_photo(): void
    {
        $response = $this->actingAs($this->owner,'sanctum')->postJson('/api/items', [
            'tipo'=>'ACHADO','titulo'=>'Chave encontrada','descricao'=>'Chave encontrada no corredor.',
            'categoria_id'=>$this->category->id,'local_id'=>$this->location->id,'data_ocorrencia'=>today()->toDateString(),
            'pergunta_verificacao'=>'Qual chaveiro estava preso?','resposta_verificacao'=>'Um foguete',
            'fotos'=>[UploadedFile::fake()->createWithContent('chave.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/mioAAAAASUVORK5CYII='))],
        ]);
        $response->assertCreated()->assertJsonPath('item.tipo','ACHADO')->assertJsonMissingPath('item.pergunta_verificacao');
        $this->assertDatabaseHas('itens',['titulo'=>'Chave encontrada','usuario_id'=>$this->owner->id]);
    }

    public function test_user_cannot_claim_an_item_they_published(): void
    {
        $item = Item::create([
            'usuario_id'=>$this->owner->id,'categoria_id'=>$this->category->id,'local_id'=>$this->location->id,
            'tipo'=>'ACHADO','titulo'=>'Objeto do próprio usuário','descricao'=>'Descrição do objeto do próprio usuário.',
            'data_ocorrencia'=>today(),'pergunta_verificacao'=>'Qual marca aparece?','resposta_verificacao'=>encrypt('Marca X'),
            'status'=>'ABERTO','expira_em'=>now()->addDays(60),
        ]);
        $this->actingAs($this->owner,'sanctum')->postJson("/api/items/{$item->id}/claims", ['resposta_verificacao'=>'Minha resposta'])->assertUnprocessable();
    }


    public function test_lost_item_can_be_published_without_photo(): void
    {
        $response = $this->actingAs($this->owner,'sanctum')->postJson('/api/items', [
            'tipo'=>'PERDIDO','titulo'=>'Caderno azul perdido','descricao'=>'Caderno azul perdido na saída da biblioteca.',
            'categoria_id'=>$this->category->id,'local_id'=>$this->location->id,'data_ocorrencia'=>today()->toDateString(),
        ]);

        $response->assertCreated()->assertJsonPath('item.tipo','PERDIDO');
        $this->assertDatabaseHas('itens',['titulo'=>'Caderno azul perdido','usuario_id'=>$this->owner->id]);
    }

    public function test_public_item_detail_does_not_expose_verification_question_or_answer(): void
    {
        $item = Item::where('titulo','Moletom cinza encontrado')->firstOrFail();

        $this->getJson('/api/items/'.$item->id)
            ->assertOk()
            ->assertJsonMissingPath('item.pergunta_verificacao')
            ->assertJsonMissingPath('item.resposta_verificacao');
    }

    public function test_verification_question_endpoint_does_not_return_expected_answer(): void
    {
        $item = Item::where('titulo','Moletom cinza encontrado')->firstOrFail();

        $this->actingAs($this->owner,'sanctum')->getJson('/api/items/'.$item->id.'/verification-question')
            ->assertUnprocessable();

        $claimant = User::where('email','admin@campus.edu.br')->firstOrFail();
        $this->actingAs($claimant,'sanctum')->getJson('/api/items/'.$item->id.'/verification-question')
            ->assertOk()
            ->assertJsonPath('pergunta_verificacao','Qual detalhe identifica o moletom?')
            ->assertJsonMissingPath('resposta_verificacao');
    }

    public function test_user_cannot_create_duplicate_active_claims(): void
    {
        $item = Item::create([
            'usuario_id'=>$this->owner->id,'categoria_id'=>$this->category->id,'local_id'=>$this->location->id,
            'tipo'=>'ACHADO','titulo'=>'Estojo encontrado para teste','descricao'=>'Estojo encontrado próximo à biblioteca.',
            'data_ocorrencia'=>today(),'pergunta_verificacao'=>'Qual adesivo está no estojo?','resposta_verificacao'=>encrypt('Estrela'),
            'status'=>'ABERTO','expira_em'=>now()->addDays(60),
        ]);
        $claimant = User::where('email','admin@campus.edu.br')->firstOrFail();

        $payload = ['resposta_verificacao'=>'Adesivo de estrela'];
        $this->actingAs($claimant,'sanctum')->postJson("/api/items/{$item->id}/claims", $payload)->assertCreated();
        $this->actingAs($claimant,'sanctum')->postJson("/api/items/{$item->id}/claims", $payload)->assertUnprocessable();
    }

    public function test_user_cannot_report_their_own_item(): void
    {
        $item = Item::create([
            'usuario_id'=>$this->owner->id,'categoria_id'=>$this->category->id,'local_id'=>$this->location->id,
            'tipo'=>'PERDIDO','titulo'=>'Mochila de teste','descricao'=>'Mochila usada somente no teste de denúncia.',
            'data_ocorrencia'=>today(),'status'=>'ABERTO','expira_em'=>now()->addDays(60),
        ]);

        $this->actingAs($this->owner,'sanctum')->postJson("/api/items/{$item->id}/reports", ['motivo'=>'Denúncia de teste inválida'])
            ->assertUnprocessable();
    }


    public function test_chat_messages_do_not_expose_participants_email_addresses(): void
    {
        $item = Item::create([
            'usuario_id'=>$this->owner->id,'categoria_id'=>$this->category->id,'local_id'=>$this->location->id,
            'tipo'=>'ACHADO','titulo'=>'Fone de teste','descricao'=>'Fone de teste para privacidade do chat.',
            'data_ocorrencia'=>today(),'pergunta_verificacao'=>'Qual cor é o estojo?','resposta_verificacao'=>encrypt('Preto'),
            'status'=>'ABERTO','expira_em'=>now()->addDays(60),
        ]);
        $claimant = User::where('email','admin@campus.edu.br')->firstOrFail();
        $claim = $item->reivindicacoes()->create([
            'solicitante_id'=>$claimant->id,'resposta_verificacao'=>'Preto','status'=>'APROVADA','decidido_por'=>$this->owner->id,'decidido_em'=>now(),
        ]);
        $claim->mensagens()->create(['remetente_id'=>$claimant->id,'conteudo'=>'Vamos combinar a devolução.']);

        $this->actingAs($claimant,'sanctum')->getJson("/api/claims/{$claim->id}/messages")
            ->assertOk()
            ->assertJsonPath('mensagens.0.remetente.nome',$claimant->nome)
            ->assertJsonMissingPath('mensagens.0.remetente.email')
            ->assertJsonMissingPath('mensagens.0.remetente.senha_hash');
    }


    public function test_suspended_user_cannot_use_an_existing_token(): void
    {
        $this->owner->update(['status'=>'SUSPENSO']);

        $this->actingAs($this->owner,'sanctum')->getJson('/api/auth/me')->assertForbidden();
    }


    public function test_moderator_can_restore_an_auto_hidden_item_when_dismissing_a_report(): void
    {
        $item = Item::create([
            'usuario_id'=>$this->owner->id,'categoria_id'=>$this->category->id,'local_id'=>$this->location->id,
            'tipo'=>'PERDIDO','titulo'=>'Chapéu de teste','descricao'=>'Chapéu usado para testar restauração após moderação.',
            'data_ocorrencia'=>today(),'status'=>'OCULTO','expira_em'=>now()->addDays(60),
        ]);
        $reporter = User::where('email','admin@campus.edu.br')->firstOrFail();
        $report = Denuncia::create([
            'item_id'=>$item->id,'denunciante_id'=>$reporter->id,'motivo'=>'Conteúdo analisado pela moderação.','status'=>'ABERTA',
        ]);
        $moderator = User::where('email','moderador@campus.edu.br')->firstOrFail();

        $this->actingAs($moderator,'sanctum')->patchJson("/api/moderation/reports/{$report->id}", [
            'acao'=>'MANTER_ANUNCIO','justificativa'=>'Anúncio revisado e sem violação identificada.',
        ])->assertOk();

        $this->assertDatabaseHas('itens',['id'=>$item->id,'status'=>'ABERTO']);
    }


    public function test_author_is_suspended_after_three_procedent_reports(): void
    {
        $reporter = User::where('email','admin@campus.edu.br')->firstOrFail();
        $moderator = User::where('email','moderador@campus.edu.br')->firstOrFail();

        for ($number = 1; $number <= 3; $number++) {
            $item = Item::create([
                'usuario_id'=>$this->owner->id,'categoria_id'=>$this->category->id,'local_id'=>$this->location->id,
                'tipo'=>'PERDIDO','titulo'=>"Anúncio denunciado {$number}",'descricao'=>'Anúncio usado para testar denúncia procedente.',
                'data_ocorrencia'=>today(),'status'=>'ABERTO','expira_em'=>now()->addDays(60),
            ]);
            $report = Denuncia::create([
                'item_id'=>$item->id,'denunciante_id'=>$reporter->id,'motivo'=>'Violação confirmada para o teste.','status'=>'ABERTA',
            ]);

            $this->actingAs($moderator,'sanctum')->patchJson("/api/moderation/reports/{$report->id}", [
                'acao'=>'REMOVER_ANUNCIO','justificativa'=>'Violação confirmada após revisão da moderação.',
            ])->assertOk();
            $this->owner->refresh();
            if ($number < 3) $this->assertSame('ATIVO',$this->owner->status);
        }

        $this->assertSame('SUSPENSO',$this->owner->status);
        $this->assertDatabaseHas('notificacoes',['usuario_id'=>$this->owner->id,'tipo'=>'CONTA_SUSPENSA']);
    }

    public function test_registration_rejects_non_institutional_domains(): void
    {
        $this->postJson('/api/auth/register', ['nome'=>'Pessoa de teste','email'=>'pessoa@gmail.com','senha'=>'SenhaForte123','senha_confirmation'=>'SenhaForte123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
