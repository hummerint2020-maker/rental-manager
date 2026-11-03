import { Link, Route, Routes } from 'react-router-dom';
import { useI18n, type Locale } from './i18n';

export function App() {
  const { locale, setLocale, t } = useI18n();
  return (
    <main className="min-h-screen bg-slate-50 px-6 py-12 text-start text-slate-900">
      <div className="mx-auto max-w-2xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <header className="mb-8 flex flex-wrap items-center justify-between gap-4">
          <Link to="/" className="font-semibold text-teal-800">{t('appName')}</Link>
          <label className="flex items-center gap-2 text-sm">
            <span>{t('language')}</span>
            <select
              value={locale}
              onChange={(event) => setLocale(event.target.value as Locale)}
              className="rounded border border-slate-300 bg-white py-2 ps-3 pe-8"
            >
              <option value="ar">{t('arabic')}</option>
              <option value="en">{t('english')}</option>
            </select>
          </label>
        </header>
        <Routes>
          <Route path="/" element={
            <section>
              <h1 className="mb-3 text-2xl font-bold">{t('pageTitle')}</h1>
              <p className="leading-8 text-slate-600">{t('description')}</p>
            </section>
          } />
          <Route path="*" element={
            <section>
              <h1 className="mb-3 text-2xl font-bold">{t('notFound')}</h1>
              <Link to="/" className="text-teal-800 underline">{t('home')}</Link>
            </section>
          } />
        </Routes>
      </div>
    </main>
  );
}
