<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $table = 'usuarios';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['nome','email','senha_hash','papel','status','emails_ativados','token_verificacao_hash','verificacao_expira_em','email_verificado_em'];
    protected $hidden = ['senha_hash','token_verificacao_hash','verificacao_expira_em'];
    protected function casts(): array { return ['email_verificado_em' => 'datetime', 'verificacao_expira_em' => 'datetime', 'emails_ativados' => 'boolean']; }
    public function getAuthPassword(): string { return $this->senha_hash; }
    public function isModerator(): bool { return in_array($this->papel, ['MODERADOR','ADMINISTRADOR'], true); }
    public function isAdmin(): bool { return $this->papel === 'ADMINISTRADOR'; }
    public function itens() { return $this->hasMany(Item::class, 'usuario_id'); }
    public function reivindicacoes() { return $this->hasMany(Reivindicacao::class, 'solicitante_id'); }
    public function notificacoes() { return $this->hasMany(Notificacao::class, 'usuario_id'); }
}
