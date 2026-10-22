<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class Reivindicacao extends Model {
 use HasUuids; protected $table='reivindicacoes'; protected $fillable=['item_id','solicitante_id','resposta_verificacao','status','motivo_recusa','decidido_por','decidido_em','encaminhada_moderacao_em']; protected $hidden=['resposta_verificacao']; protected function casts():array{return ['decidido_em'=>'datetime','encaminhada_moderacao_em'=>'datetime'];}
 public function item(){return $this->belongsTo(Item::class,'item_id');} public function solicitante(){return $this->belongsTo(User::class,'solicitante_id');} public function decisor(){return $this->belongsTo(User::class,'decidido_por');} public function mensagens(){return $this->hasMany(Mensagem::class,'reivindicacao_id')->orderBy('created_at');}
}
