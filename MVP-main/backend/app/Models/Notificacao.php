<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class Notificacao extends Model { use HasUuids; protected $table='notificacoes'; protected $fillable=['usuario_id','tipo','titulo','mensagem','dados','lida_em','email_enviado']; protected function casts():array{return ['dados'=>'array','lida_em'=>'datetime','email_enviado'=>'boolean'];} }
