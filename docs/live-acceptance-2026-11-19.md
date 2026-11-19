# Real Pixelforge acceptance — 2026-11-19 UTC

All eight acceptance cases passed through `CreateGeneration`, its credit hold and the existing queue worker. Webhooks arrived through the existing tunnel; no fallback sync was needed. No services were started, stopped or restarted. No environment file was read or changed, and nothing was staged, committed or pushed.

Eight provider generations were created: two direct fixture captures and six accepted app submissions. There were 17 create requests including the blocked requests and six overload attempts. Five acceptance generations and one fixture capture succeeded. The local `live-check@example.test` account received one $5 purchase and has zero outstanding reserves.

## Real responses versus documentation

- Pricing: the decoded JSON is identical to `doc-pricing.json`, including all rates and token tables.
- Both 202 creation objects and both terminal retrieval objects have exactly the documented top-level fields. Empty metadata is `{}` on the wire, as documented. Dynamic IDs, prompts, timestamps, image URLs and token counts naturally differ from the examples.
- The forced failure returns `generation_failed`; the example fixture uses `internal_error`. Both are documented failure codes, and both are already supported. No parser change was needed.
- The blocked error message differs from the documentation-derived fixture: fixture `"The prompt was rejected. Nothing was created or billed."`; real `"Your prompt was rejected by our content policy. You were not charged."`. HTTP 400, type `invalid_request_error` and code `content_policy_violation` match. The application already maps by code to `prompt_rejected`.
- The overload error message differs from the documentation-derived fixture: fixture `"Pixelforge is temporarily overloaded. Retry-After: 2. Nothing was created; retry, ideally with an Idempotency-Key."`; real `"Pixelforge is temporarily overloaded. Nothing was created; please retry shortly."`. HTTP 503, type `api_error`, code `overloaded`, and the actual `Retry-After: 2` header match. The application already uses the header and retries safely.
- Both physical deliveries of each `[duplicate]` event report `Pixelforge-Delivery-Attempt: 1`. The docs say the header counts every delivery attempt. Event IDs are identical across each pair, so existing event-ID deduplication remains correct. The real payloads and whitelisted tunnel delivery evidence are captured and covered by a regression test.
- Initial and final usage responses have the documented fields, integer counts and exact nine-decimal cost strings. The generation list has `object`, `data`, `has_more` and `next_cursor` as documented; every listed generation parses.
- No application parsing, billing, retry or settlement defect was found. The only client change exposes its existing authenticated raw response path for capture; normal methods keep the same validation and error mapping. The command and raw path were implemented after their tests failed for missing functionality.

## Acceptance

Every row has exactly one hold and one equal, opposite hold release. A charge exists exactly once for each success and never for a failure. `P`, `S`, `F` mean stored `generation.processing`, `generation.succeeded`, `generation.failed` events. Prices are USD.

| Case              | Status / reason            | Submit attempts | Stored events / observed deliveries | Hold / release    | Expected / charged | Private PNG |
| ----------------- | -------------------------- | --------------: | ----------------------------------- | ----------------- | ------------------ | ----------- |
| `succeed`         | succeeded                  |               1 | processing, succeeded; 2 / 2        | -0.0864 / +0.0864 | 0.0826 / 0.0826    | yes         |
| `fail`            | failed / generation_failed |               1 | processing, failed; 2 / 2           | -0.0219 / +0.0219 | 0.0000 / 0.0000    | —           |
| `slow`            | succeeded                  |               1 | processing, succeeded; 2 / 2        | -0.0219 / +0.0219 | 0.0164 / 0.0164    | yes         |
| `duplicate`       | succeeded                  |               1 | processing, succeeded; 2 / 4        | -0.0219 / +0.0219 | 0.0194 / 0.0194    | yes         |
| `overloaded`      | failed / provider_busy     |               6 | none; 0 / 0                         | -0.0219 / +0.0219 | 0.0000 / 0.0000    | —           |
| `blocked`         | failed / prompt_rejected   |               1 | none; 0 / 0                         | -0.0219 / +0.0219 | 0.0000 / 0.0000    | —           |
| `plain-reference` | succeeded                  |               1 | processing, succeeded; 2 / 2        | -0.1235 / +0.1235 | 0.1032 / 0.1032    | yes         |
| `plain-high`      | succeeded                  |               1 | processing, succeeded; 2 / 2        | -0.4476 / +0.4476 | 0.4011 / 0.4011    | yes         |

The `[slow]` generation processed from 01:01:12Z to 01:02:12Z (60 seconds). The overload case exhausted six attempts and released its hold as `provider_busy`; the blocked case immediately failed as `prompt_rejected`. All observed webhook deliveries received HTTP 204. Duplicate events produced four HTTP deliveries, two stored rows, and one charge.

## Independent exact arithmetic

Token order below is input text, input image, output, reasoning. Snapshot rates are respectively $5.00, $10.00, $40.00, $12.50 per million, or 5,000, 10,000, 40,000, 12,500 nanodollars per token. Each cost below is an explicit sum of those four integer products. The audit independently uses BCMath decimal arithmetic instead of the application calculator to verify each product, the stored cost and `ceil(cost × 1.25 / 0.0001) × 0.0001`.

| Case              | Token counts       | Provider cost in nanodollars                                              | Cost × 1.25    | Rounded expected = actual |
| ----------------- | ------------------ | ------------------------------------------------------------------------- | -------------- | ------------------------- |
| `succeed`         | 9, 0, 1100, 1759   | 9 × 5,000 + 0 × 10,000 + 1100 × 40,000 + 1759 × 12,500 = **66,032,500**   | $0.08254062500 | $0.0826                   |
| `slow`            | 6, 0, 280, 146     | 6 × 5,000 + 0 × 10,000 + 280 × 40,000 + 146 × 12,500 = **13,055,000**     | $0.01631875000 | $0.0164                   |
| `duplicate`       | 9, 0, 280, 342     | 9 × 5,000 + 0 × 10,000 + 280 × 40,000 + 342 × 12,500 = **15,520,000**     | $0.01940000000 | $0.0194                   |
| `plain-reference` | 12, 765, 1650, 706 | 12 × 5,000 + 765 × 10,000 + 1650 × 40,000 + 706 × 12,500 = **82,535,000** | $0.10316875000 | $0.1032                   |
| `plain-high`      | 10, 0, 6450, 5022  | 10 × 5,000 + 0 × 10,000 + 6450 × 40,000 + 5022 × 12,500 = **320,825,000** | $0.40103125000 | $0.4011                   |

## Stored webhook events

| Case              | Event ID                         | Type                    | Received at UTC           | Tunnel deliveries |
| ----------------- | -------------------------------- | ----------------------- | ------------------------- | ----------------: |
| `succeed`         | `evt_01M7RN4G2EEPD5B45EAECJKBGE` | `generation.processing` | 2026-11-19T01:01:02+00:00 |                 1 |
| `succeed`         | `evt_01M7RN4Q68A6J2XASYGCG8PFYP` | `generation.succeeded`  | 2026-11-19T01:01:09+00:00 |                 1 |
| `fail`            | `evt_01M7RN4PKKFST3GQPGH9ANF209` | `generation.processing` | 2026-11-19T01:01:08+00:00 |                 1 |
| `fail`            | `evt_01M7RN5G2M9N8AJ0816P1J0CD1` | `generation.failed`     | 2026-11-19T01:01:35+00:00 |                 1 |
| `slow`            | `evt_01M7RN4T5MXSCKHGE2CBKKR7V9` | `generation.processing` | 2026-11-19T01:01:12+00:00 |                 1 |
| `slow`            | `evt_01M7RN6MVD0XCKEWR40T2NBY0H` | `generation.succeeded`  | 2026-11-19T01:02:12+00:00 |                 1 |
| `duplicate`       | `evt_01M7RN4ZF2Y38PGNEQN8XHECHB` | `generation.processing` | 2026-11-19T01:01:17+00:00 |                 2 |
| `duplicate`       | `evt_01M7RN5QKW02FXNDQZ4QH1D4A1` | `generation.succeeded`  | 2026-11-19T01:01:42+00:00 |                 2 |
| `plain-reference` | `evt_01M7RN5PW1M2SEQK072JS6PHF4` | `generation.processing` | 2026-11-19T01:01:42+00:00 |                 1 |
| `plain-reference` | `evt_01M7RN64KY9H7NJ5GCYVF1YXR4` | `generation.succeeded`  | 2026-11-19T01:01:56+00:00 |                 1 |
| `plain-high`      | `evt_01M7RN5X8NYWN7EMQ8ST3D902A` | `generation.processing` | 2026-11-19T01:01:48+00:00 |                 1 |
| `plain-high`      | `evt_01M7RN673R5RS186CKWJNC65P5` | `generation.succeeded`  | 2026-11-19T01:01:58+00:00 |                 1 |

## Reconciliation and storage

Today’s UTC `ProfitReport` and a fresh authenticated `GET /v1/usage?start_date=2026-11-19&end_date=2026-11-19` both report **6 succeeded generations, 515,162,500 nanodollars ($0.515162500)**. Difference: **0 nanodollars**. The report matches both the generation count and cost.

The acceptance account contributes 5 successes and 497,967,500 nanodollars. The direct `[succeed]` capture contributes 1 success and 17,195,000 nanodollars. Because the capture command calls the provider directly, its two generations were subsequently recorded under `fixture-capture@example.test` from their captured terminal snapshots, with new local held rows, without making any further provider submissions. These imports are explicitly separate from the eight acceptance cases. Its one charge is $0.0215. The fixture account received its own simulated $5 purchase.

All six successes, including the imported capture, have private image copies in configured storage. The five acceptance images were also checked for the PNG signature. No failed queue jobs exist, and the live account has zero reserved nanodollars. Older demo data completed on November 17 and is outside today’s report.

Raw acceptance records, stored event IDs, sanitized tunnel delivery statuses, ledger amounts, arithmetic and the full report are in [live-acceptance-2026-11-19.json](live-acceptance-2026-11-19.json). Provider captures are in `tests/Fixtures/pixelforge/real-*.json`.

## Verification output

### `composer ci:check`

```text
> check
> vp check

pass: All 109 files are correctly formatted (1674ms, 12 threads)
pass: Found no warnings or lint errors in 71 files (954ms, 12 threads)

> types:check
> vue-tsc --noEmit

{"tool":"pest","result":"passed","tests":632,"passed":623,"assertions":2648,"duration_ms":20920,"skipped":9}
{"tool":"pint","result":"passed"}{"tool":"phpstan","result":"passed","errors":0}
```

### `npm run build`

```text
> build
> vp build

[plugin @laravel/vite-plugin-wayfinder] Types generated for actions, routes, form variants
[plugin laravel:fonts] Optimized font fallbacks require the optional "fontaine" package. Install it, or set "optimizedFallbacks: false" on your fonts to disable the feature.
transforming...
✓ 3684 modules transformed.
rendering chunks...
computing gzip size...
public/build/fonts-manifest.json                                 5.74 kB │ gzip:  0.71 kB
public/build/manifest.json                                      14.60 kB │ gzip:  1.85 kB
public/build/assets/instrument-sans-400-normal-DRC__1Mx.woff2   16.86 kB
public/build/assets/instrument-sans-500-normal-Dk9ku72i.woff2   17.23 kB
public/build/assets/instrument-sans-600-normal-B7fBEWYG.woff2   17.40 kB
public/build/assets/instrument-sans-400-normal-D1W7dsQl.woff    21.24 kB
public/build/assets/instrument-sans-500-normal-Z6ESRlEs.woff    21.65 kB
public/build/assets/instrument-sans-600-normal-B9e8oLYv.woff    21.67 kB
public/build/assets/fonts-C9MNnjVw.css                           2.35 kB │ gzip:  0.38 kB
public/build/assets/app-Ds1aFT64.css                            14.91 kB │ gzip:  3.04 kB
public/build/assets/app-ByhGS7XK.css                           103.50 kB │ gzip: 16.68 kB
public/build/assets/check-WgZ0zV20.js                            0.14 kB │ gzip:  0.15 kB
public/build/assets/date-tQLMeXDw.js                             0.20 kB │ gzip:  0.17 kB
public/build/assets/confirm-CsUkSVfY.js                          0.36 kB │ gzip:  0.22 kB
public/build/assets/Spinner-PSCsVFBz.js                          0.39 kB │ gzip:  0.30 kB
public/build/assets/TextLink-DhuTpRxP.js                         0.52 kB │ gzip:  0.35 kB
public/build/assets/useForwardPropsEmits-BI14s3jU.js             0.69 kB │ gzip:  0.41 kB
public/build/assets/PageNavigation-CftT0SB6.js                   0.89 kB │ gzip:  0.48 kB
public/build/assets/Label-DtxS7L-Q.js                            1.06 kB │ gzip:  0.57 kB
public/build/assets/alert-Z693qYL8.js                            1.12 kB │ gzip:  0.57 kB
public/build/assets/CardTitle-CwV0MFXt.js                        1.25 kB │ gzip:  0.49 kB
public/build/assets/PasskeyVerify-DLZRnnma.js                    1.39 kB │ gzip:  0.80 kB
public/build/assets/Welcome-rdUQ_VLt.js                          1.41 kB │ gzip:  0.73 kB
public/build/assets/InputError-Dz0l4aFO.js                       1.41 kB │ gzip:  0.76 kB
public/build/assets/ForgotPassword-p1G-W1-o.js                   1.67 kB │ gzip:  0.90 kB
public/build/assets/GenerationStatus-1RG26vak.js                 1.74 kB │ gzip:  0.84 kB
public/build/assets/PasswordInput-D11nNMRl.js                    1.79 kB │ gzip:  0.97 kB
public/build/assets/shared-CrDLG-qh.js                           2.01 kB │ gzip:  1.04 kB
public/build/assets/Index-BdF7NmC7.js                            2.16 kB │ gzip:  1.06 kB
public/build/assets/ResetPassword-DQBdpru-.js                    2.20 kB │ gzip:  0.93 kB
public/build/assets/Appearance-B-rFFMK2.js                       2.22 kB │ gzip:  1.13 kB
public/build/assets/ConfirmPassword-CD-drl_f.js                  2.23 kB │ gzip:  1.05 kB
public/build/assets/VisuallyHidden-BL8yKicn.js                   2.53 kB │ gzip:  1.21 kB
public/build/assets/createLucideIcon-BuP_eVHw.js                 2.53 kB │ gzip:  1.27 kB
public/build/assets/password-BgA-cKNw.js                         2.73 kB │ gzip:  0.73 kB
public/build/assets/Register-Dp_mCqL4.js                         2.89 kB │ gzip:  1.19 kB
public/build/assets/TwoFactorChallenge--uccaAuL.js               3.00 kB │ gzip:  1.23 kB
public/build/assets/FieldRoot-C3AmhTan.js                        3.43 kB │ gzip:  1.52 kB
public/build/assets/Show-BPgnA5Mx.js                             4.94 kB │ gzip:  1.93 kB
public/build/assets/Profile-KNkiNSnM.js                          5.23 kB │ gzip:  1.84 kB
public/build/assets/dialog-9RGJEla7.js                           5.26 kB │ gzip:  1.80 kB
public/build/assets/useForwardExpose-CLtwN1g7.js                 5.53 kB │ gzip:  2.45 kB
public/build/assets/Report-D7p7FN9b.js                           7.47 kB │ gzip:  2.38 kB
public/build/assets/Presence-d1pd8olO.js                         8.95 kB │ gzip:  3.90 kB
public/build/assets/Login-XXC_Em94.js                           12.57 kB │ gzip:  4.67 kB
public/build/assets/login-BQMepwas.js                           12.81 kB │ gzip:  4.92 kB
public/build/assets/vue-BnnQVPIU.js                             13.37 kB │ gzip:  4.45 kB
public/build/assets/Teleport-D0Ax99m7.js                        13.99 kB │ gzip:  5.20 kB
public/build/assets/Security-CGH2Ij4N.js                        26.98 kB │ gzip:  8.29 kB
public/build/assets/utils-BkoQkVx6.js                           29.05 kB │ gzip:  9.41 kB
public/build/assets/Index-vMUR_9VR.js                           41.79 kB │ gzip: 12.47 kB
public/build/assets/app-ByBN7yWV.js                            156.54 kB │ gzip: 43.84 kB
public/build/assets/wayfinder-MaKGvp3K.js                      247.53 kB │ gzip: 84.01 kB

✓ built in 1.57s
```
