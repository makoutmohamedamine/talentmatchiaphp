import { LANGUAGES } from '../i18n/translations';
import { useLanguage } from '../i18n/LanguageContext';

export default function LanguageSwitcher({ className = '' }) {
  const { lang, setLang, t } = useLanguage();

  return (
    <div className={`lang-switcher ${className}`.trim()} role="group" aria-label={t('common.language')}>
      {LANGUAGES.map(({ code, flag, label }) => (
        <button
          key={code}
          type="button"
          className={`lang-switcher-btn${lang === code ? ' is-active' : ''}`}
          onClick={() => setLang(code)}
          title={label}
          aria-pressed={lang === code}
        >
          {flag}
        </button>
      ))}
    </div>
  );
}
