import { useCallback, useEffect, useState } from 'react';
import { Link, Navigate, Route, Routes, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api, clearSession, friendlyError, imageUrl, setSession } from './api.js';

const dateToday = () => { const now = new Date(); const offset = now.getTimezoneOffset(); return new Date(now.getTime() - offset * 60000).toISOString().slice(0, 10); };
const formatDate = (value) => {
  if (!value) return 'Data não informada';
  const date = new Date(value.length === 10 ? `${value}T12:00:00` : value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('pt-BR');
};
const normalize = (value = '') => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const sensitiveCategory = (name = '') => ['documentos', 'cartoes', 'eletronicos'].includes(normalize(name));
const statusLabels = { ABERTO: 'Aberto', PONTO_GUARDA: 'No ponto de guarda', RESOLVIDO: 'Resolvido', EXPIRADO: 'Expirado', OCULTO: 'Oculto', PENDENTE: 'Pendente', APROVADA: 'Aprovada', RECUSADA: 'Recusada', CANCELADA: 'Cancelada', DEVOLVIDA: 'Devolvida', ABERTA: 'Aberta', TRATADA: 'Tratada' };

function Alert({ type = 'info', children, onClose }) {
  if (!children) return null;
  return <div className={`alert alert-${type}`} role="status"><span>{children}</span>{onClose && <button className="alert-close" aria-label="Fechar mensagem" onClick={onClose}>×</button>}</div>;
}

function Spinner({ text = 'Carregando...' }) {
  return <div className="loading-state"><span className="spinner" aria-hidden="true" />{text}</div>;
}

function EmptyState({ icon = '✦', title, description, action }) {
  return <div className="empty-state"><span className="empty-icon">{icon}</span><h3>{title}</h3><p>{description}</p>{action}</div>;
}

function App() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [user, setUser] = useState(() => {
    try { return JSON.parse(localStorage.getItem('encontrei_user') || 'null'); } catch { return null; }
  });
  const [sessionChecking, setSessionChecking] = useState(Boolean(localStorage.getItem('encontrei_token')));
  const [verificationMessage, setVerificationMessage] = useState('');
  const tokenParam = searchParams.get('verificar');

  useEffect(() => {
    let cancelled = false;
    if (tokenParam) {
      api('/auth/verify-email', { method: 'POST', body: { token: tokenParam } })
        .then((response) => {
          if (cancelled) return;
          setSession(response.token, response.user);
          setUser(response.user);
          setVerificationMessage('E-mail verificado! Sua conta está ativa.');
          setSearchParams({}, { replace: true });
          navigate('/', { replace: true });
        })
        .catch((error) => {
          if (!cancelled) {
            setVerificationMessage(friendlyError(error));
            setSearchParams({}, { replace: true });
          }
        });
    }
    return () => { cancelled = true; };
  }, [tokenParam, navigate, setSearchParams]);

  useEffect(() => {
    if (tokenParam) { setSessionChecking(false); return; }
    if (!localStorage.getItem('encontrei_token')) { setSessionChecking(false); return; }
    api('/auth/me').then((response) => {
      setUser(response.user);
      localStorage.setItem('encontrei_user', JSON.stringify(response.user));
    }).catch(() => { clearSession(); setUser(null); }).finally(() => setSessionChecking(false));
  }, []);

  const logout = useCallback(async () => {
    try { await api('/auth/logout', { method: 'POST' }); } catch { /* encerra a sessão local mesmo sem conexão */ }
    clearSession();
    setUser(null);
    navigate('/login');
  }, [navigate]);

  const authSuccess = (response) => {
    setSession(response.token, response.user);
    setUser(response.user);
    navigate('/');
  };

  if (sessionChecking) return <div className="startup"><div className="brand-mark">e</div><Spinner text="Preparando seu espaço..." /></div>;

  return <div className="app-shell">
    <Header user={user} onLogout={logout} />
    {verificationMessage && <div className="global-alert"><Alert type={verificationMessage.toLowerCase().includes('expirado') || verificationMessage.toLowerCase().includes('inválido') ? 'error' : 'success'} onClose={() => setVerificationMessage('')}>{verificationMessage}</Alert></div>}
    <main className="main-content">
      <Routes>
        <Route path="/" element={<HomePage user={user} />} />
        <Route path="/login" element={user ? <Navigate to="/" replace /> : <AuthPage mode="login" onSuccess={authSuccess} />} />
        <Route path="/cadastro" element={user ? <Navigate to="/" replace /> : <AuthPage mode="register" onSuccess={authSuccess} />} />
        <Route path="/item/:id" element={<ItemDetail user={user} />} />
        <Route path="/novo" element={<Protected user={user}><PublishPage user={user} /></Protected>} />
        <Route path="/meus-itens" element={<Protected user={user}><MyItemsPage user={user} /></Protected>} />
        <Route path="/notificacoes" element={<Protected user={user}><NotificationsPage user={user} /></Protected>} />
        <Route path="/moderacao" element={<Protected user={user}><RoleProtected user={user} roles={['MODERADOR','ADMINISTRADOR']}><ModerationPage user={user} /></RoleProtected></Protected>} />
        <Route path="/administracao" element={<Protected user={user}><RoleProtected user={user} roles={['ADMINISTRADOR']}><AdminPage /></RoleProtected></Protected>} />
        <Route path="*" element={<NotFound />} />
      </Routes>
    </main>
    <Footer />
  </div>;
}

function Header({ user, onLogout }) {
  const [menuOpen, setMenuOpen] = useState(false);
  return <header className="site-header">
    <div className="header-inner">
      <Link to="/" className="brand" aria-label="Encontrei — página inicial">
        <span className="brand-mark">e</span><span className="brand-words">encontrei<span>ACHADOS & PERDIDOS</span></span>
      </Link>
      <button className="mobile-menu-toggle" aria-label="Abrir menu" aria-expanded={menuOpen} onClick={() => setMenuOpen(!menuOpen)}>{menuOpen ? '×' : '☰'}</button>
      <nav className={`main-nav ${menuOpen ? 'nav-open' : ''}`} aria-label="Navegação principal" onClick={() => setMenuOpen(false)}>
        <Link to="/">Explorar</Link>
        {user ? <>
          <Link to="/meus-itens">Meus itens</Link>
          <Link to="/notificacoes">Notificações</Link>
          {['MODERADOR','ADMINISTRADOR'].includes(user.papel) && <Link to="/moderacao">Moderação</Link>}
          {user.papel === 'ADMINISTRADOR' && <Link to="/administracao">Administração</Link>}
          <Link to="/novo" className="nav-publish">＋ Publicar item</Link>
          <button className="nav-logout" onClick={onLogout}>Sair</button>
        </> : <>
          <Link to="/login" className="nav-login">Entrar</Link>
          <Link to="/cadastro" className="nav-publish">Criar conta</Link>
        </>}
      </nav>
    </div>
  </header>;
}

function Protected({ user, children }) {
  return user ? children : <Navigate to="/login" replace />;
}
function RoleProtected({ user, roles, children }) {
  if (!roles.includes(user?.papel)) return <section className="page-wrap"><EmptyState icon="⌁" title="Acesso restrito" description="Seu perfil não tem permissão para abrir esta área." action={<Link className="btn btn-primary" to="/">Voltar ao início</Link>} /></section>;
  return children;
}

function AuthPage({ mode, onSuccess }) {
  const navigate = useNavigate();
  const isRegister = mode === 'register';
  const [form, setForm] = useState({ nome: '', email: '', senha: '', senha_confirmation: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [verificationToken, setVerificationToken] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [resending, setResending] = useState(false);
  const change = (event) => {
    const { name, value } = event.target;
    if (name === 'tipo' && value === 'PERDIDO') {
      setForm({ ...form, tipo: value, ponto_guarda_id: '' });
      return;
    }
    if (name === 'ponto_guarda_id' && value) {
      const point = guardPoints.find((entry) => entry.id === value);
      setForm({ ...form, ponto_guarda_id: value, local_id: point?.local_id || form.local_id });
      return;
    }
    setForm({ ...form, [name]: value });
  };

  const submit = async (event) => {
    event.preventDefault(); setBusy(true); setError(''); setSuccess('');
    try {
      if (isRegister) {
        const response = await api('/auth/register', { method: 'POST', body: form });
        setSuccess(response.message);
        if (response.verification_token_dev) setVerificationToken(response.verification_token_dev);
      } else {
        const response = await api('/auth/login', { method: 'POST', body: { email: form.email, senha: form.senha } });
        onSuccess(response);
      }
    } catch (err) { setError(friendlyError(err)); }
    finally { setBusy(false); }
  };

  const verifyDev = async () => {
    setBusy(true); setError('');
    try { const response = await api('/auth/verify-email', { method: 'POST', body: { token: verificationToken } }); onSuccess(response); }
    catch (err) { setError(friendlyError(err)); }
    finally { setBusy(false); }
  };

  const resend = async () => {
    setResending(true); setError(''); setSuccess('');
    try {
      const response = await api('/auth/resend-verification', { method: 'POST', body: { email: form.email } });
      setSuccess(response.message);
      if (response.verification_token_dev) setVerificationToken(response.verification_token_dev);
    } catch (err) { setError(friendlyError(err)); }
    finally { setResending(false); }
  };

  return <div className="auth-layout">
    <section className="auth-story">
      <span className="eyebrow">COMUNIDADE DO CAMPUS</span>
      <h1>{isRegister ? 'Toda coisa perdida merece uma segunda chance.' : 'O que se perde por aí pode estar mais perto do que você imagina.'}</h1>
      <p>Um espaço simples e seguro para conectar quem encontrou um objeto a quem está procurando por ele.</p>
      <div className="story-points"><span>01 <b>Registre</b> o que encontrou ou perdeu.</span><span>02 <b>Encontre</b> anúncios do campus.</span><span>03 <b>Devolva</b> com segurança pelo chat.</span></div>
      <div className="story-doodle" aria-hidden="true">↗</div>
    </section>
    <section className="auth-panel">
      <div className="auth-card">
        <span className="eyebrow">{isRegister ? 'COMECE POR AQUI' : 'BEM-VINDO DE VOLTA'}</span>
        <h2>{isRegister ? 'Crie sua conta' : 'Entre na sua conta'}</h2>
        <p className="muted">{isRegister ? 'Use seu e-mail institucional para participar.' : 'Entre com seu e-mail institucional verificado.'}</p>
        <Alert type="error">{error}</Alert><Alert type="success">{success}</Alert>
        {!verificationToken && <form className="form-stack" onSubmit={submit}>
          {isRegister && <label>Nome completo<input name="nome" value={form.nome} onChange={change} minLength="2" maxLength="120" autoComplete="name" required placeholder="Como podemos chamar você?" /></label>}
          <label>E-mail institucional<input name="email" value={form.email} onChange={change} type="email" autoComplete="email" required placeholder="voce@campus.edu.br" /></label>
          <label>Senha<div className="password-wrap"><input name="senha" value={form.senha} onChange={change} type={showPassword ? 'text' : 'password'} minLength="8" autoComplete={isRegister ? 'new-password' : 'current-password'} required placeholder="Mínimo de 8 caracteres" /> <button type="button" className="show-password" onClick={() => setShowPassword(!showPassword)}>{showPassword ? 'Ocultar' : 'Mostrar'}</button></div></label>
          {isRegister && <label>Confirme a senha<input name="senha_confirmation" value={form.senha_confirmation} onChange={change} type={showPassword ? 'text' : 'password'} minLength="8" required autoComplete="new-password" placeholder="Digite a senha novamente" /></label>}
          <button className="btn btn-primary btn-full" type="submit" disabled={busy}>{busy ? 'Aguarde...' : isRegister ? 'Criar conta' : 'Entrar'} <span aria-hidden="true">→</span></button>
          {!isRegister && <button type="button" className="text-button resend-link" onClick={resend} disabled={resending || !form.email}>{resending ? 'Enviando...' : 'Precisa de um novo link de verificação?'}</button>}
        </form>}
        {verificationToken && <div className="dev-verify"><span className="verify-symbol">✉</span><h3>Simulação de e-mail</h3><p>Em ambiente local, use este botão para simular o clique no link de verificação enviado por e-mail.</p><button className="btn btn-primary btn-full" onClick={verifyDev} disabled={busy}>{busy ? 'Verificando...' : 'Verificar e ativar conta'}</button></div>}
        <div className="auth-switch">{isRegister ? <>Já tem uma conta? <Link to="/login">Entrar</Link></> : <>Ainda não tem conta? <Link to="/cadastro">Criar conta</Link></>}</div>
        <p className="privacy-note">🔒 Seu contato pessoal não é exibido para outros usuários.</p>
      </div>
    </section>
  </div>;
}

function HomePage({ user }) {
  const [categories, setCategories] = useState([]);
  const [locations, setLocations] = useState([]);
  const [items, setItems] = useState([]);
  const [filters, setFilters] = useState({ q: '', tipo: '', categoria_id: '', local_id: '', status: '', data_inicio: '', data_fim: '' });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadItems = useCallback(async (selected = filters) => {
    setLoading(true); setError('');
    try {
      const params = new URLSearchParams();
      Object.entries(selected).forEach(([key, value]) => { if (value) params.set(key, value); });
      const response = await api(`/items?${params.toString()}`);
      setItems(response.data || []);
    } catch (err) { setError(friendlyError(err)); setItems([]); }
    finally { setLoading(false); }
  }, [filters]);

  useEffect(() => {
    Promise.all([api('/categories'), api('/locations')]).then(([cats, locs]) => { setCategories(cats); setLocations(locs); }).catch(() => {});
    loadItems({ q: '', tipo: '', categoria_id: '', local_id: '', status: '', data_inicio: '', data_fim: '' });
    // A carga inicial deve acontecer uma vez; filtros são aplicados pelo formulário.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const updateFilter = (event) => setFilters({ ...filters, [event.target.name]: event.target.value });
  const clearFilters = () => { const empty = { q: '', tipo: '', categoria_id: '', local_id: '', status: '', data_inicio: '', data_fim: '' }; setFilters(empty); loadItems(empty); };

  return <>
    <section className="hero-section">
      <div className="hero-copy"><span className="eyebrow eyebrow-light">PEQUENAS COISAS, GRANDES REENCONTROS</span><h1>Perdeu algo?<br /><em>Vamos encontrar.</em></h1><p>O próximo reencontro pode começar com uma busca simples. Explore os itens encontrados no campus ou avise a comunidade sobre o que você perdeu.</p><div className="hero-actions"><a href="#explorar" className="btn btn-cream">Encontrar um item <span>↓</span></a>{user && <Link to="/novo" className="btn btn-outline-light">＋ Publicar anúncio</Link>}</div><div className="hero-note"><span className="avatars">● ● ●</span><span>Uma comunidade que ajuda a cuidar do que importa.</span></div></div>
      <div className="hero-art" aria-hidden="true"><div className="art-circle art-circle-one"/><div className="art-circle art-circle-two"/><div className="art-paper"><span className="art-pin">✳</span><span className="art-line art-line-long"/><span className="art-line"/><span className="art-line art-line-short"/><div className="art-stamp">ACHADO<br />NO CAMPUS</div></div><div className="art-tag">seu próximo<br />reencontro ↗</div></div>
    </section>
    <section className="how-it-works content-width"><div><span className="eyebrow">COMO FUNCIONA</span><h2>Três passos para<br />um bom reencontro.</h2></div><div className="how-step"><span>01</span><div><h3>Procure</h3><p>Use a busca e os filtros para encontrar seu objeto.</p></div></div><div className="how-step"><span>02</span><div><h3>Confirme</h3><p>Responda aos detalhes de verificação para comprovar que é seu.</p></div></div><div className="how-step"><span>03</span><div><h3>Combine</h3><p>Converse pelo chat interno e organize a devolução.</p></div></div></section>
    <section className="explore-section" id="explorar"><div className="content-width">
      <div className="section-heading"><div><span className="eyebrow">VITRINE DA COMUNIDADE</span><h2>Vamos procurar?</h2><p className="muted">Anúncios ativos publicados pela comunidade do campus.</p></div><span className="listing-count">{loading ? '…' : `${items.length} ${items.length === 1 ? 'anúncio' : 'anúncios'}`}</span></div>
      <form className="filters-panel" onSubmit={(event) => { event.preventDefault(); loadItems(filters); }}>
        <label className="search-input"><span aria-hidden="true">⌕</span><input name="q" value={filters.q} onChange={updateFilter} placeholder="Busque por objeto, cor ou detalhe..." aria-label="Buscar anúncios por texto" /></label>
        <label className="filter-field"><span>Tipo</span><select name="tipo" value={filters.tipo} onChange={updateFilter}><option value="">Todos os tipos</option><option value="ACHADO">Achados</option><option value="PERDIDO">Perdidos</option></select></label>
        <label className="filter-field"><span>Categoria</span><select name="categoria_id" value={filters.categoria_id} onChange={updateFilter}><option value="">Todas</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.nome}</option>)}</select></label>
        <label className="filter-field"><span>Local</span><select name="local_id" value={filters.local_id} onChange={updateFilter}><option value="">Todos</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.nome}{location.bloco ? ` · Bloco ${location.bloco}` : ''}</option>)}</select></label>
        <label className="filter-field"><span>Status</span><select name="status" value={filters.status} onChange={updateFilter}><option value="">Todos</option><option value="ABERTO">Aberto</option><option value="PONTO_GUARDA">Ponto de guarda</option></select></label>
        <details className="date-filters"><summary>Período</summary><div><label>De<input type="date" name="data_inicio" value={filters.data_inicio} onChange={updateFilter} /></label><label>Até<input type="date" name="data_fim" value={filters.data_fim} onChange={updateFilter} /></label></div></details>
        <button type="submit" className="btn btn-primary filter-submit">Buscar</button><button type="button" className="filter-clear" onClick={clearFilters}>Limpar</button>
      </form>
      <Alert type="error">{error}</Alert>
      {loading ? <Spinner text="Buscando anúncios..." /> : items.length ? <div className="item-grid">{items.map((item) => <ItemCard key={item.id} item={item} />)}</div> : <EmptyState icon="⌕" title="Nenhum anúncio por aqui" description="Não encontramos itens com esses filtros. Tente outra busca ou publique um anúncio de item perdido." action={user ? <Link to="/novo" className="btn btn-primary">Publicar item perdido</Link> : <Link to="/cadastro" className="btn btn-primary">Participar da comunidade</Link>} />}
      <div className="security-strip"><span className="security-icon">✳</span><div><strong>Segurança em primeiro lugar</strong><p>Detalhes secretos ajudam a confirmar a propriedade. E-mails e telefones nunca são mostrados entre usuários.</p></div><Link to="/novo">Como publicar <span>→</span></Link></div>
    </div></section>
  </>;
}

function ItemCard({ item }) {
  const photo = item.fotos?.[0];
  const found = item.tipo === 'ACHADO';
  return <article className="item-card">
    <Link to={`/item/${item.id}`} className="item-card-image" aria-label={`Ver detalhes de ${item.titulo}`}>
      {photo ? <img src={imageUrl(photo.url || photo.caminho)} alt={`Foto de ${item.titulo}`} loading="lazy" /> : <div className="photo-placeholder"><span>✳</span><small>SEM FOTO</small></div>}
      <span className={`type-pill ${found ? 'type-found' : 'type-lost'}`}>{found ? '✓ Encontrado' : '⌕ Perdido'}</span>
      {item.status === 'PONTO_GUARDA' && <span className="guard-pill">Ponto de guarda</span>}
    </Link>
    <div className="item-card-body"><div className="item-meta"><span>{item.categoria?.nome || 'Sem categoria'}</span><span>{formatDate(item.data_ocorrencia)}</span></div><Link to={`/item/${item.id}`} className="item-title">{item.titulo}</Link><p className="item-location">⌖ {item.local?.nome || 'Local não informado'}{item.local?.bloco ? ` · ${item.local.bloco}` : ''}</p><p className="item-description">{item.descricao}</p><Link to={`/item/${item.id}`} className="item-open">Ver anúncio <span>↗</span></Link></div>
  </article>;
}

function ItemDetail({ user }) {
  const { id } = useParams();
  const navigate = useNavigate();
  const [item, setItem] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [claimOpen, setClaimOpen] = useState(false);
  const [claimAnswer, setClaimAnswer] = useState('');
  const [verificationQuestion, setVerificationQuestion] = useState('');
  const [reportOpen, setReportOpen] = useState(false);
  const [reportReason, setReportReason] = useState('');
  const [notice, setNotice] = useState({ type: '', text: '' });
  const [busy, setBusy] = useState(false);

  const reload = useCallback(() => { setLoading(true); api(`/items/${id}`).then((response) => setItem(response.item)).catch((err) => setError(friendlyError(err))).finally(() => setLoading(false)); }, [id]);
  useEffect(() => { reload(); }, [reload]);

  const openClaimForm = async () => {
    if (!user) { navigate('/login'); return; }
    setBusy(true); setNotice({ type: '', text: '' });
    try { const response = await api(`/items/${id}/verification-question`); setVerificationQuestion(response.pergunta_verificacao); setClaimOpen(true); }
    catch (err) { setNotice({ type: 'error', text: friendlyError(err) }); }
    finally { setBusy(false); }
  };

  const submitClaim = async (event) => {
    event.preventDefault(); setBusy(true); setNotice({ type: '', text: '' });
    try { const response = await api(`/items/${id}/claims`, { method: 'POST', body: { resposta_verificacao: claimAnswer } }); setNotice({ type: 'success', text: response.message }); setClaimOpen(false); setClaimAnswer(''); }
    catch (err) { setNotice({ type: 'error', text: friendlyError(err) }); }
    finally { setBusy(false); }
  };
  const submitReport = async (event) => {
    event.preventDefault(); setBusy(true); setNotice({ type: '', text: '' });
    try { const response = await api(`/items/${id}/reports`, { method: 'POST', body: { motivo: reportReason } }); setNotice({ type: 'success', text: response.message }); setReportOpen(false); setReportReason(''); }
    catch (err) { setNotice({ type: 'error', text: friendlyError(err) }); }
    finally { setBusy(false); }
  };

  if (loading) return <div className="page-wrap"><Spinner text="Carregando detalhes..." /></div>;
  if (!item) return <div className="page-wrap"><Alert type="error">{error || 'Anúncio não encontrado.'}</Alert><Link to="/" className="btn btn-secondary">Voltar para a busca</Link></div>;
  const canClaim = user && item.tipo === 'ACHADO' && ['ABERTO','PONTO_GUARDA'].includes(item.status) && item.usuario_id !== user.id;
  const photo = item.fotos?.[0];

  return <section className="page-wrap detail-wrap">
    <button className="back-link" onClick={() => navigate(-1)}>← Voltar para a busca</button>
    <Alert type={notice.type}>{notice.text}</Alert>
    <div className="detail-layout">
      <div className="detail-photo-panel">{photo ? <img src={imageUrl(photo.url || photo.caminho)} alt={`Foto de ${item.titulo}`} /> : <div className="photo-placeholder detail-placeholder"><span>✳</span><small>SEM FOTO</small></div>}<span className={`type-pill ${item.tipo === 'ACHADO' ? 'type-found' : 'type-lost'}`}>{item.tipo === 'ACHADO' ? '✓ Encontrado' : '⌕ Perdido'}</span></div>
      <div className="detail-content"><div className="item-meta"><span>{item.categoria?.nome}</span><span>Publicado em {formatDate(item.created_at)}</span></div><h1>{item.titulo}</h1><div className="detail-location">⌖ {item.local?.nome}{item.local?.bloco ? ` · Bloco ${item.local.bloco}` : ''}</div><div className="detail-status"><span className={`status-dot status-${(item.status || '').toLowerCase()}`} />{statusLabels[item.status] || item.status}</div><div className="detail-divider"/><h3>Sobre o objeto</h3><p className="detail-description">{item.descricao}</p><div className="detail-facts"><div><span>Data da ocorrência</span><strong>{formatDate(item.data_ocorrencia)}</strong></div><div><span>Categoria</span><strong>{item.categoria?.nome || '—'}</strong></div>{item.ponto_guarda && <div><span>Ponto de guarda</span><strong>{item.ponto_guarda.nome}</strong></div>}</div>
        {item.tipo === 'ACHADO' && item.status === 'PONTO_GUARDA' && <div className="guard-note"><strong>Este objeto está sob guarda.</strong><p>Após enviar a reivindicação, a equipe de moderação analisará seu pedido.</p></div>}
        {canClaim && !claimOpen && <button className="btn btn-primary btn-full detail-action" disabled={busy} onClick={openClaimForm}>{busy ? 'Carregando pergunta...' : <>É meu — reivindicar item <span>→</span></>}</button>}
        {!user && item.tipo === 'ACHADO' && ['ABERTO','PONTO_GUARDA'].includes(item.status) && <Link to="/login" className="btn btn-primary btn-full detail-action">Entre para reivindicar este item</Link>}
        {claimOpen && <form className="inline-form" onSubmit={submitClaim}><h3>Vamos confirmar que é seu</h3><p>Responda com detalhes que ajudem quem encontrou a reconhecer o objeto. Sua resposta será privada.</p><div className="verification-question"><span>Pergunta de verificação</span><strong>{verificationQuestion}</strong></div><label>Sua resposta<textarea value={claimAnswer} onChange={(event) => setClaimAnswer(event.target.value)} minLength="2" maxLength="2000" required placeholder="Descreva um detalhe que só o dono saberia..." rows="4" /></label><div className="form-actions"><button className="btn btn-primary" disabled={busy}>{busy ? 'Enviando...' : 'Enviar solicitação'}</button><button type="button" className="btn btn-quiet" onClick={() => setClaimOpen(false)}>Cancelar</button></div></form>}
        {user && item.usuario_id !== user.id && <button className="text-button report-link" onClick={() => setReportOpen(!reportOpen)}>⚑ Denunciar anúncio</button>}
        {reportOpen && <form className="inline-form report-form" onSubmit={submitReport}><label>Motivo da denúncia<textarea value={reportReason} onChange={(event) => setReportReason(event.target.value)} required minLength="5" maxLength="2000" rows="3" placeholder="Explique por que este anúncio deve ser analisado..." /></label><div className="form-actions"><button className="btn btn-secondary" disabled={busy}>{busy ? 'Enviando...' : 'Enviar denúncia'}</button><button type="button" className="btn btn-quiet" onClick={() => setReportOpen(false)}>Cancelar</button></div></form>}
        <p className="contact-privacy">🔒 A plataforma não compartilha e-mails ou telefones. A conversa é feita internamente após a aprovação.</p>
      </div>
    </div>
  </section>;
}

function PublishPage({ user }) {
  const navigate = useNavigate();
  const [categories, setCategories] = useState([]);
  const [locations, setLocations] = useState([]);
  const [guardPoints, setGuardPoints] = useState([]);
  const [form, setForm] = useState({ tipo: 'ACHADO', titulo: '', categoria_id: '', descricao: '', local_id: '', data_ocorrencia: dateToday(), pergunta_verificacao: '', resposta_verificacao: '', ponto_guarda_id: '' });
  const [files, setFiles] = useState([]);
  const [previews, setPreviews] = useState([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});
  const [matches, setMatches] = useState([]);

  useEffect(() => { Promise.all([api('/categories'),api('/locations'),api('/guard-points')]).then(([cats,locs,points]) => { setCategories(cats); setLocations(locs); setGuardPoints(points); }).catch((err) => setError(friendlyError(err))).finally(() => setLoading(false)); }, []);
  useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews]);
  const category = categories.find((entry) => entry.id === form.categoria_id);
  const guarded = form.tipo === 'ACHADO' && sensitiveCategory(category?.nome);
  const isModerator = ['MODERADOR','ADMINISTRADOR'].includes(user.papel);
  const change = (event) => {
    const { name, value } = event.target;
    if (name === 'tipo' && value === 'PERDIDO') {
      setForm({ ...form, tipo: value, ponto_guarda_id: '' });
      return;
    }
    if (name === 'ponto_guarda_id' && value) {
      const point = guardPoints.find((entry) => entry.id === value);
      setForm({ ...form, ponto_guarda_id: value, local_id: point?.local_id || form.local_id });
      return;
    }
    setForm({ ...form, [name]: value });
  };

  const selectFiles = (event) => {
    const selected = Array.from(event.target.files || []);
    setError(''); setFieldErrors({});
    if (selected.length > 3) { setError('Escolha no máximo 3 fotos.'); event.target.value = ''; return; }
    const invalid = selected.find((file) => !['image/jpeg','image/png','image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024);
    if (invalid) { setError('Cada foto deve ser JPG, PNG ou WEBP e ter até 5 MB.'); event.target.value = ''; return; }
    previews.forEach((url) => URL.revokeObjectURL(url));
    setFiles(selected); setPreviews(selected.map((file) => URL.createObjectURL(file)));
  };

  const submit = async (event) => {
    event.preventDefault(); setBusy(true); setError(''); setFieldErrors({}); setMatches([]);
    if (form.tipo === 'ACHADO' && files.length === 0) { setError('Para publicar um item achado, anexe ao menos uma foto.'); setBusy(false); return; }
    if (guarded && !isModerator) { setError('Itens de documentos, cartões e eletrônicos devem ser cadastrados pela equipe do ponto de guarda.'); setBusy(false); return; }
    const body = new FormData();
    Object.entries(form).forEach(([key,value]) => { if (value && key !== 'ponto_guarda_id') body.append(key, value); });
    if (form.ponto_guarda_id) body.append('ponto_guarda_id', form.ponto_guarda_id);
    files.forEach((file) => body.append('fotos[]', file));
    try {
      const response = await api(isModerator && form.ponto_guarda_id ? '/moderation/guard-items' : '/items', { method: 'POST', body });
      setMatches(response.correspondencias || []);
      if ((response.correspondencias || []).length > 0) {
        setError('');
        navigate('/meus-itens', { state: { publishSuccess: response.message, matches: response.correspondencias } });
      } else {
        navigate('/meus-itens', { state: { publishSuccess: response.message } });
      }
    } catch (err) { setError(friendlyError(err)); setFieldErrors(err.errors || {}); }
    finally { setBusy(false); }
  };

  if (loading) return <section className="page-wrap"><Spinner text="Carregando formulário..." /></section>;
  return <section className="page-wrap narrow-page">
    <Link to="/" className="back-link">← Voltar aos anúncios</Link>
    <div className="page-title-block"><span className="eyebrow">COMPARTILHE COM A COMUNIDADE</span><h1>Publicar um item</h1><p>Quanto mais detalhes você informar, maior a chance de um reencontro.</p></div>
    <Alert type="error">{error}</Alert>
    <form className="publish-form" onSubmit={submit}>
      <div className="form-section-title"><span>01</span><div><h2>O que aconteceu?</h2><p>Primeiro, vamos entender que tipo de anúncio você quer criar.</p></div></div>
      <div className="type-choice-grid"><label className={`type-choice ${form.tipo === 'ACHADO' ? 'selected' : ''}`}><input type="radio" name="tipo" value="ACHADO" checked={form.tipo === 'ACHADO'} onChange={change} /><span className="choice-icon found-icon">✓</span><strong>Encontrei um objeto</strong><small>Quero ajudar a encontrar o dono.</small></label><label className={`type-choice ${form.tipo === 'PERDIDO' ? 'selected' : ''}`}><input type="radio" name="tipo" value="PERDIDO" checked={form.tipo === 'PERDIDO'} onChange={change} /><span className="choice-icon lost-icon">⌕</span><strong>Perdi um objeto</strong><small>Quero avisar que estou procurando.</small></label></div>
      <div className="form-section-title"><span>02</span><div><h2>Conte sobre o objeto</h2><p>Não inclua informações pessoais ou dados sensíveis na descrição pública.</p></div></div>
      <div className="form-grid"><label className="span-2">Título do anúncio<input name="titulo" value={form.titulo} onChange={change} minLength="3" maxLength="140" required placeholder="Ex.: Garrafa térmica verde" />{fieldErrors.titulo && <small className="field-error">{fieldErrors.titulo[0]}</small>}</label><label>Categoria<select name="categoria_id" value={form.categoria_id} onChange={change} required><option value="">Selecione uma categoria</option>{categories.map((entry) => <option key={entry.id} value={entry.id}>{entry.nome}</option>)}</select>{fieldErrors.categoria_id && <small className="field-error">{fieldErrors.categoria_id[0]}</small>}</label><label>Data da ocorrência<input type="date" name="data_ocorrencia" value={form.data_ocorrencia} max={dateToday()} onChange={change} required /></label><label className="span-2">Local {form.tipo === 'PERDIDO' ? 'onde você acha que perdeu' : 'onde encontrou'}<select name="local_id" value={form.local_id} onChange={change} required disabled={Boolean(form.ponto_guarda_id)}><option value="">Selecione o local</option>{locations.map((entry) => <option key={entry.id} value={entry.id}>{entry.nome}{entry.bloco ? ` · ${entry.bloco}` : ''}</option>)}</select>{fieldErrors.local_id && <small className="field-error">{fieldErrors.local_id[0]}</small>}</label><label className="span-2">Descrição<textarea name="descricao" value={form.descricao} onChange={change} rows="4" minLength="5" maxLength="5000" required placeholder="Cor, marca, detalhes visíveis e contexto..." />{fieldErrors.descricao && <small className="field-error">{fieldErrors.descricao[0]}</small>}</label></div>
      {guarded && <div className="guard-note"><strong>Este item precisa passar pelo ponto de guarda.</strong><p>Por segurança, itens de documentos, cartões e eletrônicos encontrados devem ser cadastrados pela equipe de moderação.</p></div>}
      {isModerator && form.tipo === 'ACHADO' && <label className="full-label">Ponto de guarda (opcional)<select name="ponto_guarda_id" value={form.ponto_guarda_id} onChange={change}><option value="">Publicar como anúncio comum</option>{guardPoints.map((point) => <option key={point.id} value={point.id}>{point.nome}{point.local?.nome ? ` · ${point.local.nome}` : ''}</option>)}</select><small>Preencher esse campo marca o anúncio como “No ponto de guarda”.</small></label>}
      <div className="form-section-title"><span>03</span><div><h2>Fotos do objeto</h2><p>{form.tipo === 'ACHADO' ? 'Itens achados precisam de pelo menos uma foto.' : 'Fotos são opcionais e ajudam no reconhecimento.'} Até 3 imagens JPG, PNG ou WEBP de 5 MB cada.</p></div></div>
      <label className="upload-area"><input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={selectFiles} /><span className="upload-icon">＋</span><strong>Escolha fotos para anexar</strong><small>ou arraste os arquivos para esta área</small><span className="btn btn-outline">Selecionar fotos</span></label>
      {previews.length > 0 && <div className="preview-grid">{previews.map((preview,index) => <div className="preview-photo" key={preview}><img src={preview} alt={`Pré-visualização ${index+1}`} /><span>{files[index]?.name}</span></div>)}</div>}
      {fieldErrors.fotos && <small className="field-error">{fieldErrors.fotos[0]}</small>}
      {form.tipo === 'ACHADO' && <><div className="form-section-title"><span>04</span><div><h2>Ajude a confirmar quem é o dono</h2><p>Essas informações nunca serão exibidas no anúncio. Elas ajudam você a analisar as reivindicações.</p></div></div><div className="form-grid"><label className="span-2">Pergunta de verificação<input name="pergunta_verificacao" value={form.pergunta_verificacao} onChange={change} minLength="8" maxLength="255" required placeholder="Ex.: Qual adesivo está na parte de trás?" /><small>Evite perguntas cuja resposta esteja visível na foto ou na descrição.</small></label><label className="span-2">Resposta esperada<input name="resposta_verificacao" value={form.resposta_verificacao} onChange={change} maxLength="1000" required placeholder="A resposta correta, visível somente a você" /></label></div></>}
      <div className="publish-footer"><p>Ao publicar, você concorda em não incluir dados pessoais na descrição e em usar o canal interno para combinar a devolução.</p><button type="submit" className="btn btn-primary" disabled={busy || (guarded && !isModerator)}>{busy ? 'Publicando...' : 'Publicar anúncio'} <span>→</span></button></div>
    </form>
  </section>;
}

function MyItemsPage({ user }) {
  const location = useLocation();
  const [data, setData] = useState({ anuncios: [], reivindicacoes: [] });
  const [tab, setTab] = useState('anuncios');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [editing, setEditing] = useState(null);

  const reload = useCallback(() => { setLoading(true); api('/my-items').then(setData).catch((err) => setError(friendlyError(err))).finally(() => setLoading(false)); }, []);
  useEffect(() => { reload(); }, [reload]);
  useEffect(() => { if (location.state?.publishSuccess) setSuccess(location.state.publishSuccess); }, [location.state]);
  const decide = async (claimId, decision, motive = '') => {
    setError(''); setSuccess('');
    try { const response = await api(`/claims/${claimId}/decision`, { method: 'PATCH', body: { decisao: decision, ...(decision === 'RECUSAR' ? { motivo_recusa: motive } : {}) } }); setSuccess(response.message); reload(); }
    catch (err) { setError(friendlyError(err)); }
  };
  const finishReturn = async (claimId) => {
    setError('');
    try { const response = await api(`/claims/${claimId}/returned`, { method: 'POST' }); setSuccess(response.message); reload(); }
    catch (err) { setError(friendlyError(err)); }
  };

  return <section className="page-wrap">
    <div className="page-title-row"><div><span className="eyebrow">SEU ESPAÇO</span><h1>Meus itens</h1><p className="muted">Acompanhe seus anúncios e as solicitações de devolução.</p></div><Link to="/novo" className="btn btn-primary">＋ Publicar item</Link></div>
    <Alert type="success" onClose={() => setSuccess('')}>{success}</Alert><Alert type="error">{error}</Alert>
    <div className="tabs-bar" role="tablist"><button role="tab" aria-selected={tab === 'anuncios'} className={tab === 'anuncios' ? 'active' : ''} onClick={() => setTab('anuncios')}>Meus anúncios <span>{data.anuncios.length}</span></button><button role="tab" aria-selected={tab === 'reivindicacoes'} className={tab === 'reivindicacoes' ? 'active' : ''} onClick={() => setTab('reivindicacoes')}>Minhas solicitações <span>{data.reivindicacoes.length}</span></button></div>
    {loading ? <Spinner text="Carregando seus itens..." /> : tab === 'anuncios' ? <>
      {data.anuncios.length ? <div className="dashboard-list">{data.anuncios.map((item) => <article className="dashboard-item" key={item.id}><div className="dashboard-item-thumb">{item.fotos?.[0] ? <img src={imageUrl(item.fotos[0].url || item.fotos[0].caminho)} alt="" /> : <span>✳</span>}</div><div className="dashboard-item-info"><div className="dashboard-item-heading"><span className={`mini-type ${item.tipo === 'ACHADO' ? 'mini-found' : 'mini-lost'}`}>{item.tipo === 'ACHADO' ? 'Achado' : 'Perdido'}</span><span className={`status-chip status-${item.status?.toLowerCase()}`}>{statusLabels[item.status] || item.status}</span></div><Link to={`/item/${item.id}`} className="dashboard-title">{item.titulo}</Link><p>{item.local?.nome} · {formatDate(item.data_ocorrencia)}</p>{item.status === 'ABERTO' && <button className="text-button" onClick={() => setEditing(editing === item.id ? null : item.id)}>{editing === item.id ? 'Cancelar edição' : 'Editar título, descrição e fotos'}</button>}</div>{item.status === 'ABERTO' && <Link className="dashboard-arrow" to={`/item/${item.id}`} aria-label="Abrir anúncio">↗</Link>}{editing === item.id && <EditItemForm item={item} onDone={() => { setEditing(null); reload(); setSuccess('Anúncio atualizado.'); }} onCancel={() => setEditing(null)} />}</article>)}</div> : <EmptyState icon="＋" title="Você ainda não publicou anúncios" description="Registre um objeto achado ou avise a comunidade sobre algo que perdeu." action={<Link to="/novo" className="btn btn-primary">Publicar primeiro item</Link>} />}
    </> : <>
      {data.reivindicacoes.length ? <div className="claim-list">{data.reivindicacoes.map((claim) => <ClaimCard key={claim.id} claim={claim} user={user} onDecide={decide} onReturn={finishReturn} onChanged={reload} />)}</div> : <EmptyState icon="↔" title="Nenhuma solicitação ainda" description="Quando você reivindicar um item ou alguém solicitar um objeto que publicou, a atividade aparecerá aqui." action={<Link to="/" className="btn btn-secondary">Explorar anúncios</Link>} />}
    </>}
  </section>;
}

function EditItemForm({ item, onDone, onCancel }) {
  const [titulo, setTitulo] = useState(item.titulo);
  const [descricao, setDescricao] = useState(item.descricao);
  const [files, setFiles] = useState([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const submit = async (event) => {
    event.preventDefault(); setBusy(true); setError('');
    const body = new FormData(); body.append('titulo',titulo); body.append('descricao',descricao); files.forEach((file) => body.append('fotos[]',file));
    try { await api(`/items/${item.id}`,{method:'PATCH',body}); onDone(); } catch (err) { setError(friendlyError(err)); } finally { setBusy(false); }
  };
  return <form className="edit-item-form" onSubmit={submit}><Alert type="error">{error}</Alert><label>Título<input value={titulo} onChange={(e)=>setTitulo(e.target.value)} required minLength="3" maxLength="140" /></label><label>Descrição<textarea value={descricao} onChange={(e)=>setDescricao(e.target.value)} rows="3" required minLength="5" maxLength="5000" /></label><label>Substituir fotos (opcional)<input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(e)=>{const selected=Array.from(e.target.files||[]); if(selected.length>3||selected.some((f)=>f.size>5*1024*1024||!['image/jpeg','image/png','image/webp'].includes(f.type))){setError('Selecione até 3 fotos JPG, PNG ou WEBP de até 5 MB.');return;}setFiles(selected);}} /></label><div className="form-actions"><button className="btn btn-primary" disabled={busy}>{busy?'Salvando...':'Salvar alterações'}</button><button type="button" className="btn btn-quiet" onClick={onCancel}>Cancelar</button></div></form>;
}

function ClaimCard({ claim, user, onDecide, onReturn, onChanged }) {
  const [motive, setMotive] = useState('');
  const [chatOpen, setChatOpen] = useState(false);
  const isOwner = claim.item?.usuario_id === user.id;
  const isModerator = ['MODERADOR','ADMINISTRADOR'].includes(user.papel);
  const canModerateGuard = Boolean(claim.item?.ponto_guarda_id) && isModerator;
  const canModerateForwarded = Boolean(claim.encaminhada_moderacao_em) && isModerator;
  const canDecide = claim.status === 'PENDENTE' && (isOwner || canModerateGuard || canModerateForwarded);
  const canReturn = claim.status === 'APROVADA' && (isOwner || canModerateGuard);
  return <article className="claim-card"><div className="claim-card-top"><div><span className="eyebrow">{isOwner || canModerateGuard || canModerateForwarded ? 'PEDIDO RECEBIDO' : 'SEU PEDIDO'}</span><h3>{claim.item?.titulo || 'Item'}</h3><p>{isOwner || canModerateGuard || canModerateForwarded ? `Solicitante: ${claim.solicitante?.nome || 'Usuário'}` : 'Você solicitou a devolução deste item.'}</p></div><span className={`status-chip status-${claim.status?.toLowerCase()}`}>{statusLabels[claim.status] || claim.status}</span></div>
    <div className="claim-card-detail"><span>Enviado em {formatDate(claim.created_at)}</span>{claim.motivo_recusa && <p><strong>Motivo:</strong> {claim.motivo_recusa}</p>}</div>
    {(isOwner || canModerateGuard || canModerateForwarded) && claim.resposta_verificacao && <div className="claim-answer-box"><span>Resposta enviada pelo solicitante</span><p>{claim.resposta_verificacao}</p></div>}
    {canDecide && <div className="claim-decision"><label>Motivo da recusa (preencha apenas se recusar)<input value={motive} onChange={(event)=>setMotive(event.target.value)} placeholder="Explique brevemente sua decisão" maxLength="1000" /></label><div className="form-actions"><button className="btn btn-primary" onClick={()=>onDecide(claim.id,'APROVAR')}>Aprovar</button><button className="btn btn-danger-outline" disabled={motive.trim().length<3} onClick={()=>onDecide(claim.id,'RECUSAR',motive)}>Recusar</button></div></div>}
    {claim.status === 'APROVADA' && <div className="claim-approved-actions"><button className="btn btn-secondary" onClick={()=>setChatOpen(!chatOpen)}>{chatOpen ? 'Fechar chat' : 'Abrir chat interno'} <span>↗</span></button>{canReturn && <button className="btn btn-primary" onClick={()=>{if(window.confirm('Confirmar que o objeto foi devolvido?'))onReturn(claim.id);}}>Marcar como devolvido</button>}</div>}
    {chatOpen && claim.status === 'APROVADA' && <ClaimChat claimId={claim.id} onChanged={onChanged} />}
  </article>;
}

function ClaimChat({ claimId }) {
  const [messages, setMessages] = useState([]);
  const [text, setText] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const load = useCallback(() => api(`/claims/${claimId}/messages`).then((response)=>setMessages(response.mensagens||[])).catch((err)=>setError(friendlyError(err))).finally(()=>setLoading(false)),[claimId]);
  useEffect(()=>{load();},[load]);
  const submit = async (event)=>{event.preventDefault();setBusy(true);setError('');try{await api(`/claims/${claimId}/messages`,{method:'POST',body:{conteudo:text}});setText('');load();}catch(err){setError(friendlyError(err));}finally{setBusy(false);}};
  return <div className="chat-panel"><h4>Conversa da reivindicação</h4><p className="muted small">Não compartilhe telefone ou e-mail. Combine os detalhes por aqui.</p><Alert type="error">{error}</Alert>{loading?<Spinner text="Carregando conversa..."/>:<div className="chat-messages">{messages.length?messages.map((message)=><div className="chat-message" key={message.id}><strong>{message.remetente?.nome||'Participante'}</strong><p>{message.conteudo}</p><time>{new Date(message.created_at).toLocaleString('pt-BR')}</time></div>):<p className="muted small">Nenhuma mensagem ainda. Envie a primeira para combinar a entrega.</p>}</div>}<form className="chat-compose" onSubmit={submit}><input value={text} onChange={(event)=>setText(event.target.value)} maxLength="500" required placeholder="Escreva uma mensagem curta..." /><button className="btn btn-primary" disabled={busy||!text.trim()}>{busy?'Enviando...':'Enviar'}</button></form></div>;
}

function NotificationsPage({ user }) {
  const [notifications, setNotifications] = useState([]);
  const [unread, setUnread] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [emailEnabled, setEmailEnabled] = useState(Boolean(user.emails_ativados));
  const [busy, setBusy] = useState(false);
  const load = useCallback(()=>api('/notifications').then((response)=>{setNotifications(response.data||[]);setUnread(response.nao_lidas||0);}).catch((err)=>setError(friendlyError(err))).finally(()=>setLoading(false)),[]);
  useEffect(()=>{load();},[load]);
  const markRead = async (id)=>{try{await api(`/notifications/${id}/read`,{method:'PATCH'});load();}catch(err){setError(friendlyError(err));}};
  const markAll = async ()=>{try{await api('/notifications/read-all',{method:'POST'});load();}catch(err){setError(friendlyError(err));}};
  const savePreferences = async ()=>{setBusy(true);setError('');setSuccess('');try{const response=await api('/auth/preferences',{method:'PATCH',body:{emails_ativados:emailEnabled}});localStorage.setItem('encontrei_user',JSON.stringify(response.user));setSuccess(response.message);}catch(err){setError(friendlyError(err));}finally{setBusy(false);}};
  return <section className="page-wrap narrow-page"><div className="page-title-block"><span className="eyebrow">FIQUE POR DENTRO</span><h1>Notificações <span className="unread-count">{unread}</span></h1><p>Atualizações sobre seus anúncios, reivindicações e possíveis reencontros.</p></div><Alert type="error">{error}</Alert><Alert type="success">{success}</Alert>
    <div className="preference-card"><div><strong>Receber avisos por e-mail</strong><p>Além das notificações nesta página, receba atualizações no seu e-mail institucional.</p></div><label className="switch-control"><input type="checkbox" checked={emailEnabled} onChange={(e)=>setEmailEnabled(e.target.checked)} /><span className="switch-slider"/><span className="sr-only">Ativar e-mails</span></label><button className="btn btn-secondary" disabled={busy||emailEnabled===Boolean(user.emails_ativados)} onClick={savePreferences}>{busy?'Salvando...':'Salvar'}</button></div>
    <div className="notifications-heading"><h2>Recentes</h2>{unread>0&&<button className="text-button" onClick={markAll}>Marcar todas como lidas</button>}</div>
    {loading?<Spinner/>:notifications.length?<div className="notification-list">{notifications.map((n)=><article className={`notification-row ${!n.lida_em?'notification-unread':''}`} key={n.id}><span className="notification-icon">{n.tipo?.includes('CORRESPONDENCIA')?'↔':n.tipo?.includes('REIVINDICACAO')?'✓':n.tipo?.includes('MENSAGEM')?'✉':'✳'}</span><div className="notification-copy"><h3>{n.titulo}</h3><p>{n.mensagem}</p><time>{new Date(n.created_at).toLocaleString('pt-BR')}</time></div>{!n.lida_em&&<button className="text-button" onClick={()=>markRead(n.id)}>Marcar lida</button>}</article>)}</div>:<EmptyState icon="✉" title="Tudo em dia" description="Quando algo acontecer com seus anúncios ou solicitações, você verá o aviso aqui." />}
  </section>;
}

function ModerationPage({ user }) {
  const [reports,setReports]=useState([]); const [pendingClaims,setPendingClaims]=useState([]); const [logs,setLogs]=useState([]); const [loading,setLoading]=useState(true); const [error,setError]=useState(''); const [success,setSuccess]=useState(''); const [reasons,setReasons]=useState({}); const [busyId,setBusyId]=useState(''); const [tab,setTab]=useState('denuncias');
  const load=useCallback(()=>{setLoading(true);Promise.all([api('/moderation/reports'),api('/moderation/claims'),api('/moderation/log')]).then(([r,c,l])=>{setReports(r.data||[]);setPendingClaims(c.data||[]);setLogs(l.data||[]);}).catch((err)=>setError(friendlyError(err))).finally(()=>setLoading(false));},[]);
  useEffect(()=>{load();},[load]);
  const decideClaim=async(id,decision,motive='')=>{setBusyId(id);setError('');setSuccess('');try{const response=await api(`/claims/${id}/decision`,{method:'PATCH',body:{decisao:decision,...(decision==='RECUSAR'?{motivo_recusa:motive}:{})}});setSuccess(response.message);load();}catch(err){setError(friendlyError(err));}finally{setBusyId('');}};
  const finishGuardReturn=async(id)=>{setBusyId(id);setError('');setSuccess('');try{const response=await api(`/claims/${id}/returned`,{method:'POST'});setSuccess(response.message);load();}catch(err){setError(friendlyError(err));}finally{setBusyId('');}};
  const resolve=async(id,action)=>{const reason=(reasons[id]||'').trim();if(reason.length<5){setError('Informe uma justificativa com pelo menos 5 caracteres.');return;}setBusyId(id);setError('');setSuccess('');try{const response=await api(`/moderation/reports/${id}`,{method:'PATCH',body:{acao:action,justificativa:reason}});setSuccess(response.message);setReasons({...reasons,[id]:''});load();}catch(err){setError(friendlyError(err));}finally{setBusyId('');}};
  return <section className="page-wrap"><div className="page-title-row"><div><span className="eyebrow">ÁREA DA EQUIPE</span><h1>Painel de moderação</h1><p className="muted">Analise denúncias, proteja a comunidade e acompanhe o histórico de ações.</p></div><Link to="/novo" className="btn btn-primary">＋ Cadastrar item</Link></div><Alert type="error">{error}</Alert><Alert type="success">{success}</Alert><div className="tabs-bar"><button className={tab==='denuncias'?'active':''} onClick={()=>setTab('denuncias')}>Denúncias <span>{reports.length}</span></button><button className={tab==='reivindicacoes'?'active':''} onClick={()=>setTab('reivindicacoes')}>Reivindicações <span>{pendingClaims.length}</span></button><button className={tab==='historico'?'active':''} onClick={()=>setTab('historico')}>Histórico de ações</button></div>
    {loading?<Spinner/>:tab==='reivindicacoes'?pendingClaims.length?<div className="claim-list moderation-claims">{pendingClaims.map((claim)=><ClaimCard key={claim.id} claim={claim} user={user} onDecide={decideClaim} onReturn={finishGuardReturn} onChanged={load} />)}</div>:<EmptyState icon="✓" title="Nenhuma reivindicação pendente" description="Não há reivindicações aguardando análise ou devolução de itens do ponto de guarda." />:tab==='denuncias'?reports.length?<div className="moderation-list">{reports.map((report)=><article className="report-card" key={report.id}><div className="report-head"><span className="status-chip status-aberta">Denúncia aberta</span><time>{formatDate(report.created_at)}</time></div><h3>{report.item?.titulo||'Anúncio removido'}</h3><p className="report-meta">Denunciado por {report.denunciante?.nome||'usuário'} · {report.item?.categoria?.nome||'Sem categoria'} · {report.item?.local?.nome||'Local não informado'}</p><div className="report-reason"><strong>Motivo informado</strong><p>{report.motivo}</p></div><label>Justificativa da decisão<textarea rows="2" value={reasons[report.id]||''} onChange={(e)=>setReasons({...reasons,[report.id]:e.target.value})} placeholder="Registre o motivo da decisão para o histórico..." /></label><div className="form-actions"><button className="btn btn-danger" disabled={busyId===report.id} onClick={()=>resolve(report.id,'REMOVER_ANUNCIO')}>Remover anúncio</button><button className="btn btn-secondary" disabled={busyId===report.id} onClick={()=>resolve(report.id,'MANTER_ANUNCIO')}>Manter anúncio</button>{report.item&&<Link to={`/item/${report.item.id}`} className="text-button">Ver anúncio ↗</Link>}</div></article>)}</div>:<EmptyState icon="✓" title="Nenhuma denúncia pendente" description="A fila de moderação está vazia. Novas denúncias aparecerão aqui." />:<div className="log-list">{logs.length?logs.map((log)=><article className="log-row" key={log.id}><span className="log-icon">✓</span><div><strong>{log.acao.replaceAll('_',' ')}</strong><p>{log.justificativa}</p><small>{log.ator?.nome||'Equipe'} · {new Date(log.created_at).toLocaleString('pt-BR')}</small></div></article>):<EmptyState title="Ainda não há ações registradas" description="As decisões de moderação serão exibidas aqui." />}</div>}
  </section>;
}

function AdminPage() {
  const [tab,setTab]=useState('categorias'); const [categories,setCategories]=useState([]); const [locations,setLocations]=useState([]); const [points,setPoints]=useState([]); const [users,setUsers]=useState([]); const [domains,setDomains]=useState([]); const [loading,setLoading]=useState(true); const [error,setError]=useState(''); const [success,setSuccess]=useState(''); const [busy,setBusy]=useState(false);
  const [catForm,setCatForm]=useState({nome:'',descricao:''}); const [locForm,setLocForm]=useState({nome:'',bloco:''}); const [pointForm,setPointForm]=useState({local_id:'',nome:'',referencia:''}); const [domain,setDomain]=useState('');
  const load=useCallback(async()=>{setLoading(true);try{const [c,l,p,u,d]=await Promise.all([api('/admin/categories'),api('/admin/locations'),api('/admin/guard-points'),api('/admin/users'),api('/admin/domains')]);setCategories(c);setLocations(l);setPoints(p);setUsers(u);setDomains(d);}catch(err){setError(friendlyError(err));}finally{setLoading(false);}},[]);
  useEffect(()=>{load();},[load]);
  const doRequest=async(path,method,body,successText)=>{setBusy(true);setError('');setSuccess('');try{const response=await api(path,{method,body});setSuccess(response.message||successText);await load();return true;}catch(err){setError(friendlyError(err));return false;}finally{setBusy(false);}};
  const createCategory=async(e)=>{e.preventDefault();if(await doRequest('/admin/categories','POST',catForm,'Categoria criada.'))setCatForm({nome:'',descricao:''});};
  const createLocation=async(e)=>{e.preventDefault();if(await doRequest('/admin/locations','POST',locForm,'Local criado.'))setLocForm({nome:'',bloco:''});};
  const createPoint=async(e)=>{e.preventDefault();if(await doRequest('/admin/guard-points','POST',pointForm,'Ponto de guarda criado.'))setPointForm({local_id:'',nome:'',referencia:''});};
  const createDomain=async(e)=>{e.preventDefault();if(await doRequest('/admin/domains','POST',{dominio:domain},'Domínio adicionado.'))setDomain('');};
  const patch=async(path,body)=>doRequest(path,'PATCH',body,'Alteração salva.');
  const tabs=[['categorias','Categorias'],['locais','Locais do campus'],['pontos','Pontos de guarda'],['usuarios','Usuários e perfis'],['dominios','Domínios institucionais']];
  return <section className="page-wrap"><div className="page-title-block"><span className="eyebrow">CONFIGURAÇÃO DO SISTEMA</span><h1>Painel administrativo</h1><p>Gerencie os dados de referência e os perfis que mantêm a plataforma funcionando.</p></div><Alert type="error">{error}</Alert><Alert type="success">{success}</Alert><div className="admin-tabs">{tabs.map(([key,label])=><button key={key} className={tab===key?'active':''} onClick={()=>setTab(key)}>{label}</button>)}</div>
    {loading?<Spinner/>:<div className="admin-panel">
      {tab==='categorias'&&<><form className="admin-create-form" onSubmit={createCategory}><h2>Nova categoria</h2><label>Nome<input value={catForm.nome} onChange={(e)=>setCatForm({...catForm,nome:e.target.value})} required maxLength="80" /></label><label>Descrição<input value={catForm.descricao} onChange={(e)=>setCatForm({...catForm,descricao:e.target.value})} maxLength="255" /></label><button className="btn btn-primary" disabled={busy}>Adicionar categoria</button></form><div className="admin-table">{categories.map((c)=><div className="admin-row" key={c.id}><div><strong>{c.nome}</strong><small>{c.descricao||'Sem descrição'}</small></div><span className={`status-chip ${c.ativo?'status-aberto':'status-oculto'}`}>{c.ativo?'Ativa':'Inativa'}</span><button className="btn btn-outline btn-small" onClick={()=>patch(`/admin/categories/${c.id}`,{ativo:!c.ativo})}>{c.ativo?'Desativar':'Reativar'}</button></div>)}</div></>}
      {tab==='locais'&&<><form className="admin-create-form" onSubmit={createLocation}><h2>Novo local do campus</h2><label>Nome<input value={locForm.nome} onChange={(e)=>setLocForm({...locForm,nome:e.target.value})} required maxLength="120" /></label><label>Bloco / setor<input value={locForm.bloco} onChange={(e)=>setLocForm({...locForm,bloco:e.target.value})} maxLength="80" /></label><button className="btn btn-primary" disabled={busy}>Adicionar local</button></form><div className="admin-table">{locations.map((l)=><div className="admin-row" key={l.id}><div><strong>{l.nome}</strong><small>{l.bloco?`Bloco/setor: ${l.bloco}`:'Sem bloco definido'}</small></div><span className={`status-chip ${l.ativo?'status-aberto':'status-oculto'}`}>{l.ativo?'Ativo':'Inativo'}</span><button className="btn btn-outline btn-small" onClick={()=>patch(`/admin/locations/${l.id}`,{ativo:!l.ativo})}>{l.ativo?'Desativar':'Reativar'}</button></div>)}</div></>}
      {tab==='pontos'&&<><form className="admin-create-form" onSubmit={createPoint}><h2>Novo ponto de guarda</h2><label>Local<select value={pointForm.local_id} onChange={(e)=>setPointForm({...pointForm,local_id:e.target.value})} required><option value="">Selecione um local</option>{locations.filter((l)=>l.ativo).map((l)=><option key={l.id} value={l.id}>{l.nome}</option>)}</select></label><label>Nome<input value={pointForm.nome} onChange={(e)=>setPointForm({...pointForm,nome:e.target.value})} required maxLength="120" /></label><label>Referência<input value={pointForm.referencia} onChange={(e)=>setPointForm({...pointForm,referencia:e.target.value})} maxLength="255" /></label><button className="btn btn-primary" disabled={busy}>Adicionar ponto</button></form><div className="admin-table">{points.map((p)=><div className="admin-row" key={p.id}><div><strong>{p.nome}</strong><small>{p.local?.nome||'Local não informado'} · {p.referencia||'Sem referência'}</small></div><span className={`status-chip ${p.ativo?'status-aberto':'status-oculto'}`}>{p.ativo?'Ativo':'Inativo'}</span><button className="btn btn-outline btn-small" onClick={()=>patch(`/admin/guard-points/${p.id}`,{ativo:!p.ativo})}>{p.ativo?'Desativar':'Reativar'}</button></div>)}</div></>}
      {tab==='usuarios'&&<div className="admin-table">{users.map((u)=><div className="admin-row user-admin-row" key={u.id}><div><strong>{u.nome}</strong><small>{u.email} · {statusLabels[u.status]||u.status}</small></div><select aria-label={`Perfil de ${u.nome}`} value={u.papel} onChange={(e)=>patch(`/admin/users/${u.id}/role`,{papel:e.target.value})}><option value="USUARIO">Usuário</option><option value="MODERADOR">Moderador</option><option value="ADMINISTRADOR">Administrador</option></select><select aria-label={`Status de ${u.nome}`} value={u.status} onChange={(e)=>patch(`/admin/users/${u.id}/status`,{status:e.target.value})}><option value="PENDENTE" disabled>Pendente</option><option value="ATIVO">Ativo</option><option value="SUSPENSO">Suspenso</option></select></div>)}</div>}
      {tab==='dominios'&&<><form className="admin-create-form" onSubmit={createDomain}><h2>Domínio autorizado para cadastro</h2><label>Domínio (sem @)<input value={domain} onChange={(e)=>setDomain(e.target.value.toLowerCase())} required placeholder="alunos.sua-instituicao.br" /></label><button className="btn btn-primary" disabled={busy}>Adicionar domínio</button></form><div className="admin-table">{domains.map((d)=><div className="admin-row" key={d.id}><div><strong>{d.dominio}</strong><small>Define quem pode criar uma conta institucional</small></div><span className={`status-chip ${d.ativo?'status-aberto':'status-oculto'}`}>{d.ativo?'Ativo':'Inativo'}</span><button className="btn btn-outline btn-small" onClick={()=>patch(`/admin/domains/${d.id}`,{ativo:!d.ativo})}>{d.ativo?'Desativar':'Reativar'}</button></div>)}</div></>}
    </div>}
  </section>;
}

function NotFound() {
  return <section className="page-wrap"><EmptyState icon="⌕" title="Não encontramos esta página" description="O endereço pode estar incorreto ou o anúncio não está mais disponível." action={<Link to="/" className="btn btn-primary">Voltar ao início</Link>} /></section>;
}

function Footer() {
  return <footer className="site-footer"><div className="footer-inner"><Link to="/" className="brand brand-footer"><span className="brand-mark">e</span><span className="brand-words">encontrei<span>ACHADOS & PERDIDOS</span></span></Link><p>Um reencontro de cada vez. Feito para a comunidade do campus.</p><div><Link to="/">Explorar</Link><Link to="/novo">Publicar</Link><a href="#explorar">Segurança</a></div><small>© {new Date().getFullYear()} Encontrei · MVP acadêmico</small></div></footer>;
}

export default App;
