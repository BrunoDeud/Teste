<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class Mensagem extends Model { use HasUuids; protected $table='mensagens'; protected $fillable=['reivindicacao_id','remetente_id','conteudo']; protected $hidden=['remetente_id']; public function remetente(){return $this->belongsTo(User::class,'remetente_id')->select(['id','nome','papel']);} }
