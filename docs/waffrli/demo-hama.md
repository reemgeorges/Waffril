# Demo cities: Hama (main) and Homs

`Database\Seeders\Demo\DemoHamaSeeder` and `DemoHomsSeeder` build two demonstration cities for the customer and driver apps. Both run as part of `WaffrliDemoSeeder`, or on their own:

```bash
php artisan db:seed --class="Database\Seeders\Demo\DemoHamaSeeder"
```

```bash
php artisan db:seed --class="Database\Seeders\Demo\DemoHomsSeeder"
```

**Safety.**
- They run only when `APP_ENV` is `local`, `development`, `demo` or `testing`.
- Everything is matched by natural keys, so a second run adds nothing, and an existing row (including an admin's edit) is never changed or deleted.
- **Reference data** (city, delivery areas, fee rules, stores, branches, products, hours, demo customers, drivers) is purely additive, so it is also safe on a local database that already holds test orders.
- **Demo transactions** (orders, payments, refunds, ratings, PLUS) are added only when the database holds no real orders or wallet movements. Otherwise they are skipped with a warning.
- Every store is **fictional** and described as a demo store; no real merchant is represented.

## Geography and coverage

The data comes from OpenStreetMap through the Nominatim API (retrieved 2026-11-16; Data © OpenStreetMap contributors, ODbL 1.0 — https://www.openstreetmap.org/copyright). It is stored in `database/seeders/Demo/data/hama.json` and `homs.json` with the OSM id of every object. Overpass was not reachable from the build environment, so the sources were collected with bounded Nominatim lookups (at most one request per ~1.5 s).

**How coverage works (unchanged resolver).** Once a city has active delivery areas, an address must be inside one of them, and where areas overlap, the lowest `sort_order` wins. Each demo city therefore gets:

1. **One whole service area** (`sort_order` 1000), the catch-all. No valid address in the city is ever rejected, even when an admin deactivates a named area.
2. **Named areas** (`sort_order` 1–49), which take precedence where they apply.

Every area records how trustworthy its boundary is, in the new `boundary_quality` and `boundary_source` columns; the panel shows them as a badge with a tooltip:

| Quality | Meaning |
|---|---|
| **Verified** | The exact boundary of an OpenStreetMap object (a city or an administrative neighbourhood) |
| **Approximate** | A "nearest neighbourhood centre" area: the service area split so that every location belongs to the closest OSM neighbourhood point. Gap-free by construction, but **not** an official boundary |
| *(empty)* | Drawn by an admin |

### Hama (main demonstration city)

- **Whole service area:** **verified** OSM city boundary `relation/18354844 (place=city)`, about 94 km², including six villages that OSM places inside the city.
- **City detection:** centre 35.1343368, 36.7496276; radius 8 km (the farthest boundary point is 7.5 km away).
- **Named areas:** 32 **approximate** areas, one around each OSM object:

| Arabic | English | OSM object | OSM id |
|---|---|---|---|
| الضاهرية | Ad Dahiriyah | Village inside the city boundary | `node/572554476` |
| العائدين | Al A'idīn | Neighbourhood node | `node/14049297164` |
| حي عين اللوزة | Ain Al-Lawza | Neighbourhood node | `node/14049297172` |
| العليليات | Al-Elelyiat | Named residential area | `way/370443509` |
| المدينة | Al-Madinah | Named residential area | `way/370456605` |
| الأندلس | Al-Andalus | Neighbourhood node | `node/14049297171` |
| الصابونية | As-Sabouniyah | Named residential area | `way/370443508` |
| الصواعق الشمالي | As-Sawa'eq ash-Shamali | Village inside the city boundary | `node/12200018827` |
| الشريعة | Ash-Shariaa | Named residential area | `way/370443510` |
| الشرقية | Ash-Sharqiyah | Named residential area | `way/370553255` |
| عين الباد | Ayn al-Bad | Village inside the city boundary | `node/571184065` |
| باب القبلي | Bab Qibli | Neighbourhood node | `node/14049297179` |
| البارودية | Al Barudiyah | Neighbourhood node | `node/14049297165` |
| ضاحية أبي الفداء | Dahiyat Abi Al-Fidaa | Named residential area | `way/370456608` |
| الفيحاء | Al Fayhaa | Neighbourhood node | `node/14049297166` |
| غرب المشتل | Gharb Al Mashtal | Neighbourhood node | `node/14049297175` |
| حي الحميدية | Al Hamidiyah | Neighbourhood node | `node/14049297162` |
| حارة الطب | Al-Tab Lane | Neighbourhood node | `node/14049297174` |
| جنوب الملعب | Janob al Malaab | Neighbourhood node | `node/14049297176` |
| جبرين | Jibrin | Village inside the city boundary | `node/12284942796` |
| كازو | Kazu | Village inside the city boundary | `node/572558782` |
| حي مشاع الأربعين | Mashaa Al-Arbaeen * | Neighbourhood node | `way/1455768720` |
| مشاع الفروسية | Mashaa Al-Furusiya | Neighbourhood node | `node/14049297178` |
| النصر | Nasr | Neighbourhood node | `node/14049297173` |
| الرياضي | Riyadi | Neighbourhood node | `node/14049297177` |
| رقيطة | Ruqaytah | Village inside the city boundary | `node/572553208` |
| حي السبيل | Al Sabil | Neighbourhood node | `node/14049297167` |
| الشهباء | Ash Shahba | Neighbourhood node | `node/14049297161` |
| شمالية | Ash Shamaliyah | Neighbourhood node | `node/14049297159` |
| سوق الغنم | Souq Al-Ghanam | Neighbourhood node | `node/14049297169` |
| تشرين | Tishrine | Neighbourhood node | `node/14049297168` |
| الزنبقي | Az Zanbaqi | Neighbourhood node | `node/14049297160` |

\* English name transliterated here (OSM has no `name:en`). Other English names are OSM's `name:en`, with a trailing "Neighborhood" removed.

**Not covered.** OSM has **no** boundary polygons for Hama's neighbourhoods. Several well-known neighbourhood names were searched and **not** found as OSM places: الحاضر, الدباغة, المرابط, الجراجمة, الشيخ عنبر, بستان السعادة, كرم الحوراني, الطوافرة, باب البلد, الأميرية and others. They are not invented as areas; their addresses fall in the nearest named area and always inside the whole service area.

### Homs (smaller)

- **No city boundary in OSM.** OSM has only a `place=city` node, so the whole service area is an **approximate** 7.5 km radius around `node/572549095`, labelled as such.
- **Verified neighbourhoods:** five neighbourhoods have a real OSM administrative boundary and are **verified** areas.
- **Approximate areas:** ten more OSM neighbourhood or suburb nodes give **approximate** nearest-centre areas.

| Arabic | English | Boundary | OSM id |
|---|---|---|---|
| حي القصور | Qosour | Verified (administrative boundary) | `relation/15951707` |
| حي الحصوية | Hasaweyyah | Verified (administrative boundary) | `relation/15951708` |
| حي الميماس | Mimas | Verified (administrative boundary) | `relation/15951709` |
| حي الوعر | Wa'er | Verified (administrative boundary) | `relation/15951710` |
| حي جورة الشياح | Jouret Ash-Shayyah | Verified (administrative boundary) | `relation/15860891` |
| عكرمة الجديدة | Akrama Al-Jadida * | Approximate (nearest centre) | `node/5222287373` |
| حي العدوية | Al-Adawiyah * | Approximate (nearest centre) | `node/6101614446` |
| حي الإنشاءات | Al-Insha'at | Approximate (nearest centre) | `node/1773966659` |
| حي المحطة | Al-Mahatta * | Approximate (nearest centre) | `node/6172867200` |
| حي السلطانية | As-Sultaniyah * | Approximate (nearest centre) | `node/6172884511` |
| حي باب هود | Bab Hood * | Approximate (nearest centre) | `node/6172888598` |
| ضاحية الوليد | Dahiyat Al-Walid * | Approximate (nearest centre) | `node/6124248198` |
| حي كرم اللوز | Karm Al-Louz | Approximate (nearest centre) | `node/6090493007` |
| حي كرم الشامي | Karm Al-Shami * | Approximate (nearest centre) | `node/6090515240` |
| حي وادي الذهب | Wadi Al-Dahab | Approximate (nearest centre) | `node/9247043627` |

### Validation (tests)

- `tests/Unit/NearestCentrePartitionTest`: the areas tile the boundary with no gaps or overlaps, their areas add up to the boundary area, and every ring is valid and closed.
- `tests/Feature/Customer/HamaDemoDatasetTest`:
  - every point of a ~400 m grid over Hama (several hundred points) is in a named area, is priced by a fee rule, and is detected as Hama by GPS;
  - every OSM centre resolves to its own area;
  - real cash-on-delivery checkouts work from the old centre (الزنبقي), the outer villages (كازو, جبرين), the south (ضاحية أبي الفداء), the east (سوق الغنم) and the north (مشاع الأربعين), each with the right area, fee rule, SYP currency and totals;
  - the minimum order, city mismatch and opening hours are enforced;
  - every price is a whole, shop-like SYP amount;
  - re-running changes nothing.
- `tests/Feature/Customer/RegionalDemoSeederSafetyTest`: next to real orders only reference data is added; production is refused.

## Delivery fees (NEW Syrian pounds)

| Scope | Distance | Base | Per km |
|---|---|---|---|
| Hama (city-wide) | 0–3 km | 75 | 10 |
| Hama | 3–6 km | 105 | 12 |
| Hama | 6–12 km | 141 | 14 |
| Hama | 12–25 km | 225 | 15 |
| الزنبقي (old centre, area rule) | 0–25 km | 60 | 8 |
| Homs (city-wide) | 0–3 / 3–7 / 7–20 km | 80 / 115 / 158 | 10 / 12 / 14 |

The bands are continuous at every edge. The area rule shows that the most specific scope wins: the resolver is unchanged.

## Money: what is stored, what is shown

- **Written in NEW Syrian pounds.** Prices, option deltas, minimum orders and fees are written in the seeders in new pounds, which is what customers and admins see.
- **Stored through `OperationalMoney::toBase()`,** the same exact conversion the admin panel uses:
  - **Accounting base USD** (the default; SYP rate 13000 old SYP per USD, a demonstration rate): the stored value is the base-currency amount (DECIMAL 18,4) that converts back to exactly the written price. Example: 250 SYP is stored as USD 1.9231 → 25,000 old SYP → **250** new SYP.
  - **Accounting base SYP:** the stored value is the amount in old pounds (×100).
- **Nudging.** About a quarter of round SYP amounts have **no** exact 4-decimal USD equivalent. Such a price is moved to the nearest exactly storable shop-like amount (steps of 5 SYP below 200, of 10/25/50 above; e.g. 150 → 155, 500 → 490), and the seeder prints every adjustment. Nothing is rounded silently.
- **History is never touched.** Existing orders, payments, wallets and settlements keep their own currency. The demo orders are placed **in SYP** through the real checkout, so their snapshots, payments, cash collection and refunds are in SYP.

## Demo accounts (fictional numbers)

| Who | Phones | Notes |
|---|---|---|
| Hama customers 1–8 | +1 202 555 0131 … 0138 | Arabic names; addresses from the old centre to the outer villages. Customer 4 is a PLUS member when a plan exists |
| Hama drivers 1–4 | +1 202 555 0231 … 0234 | Driver 4 is offline |
| Homs customers 1–3 / drivers 1–2 | +1 202 555 0151 … / 0251 … | |

- **Customer login.** Customers sign in with an OTP. To use one fixed code for these test numbers locally, see README §8 ("Fixed test OTP"): add them to `WAFFRLI_TEST_FIXED_OTP_PHONES`.
- **Driver login.** Drivers have random passwords; set one with `php artisan waffrli:demo-driver-password`.

## Representative Hama orders

16 orders (`DEMO-HAMA-0001` … `0016`), all through the real checkout and services:

- **Delivered:** 7, one with a partial refund to the wallet and two rated.
- **Cancelled:** 3 (by the customer while pending; by an admin after acceptance; by an admin after pickup with a wallet reversal).
- **Live (24-hour branches only, so seeding works at any hour):** pending, accepted, assigned, picked up, an accepted order with a pending cancellation request, and a scheduled order for tomorrow.
