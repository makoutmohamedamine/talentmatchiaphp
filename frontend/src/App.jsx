import { useState, useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import Sidebar from './components/Sidebar';
import Dashboard from './page/Dashboard';
import DossiersCV from './page/DossiersCV';
import Candidats from './page/Candidats';
import Postes from './page/Postes';
import ChatRH from './page/ChatRH';
import OutlookSync from './components/OutlookSync';

import AnalyseIA from './page/AnalyseIA';
import Entretiens from './page/Entretiens';
import GestionUsers from './page/GestionUsers';
import Login from './page/LoginModern';
import Setup from './page/Setup';
import { checkSetup } from './api/api';
import { useLanguage } from './i18n/LanguageContext';

export default function App() {
  const { t } = useLanguage();
  const [token, setToken] = useState(localStorage.getItem('access_token'));
  const [currentUser, setCurrentUser] = useState(() => {
    try { return JSON.parse(localStorage.getItem('current_user') || 'null'); }
    catch { return null; }
  });
  const [needsSetup, setNeedsSetup] = useState(token ? false : null);
  const [welcomeToast, setWelcomeToast] = useState('');

  useEffect(() => {
    if (!token) {
      const timeout = setTimeout(() => setNeedsSetup(false), 3000);
      checkSetup()
        .then(res => { clearTimeout(timeout); setNeedsSetup(res.data.needs_setup); })
        .catch(() => { clearTimeout(timeout); setNeedsSetup(false); });
      return () => clearTimeout(timeout);
    } else {
      setNeedsSetup(false);
    }
  }, [token]);

  useEffect(() => {
    if (token) {
      import('./api/api').then(({ getMe }) => {
        getMe().then(res => {
          if (res.data && res.data.user) {
            setCurrentUser(res.data.user);
            localStorage.setItem('current_user', JSON.stringify(res.data.user));
          }
        }).catch(() => {
          handleLogout();
        });
      });
    }
  }, [token]);

  useEffect(() => {
    if (!welcomeToast) return undefined;
    const timer = setTimeout(() => setWelcomeToast(''), 2600);
    return () => clearTimeout(timer);
  }, [welcomeToast]);

  const handleLogin = (accessToken, user) => {
    localStorage.setItem('access_token', accessToken);
    if (user) localStorage.setItem('current_user', JSON.stringify(user));
    const displayName =
      [user?.first_name, user?.last_name].filter(Boolean).join(' ').trim()
      || user?.username
      || sessionStorage.getItem('welcome_user')
      || '';
    if (displayName) {
      setWelcomeToast(displayName);
      sessionStorage.removeItem('welcome_user');
    }
    setToken(accessToken);
    setCurrentUser(user);
    setNeedsSetup(false);
  };

  const handleLogout = () => {
    localStorage.removeItem('access_token');
    localStorage.removeItem('refresh_token');
    localStorage.removeItem('current_user');
    setToken(null);
    setCurrentUser(null);
  };

  if (!token) {
    if (needsSetup === null) {
      return (
        <div style={{
          minHeight: '100vh', display: 'flex',
          alignItems: 'center', justifyContent: 'center',
          background: '#f9f9f9', color: '#6b7280', fontSize: '0.9rem',
        }}>
          {t('common.loading')}
        </div>
      );
    }
    if (needsSetup) {
      return <Setup onSetupComplete={handleLogin} />;
    }
    return <Login onLogin={handleLogin} />;
  }

  const isAdmin = currentUser?.role === 'admin' || currentUser?.is_staff === true || currentUser?.is_superuser === true;

  return (
    <BrowserRouter>
      <div className="app-shell">
        {welcomeToast && (
          <div className="welcome-screen" role="status" aria-live="polite">
            <div className="welcome-screen-card">
              <div className="welcome-screen-logo">TM</div>
              <span className="welcome-screen-kicker">{t('welcome.success')}</span>
              <h1>{t('welcome.hello')} {welcomeToast}</h1>
              <p>{t('welcome.sync')}</p>
              <div className="welcome-screen-loader">
                <span />
                <span />
                <span />
              </div>
            </div>
          </div>
        )}
        <Sidebar onLogout={handleLogout} currentUser={currentUser} />
        <main className="app-main">
          <Routes>
            <Route path="/"          element={<Dashboard />} />
            <Route path="/dossiers"    element={<Navigate to="/dossiers-cv" replace />} />
            <Route path="/dossiers-cv" element={<DossiersCV />} />
            <Route path="/candidats" element={<Candidats />} />
            <Route path="/postes"    element={<Postes />} />
            <Route path="/entretiens" element={<Entretiens />} />
            <Route path="/chat-rh"   element={<ChatRH />} />
            <Route path="/outlook"    element={<OutlookSync />} />
            <Route path="/analyse-ia" element={<AnalyseIA />} />
            <Route path="/utilisateurs" element={isAdmin ? <GestionUsers /> : <Navigate to="/" />} />
            <Route path="*"          element={<Navigate to="/" />} />
          </Routes>
        </main>
      </div>
    </BrowserRouter>
  );
}
