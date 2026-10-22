<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Item extends Model
{
    use HasUuids;
    protected $table = 'itens';
    protected $fillable = ['usuario_id','categoria_id','local_id','ponto_guarda_id','tipo','titulo','descricao','data_ocorrencia','pergunta_verificacao','resposta_verificacao','status','expira_em','aviso_expiracao_enviado_em','resolvido_em','atualizado_conteudo_em'];
    protected $hidden = ['pergunta_verificacao','resposta_verificacao'];
    protected function casts(): array { return ['data_ocorrencia' => 'date', 'expira_em' => 'datetime', 'aviso_expiracao_enviado_em' => 'datetime', 'resolvido_em' => 'datetime', 'atualizado_conteudo_em' => 'datetime']; }
    public function publicador() { return $this->belongsTo(User::class, 'usuario_id'); }
    public function categoria() { return $this->belongsTo(Categoria::class, 'categoria_id'); }
    public function local() { return $this->belongsTo(LocalCampus::class, 'local_id'); }
    public function pontoGuarda() { return $this->belongsTo(PontoGuarda::class, 'ponto_guarda_id'); }
    public function fotos() { return $this->hasMany(ItemFoto::class, 'item_id')->orderBy('ordem'); }
    public function reivindicacoes() { return $this->hasMany(Reivindicacao::class, 'item_id'); }
    public function denuncias() { return $this->hasMany(Denuncia::class, 'item_id'); }
}
