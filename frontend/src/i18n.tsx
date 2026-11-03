import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import ar from './locales/ar.json';
import en from './locales/en.json';

export type Locale = 'ar' | 'en';
type MessageKey = keyof typeof ar;
const dictionaries: Record<Locale, Record<MessageKey, string>> = { ar, en };
type I18n = {
  locale: Locale;
  setLocale: (locale: Locale) => void;
  t: (key: MessageKey) => string;
};
const I18nContext = createContext<I18n | null>(null);

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocale] = useState<Locale>('ar');
  useEffect(() => {
    document.documentElement.lang = locale;
    document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
    document.title = dictionaries[locale].appName;
  }, [locale]);

  return (
    <I18nContext.Provider value={{ locale, setLocale, t: (key) => dictionaries[locale][key] }}>
      {children}
    </I18nContext.Provider>
  );
}

export function useI18n(): I18n {
  const context = useContext(I18nContext);
  if (!context) throw new Error('useI18n requires I18nProvider');
  return context;
}
