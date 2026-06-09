import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { fr, enUS, ar } from 'date-fns/locale';
import { translate } from './translations';

const STORAGE_KEY = 'talentmatch_lang';

const LanguageContext = createContext(null);

const dateLocales = { fr, en: enUS, ar };

export function LanguageProvider({ children }) {
  const [lang, setLangState] = useState(() => {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved === 'fr' || saved === 'en' || saved === 'ar') return saved;
    const browser = (navigator.language || 'fr').slice(0, 2);
    if (browser === 'en' || browser === 'ar') return browser;
    return 'fr';
  });

  const setLang = useCallback((code) => {
    setLangState(code);
    localStorage.setItem(STORAGE_KEY, code);
  }, []);

  useEffect(() => {
    document.documentElement.lang = lang;
    document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
    document.body.classList.toggle('is-rtl', lang === 'ar');
  }, [lang]);

  const t = useCallback((key) => translate(lang, key), [lang]);

  const value = useMemo(
    () => ({ lang, setLang, t, dateLocale: dateLocales[lang] || fr }),
    [lang, setLang, t]
  );

  return <LanguageContext.Provider value={value}>{children}</LanguageContext.Provider>;
}

export function useLanguage() {
  const ctx = useContext(LanguageContext);
  if (!ctx) {
    throw new Error('useLanguage must be used within LanguageProvider');
  }
  return ctx;
}
