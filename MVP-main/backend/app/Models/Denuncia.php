<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class Denuncia extends Model { use HasUuids; protected $table='denuncias'; protected $fillable=['item_id','denunciante_id','motivo','status','acao_moderacao','resolvida_por','resolucao','resolvida_em']; protected function casts():array{return ['resolvida_em'=>'datetime'];} public function item(){return $this->belongsTo(Item::class,'item_id');} public function denunciante(){return $this->belongsTo(User::class,'denunciante_id');} }
