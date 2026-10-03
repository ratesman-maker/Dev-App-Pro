---
name: frontend-patterns
description: Frontend vzory pro Dev App Pro — React 19, TanStack Query, shadcn/ui, Tailwind 4. Použij při tvorbě/úpravě stránek, komponent, hooků a při práci na výkonu nebo UI konzistenci.
---

# Frontend Patterns — Dev App Pro

Vzory pro `frontend/` (Vite + React 19 + TypeScript + Tailwind 4 + shadcn/ui).
Build: `npm run build` → `assets/dist/`. API klient: `src/lib/api.ts` (CSRF `X-CSRF-Token` z cookie).

## Kdy aktivovat

- Nová stránka, komponenta, dialog nebo hook
- Úprava stávajícího UI (konzistence!)
- Data fetching/mutations (TanStack Query)
- Výkonnost (re-rendery, bundle, lazy loading)
- Formuláře a validace na FE

## Tvrdá pravidla (nejdřív tohle)

- [ ] **Žádné stíny** — `shadow`, `box-shadow` zakázané uživatelem (všechny prvky: karty, dialogy, toasty, dropdowny)
- [ ] **Žádné inline styly** — CSP `style-src 'self'`; dynamické hodnoty přes CSS proměnné (vzor `--progress` + `.progress-bar`)
- [ ] **Žádné `<style>` injekty** — recharts `ChartStyle` je no-op; barvy grafů přes CSS třídy (`.chart-finance`, `--color-*` v globals.css)
- [ ] Texty česky; datum `26.3.2026` (`fmtDate`), měna `1 500 Kč` (`fmtMoney`), čas `4h 0m` (`fmtMinutes`), velikost `fmtBytes`
- [ ] Konstanty/labely přes `src/lib/constants.ts`, typy přes `src/types/index.ts` — žádné magic strings

## Struktura a reuse

```
pages/{Module}Page.tsx        — stránka (lazy route)
hooks/use{Module}.ts          — TanStack Query hooks (listy, CRUD, mutations)
components/{module}/          — modulové dialogy/sloupce
components/shared/            — DataTable, DetailModal, ConfirmDialog, AttachmentSelect, EmptyState
components/ui/                — shadcn primitivy (button, card, input, badge, dialog, select)
components/ui/PageSkeleton.tsx — loading stav stránky
```

- [ ] Nová stránka = lazy `React.lazy` import + `<Route>` v `App.tsx` pod `ProtectedRoute` + položka v `Sidebar.tsx` (s `prefetch="intent"` na NavLinku)
- [ ] Seznam = **DataTable** (řazení, paginace, search) — nevymýšlet vlastní tabulku
- [ ] CRUD dialog = vzor existujících dialogů + `useFormDialog` (stav formuláře/chyby/submit)
- [ ] Destrukční akce = `ConfirmDialog`, nikdy `window.confirm`
- [ ] Loading = `PageSkeleton` nebo skeleton řádky, ne spinner uprostřed
- [ ] Detaily = `DetailModal` (vzor `InvoiceDetailModal`)
- [ ] Error state = česká hláška + retry tlačítko, ne `alert()`

## Data fetching — TanStack Query

- [ ] Listy/detail přes `useQuery` s klíčem `['module', params]` — invalidace přes `queryClient.invalidateQueries({queryKey: ['module']})`
- [ ] CRUD přes `useMutation` ve `use{Module}.ts` — nikdy `fetch`/`api.ts` přímo v komponentách
- [ ] **Delete = optimistic update** (vzor 13 existujících hooků): `onMutate` (optimisticky odebrat z cache) → `onError` (rollback) → `onSettled` (invalidate)
- [ ] Polling jen kde je potřeba a s podmínkou: notifikace 30 s, hosting joby 2 s jen při aktivních (`useHostingJobs`), restore progress 1 s — `refetchInterval` vypnout když není co sledovat
- [ ] Search input = `useDebounce` hook (sdílený)
- [ ] Server-side paginace/sort/search — FE posílá `page`/`per_page`/`search`/`sort` parametry, nefiltrovat na klientu

## Formuláře

- [ ] Validace na FE = UX pomoc (422 `fields` z API zobrazit inline u polí), **rozhodující validace je na BE**
- [ ] Částky/součty nikdy nepočítat na FE pro odeslání — server počítá (vzor `invoice_items` → `subtotal` na serveru)
- [ ] Po submitu: zavřít dialog, invalidate query, toast (česky), ne reload stránky
- [ ] Disabled submit během `isPending` mutation

## Výkon

- [ ] `React.lazy` pro všechny stránky; těžké komponenty (grafy) lazy
- [ ] `useCallback` pro handlery předávané do memoizovaných komponent (vzor: 6 stránek už ho má)
- [ ] `useMemo` jen pro drahé výpočty/transformace — ne všude (nememoizovat primitivy/jednoduché výrazy)
- [ ] Stabilní `key` v seznamech (entity `id`, ne index)
- [ ] Dlouhé seznamy: server paginace (výchozí), ne virtuální scroll (zatím nepotřeba)
- [ ] Thumbnail obrázky místo ikon v `FilesPage` — pro nové souborové UI taky
- [ ] Bundle: `manualChunks` react-vendor (již v vite config), nové těžké deps zvážit (mPDF-like knihovny na FE ne)

### React výkon — pokročilá pravidla

Distilace z Vercel `react-best-practices` (subset aplikovatelný na SPA, bez SSR/Next.js):

**Waterfalls (kritické):**
- [ ] Nezávislé async operace paralelně — `Promise.all`, paralelní `useQuery` klíče; nikdy sekvenční `await` nezávislých věcí
- [ ] `await` až v branchi kde se hodnota použije; levnou sync podmínku testovat před await

**Re-rendery:**
- [ ] Odvozený stav počítat **při renderu**, ne `useEffect` + `setState` (jen když opravdu závisí na předchozím renderu)
- [ ] **Nikdy komponenty definované uvnitř komponenty** — nová identita → remount + ztráta stavu
- [ ] Funkcionální `setState` (`setX(prev => …)`) pro stabilní callbacky
- [ ] `useState(() => drahyVypocet())` — lazy init, ne výpočet každý render
- [ ] `useDeferredValue` pro drahé filtrování/řazení při psaní (doplňuje `useDebounce`)
- [ ] `useRef` pro transient hodnoty (scroll pozice, timery, předchozí hodnoty) — ne `useState`, když nemá re-renderovat

**Rendering/JS:**
- [ ] Podmíněný render **ternárem, ne `&&`** — `{count && <X/>}` vypíše `0` při count=0
- [ ] Statické JSX vyhnat mimo komponentu (konstantní markup na module level)
- [ ] `Set`/`Map` pro opakované lookupy místo `array.find`/`includes` v cyklu
- [ ] Early return místo hlubokého větvení; `toSorted()` pro immutable řazení
- [ ] Passive listenery u scroll/resize (`{ passive: true }`); localStorage data minimalizovat

**Bundle:**
- [ ] Přímé importy — ne barrel `import {x} from 'lib'` u těžkých knihoven (recharts, lucide — tree-shakeable, ale importovat jen potřebné)
- [ ] Preload na intent — `prefetch="intent"` na NavLink už je; rozšiřovat na těžké dialogy

## TypeScript

- [ ] Typy pro API entity v `src/types/index.ts` — sdílené, exportované
- [ ] API response typy odpovídají BE kontraktu (`data`/`total`/`page`/`per_page`, `error`/`fields`)
- [ ] `tsc -b` před buildem čistý; žádné `any` pro API data

## A11y a detail

Priorita podle dopadu: 1) accessible names, 2) keyboard access, 3) focus/dialogy, 4) sémantika.

- [ ] `label` u každého inputu (shadcn `Label`), `htmlFor` spárovaný
- [ ] **Icon-only tlačítka = povinný `aria-label`** (např. theme toggle, zavírací křížek)
- [ ] **Dialogy/focus:** Radix `Dialog` už řeší focus trap + Escape — nebudovat ručně; po zavření vrátit focus na trigger
- [ ] Chyby formuláře inline u pole + `role="alert"`/`aria-describedby`, ne jen barevný text
- [ ] Klávesnice: Tab/Enter/Escape musí fungovat bez myši na všech interaktivních prvcích; viditelný focus ring (`focus-visible`)
- [ ] Kontrast text↔pozadí v obou režimech (dark default, ale light testovat)
- [ ] Disabled tlačítka s `title`/tooltip důvodem (vzor: generování SSL během hosting jobu)
- [ ] Klikatelné řádky tabulky → detail (vzor Dashboard/Clients)
- [ ] Dark mode default (`class="dark"` v index.html) — nové komponenty testovat i v dark

## Checklist před commitem

- [ ] `npm run lint` + `tsc -b` čisté
- [ ] `npm run build` projde + **vizuální kontrola v prohlížeči** (build se lepí do cache — tvrdý refresh!)
- [ ] Žádné stíny / inline styly / `console.log`
- [ ] Nové UI ve stylu shadcn, reuse `shared/` komponent
- [ ] Texty česky, formáty přes fmt* helpery
- [ ] 422 field chyby z API zobrazené, ne obecný alert
- [ ] Optimistic delete kde patří; invalidace po mutations
- [ ] CSP-safe: žádné `dangerouslySetInnerHTML`, `onclick=` atributy, externí zdroje mimo `'self'`

## Anti-patterns

- ❌ `useState` pro server data → TanStack Query
- ❌ `useEffect` + `fetch` pro loading → `useQuery`
- ❌ Vlastní tabulka/dialog → `DataTable`/`Dialog` ze `shared/`
- ❌ `window.confirm`/`alert` → `ConfirmDialog`/toast
- ❌ Inline `style={{}}` nebo Tailwind `shadow-*` → zakázáno (CSP/user)
- ❌ `index` jako `key` → `entity.id`
- ❌ Kalkulace cen/součtů na FE pro submit → server
