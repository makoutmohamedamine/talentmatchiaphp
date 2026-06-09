import { useEffect, useRef, useState } from 'react';
import { API_BASE_URL } from '../api/api';
import { useLanguage } from '../i18n/LanguageContext';
import LanguageSwitcher from '../components/LanguageSwitcher';

function AuthField({ label, type = 'text', value, onChange, placeholder, autoComplete, required = true }) {
  return (
    <label className="auth-field">
      <span>{label}</span>
      <input
        type={type}
        value={value}
        onChange={onChange}
        placeholder={placeholder}
        autoComplete={autoComplete}
        required={required}
      />
    </label>
  );
}

function LoadingDot() {
  return <span className="auth-loading-dot" aria-hidden="true" />;
}

export default function LoginModern({ onLogin }) {
  const { t } = useLanguage();
  const [isSignUp, setIsSignUp] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [welcomeName, setWelcomeName] = useState('');
  const welcomeTimer = useRef(null);

  const [loginUsername, setLoginUsername] = useState('');
  const [loginPassword, setLoginPassword] = useState('');

  const [signUpData, setSignUpData] = useState({
    username: '',
    email: '',
    first_name: '',
    last_name: '',
    password: '',
    password_confirm: '',
  });

  useEffect(() => {
    return () => {
      if (welcomeTimer.current) clearTimeout(welcomeTimer.current);
    };
  }, []);

  const triggerWelcomeAndLogin = (token, user) => {
    const userName =
      [user?.first_name, user?.last_name].filter(Boolean).join(' ').trim() ||
      user?.username ||
      t('common.user');

    sessionStorage.setItem('welcome_user', userName);
    setWelcomeName(userName);
    welcomeTimer.current = setTimeout(() => {
      setWelcomeName('');
      onLogin(token, user);
    }, 1200);
  };

  const handleLoginSubmit = async (event) => {
    event.preventDefault();
    setLoading(true);
    setError('');

    try {
      const res = await fetch(`${API_BASE_URL}/auth/login/`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: loginUsername, password: loginPassword }),
      });
      const data = await res.json();

      if (res.ok) {
        localStorage.setItem('access_token', data.access);
        localStorage.setItem('refresh_token', data.refresh);
        localStorage.setItem('current_user', JSON.stringify(data.user));
        triggerWelcomeAndLogin(data.access, data.user);
      } else {
        setError(data.error || t('auth.wrongCredentials'));
      }
    } catch {
      setError(t('auth.serverError'));
    } finally {
      setLoading(false);
    }
  };

  const handleSignUpSubmit = async (event) => {
    event.preventDefault();
    setError('');

    if (!signUpData.username || !signUpData.email || !signUpData.first_name || !signUpData.last_name) {
      setError(t('auth.allFieldsRequired'));
      return;
    }
    if (signUpData.password.length < 6) {
      setError(t('auth.passwordMin'));
      return;
    }
    if (signUpData.password !== signUpData.password_confirm) {
      setError(t('auth.passwordMismatch'));
      return;
    }

    setLoading(true);
    try {
      const res = await fetch(`${API_BASE_URL}/auth/register/`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          username: signUpData.username,
          email: signUpData.email,
          first_name: signUpData.first_name,
          last_name: signUpData.last_name,
          password: signUpData.password,
        }),
      });
      const data = await res.json();

      if (res.ok) {
        localStorage.setItem('access_token', data.access);
        localStorage.setItem('refresh_token', data.refresh);
        localStorage.setItem('current_user', JSON.stringify(data.user));
        triggerWelcomeAndLogin(data.access, data.user);
      } else {
        setError(data.error || t('auth.signupError'));
      }
    } catch {
      setError(t('auth.serverError'));
    } finally {
      setLoading(false);
    }
  };

  const handleSignUpChange = (field, value) => {
    setSignUpData((previous) => ({ ...previous, [field]: value }));
  };

  return (
    <main className="auth-page">
      <div className="auth-lang-floating">
        <LanguageSwitcher />
      </div>

      {welcomeName && (
        <div className="auth-welcome-overlay">
          <section className="auth-welcome-card">
            <div className="auth-welcome-mark">TM</div>
            <p>{t('welcome.success')}</p>
            <h2>{t('welcome.hello')} {welcomeName}</h2>
            <span>{t('welcome.preparing')}</span>
          </section>
        </div>
      )}

      <section className="auth-brand-panel" aria-label="TalentMatch IA">
        <div className="auth-brand-glow auth-brand-glow-one" />
        <div className="auth-brand-glow auth-brand-glow-two" />

        <div className="auth-logo-card">
          <img src="/logocld.png" alt="TalentMatch IA" />
        </div>

        <div className="auth-brand-content">
          <span className="auth-eyebrow">{t('auth.brandEyebrow')}</span>
          <h1>
            TalentMatch
            <strong> IA</strong>
          </h1>
          <p>{t('auth.brandText')}</p>
        </div>

        <div className="auth-feature-grid">
          <article>
            <span>01</span>
            <strong>{t('auth.feature1Title')}</strong>
            <p>{t('auth.feature1Text')}</p>
          </article>
          <article>
            <span>02</span>
            <strong>{t('auth.feature2Title')}</strong>
            <p>{t('auth.feature2Text')}</p>
          </article>
          <article>
            <span>03</span>
            <strong>{t('auth.feature3Title')}</strong>
            <p>{t('auth.feature3Text')}</p>
          </article>
        </div>
      </section>

      <section className="auth-form-panel">
        <div className="auth-card">
          <div className="auth-card-topline" />
          <div className="auth-card-header">
            <span className="auth-card-kicker">{isSignUp ? t('auth.newAccess') : t('auth.secureAccess')}</span>
            <h2>{isSignUp ? t('auth.signupTitle') : t('auth.loginTitle')}</h2>
            <p>{isSignUp ? t('auth.signupSubtitle') : t('auth.loginSubtitle')}</p>
          </div>

          {isSignUp ? (
            <form className="auth-form" onSubmit={handleSignUpSubmit}>
              <div className="auth-two-cols">
                <AuthField
                  label={t('auth.firstName')}
                  value={signUpData.first_name}
                  onChange={(e) => handleSignUpChange('first_name', e.target.value)}
                  placeholder="Amine"
                  autoComplete="given-name"
                />
                <AuthField
                  label={t('auth.lastName')}
                  value={signUpData.last_name}
                  onChange={(e) => handleSignUpChange('last_name', e.target.value)}
                  placeholder="Jabri"
                  autoComplete="family-name"
                />
              </div>

              <AuthField
                label={t('auth.email')}
                type="email"
                value={signUpData.email}
                onChange={(e) => handleSignUpChange('email', e.target.value)}
                placeholder="nom@societe.com"
                autoComplete="email"
              />
              <AuthField
                label={t('auth.username')}
                value={signUpData.username}
                onChange={(e) => handleSignUpChange('username', e.target.value)}
                placeholder="amine"
                autoComplete="username"
              />
              <AuthField
                label={t('auth.password')}
                type="password"
                value={signUpData.password}
                onChange={(e) => handleSignUpChange('password', e.target.value)}
                placeholder="••••••"
                autoComplete="new-password"
              />
              <AuthField
                label={t('auth.confirmPassword')}
                type="password"
                value={signUpData.password_confirm}
                onChange={(e) => handleSignUpChange('password_confirm', e.target.value)}
                placeholder="••••••"
                autoComplete="new-password"
              />

              {error && <div className="auth-error">! {error}</div>}

              <button className="auth-submit" type="submit" disabled={loading}>
                {loading ? <><LoadingDot /> {t('auth.signupLoading')}</> : t('auth.signupBtn')}
              </button>

              <p className="auth-switch">
                {t('auth.hasAccount')}
                <button type="button" onClick={() => { setIsSignUp(false); setError(''); }}>
                  {t('auth.goLogin')}
                </button>
              </p>
            </form>
          ) : (
            <form className="auth-form" onSubmit={handleLoginSubmit}>
              <AuthField
                label={t('auth.username')}
                value={loginUsername}
                onChange={(e) => setLoginUsername(e.target.value)}
                placeholder="Amine"
                autoComplete="username"
              />
              <AuthField
                label={t('auth.password')}
                type="password"
                value={loginPassword}
                onChange={(e) => setLoginPassword(e.target.value)}
                placeholder="••••••"
                autoComplete="current-password"
              />

              {error && <div className="auth-error">! {error}</div>}

              <button className="auth-submit" type="submit" disabled={loading}>
                {loading ? <><LoadingDot /> {t('auth.loginLoading')}</> : t('auth.loginBtn')}
              </button>

              <p className="auth-switch">
                {t('auth.noAccount')}
                <button type="button" onClick={() => { setIsSignUp(true); setError(''); }}>
                  {t('auth.goSignup')}
                </button>
              </p>
            </form>
          )}

          <div className="auth-footer">
            <span>TalentMatch IA</span>
            <span>{t('auth.footerTagline')}</span>
          </div>
        </div>
      </section>
    </main>
  );
}
